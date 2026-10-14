<?php
// Turn on all errors so we can see them
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

echo "Step 1: Loading autoloader and sentry.php...<br>";
require_once 'src/Helpers/sentry.php';

echo "Step 2: Checking DSN...<br>";
$env = parse_ini_file(__DIR__ . '/.env');
$dsn = $env['SENTRY_DSN'] ?? '';

if (empty($dsn)) {
    die("ERROR: SENTRY_DSN is empty! Check your .env file.");
} else {
    echo "DSN Found: " . substr($dsn, 0, 20) . "...<br>"; // Print part of it for safety
}

echo "Step 3: Sending test message...<br>";
\Sentry\captureMessage("Sentry test from EBSMI system - SUCCESS!");

echo "Step 4: Sending test exception...<br>";
try {
    throw new Exception("This is a test error for Sentry integration.");
} catch (\Throwable $exception) {
    \Sentry\captureException($exception);
}

echo "Step 5: Done! If you see this, the code ran perfectly. Check your Sentry dashboard now.";