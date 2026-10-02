<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
if (session_status() !== PHP_SESSION_ACTIVE) {
    ini_set('session.cookie_httponly','1'); ini_set('session.cookie_samesite','Strict');
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ini_set('session.cookie_secure','1');
    session_start();
}
function load_users(): array {
    $file=(string)(cfg()['users_file'] ?? '/etc/wyze-dashboard/users.json');
    $data=json_decode((string)@file_get_contents($file),true);
    return (is_array($data) && isset($data['users']) && is_array($data['users'])) ? $data['users'] : [];
}
function current_user(): ?array { return empty($_SESSION['logged_in']) ? null : ['username'=>(string)($_SESSION['username']??''),'role'=>(string)($_SESSION['role']??'viewer')]; }
function is_admin(): bool { return !empty($_SESSION['logged_in']) && (($_SESSION['role']??'')==='admin'); }
function require_login(): void { if (empty($_SESSION['logged_in'])) { $next=urlencode($_SERVER['REQUEST_URI']??'/'); header("Location: login.php?next={$next}"); exit; } }
function require_admin(): void { require_login(); if (!is_admin()) { http_response_code(403); exit('Administrator access required.'); } }
function csrf_token(): string { if (empty($_SESSION['csrf'])) $_SESSION['csrf']=bin2hex(random_bytes(32)); return $_SESSION['csrf']; }
function verify_csrf(): void { $token=$_POST['csrf']??''; if (!is_string($token)||!hash_equals(csrf_token(),$token)) { http_response_code(403); exit('Invalid CSRF token.'); } }
