<?php
// api/password-reset.php — password reset flow for HR and Admin accounts.
//
//   request  { email }          sends a one-time reset link to the given email (generic answer,
//                                never reveals whether the email exists)
//   reset    { token, password, confirm }   verifies the token, hashes the new password and
//                                           clears the reset token fields
//
// No session is required: the request step is reached from the public login / forgot pages,
// and the reset step is reached from the token link in the email.
error_reporting(0);
@ini_set('display_errors', '0');

require_once __DIR__ . '/../src/Helpers/security.php';
secure_session(1800);
session_start();
send_security_headers(false);

header('Content-Type: application/json; charset=UTF-8');
date_default_timezone_set('Asia/Manila');

require_once __DIR__ . '/../src/Helpers/db.php';
require_once __DIR__ . '/../src/Services/EmailService.php';

function respond(array $data, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(['status' => 'error', 'message' => 'Invalid request.'], 405);
}

// Rate limit password reset requests (5 per 15 minutes per IP)
$ip = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
$rl = rate_limit('password-reset:' . $ip, 5, 15 * 60);
if (!$rl['allowed']) {
    http_response_code(429);
    respond(['status' => 'error', 'message' => 'Too many reset requests. Please try again in ' . $rl['retry_after'] . ' seconds.', 'retry_after' => $rl['retry_after']]);
}

$json = json_decode((string) file_get_contents('php://input'), true);
$input = is_array($json) ? $json : $_POST;
$action = (string) ($input['action'] ?? '');

try {
    $pdo = db();

    switch ($action) {
        case 'request': {
            $email = strtolower(trim((string) ($input['email'] ?? '')));
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                respond(['status' => 'success', 'message' => 'If the email exists, a reset link has been sent.']);
            }

            // Never reveal whether the email exists: look it up, but answer the same either way.
            $user = $pdo->prepare(
                "SELECT user_id, name, email FROM users
                 WHERE LOWER(email) = :e AND deleted_at IS NULL LIMIT 1"
            );
            $user->execute([':e' => $email]);
            $row = $user->fetch();

            if ($row) {
                $token = bin2hex(random_bytes(32));                 // 64 random characters
                $expires = date('Y-m-d H:i:s', strtotime('+30 minutes'));

                $pdo->prepare(
                    "UPDATE users
                     SET reset_token = :token, reset_token_expires_at = :expires
                     WHERE user_id = :u"
                )->execute([
                    ':token' => $token,
                    ':expires' => $expires,
                    ':u' => (int) $row['user_id'],
                ]);

                try {
                    (new EmailService())->sendPasswordResetEmail(
                        (string) $row['email'],
                        (string) $row['name'],
                        $token
                    );
                } catch (Throwable $e) {
                    error_log('password-reset email send failed: ' . $e->getMessage());
                    // Still answer generically: do not reveal anything about the account.
                }
            }

            respond([
                'status' => 'success',
                'message' => 'If the email exists, a reset link has been sent.',
            ]);
        }

        case 'reset': {
            $token = (string) ($input['token'] ?? '');
            $password = (string) ($input['password'] ?? '');
            $confirm = (string) ($input['confirm'] ?? '');

            if ($token === '') {
                respond(['status' => 'error', 'message' => 'Invalid reset link.'], 400);
            }
            if ($password === '' || $password !== $confirm) {
                respond(['status' => 'error', 'message' => 'Please enter the same password in both fields.'], 422);
            }
            if (strlen($password) < 8 || !preg_match('/[a-z]/', $password) || !preg_match('/[A-Z]/', $password) || !preg_match('/\d/', $password)) {
                respond(['status' => 'error', 'message' => 'The password must be at least 8 characters and include upper and lower case letters and a number.'], 422);
            }

            $now = date('Y-m-d H:i:s');
            $user = $pdo->prepare(
                "SELECT user_id FROM users
                 WHERE reset_token = :token
                   AND reset_token_expires_at > :now
                   AND deleted_at IS NULL
                 LIMIT 1"
            );
            $user->execute([':token' => $token, ':now' => $now]);
            $row = $user->fetch();
            if (!$row) {
                respond(['status' => 'error', 'message' => 'This reset link is invalid or has expired. Please request a new one.'], 400);
            }

            $pdo->prepare(
                "UPDATE users
                 SET password_hash = :hash, reset_token = NULL, reset_token_expires_at = NULL
                 WHERE user_id = :u"
            )->execute([
                ':hash' => password_hash($password, PASSWORD_DEFAULT),
                ':u' => (int) $row['user_id'],
            ]);

            respond(['status' => 'success', 'message' => 'Your password has been reset. You can now log in with your new password.']);
        }

        default:
            respond(['status' => 'error', 'message' => 'Unknown action.'], 400);
    }
} catch (Throwable $e) {
    error_log('api/password-reset.php error: ' . $e->getMessage());
    respond(['status' => 'error', 'message' => 'Something went wrong. Please try again.'], 500);
}
