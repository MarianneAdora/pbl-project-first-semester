<?php
// src/Helpers/sentry.php
require_once __DIR__ . '/../../vendor/autoload.php';

$env = parse_ini_file(__DIR__ . '/../../.env');
$dsn = $env['SENTRY_DSN'] ?? '';

if (!empty($dsn)) {
    \Sentry\init([
        'dsn' => $dsn,
        'environment' => 'development',
    ]);
}