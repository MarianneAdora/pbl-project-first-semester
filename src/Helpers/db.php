<?php
// src/Helpers/db.php — shared PDO connection (PostgreSQL, reads credentials from .env when present).

require_once __DIR__ . '/env.php';

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;

    $host = getenv('DB_HOST') ?: 'localhost';
    $port = getenv('DB_PORT') ?: '5432';
    $name = getenv('DB_NAME') ?: 'ebsmi_screening';
    $user = getenv('DB_USER') ?: 'postgres';
    $pass = getenv('DB_PASS');
    if ($pass === false) $pass = '';

    $pdo = new PDO("pgsql:host={$host};port={$port};dbname={$name}", $user, (string) $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    return $pdo;
}
