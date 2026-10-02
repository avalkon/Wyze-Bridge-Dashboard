<?php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';
require_login();

$root = realpath((string)cfg()['recordings_dir']);
$rel = rawurldecode((string)($_GET['f'] ?? ''));
if ($root === false || $rel === '' || str_contains($rel, "\0")) { http_response_code(404); exit; }

$mp4 = realpath($root . DIRECTORY_SEPARATOR . $rel);
if ($mp4 === false || !is_file($mp4) || !str_starts_with($mp4, $root . DIRECTORY_SEPARATOR)) {
    http_response_code(404); exit;
}
$jpg = preg_replace('/\.mp4$/i', '.jpg', $mp4);
if (!$jpg || !is_file($jpg)) {
    // Tiny inline SVG placeholder
    header('Content-Type: image/svg+xml');
    echo '<svg xmlns="http://www.w3.org/2000/svg" width="640" height="360"><rect width="100%" height="100%" fill="#111820"/><text x="50%" y="50%" dominant-baseline="middle" text-anchor="middle" fill="#7f8da0" font-family="sans-serif" font-size="24">No thumbnail yet</text></svg>';
    exit;
}
header('Content-Type: image/jpeg');
header('Cache-Control: private, max-age=3600');
readfile($jpg);
