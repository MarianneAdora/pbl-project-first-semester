<?php
// api/admin-action.php — DB-backed AJAX endpoint for admin archive/restore/purge actions.
// Replaces static-admin.php for: account_archive, account_restore, account_purge,
//                                 question_archive, question_restore, question_purge, notify

session_start();
header('Content-Type: application/json; charset=UTF-8');
date_default_timezone_set('Asia/Manila');

require_once __DIR__ . '/../src/Repositories/AdminRepository.php';

function admin_respond(array $data, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

if (($_SESSION['user_role'] ?? '') !== 'admin') {
    admin_respond(['status' => 'error', 'message' => 'Your session has ended. Please log in again.'], 403);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    admin_respond(['status' => 'error', 'message' => 'Invalid request.'], 405);
}

$json   = json_decode((string) file_get_contents('php://input'), true);
$input  = is_array($json) ? $json : $_POST;
$action = (string) ($input['action'] ?? '');

try {
    $missing = 'That item was not found. It may have been removed already.';

    switch ($action) {
        case 'account_archive':
            if (!repo_account_archive((int) ($input['id'] ?? 0))) admin_respond(['status' => 'error', 'message' => $missing], 404);
            $message = 'HR account moved to the Archive.';
            break;
        case 'account_restore':
            if (!repo_account_restore((int) ($input['id'] ?? 0))) admin_respond(['status' => 'error', 'message' => $missing], 404);
            $message = 'HR account restored.';
            break;
        case 'account_purge':
            if (!repo_account_purge((int) ($input['id'] ?? 0))) admin_respond(['status' => 'error', 'message' => $missing], 404);
            $message = 'HR account deleted forever.';
            break;

        case 'question_archive':
            $id = (int) ltrim((string) ($input['id'] ?? ''), 'q');
            if (!repo_question_archive($id)) admin_respond(['status' => 'error', 'message' => $missing], 404);
            $message = 'Question moved to the Archive.';
            break;
        case 'question_restore':
            $id = (int) ltrim((string) ($input['id'] ?? ''), 'q');
            if (!repo_question_restore($id)) admin_respond(['status' => 'error', 'message' => $missing], 404);
            $message = 'Question restored.';
            break;
        case 'question_purge':
            $id = (int) ltrim((string) ($input['id'] ?? ''), 'q');
            if (!repo_question_purge($id)) admin_respond(['status' => 'error', 'message' => $missing], 404);
            $message = 'Question deleted forever.';
            break;

        case 'notify':
            admin_respond(['status' => 'success', 'message' => '', 'result' => null, 'data' => ['notify' => repo_admin_notify()]]);
            break;

        default:
            admin_respond(['status' => 'error', 'message' => 'Unknown action.'], 400);
    }

    admin_respond([
        'status'  => 'success',
        'message' => $message,
        'result'  => null,
        'data'    => [
            'accounts'  => repo_admin_accounts(),
            'questions' => repo_admin_questions_list(),
            'archive'   => repo_admin_archive(),
        ],
    ]);

} catch (InvalidArgumentException $e) {
    admin_respond(['status' => 'error', 'message' => $e->getMessage()], 422);
} catch (Throwable $e) {
    error_log('admin-action.php error: ' . $e->getMessage());
    admin_respond(['status' => 'error', 'message' => 'Something went wrong. Please try again.'], 500);
}
