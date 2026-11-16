<?php
// api/upload-file.php — applicant video upload to Cloudinary.
// Form field: "video" (mp4/mov/webm, 50 MB max). Folder: ebsmi-shortlist-applicant-videos.
// Answers: { status: 'success', url, hash } | { status: 'error', message }.
// Requires a verified applicant session (opening.php?token=... + OTP).

require_once __DIR__ . '/../src/Helpers/security.php';
secure_session(1800);
session_start();
send_security_headers(false);

header('Content-Type: application/json; charset=UTF-8');

$respond = function (array $data, int $code = 200): void {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
};

if (empty($_SESSION['otp_verified']) || empty($_SESSION['applicant_id'])) {
    $respond(['status' => 'error', 'message' => 'Your session has ended. Please open your invite link again.'], 403);
}
$applicantId = (int) $_SESSION['applicant_id'];

$postMax = trim((string) ini_get('post_max_size'));
$postMaxUnit = strtolower(substr($postMax, -1));
$postMaxMultiplier = ['k' => 1024, 'm' => 1048576, 'g' => 1073741824][$postMaxUnit] ?? 1;
$postMaxBytes = (int) $postMax * $postMaxMultiplier;
$contentLength = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
if ($postMaxBytes > 0 && $contentLength > $postMaxBytes) {
    $respond(['status' => 'error', 'message' => 'The upload is too large for the server to receive. Please use a video of 50 MB or smaller.'], 413);
}

// CSRF protection
$csrfIn = (string) ($_POST['csrf_token'] ?? '');
if (!csrf_verify($csrfIn)) {
    $respond(['status' => 'error', 'message' => 'Invalid CSRF token. Please refresh and try again.'], 403);
}

$file = isset($_FILES['video']) && is_array($_FILES['video']) ? $_FILES['video'] : null;
if (!$file) {
    $respond(['status' => 'error', 'message' => 'Choose a video file first.'], 422);
}
if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    $uploadError = (int) $file['error'];
    if ($uploadError === UPLOAD_ERR_INI_SIZE || $uploadError === UPLOAD_ERR_FORM_SIZE) {
        $respond(['status' => 'error', 'message' => 'The video must be 50 MB or smaller.'], 413);
    }
    if ($uploadError === UPLOAD_ERR_NO_FILE) {
        $respond(['status' => 'error', 'message' => 'Choose a video file first.'], 422);
    }
    error_log('upload-file PHP upload error: ' . $uploadError);
    $respond(['status' => 'error', 'message' => 'The video could not be received. Please try again.'], 400);
}

// Extension check
$ext = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
if (!in_array($ext, ['mp4', 'mov', 'webm'], true)) {
    $respond(['status' => 'error', 'message' => 'Upload an MP4, MOV or WebM video.'], 422);
}

// MIME type verification via magic bytes (finfo)
$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime = $finfo->file($file['tmp_name']);
$allowedMimes = ['video/mp4', 'video/quicktime', 'video/webm'];
if (!in_array($mime, $allowedMimes, true)) {
    $respond(['status' => 'error', 'message' => 'The file does not appear to be a valid video.'], 422);
}

if (($file['size'] ?? 0) > 50 * 1024 * 1024) {
    $respond(['status' => 'error', 'message' => 'The video must be 50 MB or smaller.'], 422);
}
if (($file['size'] ?? 0) === 0) {
    $respond(['status' => 'error', 'message' => 'This file is empty. Choose a different video.'], 422);
}

try {
    require_once __DIR__ . '/../src/Services/CloudinaryService.php';
    $cloud = new CloudinaryService();
    $uploaded = $cloud->uploadVideo($file['tmp_name'], $applicantId, 'ebsmi-shortlist-applicant-videos');
    $url = is_array($uploaded) ? (string) ($uploaded['url'] ?? '') : '';
    $hash = is_array($uploaded) ? (string) ($uploaded['hash'] ?? '') : '';
    if ($url === '') throw new Exception('Cloudinary returned an empty URL.');
    $respond(['status' => 'success', 'url' => $url, 'hash' => $hash]);
} catch (Throwable $e) {
    error_log('upload-file Cloudinary error: ' . $e->getMessage());
    $respond(['status' => 'error', 'message' => 'The upload did not finish. Please try again.'], 502);
}


