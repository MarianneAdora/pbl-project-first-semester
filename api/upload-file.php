<?php
// api/upload-file.php — applicant video upload to Cloudinary.
// Form field: "video" (mp4/mov/webm, 50 MB max). Folder: ebsmi-shortlist-applicant-videos.
// Answers: { status: 'success', url, hash } | { status: 'error', message }.
// Requires a verified applicant session (opening.php?token=... + OTP).

session_start();
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

$file = isset($_FILES['video']) && is_array($_FILES['video']) ? $_FILES['video'] : null;
if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    $respond(['status' => 'error', 'message' => 'Choose a video file first.'], 422);
}

$ext = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
if (!in_array($ext, ['mp4', 'mov', 'webm'], true)) {
    $respond(['status' => 'error', 'message' => 'Upload an MP4, MOV or WebM video.'], 422);
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


