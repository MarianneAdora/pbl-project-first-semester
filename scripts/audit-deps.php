#!/usr/bin/env php
<?php
// scripts/audit-deps.php — scan composer dependencies for known vulnerabilities.
// Run: php scripts/audit-deps.php
// Requires composer to be installed and composer.lock present.

require_once __DIR__ . '/../vendor/autoload.php';

$lockFile = __DIR__ . '/../composer.lock';
if (!file_exists($lockFile)) {
    fwrite(STDERR, "composer.lock not found. Run `composer install` first.\n");
    exit(1);
}

$lock = json_decode(file_get_contents($lockFile), true);
$packages = $lock['packages'] ?? [];

echo "=== Dependency Vulnerability Audit ===\n";
echo "Scanning " . count($packages) . " package(s) from composer.lock\n\n";

// Known-vulnerable packages (advisory database subset for PHP packages)
// This is a static list; for production use `composer audit` instead.
$vulnerable = [
    'smalot/pdfparser' => [
        'fixed' => 'v2.1.3',
        'issue' => 'Potential arbitrary file read via crafted PDF',
        'severity' => 'medium',
    ],
];

$found = 0;
foreach ($packages as $pkg) {
    $name = $pkg['name'] ?? '';
    $version = $pkg['version'] ?? 'unknown';
    if (isset($vulnerable[$name])) {
        $v = $vulnerable[$name];
        $fixed = version_compare($version, $v['fixed'], '>=');
        echo "  [!] {$name} {$version}";
        if (!$fixed) {
            echo " — VULNERABLE: {$v['issue']} (fixed in {$v['fixed']})";
            $found++;
        } else {
            echo " — patched";
        }
        echo "\n";
    }
}

if ($found > 0) {
    echo "\n{$found} vulnerable package(s) found. Update with: composer update\n";
    exit(1);
}

echo "\nNo known vulnerabilities found in scanned packages.\n";
echo "For comprehensive scanning, run: composer audit\n";
