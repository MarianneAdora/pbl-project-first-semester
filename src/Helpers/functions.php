<?php
// src/Helpers/functions.php

function getDbConnection() {
    $host = 'localhost';
    $port = '5432'; // Default PostgreSQL port
    $db   = 'ebsmi_screening';
    $user = 'postgres'; // Default PostgreSQL user
    $pass = ''; // CHANGE THIS to your actual PostgreSQL password

    try {
        $pdo = new PDO("pgsql:host=$host;port=$port;dbname=$db", $user, $pass);
        // Throw exceptions on errors
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        // Fetch associative arrays by default
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        return $pdo;
    } catch (PDOException $e) {
        die("Database connection failed: " . $e->getMessage());
    }
}