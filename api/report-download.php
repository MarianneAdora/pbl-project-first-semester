<?php
require_once __DIR__ . '/../src/Helpers/security.php';
start_secure_session(1800);
send_security_headers(false);

header('Content-Type: application/json; charset=UTF-8');
date_default_timezone_set('Asia/Manila');

require_once __DIR__ . '/../src/Repositories/ActivityRepository.php';

function report_download_respond(array $data, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    report_download_respond(['status' => 'error', 'message' => 'Invalid request.'], 405);
}

$json = json_decode((string) file_get_contents('php://input'), true);
$input = is_array($json) ? $json : $_POST;
$csrf = (string) ($input['csrf_token'] ?? '');
if (!csrf_verify($csrf)) {
    report_download_respond(['status' => 'error', 'message' => 'Invalid CSRF token. Please refresh and try again.'], 403);
}

$role = (string) ($_SESSION['user_role'] ?? '');
if (!in_array($role, ['hr', 'admin'], true) || (string) ($input['report'] ?? '') !== $role) {
    report_download_respond(['status' => 'error', 'message' => 'Your session has ended. Please log in again.'], 403);
}

try {
    $roleLabel = $role === 'hr' ? 'HR' : 'Admin';
    $activity = repo_log_activity('Dashboard Report Downloaded', $roleLabel . ' dashboard statistics PDF');
    if ($activity === null) {
        report_download_respond(['status' => 'error', 'message' => 'The report downloaded, but the activity could not be recorded.'], 500);
    }

    report_download_respond(['status' => 'success', 'activity' => $activity]);
} catch (Throwable $e) {
    error_log('report-download.php error: ' . $e->getMessage());
    report_download_respond(['status' => 'error', 'message' => 'The report downloaded, but the activity could not be recorded.'], 500);
}
