<?php
// src/Helpers/db.php — shared PDO connection (PostgreSQL).
//
// Database credentials are read from the .env file at the project root.
// To change them (host, port, database name, user, password), edit .env:
//   DB_HOST, DB_PORT, DB_NAME, DB_USER, DB_PASS
// A template is provided in .env.example.

// Surface real errors instead of a blank page (Azure / production otherwise hides them).
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

// Show a clear error page instead of a blank page when the database is
// unreachable (e.g. Azure deployment without an Azure Database for PostgreSQL).
set_exception_handler(function ($e) {
    http_response_code(500);
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><title>Application error</title><style>body{font-family:sans-serif;padding:2rem;line-height:1.5}code{background:#f3f4f6;padding:0.2rem 0.4rem;border-radius:4px}</style></head><body><h1>Application error</h1><p>' . htmlspecialchars((string) $e->getMessage()) . '</p></body></html>';
    exit;
});
register_shutdown_function(function () {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        http_response_code(500);
        echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><title>Fatal error</title></head><body><h1>Fatal error</h1><p>' . htmlspecialchars($err['message']) . '</p><p>' . htmlspecialchars($err['file']) . ':' . $err['line'] . '</p></body></html>';
    }
});

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
    if ($pass === false) {
        // No .env value found; fall back to empty (rely on Postgres trust auth).
        $pass = '';
    }

    try {
        $pdo = new PDO("pgsql:host={$host};port={$port};dbname={$name}", $user, (string) $pass);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        return $pdo;
    } catch (PDOException $e) {
        throw new RuntimeException(
            "Database connection failed: " . $e->getMessage() .
            " Check DB_* values in your .env file.", $e->getCode(), $e
        );
    }
}
