<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Método no permitido'], JSON_UNESCAPED_UNICODE);
    exit;
}

$raw = file_get_contents('php://input');
$input = json_decode($raw ?: '', true);
$mensaje = trim((string)($input['mensaje'] ?? ''));

if ($mensaje === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Mensaje vacío'], JSON_UNESCAPED_UNICODE);
    exit;
}

$empresa  = trim((string)($input['empresa'] ?? 'Materias Primas IA'));
$modelo   = trim((string)($input['modelo'] ?? 'gpt-4o-mini'));
$catalogo = is_array($input['catalogo'] ?? null) ? $input['catalogo'] : [];
$catsMap  = is_array($input['cats'] ?? null) ? $input['cats'] : [];
$carrito  = is_array($input['carrito'] ?? null) ? $input['carrito'] : [];

function mp_norm_txt(string $s): string {
    $s = mb_strtolower(trim($s), 'UTF-8');
    $s = strtr($s, ['á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ü'=>'u','ñ'=>'n']);
    return preg_replace('/\s+/', ' ', $s) ?? $s;
}

$msgNorm = mp_norm_txt($mensaje);

// Detecta si el usuario pide productos de una categoría específica
$matchedCategoryKey = null;
$matchedCategoryLabel = null;

$categoryAliases = [
    'reposteria' => ['reposteria', 'reposterias', 'pasteleria', 'chocolate', 'cocoa', 'cobertura'],
    'chiles'     => ['chile', 'chiles', 'chile seco', 'chiles secos', 'guajillo', 'ancho'],
    'especias'   => ['especia', 'especias', 'curcuma', 'canela'],
    'harinas'    => ['harina', 'harinas', 'almendra'],
    'aceites'    => ['aceite', 'aceites', 'oliva'],
];

foreach ($catsMap as $ckey => $clabel) {
    $kNorm = mp_norm_txt((string)$ckey);
    $lNorm = mp_norm_txt((string)$clabel);
    $singularL = rtrim($lNorm, 's');
    if (
        ($kNorm !== '' && preg_match('/\b' . preg_quote($kNorm, '/') . '\b/u', $msgNorm)) ||
        ($lNorm !== '' && (mb_strpos($msgNorm, $lNorm) !== false || ($singularL !== '' && mb_strpos($msgNorm, $singularL) !== false)))
    ) {
        $matchedCategoryKey = (string)$ckey;
        $matchedCategoryLabel = (string)$clabel;
        break;
    }
}

if ($matchedCategoryKey === null) {
    foreach ($categoryAliases as $ckey => $aliases) {
        foreach ($aliases as $alias) {
            if (preg_match('/\b' . preg_quote($alias, '/') . '\b/u', $msgNorm)) {
                $matchedCategoryKey = $ckey;
                $matchedCategoryLabel = (string)($catsMap[$ckey] ?? ucfirst($ckey));
                break 2;
            }
        }
    }
}

$productosSugeridos = [];
if ($matchedCategoryKey !== null) {
    foreach ($catalogo as $p) {
        $pCat = mp_norm_txt((string)($p['categoria_key'] ?? $p['c'] ?? ''));
        $pCatLabel = mp_norm_txt((string)($p['categoria'] ?? ''));
        if ($pCat === mp_norm_txt($matchedCategoryKey) || ($matchedCategoryLabel && $pCatLabel === mp_norm_txt($matchedCategoryLabel))) {
            $productosSugeridos[] = $p;
        }
    }
} else {
    // Coincidencia por nombre o descripción si no es categoría directa
    $tokens = array_filter(explode(' ', preg_replace('/[^a-z0-9 ]+/u', ' ', $msgNorm) ?? ''), fn($w) => mb_strlen($w) >= 3);
    foreach ($catalogo as $p) {
        $haystack = mp_norm_txt((string)($p['nombre'] ?? $p['n'] ?? '') . ' ' . ($p['descripcion'] ?? $p['d'] ?? '') . ' ' . ($p['categoria'] ?? ''));
        foreach ($tokens as $tok) {
            if (mb_strpos($haystack, $tok) !== false) {
                $productosSugeridos[] = $p;
                break;
            }
        }
    }
}

// API key protegida en el servidor (nunca enviada al navegador)
$apiKey = getenv('OPENAI_API_KEY') ?: ($_ENV['OPENAI_API_KEY'] ?? '');
$systemPrompt = "Eres el asistente oficial de la tienda de materias primas \"{$empresa}\" en México.\n"
    . "Ayudas con catálogo, presentaciones, precios, stock/almacenamiento, carrito, cotizaciones y preórdenes.\n"
    . "Si el usuario pregunta por una categoría (ej. repostería, chiles, especias, harinas, aceites), menciona TODOS los productos disponibles de esa categoría con sus presentaciones y precios.\n"
    . "NO inventes productos que no estén en el catálogo.\n"
    . "Responde en español mexicano, claro, útil y breve.\n"
    . "Catálogo actual: " . json_encode($catalogo, JSON_UNESCAPED_UNICODE) . "\n"
    . "Carrito actual del cliente: " . json_encode($carrito, JSON_UNESCAPED_UNICODE);

$payload = [
    'model' => $modelo !== '' ? $modelo : 'gpt-4o-mini',
    'messages' => [
        ['role' => 'system', 'content' => $systemPrompt],
        ['role' => 'user', 'content' => $mensaje],
    ],
    'temperature' => 0.25,
    'max_tokens' => 650,
];

$ch = curl_init('https://api.openai.com/v1/chat/completions');
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 22,
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $apiKey,
    ],
    CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
]);

$responseBody = curl_exec($ch);
$curlErr = curl_error($ch);
$httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$reply = '';
if ($responseBody !== false && $httpCode >= 200 && $httpCode < 300) {
    $decoded = json_decode($responseBody, true);
    $reply = trim((string)($decoded['choices'][0]['message']['content'] ?? ''));
}

// Respaldo inteligente si la API externa falla o tarda
if ($reply === '') {
    if (!empty($productosSugeridos)) {
        $enc = $matchedCategoryLabel ? "Productos disponibles en {$matchedCategoryLabel}:" : "Productos encontrados:";
        $lines = [$enc];
        foreach ($productosSugeridos as $p) {
            $nom = (string)($p['nombre'] ?? $p['n'] ?? 'Producto');
            $stk = (int)($p['stock'] ?? $p['s'] ?? 0);
            $pres = is_array($p['presentaciones'] ?? null) ? implode(', ', $p['presentaciones']) : '';
            $lines[] = "• {$nom} (" . ($stk > 0 ? "{$stk} en stock" : "Agotado") . ")" . ($pres !== '' ? " — {$pres}" : '');
        }
        $reply = implode("\n", $lines);
    } elseif (preg_match('/\b(hola|buenas|ayuda|menu|categorias)\b/u', $msgNorm)) {
        $names = array_values($catsMap);
        $reply = "¡Hola! Puedo mostrarte todos los productos por categoría (" . (!empty($names) ? implode(', ', $names) : 'repostería, chiles, especias, harinas, aceites') . "), revisar stock o armar tu orden.";
    } else {
        $reply = "No encontré coincidencias exactas para \"{$mensaje}\". Prueba diciendo el nombre de una categoría como repostería, chiles, especias, harinas o aceites."
            . ($curlErr !== '' ? " (Nota de red: {$curlErr})" : '');
    }
}

echo json_encode([
    'ok' => true,
    'reply' => $reply,
    'category_key' => $matchedCategoryKey,
    'category_label' => $matchedCategoryLabel,
    'productos_sugeridos' => $productosSugeridos,
], JSON_UNESCAPED_UNICODE);