<?php
// hr/cv-proxy.php — serves a CV from Cloudinary.
// On first view, sets anonymous access on the asset via the Admin API (fixes the 401).
// After that the Cloudinary URL is permanently public and the redirect goes straight through.
session_start();

if (!in_array($_SESSION['user_role'] ?? '', ['hr', 'admin'], true)) {
    http_response_code(403); exit('Access denied.');
}

$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0) { http_response_code(400); exit('Missing id.'); }

require_once __DIR__ . '/../src/Helpers/db.php';
try {
    $st = db()->prepare("SELECT cv_file_url FROM applicant WHERE applicant_id = :id AND deleted_at IS NULL LIMIT 1");
    $st->execute([':id' => $id]);
    $row = $st->fetch();
} catch (Throwable $e) {
    http_response_code(500); exit('Database error.');
}

$cvUrl = trim((string) ($row['cv_file_url'] ?? ''));
if ($cvUrl === '') { http_response_code(404); exit('No CV on record for this applicant.'); }

$env       = parse_ini_file(__DIR__ . '/../.env');
$cloudName = $env['CLOUDINARY_CLOUD_NAME'] ?? '';
$apiKey    = $env['CLOUDINARY_API_KEY'] ?? '';
$apiSecret = $env['CLOUDINARY_API_SECRET'] ?? '';

// Extract public_id from the stored URL
$urlPath = parse_url($cvUrl, PHP_URL_PATH) ?? '';
$pattern = '#^/' . preg_quote($cloudName, '#') . '/[^/]+/upload/(?:v\d+/)?(.+)$#';
if (!preg_match($pattern, $urlPath, $m)) {
    header('Location: ' . $cvUrl); exit;
}
$publicId = $m[1];

// Call the Cloudinary explicit API to set anonymous access on this asset.
// This is a no-op if access is already anonymous, so it's safe to call every time.
$timestamp = time();
$toSign    = ['access_control' => '[{"access_type":"anonymous"}]', 'public_id' => $publicId, 'timestamp' => $timestamp, 'type' => 'upload'];
ksort($toSign);
$parts = [];
foreach ($toSign as $k => $v) $parts[] = $k . '=' . $v;
$signature = sha1(implode('&', $parts) . $apiSecret);

$ch = curl_init('https://api.cloudinary.com/v1_1/' . $cloudName . '/raw/explicit');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, [
    'public_id'      => $publicId,
    'type'           => 'upload',
    'access_control' => '[{"access_type":"anonymous"}]',
    'api_key'        => $apiKey,
    'timestamp'      => $timestamp,
    'signature'      => $signature,
]);
curl_exec($ch);
curl_close($ch);

header('Location: ' . $cvUrl);
exit;
