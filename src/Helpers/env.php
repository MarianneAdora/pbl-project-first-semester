<?php
function loadEnv($path) {
    if (!file_exists($path)) {
        return;
    }
    
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        // Skip comments and blank lines
        $trimmed = trim($line);
        if ($trimmed === '' || strpos($trimmed, '#') === 0) {
            continue;
        }
        
        // Skip lines without an equals sign
        if (strpos($trimmed, '=') === false) {
            continue;
        }
        
        // Split by the first equals sign
        list($name, $value) = explode('=', $trimmed, 2);
        $name = trim($name);
        $value = trim($value);
        
        // Set the environment variable
        if (!array_key_exists($name, $_SERVER) && !array_key_exists($name, $_ENV)) {
            putenv(sprintf('%s=%s', $name, $value));
            $_ENV[$name] = $value;
            $_SERVER[$name] = $value;
        }
    }
}

// Load the .env file from the root directory
loadEnv(__DIR__ . '/../../.env');