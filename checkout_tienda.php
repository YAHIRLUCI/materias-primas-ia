<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Método no permitido'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$raw = file_get_contents('php://input');
$data = json_decode($raw ?: '', true);

$items = is_array($data['items'] ?? null) ? $data['items'] : [];
if (empty($items)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'La orden está vacía'], JSON_UNESCAPED_UNICODE);
    exit;
}

$esPreorden    = !empty($data['es_preorden']);
$fechaEntrega  = trim((string)($data['fecha_entrega'] ?? ''));
$franja        = trim((string)($data['franja'] ?? 'Indistinto'));
$notas         = trim((string)($data['notas'] ?? ''));
$clienteNombre = trim((string)($data['cliente_nombre'] ?? ($_SESSION['usuario_nombre'] ?? $_SESSION['nombre'] ?? 'Cliente Tienda')));
$clienteCorreo = trim((string)($data['cliente_correo'] ?? ($_SESSION['usuario_correo'] ?? $_SESSION['correo'] ?? '')));
$clienteTel    = trim((string)($data['cliente_telefono'] ?? ($_SESSION['usuario_telefono'] ?? '0000000000')));
if ($clienteTel === '') {
    $clienteTel = '0000000000';
}

$connPath = __DIR__ . '/conexion.php';
if (!is_file($connPath)) {
    echo json_encode([
        'ok' => true,
        'folio' => 'ORD-' . strtoupper(bin2hex(random_bytes(3))),
        'guardado_db' => false,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

require_once $connPath;

if (!isset($pdo) || !($pdo instanceof PDO)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Sin conexión PDO a la base de datos'], JSON_UNESCAPED_UNICODE);
    exit;
}

function mp_table_info(PDO $pdo, string $table): array {
    try {
        return $pdo->query("SHOW COLUMNS FROM `{$table}`")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return [];
    }
}

function mp_detect_pk(array $colsInfo, array $preferredNames): ?string {
    $names = array_column($colsInfo, 'Field');
    foreach ($preferredNames as $pref) {
        if (in_array($pref, $names, true)) {
            return $pref;
        }
    }
    foreach ($colsInfo as $c) {
        if (strtoupper((string)($c['Key'] ?? '')) === 'PRI') {
            return (string)$c['Field'];
        }
    }
    return $names[0] ?? null;
}

function mp_resolve_presentacion(PDO $pdo, int $productoId, string $presentacion): array {
    try {
        $cols = $pdo->query("SHOW COLUMNS FROM `presentaciones_gramaje`")->fetchAll(PDO::FETCH_COLUMN);
        if (empty($cols)) {
            return ['id' => null, 'precio' => null];
        }
        $pkCol = in_array('id_presentacion', $cols, true) ? 'id_presentacion'
               : (in_array('id', $cols, true) ? 'id' : $cols[0]);
        $prodCol = in_array('producto_id', $cols, true) ? 'producto_id'
                 : (in_array('id_producto', $cols, true) ? 'id_producto' : null);
        $labelCol = in_array('nombre_presentacion', $cols, true) ? 'nombre_presentacion'
                  : (in_array('presentacion', $cols, true) ? 'presentacion'
                  : (in_array('gramaje', $cols, true) ? 'gramaje' : null));
        $priceCol = in_array('precio', $cols, true) ? 'precio' : null;

        $sel = $priceCol ? "`{$pkCol}` AS id_p, `{$priceCol}` AS precio_p" : "`{$pkCol}` AS id_p, NULL AS precio_p";

        if ($prodCol !== null && $labelCol !== null && $presentacion !== '') {
            $st = $pdo->prepare("SELECT {$sel} FROM `presentaciones_gramaje` WHERE `{$prodCol}` = ? AND LOWER(TRIM(`{$labelCol}`)) = LOWER(TRIM(?)) LIMIT 1");
            $st->execute([$productoId, $presentacion]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                return ['id' => (int)$row['id_p'], 'precio' => $row['precio_p'] !== null ? (float)$row['precio_p'] : null];
            }
        }

        if ($prodCol !== null) {
            $st = $pdo->prepare("SELECT {$sel} FROM `presentaciones_gramaje` WHERE `{$prodCol}` = ? ORDER BY `{$pkCol}` ASC LIMIT 1");
            $st->execute([$productoId]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                return ['id' => (int)$row['id_p'], 'precio' => $row['precio_p'] !== null ? (float)$row['precio_p'] : null];
            }
        }

        $stAny = $pdo->query("SELECT {$sel} FROM `presentaciones_gramaje` ORDER BY `{$pkCol}` ASC LIMIT 1");
        $rowAny = $stAny ? $stAny->fetch(PDO::FETCH_ASSOC) : false;
        if ($rowAny) {
            return ['id' => (int)$rowAny['id_p'], 'precio' => $rowAny['precio_p'] !== null ? (float)$rowAny['precio_p'] : null];
        }
        return ['id' => null, 'precio' => null];
    } catch (Throwable $e) {
        return ['id' => null, 'precio' => null];
    }
}

try {
    $pdo->beginTransaction();

    $subtotalGeneral = 0.0;
    $cleanItems = [];
    foreach ($items as $it) {
        $pid = (int)($it['id'] ?? 0);
        $pres = trim((string)($it['presentacion'] ?? '1 kg'));
        $precio = max(0.0, (float)($it['precio'] ?? 0));
        $cant = max(1, (int)($it['cantidad'] ?? 1));
        if ($pid <= 0) {
            continue;
        }

        $resPres = mp_resolve_presentacion($pdo, $pid, $pres);
        if ($precio <= 0 && $resPres['precio'] !== null) {
            $precio = (float)$resPres['precio'];
        }

        $subLinea = round($precio * $cant, 2);
        $subtotalGeneral = round($subtotalGeneral + $subLinea, 2);

        $cleanItems[] = [
            'producto_id'     => $pid,
            'nombre'          => trim((string)($it['nombre'] ?? 'Producto')),
            'presentacion'    => $pres,
            'presentacion_id' => $resPres['id'],
            'precio_unitario' => round($precio, 2),
            'cantidad'        => $cant,
            'subtotal'        => $subLinea,
        ];
    }

    if (empty($cleanItems)) {
        throw new RuntimeException('No hay productos válidos en la orden.');
    }

    $descuento = 0.0;
    $totalFinal = round(max(0.0, $subtotalGeneral - $descuento), 2);
    $folio = ($esPreorden ? 'PRE-' : 'ORD-') . date('ymd') . '-' . strtoupper(bin2hex(random_bytes(2)));

    // Vincular o insertar en clientes
    $clienteId = null;
    $infoCli = mp_table_info($pdo, 'clientes');
    if (!empty($infoCli)) {
        $cliPk = mp_detect_pk($infoCli, ['id_cliente', 'id', 'cliente_id']);
        $colNamesCli = array_column($infoCli, 'Field');

        if ($clienteCorreo !== '' && in_array('correo', $colNamesCli, true) && $cliPk !== null) {
            $stCli = $pdo->prepare("SELECT `{$cliPk}` FROM clientes WHERE correo = ? LIMIT 1");
            $stCli->execute([$clienteCorreo]);
            $foundCli = $stCli->fetchColumn();
            if ($foundCli !== false) {
                $clienteId = (int)$foundCli;
            }
        }

        if ($clienteId === null) {
            $insCols = [];
            $insVals = [];
            foreach ($infoCli as $cInfo) {
                $f = (string)$cInfo['Field'];
                $extra = strtolower((string)($cInfo['Extra'] ?? ''));
                $nullAllowed = strtoupper((string)($cInfo['Null'] ?? 'YES')) === 'YES';
                $type = strtolower((string)($cInfo['Type'] ?? ''));
                if (str_contains($extra, 'auto_increment')) {
                    continue;
                }
                $val = null;
                if ($f === 'nombre') {
                    $val = $clienteNombre !== '' ? $clienteNombre : 'Cliente Tienda';
                } elseif ($f === 'correo' && $clienteCorreo !== '') {
                    $val = $clienteCorreo;
                } elseif ($f === 'telefono') {
                    $val = $clienteTel;
                } elseif (!$nullAllowed && $cInfo['Default'] === null) {
                    if (str_contains($type, 'int') || str_contains($type, 'decimal') || str_contains($type, 'float')) {
                        $val = 0;
                    } elseif (str_contains($type, 'datetime') || str_contains($type, 'timestamp')) {
                        $val = date('Y-m-d H:i:s');
                    } elseif ($type === 'date') {
                        $val = date('Y-m-d');
                    } else {
                        $val = '';
                    }
                }
                if ($val !== null) {
                    $insCols[] = "`{$f}`";
                    $insVals[] = $val;
                }
            }

            if (!empty($insCols)) {
                $ph = implode(', ', array_fill(0, count($insCols), '?'));
                $stInsCli = $pdo->prepare("INSERT INTO clientes (" . implode(', ', $insCols) . ") VALUES ({$ph})");
                $stInsCli->execute($insVals);
                $clienteId = (int)$pdo->lastInsertId();
            }
        }
    }

    // Armar observaciones legibles para panel.php
    $obsPartes = ["Folio: {$folio}", "Cliente: {$clienteNombre}"];
    if ($esPreorden) {
        $obsPartes[] = "PREORDEN — Entrega: " . ($fechaEntrega !== '' ? $fechaEntrega : 'Por definir') . " ({$franja})";
    } else {
        $obsPartes[] = "Pedido directo desde tienda";
    }
    if ($notas !== '') {
        $obsPartes[] = "Notas: {$notas}";
    }
    $observacionesFinal = implode(' | ', $obsPartes);

    // Insertar en ordenes_compra con subtotal, descuento_aplicado y total_final reales
    $infoOrd = mp_table_info($pdo, 'ordenes_compra');
    $colsOrd = array_column($infoOrd, 'Field');
    $ordenId = null;

    if (!empty($infoOrd)) {
        $ordCols = [];
        $ordVals = [];

        if (in_array('id_cliente', $colsOrd, true)) {
            $ordCols[] = '`id_cliente`';
            $ordVals[] = $clienteId;
        }
        if (in_array('estado', $colsOrd, true)) {
            $ordCols[] = '`estado`';
            $ordVals[] = 'Pendiente';
        }
        if (in_array('subtotal', $colsOrd, true)) {
            $ordCols[] = '`subtotal`';
            $ordVals[] = number_format($subtotalGeneral, 2, '.', '');
        }
        if (in_array('descuento_aplicado', $colsOrd, true)) {
            $ordCols[] = '`descuento_aplicado`';
            $ordVals[] = number_format($descuento, 2, '.', '');
        }
        if (in_array('total_final', $colsOrd, true)) {
            $ordCols[] = '`total_final`';
            $ordVals[] = number_format($totalFinal, 2, '.', '');
        }
        if (in_array('observaciones', $colsOrd, true)) {
            $ordCols[] = '`observaciones`';
            $ordVals[] = $observacionesFinal;
        }

        // Respaldo para cualquier otra columna NOT NULL sin default
        foreach ($infoOrd as $oInfo) {
            $f = (string)$oInfo['Field'];
            $extra = strtolower((string)($oInfo['Extra'] ?? ''));
            $nullAllowed = strtoupper((string)($oInfo['Null'] ?? 'YES')) === 'YES';
            if (str_contains($extra, 'auto_increment')) {
                continue;
            }
            $yaIncluida = in_array("`{$f}`", $ordCols, true);
            if (!$yaIncluida && !$nullAllowed && $oInfo['Default'] === null) {
                $type = strtolower((string)($oInfo['Type'] ?? ''));
                $ordCols[] = "`{$f}`";
                if (str_contains($type, 'int') || str_contains($type, 'decimal') || str_contains($type, 'float')) {
                    $ordVals[] = 0;
                } elseif (str_contains($type, 'datetime') || str_contains($type, 'timestamp')) {
                    $ordVals[] = date('Y-m-d H:i:s');
                } elseif ($type === 'date') {
                    $ordVals[] = date('Y-m-d');
                } else {
                    $ordVals[] = '';
                }
            }
        }

        if (!empty($ordCols)) {
            $phOrd = implode(', ', array_fill(0, count($ordCols), '?'));
            $stOrd = $pdo->prepare("INSERT INTO ordenes_compra (" . implode(', ', $ordCols) . ") VALUES ({$phOrd})");
            $stOrd->execute($ordVals);
            $ordenId = (int)$pdo->lastInsertId();
        }
    }

    // Insertar partidas en detalle_orden
    $infoDet = mp_table_info($pdo, 'detalle_orden');
    $colsDet = array_column($infoDet, 'Field');

    foreach ($cleanItems as $ci) {
        if (!empty($infoDet)) {
            $detCols = [];
            $detVals = [];

            if (in_array('id_orden', $colsDet, true) && $ordenId) {
                $detCols[] = '`id_orden`';
                $detVals[] = $ordenId;
            }
            if (in_array('id_producto', $colsDet, true)) {
                $detCols[] = '`id_producto`';
                $detVals[] = $ci['producto_id'];
            }
            if (in_array('id_presentacion', $colsDet, true)) {
                $detCols[] = '`id_presentacion`';
                $detVals[] = $ci['presentacion_id'];
            }
            if (in_array('cantidad', $colsDet, true)) {
                $detCols[] = '`cantidad`';
                $detVals[] = $ci['cantidad'];
            }
            if (in_array('precio_unitario', $colsDet, true)) {
                $detCols[] = '`precio_unitario`';
                $detVals[] = number_format($ci['precio_unitario'], 2, '.', '');
            }
            if (in_array('subtotal', $colsDet, true)) {
                $detCols[] = '`subtotal`';
                $detVals[] = number_format($ci['subtotal'], 2, '.', '');
            }

            if (!empty($detCols)) {
                $phDet = implode(', ', array_fill(0, count($detCols), '?'));
                $stDet = $pdo->prepare("INSERT INTO detalle_orden (" . implode(', ', $detCols) . ") VALUES ({$phDet})");
                $stDet->execute($detVals);
            }
        }
    }

    $pdo->commit();

    echo json_encode([
        'ok' => true,
        'folio' => $folio,
        'orden_id' => $ordenId,
        'subtotal' => $subtotalGeneral,
        'total' => $totalFinal,
        'guardado_db' => true,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => 'No se pudo registrar la orden: ' . $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}