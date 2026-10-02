<?php
declare(strict_types=1);
require_once __DIR__ . '/lib.php';
require_admin();
require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405); exit;
}
verify_csrf();

$action = (string)($_POST['action'] ?? '');
$file = (string)($_POST['file'] ?? '');
$return = (string)($_POST['return'] ?? 'index.php');
if (!preg_match('/^[A-Za-z0-9_.?=&%\/-]+$/', $return)) $return = 'index.php';

$map = ['protect'=>'/protect','unprotect'=>'/unprotect','delete'=>'/delete'];
if (!isset($map[$action])) {
    http_response_code(400); exit('Unknown action');
}

try {
    helper_request('POST', $map[$action], ['file'=>$file]);
    header('Location: ' . $return);
    exit;
} catch (Throwable $e) {
    http_response_code(500);
    echo h($e->getMessage());
}
