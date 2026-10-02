<?php
declare(strict_types=1);

const DASHBOARD_CONFIG = '/etc/wyze-dashboard/dashboard.ini';

function cfg(): array {
    static $config = null;
    if ($config !== null) return $config;

    $config = parse_ini_file(DASHBOARD_CONFIG, false, INI_SCANNER_RAW);
    if ($config === false) {
        http_response_code(500);
        exit('Dashboard configuration could not be loaded.');
    }

    date_default_timezone_set($config['timezone'] ?? 'UTC');
    return $config;
}

function h(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
