<?php
// hr/archive-action.php — shared POST endpoint for Screening List Delete / Archive Restore.
// Body: JSON { action: 'archive' | 'restore', ids: [int, ...] }
// Archive = soft-delete (deleted_at = NOW()); Restore clears it. No purge yet.

session_start();
header('Content-Type: application/json');

$respond = function (array $data, int $code = 200): void {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
};

if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'hr') {
    $respond(['status' => 'error', 'message' => 'Your session has ended. Please log in again.'], 403);
}

require_once __DIR__ . '/../src/Repositories/ApplicantRepository.php';

$json = json_decode((string) file_get_contents('php://input'), true);
$input = is_array($json) ? $json : $_POST;
$action = (string) ($input['action'] ?? '');
$ids = array_map('intval', (array) ($input['ids'] ?? []));

try {
    if ($action === 'archive') {
        $n = repo_archive_applicants($ids);
    } elseif ($action === 'restore') {
        $n = repo_restore_applicants($ids);
    } else {
        $respond(['status' => 'error', 'message' => 'Unknown action.'], 400);
    }

    $archived = repo_applicants_archived();
    $sync = ['ids' => [], 'at' => []];
    foreach ($archived as $a) {
        $sync['ids'][] = (int) $a['id'];
        if (!empty($a['archived_at'])) $sync['at'][(int) $a['id']] = (int) (strtotime((string) $a['archived_at']) * 1000);
    }

    $respond([
        'status' => 'success',
        'count' => $n,
        'stats' => repo_dashboard_stats(),
        'completed_ids' => [],
        'archived' => count($archived),
        'archive' => $sync,
    ]);
} catch (Throwable $e) {
    error_log('archive-action DB error: ' . $e->getMessage());
    $respond(['status' => 'error', 'message' => 'Something went wrong while saving. Please try again.'], 500);
}
