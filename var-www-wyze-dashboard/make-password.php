<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(404); exit;
}
$password = $argv[1] ?? '';
if (strlen($password) < 12) {
    fwrite(STDERR, "Usage: php make-password.php 'a-password-at-least-12-chars'\n");
    exit(1);
}
echo password_hash($password, PASSWORD_DEFAULT), PHP_EOL;
