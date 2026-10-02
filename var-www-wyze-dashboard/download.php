<?php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';
require_login();

$root = realpath((string)cfg()['recordings_dir']);
$rel = rawurldecode((string)($_GET['f'] ?? ''));
if ($root === false || $rel === '' || str_contains($rel, "\0")) { http_response_code(404); exit; }

$file = realpath($root . DIRECTORY_SEPARATOR . $rel);
if ($file === false || !is_file($file) || !str_starts_with($file, $root . DIRECTORY_SEPARATOR) ||
    strtolower(pathinfo($file, PATHINFO_EXTENSION)) !== 'mp4') {
    http_response_code(404); exit;
}
header('Content-Type: video/mp4');
header('Content-Disposition: attachment; filename="' . basename($file) . '"');
header('Content-Length: ' . filesize($file));
readfile($file);
