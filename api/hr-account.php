<?php
// api/hr-account.php — the HR's own account changes (Settings > Account).
//   save_profile    { name, email }     name is applied at once; a new email waits for the Admin's approval
//   change_password { current, next }   waits for the Admin's approval
//   cancel_request  {}                  HR takes its pending request back
//   status          {}                  the pending request, if any
// Every change is written to the activity log, so it shows in the Admin's Recent Activities.

session_start();
require_once __DIR__ . '/../src/Helpers/security.php';
secure_session(1800);
session_start();
send_security_headers(false);

header('Content-Type: application/json; charset=UTF-8');
date_default_timezone_set('Asia/Manila');

require_once __DIR__ . '/../src/Helpers/db.php';
require_once __DIR__ . '/../src/Repositories/ActivityRepository.php';
require_once __DIR__ . '/../src/Repositories/ProfileChangeRepository.php';

function hr_respond(array $data, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

// CSRF + auth guard
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    hr_respond(['status' => 'error', 'message' => 'Invalid request.'], 405);
}
$json   = json_decode((string) file_get_contents('php://input'), true);
$input  = is_array($json) ? $json : $_POST;
$action = (string) ($input['action'] ?? '');

$csrfIn = (string) ($input['csrf_token'] ?? '');
    if (!csrf_verify($csrfIn)) {
        hr_respond(['status' => 'error', 'message' => 'Invalid CSRF token. Please refresh and try again.'], 403);
    }

if (($_SESSION['user_role'] ?? '') !== 'hr' || empty($_SESSION['user_id'])) {
    hr_respond(['status' => 'error', 'message' => 'Your session has ended. Please log in again.'], 403);
}
$uid    = (int) $_SESSION['user_id'];

try {
    $pdo = db();
    $me = $pdo->prepare("SELECT user_id, name, email, password_hash FROM users WHERE user_id = :u AND role = 'hr' AND deleted_at IS NULL");
    $me->execute([':u' => $uid]);
    $user = $me->fetch();
    if (!$user) hr_respond(['status' => 'error', 'message' => 'Your account is no longer active.'], 403);

    $pending = fn() => repo_pcr_hr_view(repo_pcr_pending_for_user($uid, $pdo));
    $parts = function (?string $email, bool $pw): string {
        $p = [];
        if ($email !== null) $p[] = 'email';
        if ($pw) $p[] = 'password';
        return implode(' & ', $p);
    };

    switch ($action) {
        case 'status':
            hr_respond(['status' => 'success', 'message' => '', 'pending' => $pending()]);
            break;

        case 'save_profile':
            $name  = trim((string) ($input['name'] ?? ''));
            $email = strtolower(trim((string) ($input['email'] ?? '')));

            if ($name === '' || mb_strlen($name) > 100 || preg_match('/\d/', $name) || !preg_match("/^[\\p{L}\\p{M}'.\\- ]+$/u", $name))
                hr_respond(['status' => 'error', 'field' => 'name', 'message' => 'Please enter a valid name (letters only).'], 422);
            if (!filter_var($email, FILTER_VALIDATE_EMAIL))
                hr_respond(['status' => 'error', 'field' => 'email', 'message' => 'Enter a valid email address (example: name@gmail.com).'], 422);

            $changes = ['name_changed' => false, 'email_requested' => false];

            // ---- name: applied at once ----
            if ($name !== (string) $user['name']) {
                $pdo->prepare("UPDATE users SET name = :n WHERE user_id = :u")->execute([':n' => $name, ':u' => $uid]);
                repo_log_activity('HR Name Changed', $user['name'] . ' → ' . $name, 'HR', $pdo);
                $_SESSION['user_name'] = $name;
                $changes['name_changed'] = true;
            }

            // ---- email: waits for the Admin ----
            if ($email !== strtolower((string) $user['email'])) {
                $dup = $pdo->prepare("SELECT 1 FROM users WHERE email = :e AND user_id != :u AND deleted_at IS NULL");
                $dup->execute([':e' => $email, ':u' => $uid]);
                if ($dup->fetchColumn()) hr_respond(['status' => 'error', 'field' => 'email', 'message' => 'That email address is already in use.'], 422);

                $req = repo_pcr_submit($uid, $email, null, $pdo);
                $row = repo_pcr_pending_for_user($uid, $pdo);
                repo_log_activity('HR Change Request', $name . ' (HR) · ' . $parts($row['new_email'], !empty($row['new_password_hash'])) . ' · waiting for approval', 'HR', $pdo, $req['id']);
                $changes['email_requested'] = true;
            }

            hr_respond([
                'status'  => 'success',
                'message' => $changes['email_requested'] ? 'Saved. Your new email is waiting for the admin\'s approval.' : 'Settings saved.',
                'name'    => $name,
                'email'   => (string) $user['email'],          // the email that is really in use right now
                'changes' => $changes,
                'pending' => $pending(),
            ]);
            break;

        case 'change_password':
            $current = (string) ($input['current'] ?? '');
            $next    = (string) ($input['next'] ?? '');

            if (!password_verify($current, (string) $user['password_hash']))
                hr_respond(['status' => 'error', 'field' => 'pwCurrent', 'message' => 'Your current password is not correct.'], 422);
            if (strlen($next) < 8 || !preg_match('/[a-z]/', $next) || !preg_match('/[A-Z]/', $next) || !preg_match('/\d/', $next))
                hr_respond(['status' => 'error', 'field' => 'pwNew', 'message' => 'The new password does not meet all the requirements.'], 422);
            if ($next === $current)
                hr_respond(['status' => 'error', 'field' => 'pwNew', 'message' => 'The new password must be different from the current one.'], 422);

            $req = repo_pcr_submit($uid, null, $next, $pdo);
            $row = repo_pcr_pending_for_user($uid, $pdo);
            repo_log_activity('HR Change Request', $user['name'] . ' (HR) · ' . $parts($row['new_email'], !empty($row['new_password_hash'])) . ' · waiting for approval', 'HR', $pdo, $req['id']);

            hr_respond([
                'status'  => 'success',
                'message' => 'Sent. Your new password is waiting for the admin\'s approval. Keep using your current password until then.',
                'pending' => $pending(),
            ]);
            break;

        case 'cancel_request':
            $row = repo_pcr_pending_for_user($uid, $pdo);
            if (!$row) hr_respond(['status' => 'success', 'message' => 'There is no request waiting.', 'pending' => null]);
            repo_pcr_close((int) $row['request_id'], 'cancelled', $uid, $pdo);
            repo_log_activity('HR Change Cancelled', $user['name'] . ' (HR) · ' . $parts($row['new_email'], !empty($row['new_password_hash'])), 'HR', $pdo, (int) $row['request_id']);
            hr_respond(['status' => 'success', 'message' => 'Your request was cancelled.', 'pending' => null]);
            break;

        default:
            hr_respond(['status' => 'error', 'message' => 'Unknown action.'], 400);
    }
} catch (InvalidArgumentException $e) {
    hr_respond(['status' => 'error', 'message' => $e->getMessage()], 422);
} catch (Throwable $e) {
    error_log('hr-account.php error: ' . $e->getMessage());
    hr_respond(['status' => 'error', 'message' => 'Something went wrong. Please try again.'], 500);
}