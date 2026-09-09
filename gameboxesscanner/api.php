<?php
// Shared store for scanned EAN codes, so every device sees the same data.
//
// GET  ?key=...            → { "games": [...] }
// POST ?key=... {games:[]} → merges the posted entries into the store and
//                            returns the merged set.
//
// The key lives in config.php, which is deliberately NOT in the repository.
// Copy config.php.example to config.php on the server and pick your own.

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

const MAX_BODY = 524288;   // 512 KB
const MAX_ENTRIES = 5000;

function send($code, $payload) {
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

$configFile = __DIR__ . '/config.php';
if (!is_file($configFile)) {
    send(500, ['error' => 'config.php saknas på servern']);
}
$config = require $configFile;
$secret = isset($config['key']) ? (string)$config['key'] : '';
if ($secret === '' || $secret === 'byt-ut-mig') {
    send(500, ['error' => 'ingen nyckel satt i config.php']);
}

$given = isset($_GET['key']) ? (string)$_GET['key'] : '';
if (!hash_equals($secret, $given)) {
    send(403, ['error' => 'fel nyckel']);
}

function lower($s) {
    return function_exists('mb_strtolower') ? mb_strtolower($s, 'UTF-8') : strtolower($s);
}

function cut($s, $n) {
    return function_exists('mb_substr') ? mb_substr($s, 0, $n, 'UTF-8') : substr($s, 0, $n);
}

// UPC-A prints 12 digits; scanners usually report the same product as EAN-13
// with a leading zero. Store one canonical form so they never split in two.
function norm_ean($v) {
    $d = preg_replace('/\D/', '', (string)$v);
    if (strlen($d) === 13 && $d[0] === '0') {
        $d = substr($d, 1);
    }
    return $d;
}

function clean($g) {
    if (!is_array($g)) return null;
    $namn = isset($g['namn']) ? trim((string)$g['namn']) : '';
    $lada = isset($g['låda']) ? trim((string)$g['låda']) : '';
    if ($namn === '' || $lada === '') return null;

    $ean = (isset($g['ean']) && $g['ean'] !== null) ? norm_ean($g['ean']) : '';
    $status = (isset($g['status']) && $g['status'] !== null) ? (string)$g['status'] : null;
    if ($status !== null && !in_array($status, ['full', 'luft'], true)) {
        $status = null;
    }
    return [
        'ean'    => $ean === '' ? null : $ean,
        'namn'   => cut($namn, 200),
        'låda'   => cut($lada, 20),
        'status' => $status,
    ];
}

function entry_key($g) {
    if ($g['ean'] !== null && $g['ean'] !== '') return 'e:' . $g['ean'];
    return 'n:' . lower($g['namn']);
}

$store = __DIR__ . '/codes.json';
$fp = @fopen($store, 'c+');
if ($fp === false) {
    send(500, ['error' => 'kan inte öppna codes.json — kontrollera skrivrättigheter']);
}
flock($fp, LOCK_EX);

$raw = stream_get_contents($fp);
$current = json_decode($raw === false ? '' : $raw, true);
if (!is_array($current)) $current = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = file_get_contents('php://input');
    if (strlen($body) > MAX_BODY) {
        flock($fp, LOCK_UN); fclose($fp);
        send(413, ['error' => 'för mycket data']);
    }
    $in = json_decode($body, true);
    $incoming = (is_array($in) && isset($in['games']) && is_array($in['games'])) ? $in['games'] : [];

    // Merge server-side so two devices saving at once cannot clobber each
    // other: last write wins per entry, not per whole file.
    $map = [];
    foreach ($current as $g) {
        $c = clean($g);
        if ($c) $map[entry_key($c)] = $c;
    }
    foreach ($incoming as $g) {
        $c = clean($g);
        if ($c) $map[entry_key($c)] = $c;
    }
    $merged = array_values($map);

    if (count($merged) > MAX_ENTRIES) {
        flock($fp, LOCK_UN); fclose($fp);
        send(413, ['error' => 'för många poster']);
    }

    rewind($fp);
    ftruncate($fp, 0);
    fwrite($fp, json_encode($merged, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    fflush($fp);
    $current = $merged;
}

flock($fp, LOCK_UN);
fclose($fp);

send(200, ['games' => $current, 'count' => count($current)]);
