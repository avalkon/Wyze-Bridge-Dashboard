<?php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';
require_login();

$root = realpath((string)cfg()['recordings_dir']);
$rel = rawurldecode((string)($_GET['f'] ?? ''));

if ($root === false || $rel === '' || str_contains($rel, "\0")) {
    http_response_code(404); exit;
}

$file = realpath($root . DIRECTORY_SEPARATOR . $rel);
if ($file === false || !is_file($file) || !str_starts_with($file, $root . DIRECTORY_SEPARATOR)) {
    http_response_code(404); exit;
}
if (strtolower(pathinfo($file, PATHINFO_EXTENSION)) !== 'mp4') {
    http_response_code(403); exit;
}

$size = filesize($file);
$start = 0;
$end = $size - 1;

header('Content-Type: video/mp4');
header('Accept-Ranges: bytes');
header('Cache-Control: private, max-age=3600');

if (isset($_SERVER['HTTP_RANGE']) &&
    preg_match('/bytes=(\d*)-(\d*)/', $_SERVER['HTTP_RANGE'], $m)) {
    if ($m[1] !== '') $start = (int)$m[1];
    if ($m[2] !== '') $end = min((int)$m[2], $size - 1);
    if ($start > $end || $start >= $size) {
        header("Content-Range: bytes */{$size}");
        http_response_code(416); exit;
    }
    http_response_code(206);
    header("Content-Range: bytes {$start}-{$end}/{$size}");
}

$length = $end - $start + 1;
header("Content-Length: {$length}");

$fp = fopen($file, 'rb');
fseek($fp, $start);
$remaining = $length;
while ($remaining > 0 && !feof($fp)) {
    $chunk = fread($fp, min(1024 * 1024, $remaining));
    if ($chunk === false) break;
    echo $chunk;
    flush();
    $remaining -= strlen($chunk);
}
fclose($fp);
