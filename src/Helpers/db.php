<?php
// src/Helpers/db.php — shared PDO connection (PostgreSQL).
//
// Database credentials are read from the .env file at the project root.
// To change them (host, port, database name, user, password), edit .env:
//   DB_HOST, DB_PORT, DB_NAME, DB_USER, DB_PASS
// A template is provided in .env.example.

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
