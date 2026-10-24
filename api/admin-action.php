<?php
// api/admin-action.php — DB-backed AJAX endpoint for admin archive/restore/purge actions.
// Replaces static-admin.php for: account_archive, account_restore, account_purge,
//                                 question_archive, question_restore, question_purge, notify

session_start();
header('Content-Type: application/json; charset=UTF-8');
date_default_timezone_set('Asia/Manila');

require_once __DIR__ . '/../src/Repositories/AdminRepository.php';
require_once __DIR__ . '/../src/Services/EmailService.php';
require_once __DIR__ . '/../src/Helpers/db.php';

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
        case 'account_add':
            $acc = $input['account'] ?? [];
            $name     = trim((string) ($acc['name']     ?? ''));
            $email    = strtolower(trim((string) ($acc['email']    ?? '')));
            $password = (string) ($acc['password'] ?? '');
            $status   = ($acc['status'] ?? 'Active') === 'Inactive' ? 'inactive' : 'active';
            $sendMail = !empty($acc['send_email']);

            if (!$name)  admin_respond(['status' => 'error', 'message' => 'Please enter the full name.'], 422);
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) admin_respond(['status' => 'error', 'message' => 'Please enter a valid email address.'], 422);
            if (strlen($password) < 8 || !preg_match('/[A-Za-z]/', $password) || !preg_match('/\d/', $password))
                admin_respond(['status' => 'error', 'message' => 'Password must be at least 8 characters with letters and a number.'], 422);

            $pdo = db();
            $exists = $pdo->prepare("SELECT 1 FROM users WHERE email = :e AND deleted_at IS NULL");
            $exists->execute([':e' => $email]);
            if ($exists->fetchColumn()) admin_respond(['status' => 'error', 'message' => 'That email address is already in use.'], 422);

            $hash = password_hash($password, PASSWORD_DEFAULT);
            $ins = $pdo->prepare("INSERT INTO users (name, email, password_hash, role, created_at) VALUES (:n, :e, :p, 'hr', NOW()) RETURNING user_id");
            $ins->execute([':n' => $name, ':e' => $email, ':p' => $hash]);
            $newId = (int) $ins->fetchColumn();
            repo_log_activity('HR Account Added', $name . ' (HR)');

            $emailed = false;
            if ($sendMail) {
                $emailed = (new EmailService())->sendHrCredentialsEmail($email, $name, $password);
            }

            admin_respond([
                'status'  => 'success',
                'message' => 'HR account created.',
                'result'  => ['id' => $newId, 'name' => $name, 'email' => $email, 'role' => 'HR Staff', 'emailed' => $emailed],
                'data'    => ['accounts' => repo_admin_accounts(), 'questions' => repo_admin_questions_list(), 'archive' => repo_admin_archive()],
            ]);
            break;

        case 'account_update':
            $id  = (int) ($input['id'] ?? 0);
            $acc = $input['account'] ?? [];
            $name     = trim((string) ($acc['name']     ?? ''));
            $email    = strtolower(trim((string) ($acc['email']    ?? '')));
            $password = (string) ($acc['password'] ?? '');
            $sendMail = !empty($acc['send_email']);

            if (!$id)   admin_respond(['status' => 'error', 'message' => 'Invalid account ID.'], 422);
            if (!$name) admin_respond(['status' => 'error', 'message' => 'Please enter the full name.'], 422);
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) admin_respond(['status' => 'error', 'message' => 'Please enter a valid email address.'], 422);

            $pdo = db();
            $dup = $pdo->prepare("SELECT 1 FROM users WHERE email = :e AND user_id != :id AND deleted_at IS NULL");
            $dup->execute([':e' => $email, ':id' => $id]);
            if ($dup->fetchColumn()) admin_respond(['status' => 'error', 'message' => 'That email address is already in use.'], 422);

            if ($password !== '') {
                if (strlen($password) < 8 || !preg_match('/[A-Za-z]/', $password) || !preg_match('/\d/', $password))
                    admin_respond(['status' => 'error', 'message' => 'Password must be at least 8 characters with letters and a number.'], 422);
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $upd = $pdo->prepare("UPDATE users SET name=:n, email=:e, password_hash=:p WHERE user_id=:id AND role='hr' AND deleted_at IS NULL");
                $upd->execute([':n' => $name, ':e' => $email, ':p' => $hash, ':id' => $id]);
            } else {
                $upd = $pdo->prepare("UPDATE users SET name=:n, email=:e WHERE user_id=:id AND role='hr' AND deleted_at IS NULL");
                $upd->execute([':n' => $name, ':e' => $email, ':id' => $id]);
            }
            if (!$upd->rowCount()) admin_respond(['status' => 'error', 'message' => 'Account not found or already deleted.'], 404);
            repo_log_activity('HR Account Edited', $name . ' (HR)');

            $emailed = false;
            if ($sendMail && $password !== '') {
                $emailed = (new EmailService())->sendHrCredentialsEmail($email, $name, $password);
            }

            admin_respond([
                'status'  => 'success',
                'message' => 'HR account updated.',
                'result'  => ['id' => $id, 'name' => $name, 'email' => $email, 'role' => 'HR Staff', 'emailed' => $emailed],
                'data'    => ['accounts' => repo_admin_accounts(), 'questions' => repo_admin_questions_list(), 'archive' => repo_admin_archive()],
            ]);
            break;

        case 'account_archive':
            $aid = (int) ($input['id'] ?? 0);
            $ast = db()->prepare("SELECT name FROM users WHERE user_id = :id"); $ast->execute([':id' => $aid]); $aname = (string) $ast->fetchColumn();
            if (!repo_account_archive($aid)) admin_respond(['status' => 'error', 'message' => $missing], 404);
            repo_log_activity('HR Account Deleted', $aname . ' (HR)');
            $message = 'HR account moved to the Archive.';
            break;
        case 'account_restore':
            $aid = (int) ($input['id'] ?? 0);
            $ast = db()->prepare("SELECT name FROM users WHERE user_id = :id"); $ast->execute([':id' => $aid]); $aname = (string) $ast->fetchColumn();
            if (!repo_account_restore($aid)) admin_respond(['status' => 'error', 'message' => $missing], 404);
            repo_log_activity('HR Account Restored', $aname . ' (HR)');
            $message = 'HR account restored.';
            break;
        case 'account_purge':
            $aid = (int) ($input['id'] ?? 0);
            $ast = db()->prepare("SELECT name FROM users WHERE user_id = :id"); $ast->execute([':id' => $aid]); $aname = (string) $ast->fetchColumn();
            if (!repo_account_purge($aid)) admin_respond(['status' => 'error', 'message' => $missing], 404);
            repo_log_activity('HR Account Deleted Forever', $aname . ' (HR)');
            $message = 'HR account deleted forever.';
            break;

        case 'question_archive':
            $id = (int) ltrim((string) ($input['id'] ?? ''), 'q');
            $qst = db()->prepare("SELECT section FROM question_config WHERE question_id = :id"); $qst->execute([':id' => $id]); $qsec = (string) $qst->fetchColumn();
            if (!repo_question_archive($id)) admin_respond(['status' => 'error', 'message' => $missing], 404);
            repo_log_activity('Questionnaires Deleted', 'Q' . $id . ' · ' . $qsec);
            $message = 'Question moved to the Archive.';
            break;
        case 'question_restore':
            $id = (int) ltrim((string) ($input['id'] ?? ''), 'q');
            $qst = db()->prepare("SELECT section FROM question_config WHERE question_id = :id"); $qst->execute([':id' => $id]); $qsec = (string) $qst->fetchColumn();
            if (!repo_question_restore($id)) admin_respond(['status' => 'error', 'message' => $missing], 404);
            repo_log_activity('Questionnaires Restored', 'Q' . $id . ' · ' . $qsec);
            $message = 'Question restored.';
            break;
        case 'question_purge':
            $id = (int) ltrim((string) ($input['id'] ?? ''), 'q');
            $qst = db()->prepare("SELECT section FROM question_config WHERE question_id = :id"); $qst->execute([':id' => $id]); $qsec = (string) $qst->fetchColumn();
            if (!repo_question_purge($id)) admin_respond(['status' => 'error', 'message' => $missing], 404);
            repo_log_activity('Questionnaires Deleted Forever', 'Q' . $id . ' · ' . $qsec);
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
