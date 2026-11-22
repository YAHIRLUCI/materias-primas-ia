<?php
declare(strict_types=1);

$mpIsDirectCall = (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__));
if ($mpIsDirectCall) {
    header('Content-Type: application/json; charset=utf-8');
}

$defaultCats = [
    'especias'   => 'Especias',
    'chiles'     => 'Chiles secos',
    'reposteria' => 'Repostería',
    'harinas'    => 'Harinas',
    'aceites'    => 'Aceites',
];

$defaultProducts = [
    ['id' => 1, 'n' => 'Cúrcuma en Polvo', 'c' => 'especias', 'd' => 'Alta pureza, color intenso.', 's' => 45, 'img' => '', 'o' => [['100 g', 25.0], ['500 g', 110.0], ['1 kg', 200.0]]],
    ['id' => 2, 'n' => 'Chile Guajillo', 'c' => 'chiles', 'd' => 'Entero y limpio, para salsas y adobos.', 's' => 12, 'img' => '', 'o' => [['250 g', 40.0], ['1 kg', 140.0]]],
    ['id' => 3, 'n' => 'Cocoa Alcalinizada', 'c' => 'reposteria', 'd' => 'Ideal para repostería fina.', 's' => 8, 'img' => '', 'o' => [['500 g', 80.0], ['1 kg', 150.0]]],
    ['id' => 4, 'n' => 'Canela Ceylán', 'c' => 'especias', 'd' => 'Premium, rama entera.', 's' => 30, 'img' => '', 'o' => [['100 g', 45.0], ['250 g', 100.0]]],
    ['id' => 5, 'n' => 'Chile Ancho', 'c' => 'chiles', 'd' => 'Suave y sabroso.', 's' => 5, 'img' => '', 'o' => [['250 g', 50.0], ['1 kg', 165.0]]],
    ['id' => 6, 'n' => 'Harina de Almendra', 'c' => 'harinas', 'd' => 'Sin gluten, molienda fina.', 's' => 22, 'img' => '', 'o' => [['500 g', 120.0], ['1 kg', 220.0]]],
    ['id' => 7, 'n' => 'Aceite de Oliva E.V.', 'c' => 'aceites', 'd' => 'Extracción en frío.', 's' => 18, 'img' => '', 'o' => [['500 ml', 95.0], ['1 L', 175.0]]],
    ['id' => 8, 'n' => 'Chocolate Cobertura 70%', 'c' => 'reposteria', 'd' => 'Temperado, ideal cobertura.', 's' => 9, 'img' => '', 'o' => [['500 g', 140.0], ['1 kg', 260.0]]],
];

function mp_slug(string $txt): string {
    $t = mb_strtolower(trim($txt), 'UTF-8');
    $map = ['á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ü'=>'u','ñ'=>'n'];
    $t = strtr($t, $map);
    $t = preg_replace('/[^a-z0-9]+/', '_', $t) ?? $t;
    return trim($t, '_') ?: 'general';
}

