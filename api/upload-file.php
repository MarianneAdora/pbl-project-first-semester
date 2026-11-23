<?php
// api/upload-file.php — applicant video upload to Cloudinary.
// Form field: "video" (MP4/MOV/WebM chunks, 50 MB total). Folder: ebsmi-shortlist-applicant-videos.
// Answers: { status: 'success', url, hash } | { status: 'error', message }.
// Requires a verified applicant session (opening.php?token=... + OTP).

require_once __DIR__ . '/../src/Helpers/security.php';
start_secure_session(1800);
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
if (empty($_SESSION['screening_privacy_accepted_at'])) {
    $respond(['status' => 'error', 'message' => 'Please review and accept the privacy statement before continuing screening.'], 403);
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

$chunkSize = 900 * 1024;
$maxVideoSize = 50 * 1024 * 1024;
$chunkedRequest = isset($_POST['upload_id']) || isset($_POST['chunk_index'])
    || isset($_POST['chunk_count']) || isset($_POST['file_name'])
    || (string) ($_POST['action'] ?? '') === 'cancel';
$cleanupChunkUpload = static function (): void {};
$assembledPath = '';

if ($chunkedRequest) {
    $uploadId = (string) ($_POST['upload_id'] ?? '');
    if (!preg_match('/^[a-f0-9]{32}$/', $uploadId)) {
        $respond(['status' => 'error', 'message' => 'Invalid upload. Please try again.'], 422);
    }

    $uploadDir = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'applicant-video-chunks';
    if (!is_dir($uploadDir) && !@mkdir($uploadDir, 0700, true) && !is_dir($uploadDir)) {
        error_log('upload-file could not create the temporary video directory.');
        $respond(['status' => 'error', 'message' => 'The upload could not be prepared. Please try again.'], 500);
    }

    $ownerPrefix = hash('sha256', session_id() . ':' . $applicantId);
    $uploadPrefix = $ownerPrefix . '-' . $uploadId;
    $assembledPath = $uploadDir . DIRECTORY_SEPARATOR . $uploadPrefix . '.video';
    $cleanupChunkUpload = static function () use ($uploadDir, $uploadPrefix, $assembledPath): void {
        foreach (glob($uploadDir . DIRECTORY_SEPARATOR . $uploadPrefix . '-*') ?: [] as $path) {
            if (is_file($path)) @unlink($path);
        }
        if (is_file($assembledPath)) @unlink($assembledPath);
    };

    foreach (glob($uploadDir . DIRECTORY_SEPARATOR . $ownerPrefix . '-*') ?: [] as $stalePath) {
        if (is_file($stalePath) && @filemtime($stalePath) < time() - 3600) @unlink($stalePath);
    }

    if ((string) ($_POST['action'] ?? '') === 'cancel') {
        $cleanupChunkUpload();
        $respond(['status' => 'cancelled']);
    }

    $chunkIndex = filter_var($_POST['chunk_index'] ?? null, FILTER_VALIDATE_INT);
    $chunkCount = filter_var($_POST['chunk_count'] ?? null, FILTER_VALIDATE_INT);
    $originalName = basename(str_replace('\\', '/', (string) ($_POST['file_name'] ?? '')));
    $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    if ($chunkIndex === false || $chunkCount === false || $chunkCount < 1
        || $chunkCount > 57 || $chunkIndex < 0 || $chunkIndex >= $chunkCount
        || !in_array($extension, ['mp4', 'mov', 'webm'], true)) {
        $cleanupChunkUpload();
        $respond(['status' => 'error', 'message' => 'Invalid upload details. Please try again.'], 422);
    }

    $chunkFile = isset($_FILES['video']) && is_array($_FILES['video']) ? $_FILES['video'] : null;
    if (!$chunkFile || ($chunkFile['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        $uploadError = (int) ($chunkFile['error'] ?? UPLOAD_ERR_NO_FILE);
        $cleanupChunkUpload();
        $message = $uploadError === UPLOAD_ERR_INI_SIZE || $uploadError === UPLOAD_ERR_FORM_SIZE
            ? 'The upload chunk was too large. Please try again.'
            : 'The video chunk could not be received. Please try again.';
        $respond(['status' => 'error', 'message' => $message], $uploadError === UPLOAD_ERR_INI_SIZE ? 413 : 400);
    }

    $chunkBytes = (int) ($chunkFile['size'] ?? 0);
    if ($chunkBytes < 1 || $chunkBytes > $chunkSize
        || ($chunkIndex < $chunkCount - 1 && $chunkBytes !== $chunkSize)) {
        $cleanupChunkUpload();
        $respond(['status' => 'error', 'message' => 'The video chunk was incomplete. Please try again.'], 422);
    }

    $partPath = $uploadDir . DIRECTORY_SEPARATOR . $uploadPrefix . '-' . $chunkIndex . '.part';
    if (!move_uploaded_file((string) $chunkFile['tmp_name'], $partPath)) {
        $cleanupChunkUpload();
        error_log('upload-file could not store an incoming video chunk.');
        $respond(['status' => 'error', 'message' => 'The video could not be saved. Please try again.'], 500);
    }

    if ($chunkIndex < $chunkCount - 1) {
        $respond(['status' => 'chunk_received', 'received' => $chunkIndex + 1, 'total' => $chunkCount]);
    }

    $assembled = @fopen($assembledPath, 'wb');
    if ($assembled === false) {
        $cleanupChunkUpload();
        $respond(['status' => 'error', 'message' => 'The video could not be prepared. Please try again.'], 500);
    }
    $totalBytes = 0;
    $assemblyFailed = false;
    for ($index = 0; $index < $chunkCount; $index++) {
        $path = $uploadDir . DIRECTORY_SEPARATOR . $uploadPrefix . '-' . $index . '.part';
        if (!is_file($path)) {
            $assemblyFailed = true;
            break;
        }
        $partBytes = (int) filesize($path);
        $totalBytes += $partBytes;
        $part = @fopen($path, 'rb');
        if ($part === false || $totalBytes > $maxVideoSize || stream_copy_to_stream($part, $assembled) === false) {
            if (is_resource($part)) fclose($part);
            $assemblyFailed = true;
            break;
        }
        fclose($part);
    }
    fclose($assembled);
    if ($assemblyFailed || $totalBytes < 1 || $totalBytes > $maxVideoSize) {
        $cleanupChunkUpload();
        $respond(['status' => 'error', 'message' => 'The video could not be assembled. Please try again.'], 422);
    }
    foreach (glob($uploadDir . DIRECTORY_SEPARATOR . $uploadPrefix . '-*.part') ?: [] as $path) {
        if (is_file($path)) @unlink($path);
    }

    $file = [
        'name' => $originalName,
        'tmp_name' => $assembledPath,
        'size' => $totalBytes,
        'error' => UPLOAD_ERR_OK,
    ];
} else {
    $file = isset($_FILES['video']) && is_array($_FILES['video']) ? $_FILES['video'] : null;
}

if (!$file) {
    $cleanupChunkUpload();
    $respond(['status' => 'error', 'message' => 'Choose a video file first.'], 422);
}
if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    $uploadError = (int) $file['error'];
    if ($uploadError === UPLOAD_ERR_INI_SIZE || $uploadError === UPLOAD_ERR_FORM_SIZE) {
        $cleanupChunkUpload();
        $respond(['status' => 'error', 'message' => 'The video must be 50 MB or smaller.'], 413);
    }
    if ($uploadError === UPLOAD_ERR_NO_FILE) {
        $cleanupChunkUpload();
        $respond(['status' => 'error', 'message' => 'Choose a video file first.'], 422);
    }
    error_log('upload-file PHP upload error: ' . $uploadError);
    $cleanupChunkUpload();
    $respond(['status' => 'error', 'message' => 'The video could not be received. Please try again.'], 400);
}

// Extension check
$ext = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
if (!in_array($ext, ['mp4', 'mov', 'webm'], true)) {
    $cleanupChunkUpload();
    $respond(['status' => 'error', 'message' => 'Upload an MP4, MOV or WebM video.'], 422);
}

// MIME type verification via magic bytes (finfo)
$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime = $finfo->file($file['tmp_name']);
$allowedMimes = ['video/mp4', 'video/quicktime', 'video/webm'];
if (!in_array($mime, $allowedMimes, true)) {
    $cleanupChunkUpload();
    $respond(['status' => 'error', 'message' => 'The file does not appear to be a valid video.'], 422);
}

if (($file['size'] ?? 0) > 50 * 1024 * 1024) {
    $cleanupChunkUpload();
    $respond(['status' => 'error', 'message' => 'The video must be 50 MB or smaller.'], 422);
}
if (($file['size'] ?? 0) === 0) {
    $cleanupChunkUpload();
    $respond(['status' => 'error', 'message' => 'This file is empty. Choose a different video.'], 422);
}

try {
    require_once __DIR__ . '/../src/Services/CloudinaryService.php';
    $cloud = new CloudinaryService();
    $uploaded = $cloud->uploadVideo($file['tmp_name'], $applicantId, 'ebsmi-shortlist-applicant-videos');
    $url = is_array($uploaded) ? (string) ($uploaded['url'] ?? '') : '';
    $hash = is_array($uploaded) ? (string) ($uploaded['hash'] ?? '') : '';
    if ($url === '') throw new Exception('Cloudinary returned an empty URL.');
    $cleanupChunkUpload();
    $respond(['status' => 'success', 'url' => $url, 'hash' => $hash]);
} catch (Throwable $e) {
    $cleanupChunkUpload();
    error_log('upload-file Cloudinary error: ' . $e->getMessage());
    $respond(['status' => 'error', 'message' => 'The upload did not finish. Please try again.'], 502);
}


