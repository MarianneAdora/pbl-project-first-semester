<?php
// hr/cv-proxy.php — serves a CV from Cloudinary to signed-in HR/Admin only.
require_once __DIR__ . '/../src/Helpers/security.php';
start_secure_session(1800);

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

$context = stream_context_create([
    'http' => [
        'method' => 'GET',
        'timeout' => 60,
    ],
    'ssl' => [
        'verify_peer' => false,
        'verify_peer_name' => false,
    ],
]);

$bytes = @file_get_contents($cvUrl, false, $context);
if ($bytes === false) {
    http_response_code(502);
    exit('Could not retrieve the CV from storage.');
}

$httpCode = 200;
foreach ($http_response_header ?? [] as $header) {
    if (stripos($header, 'HTTP/') === 0) {
        preg_match('/HTTP\/[\d.]+ (\d+)/', $header, $m);
        if (!empty($m[1])) $httpCode = (int) $m[1];
        break;
    }
}

if ($httpCode < 200 || $httpCode >= 300 || $bytes === '') {
    http_response_code(502);
    exit('Could not retrieve the CV from storage.');
}

$contentType = '';
foreach ($http_response_header ?? [] as $header) {
    if (stripos($header, 'Content-Type:') === 0) {
        $contentType = trim(substr($header, strpos($header, ':') + 1));
        break;
    }
}

if ($bytes === false || $httpCode < 200 || $httpCode >= 300 || $bytes === '') {
    http_response_code(502);
    exit('Could not retrieve the CV from storage.');
}

if ($contentType === '' || stripos($contentType, 'text/html') !== false) {
    $ext = strtolower(pathinfo(parse_url($cvUrl, PHP_URL_PATH) ?: '', PATHINFO_EXTENSION));
    $map = [
        'pdf'  => 'application/pdf',
        'png'  => 'image/png',
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif'  => 'image/gif',
        'webp' => 'image/webp',
        'doc'  => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    ];
    $contentType = $map[$ext] ?? 'application/octet-stream';
}

$filename = basename(parse_url($cvUrl, PHP_URL_PATH) ?: 'cv');
$filename = preg_replace('/[^A-Za-z0-9._-]+/', '_', $filename) ?: 'cv';

header('Content-Type: ' . $contentType);
header('Content-Disposition: inline; filename="' . str_replace(['"', "\r", "\n"], '', $filename) . '"');
header('X-Content-Type-Options: nosniff');
header('Content-Length: ' . strlen($bytes));
header('Cache-Control: private, no-store, no-cache, must-revalidate');

echo $bytes;
exit;
