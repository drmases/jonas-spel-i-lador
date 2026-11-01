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

// Public read for the box list. It shows the same collection the site already
// publishes, so a key would only add setup friction on every device. Codes and
// timestamps stay out of this view, and writing still needs the key.
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['read'])) {
    $raw = @file_get_contents(__DIR__ . '/codes.json');
    $data = json_decode($raw === false ? '' : $raw, true);
    if (!is_array($data)) $data = [];
    $out = [];
    foreach ($data as $g) {
        if (!is_array($g) || empty($g['namn']) || empty($g['låda'])) continue;
        $out[] = [
            'id'    => isset($g['id']) ? $g['id'] : null,
            'namn'  => (string)$g['namn'],
            'låda'  => (string)$g['låda'],
            'värde' => isset($g['värde']) ? $g['värde'] : null,
        ];
    }
    send(200, ['games' => $out, 'count' => count($out)]);
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

    // Value grade 1-3. Anything else is treated as unset.
    $varde = (isset($g['värde']) && $g['värde'] !== null) ? (int)$g['värde'] : null;
    if ($varde !== null && !in_array($varde, [1, 2, 3], true)) {
        $varde = null;
    }

    // A game's stable id from games.js. Several titles exist in two boxes, so
    // the name alone cannot tell two entries apart.
    $id = isset($g['id']) && $g['id'] !== null ? trim((string)$g['id']) : '';
    if (!preg_match('/^[A-Za-z0-9_-]{1,32}$/', $id)) {
        $id = '';
    }

    // When this entry was last written, so a device holding an old copy cannot
    // undo a newer change. Entries predating this carry 0 and always lose.
    $t = isset($g['t']) ? (int)$g['t'] : 0;
    if ($t < 0) $t = 0;

    // Note: status is deliberately absent. Full/luft belongs to the box, not
    // the game, and lives in games.js — a stale copy here would overwrite it.
    return [
        't'     => $t,
        'id'    => $id === '' ? null : $id,
        'ean'   => $ean === '' ? null : $ean,
        'namn'  => cut($namn, 200),
        'låda'  => cut($lada, 20),
        'värde' => $varde,
    ];
}

// Every way the same game can be named. An entry saved before ids existed is
// keyed by its code; the same game saved later carries an id. Matching on a
// single key left both in the store, and the older one — normalised to
// värde:null — then wiped the grade the newer one held.
function entry_keys($g) {
    $keys = [];
    if (!empty($g['id']))  $keys[] = 'i:' . $g['id'];
    if (!empty($g['ean'])) $keys[] = 'e:' . $g['ean'];
    $keys[] = 'n:' . lower($g['namn']) . '|' . lower($g['låda']);
    return $keys;
}

// Fold an entry into the list, merging it with anything that shares an
// identifier. $only limits which fields are taken from $c; null means all.
function absorb(&$entries, &$index, $c, $only) {
    $hit = null;
    foreach (entry_keys($c) as $k) {
        if (isset($index[$k])) { $hit = $index[$k]; break; }
    }
    if ($hit === null) {
        $entries[] = $c;
        $hit = count($entries) - 1;
    } else {
        $existing = $entries[$hit];
        $newer = $c['t'] >= $existing['t'];
        foreach (['id', 'ean', 'namn', 'låda', 'värde'] as $f) {
            if ($only !== null && !in_array($f, $only, true)) continue;

            // An identifier the stored entry lacks is always worth adopting:
            // it is what lets a code-keyed and an id-keyed copy fold together.
            if (($f === 'id' || $f === 'ean') && $existing[$f] === null) {
                $entries[$hit][$f] = $c[$f];
                continue;
            }

            // Never let an unset value erase a grade unless it is genuinely
            // newer. A device on an old copy posts nulls it has no business
            // asserting, which is how 35 grades were lost.
            if ($f === 'värde' && $c[$f] === null && $existing[$f] !== null
                && $c['t'] <= $existing['t']) {
                continue;
            }

            if ($newer) $entries[$hit][$f] = $c[$f];
        }
        $entries[$hit]['t'] = max($existing['t'], $c['t']);
    }
    foreach (entry_keys($entries[$hit]) as $k) { $index[$k] = $hit; }
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
    // other: last write wins per field, not per whole file. Folding the
    // stored entries through the same path also repairs duplicates already
    // in codes.json.
    $entries = [];
    $index = [];
    foreach ($current as $g) {
        $c = clean($g);
        if ($c) absorb($entries, $index, $c, null);
    }
    // Overlay only the fields the client actually sent. Replacing the whole
    // entry would let a device running older code wipe a value it never knew
    // about — simply opening the app there would undo the grading.
    foreach ($incoming as $g) {
        $c = clean($g);
        if (!$c) continue;
        $provided = array_values(array_filter(
            ['id', 'ean', 'namn', 'låda', 'värde'],
            function ($f) use ($g) { return array_key_exists($f, $g); }
        ));
        absorb($entries, $index, $c, $provided);
    }
    $merged = $entries;

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