function mp_obtener_catalogo(): array {
    global $defaultCats, $defaultProducts;

    $connPath = __DIR__ . '/conexion.php';
    if (is_file($connPath)) {
        require_once $connPath;
    }

    if (!isset($pdo) || !($pdo instanceof PDO)) {
        return ['ok' => true, 'source' => 'fallback', 'cats' => $defaultCats, 'products' => $defaultProducts];
    }

    try {
        $cats = [];
        $catIdToKey = [];

        $hasSlug = false;
        try {
            $colsCat = $pdo->query("SHOW COLUMNS FROM categorias")->fetchAll(PDO::FETCH_COLUMN);
            $hasSlug = in_array('slug', $colsCat, true);
        } catch (Throwable $e) {
            $hasSlug = false;
        }

        $sqlCats = $hasSlug
            ? "SELECT id, nombre, slug FROM categorias ORDER BY nombre ASC"
            : "SELECT id, nombre FROM categorias ORDER BY nombre ASC";

        foreach ($pdo->query($sqlCats)->fetchAll(PDO::FETCH_ASSOC) as $c) {
            $cid = (int)$c['id'];
            $nombre = (string)$c['nombre'];
            $key = ($hasSlug && !empty($c['slug'])) ? (string)$c['slug'] : mp_slug($nombre);
            if ($key === 'reposteria_fina') {
                $key = 'reposteria';
            }
            $cats[$key] = $nombre;
            $catIdToKey[$cid] = $key;
        }

        $colsProd = $pdo->query("SHOW COLUMNS FROM productos")->fetchAll(PDO::FETCH_COLUMN);
        $hasImg = in_array('imagen', $colsProd, true);
        $hasDesc = in_array('descripcion', $colsProd, true);
        $hasStock = in_array('stock', $colsProd, true);
        $hasCatId = in_array('categoria_id', $colsProd, true);

        $selectFields = [
            "p.id",
            "p.nombre",
            $hasDesc ? "p.descripcion" : "'' AS descripcion",
            $hasStock ? "p.stock" : "20 AS stock",
            $hasImg ? "p.imagen" : "'' AS imagen",
            $hasCatId ? "p.categoria_id" : "0 AS categoria_id",
        ];

        $sqlProds = "SELECT " . implode(", ", $selectFields) . " FROM productos p ORDER BY p.id ASC";
        $rowsProd = $pdo->query($sqlProds)->fetchAll(PDO::FETCH_ASSOC);

        if (empty($rowsProd)) {
            return [
                'ok' => true,
                'source' => 'fallback_empty_db',
                'cats' => !empty($cats) ? $cats : $defaultCats,
                'products' => $defaultProducts,
            ];
        }

        $presByProd = [];
        try {
            $colsPres = $pdo->query("SHOW COLUMNS FROM presentaciones_gramaje")->fetchAll(PDO::FETCH_COLUMN);
            $labelCol = in_array('presentacion', $colsPres, true) ? 'presentacion'
                      : (in_array('gramaje', $colsPres, true) ? 'gramaje'
                      : (in_array('nombre', $colsPres, true) ? 'nombre' : null));
            $priceCol = in_array('precio', $colsPres, true) ? 'precio'
                      : (in_array('precio_venta', $colsPres, true) ? 'precio_venta' : null);

            if ($labelCol && $priceCol) {
                $stmtP = $pdo->query("SELECT producto_id, {$labelCol} AS pres_label, {$priceCol} AS pres_precio FROM presentaciones_gramaje ORDER BY id ASC");
                foreach ($stmtP->fetchAll(PDO::FETCH_ASSOC) as $pr) {
                    $pid = (int)$pr['producto_id'];
                    $presByProd[$pid][] = [(string)$pr['pres_label'], (float)$pr['pres_precio']];
                }
            }
        } catch (Throwable $e) {
            $presByProd = [];
        }

        $products = [];
        foreach ($rowsProd as $r) {
            $pid = (int)$r['id'];
            $cid = (int)$r['categoria_id'];
            $ckey = $catIdToKey[$cid] ?? 'general';
            if (!isset($cats[$ckey])) {
                $cats[$ckey] = ucfirst(str_replace('_', ' ', $ckey));
            }
            $opts = $presByProd[$pid] ?? [['1 kg', 100.0]];
            $products[] = [
                'id'  => $pid,
                'n'   => (string)$r['nombre'],
                'c'   => $ckey,
                'd'   => (string)($r['descripcion'] ?? ''),
                's'   => (int)($r['stock'] ?? 0),
                'img' => (string)($r['imagen'] ?? ''),
                'o'   => $opts,
            ];
        }

        return [
            'ok' => true,
            'source' => 'mysql',
            'cats' => !empty($cats) ? $cats : $defaultCats,
            'products' => $products,
        ];
    } catch (Throwable $e) {
        return [
            'ok' => true,
            'source' => 'fallback_error',
            'cats' => $defaultCats,
            'products' => $defaultProducts,
        ];
    }
}

if ($mpIsDirectCall) {
    echo json_encode(mp_obtener_catalogo(), JSON_UNESCAPED_UNICODE);
}