<?php
require_once __DIR__ . '/../src/Helpers/security.php';
secure_session(1800);
session_start();
send_security_headers(false);

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

function notification_read_respond(array $data, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    notification_read_respond(['status' => 'error', 'message' => 'Invalid request.'], 405);
}

$json = json_decode((string) file_get_contents('php://input'), true);
$input = is_array($json) ? $json : $_POST;
$csrf = (string) ($input['csrf_token'] ?? '');
if (!csrf_verify($csrf)) {
    notification_read_respond(['status' => 'error', 'message' => 'Invalid CSRF token. Please refresh and try again.'], 403);
}

$userId = (int) ($_SESSION['user_id'] ?? 0);
if ($userId <= 0 || !in_array((string) ($_SESSION['user_role'] ?? ''), ['hr', 'admin'], true)) {
    notification_read_respond(['status' => 'error', 'message' => 'Your session has ended. Please log in again.'], 403);
}

$action = (string) ($input['action'] ?? '');
$keys = $input['keys'] ?? [];
if (!is_array($keys) || count($keys) > 2000) {
    notification_read_respond(['status' => 'error', 'message' => 'Invalid notification list.'], 422);
}

$cleanKeys = [];
foreach ($keys as $key) {
    if (!is_string($key) || strlen($key) > 255 || preg_match('/^[A-Za-z0-9][A-Za-z0-9:_-]*$/D', $key) !== 1) {
        notification_read_respond(['status' => 'error', 'message' => 'Invalid notification identifier.'], 422);
    }
    $cleanKeys[] = $key;
}
$keys = array_values(array_unique($cleanKeys));

try {
    $pdo = db();
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS notification_reads (
            user_id INTEGER NOT NULL REFERENCES users(user_id) ON DELETE CASCADE,
            notification_key VARCHAR(255) NOT NULL,
            read_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (user_id, notification_key)
        )"
    );

    if ($action === 'state') {
        if (!$keys) notification_read_respond(['status' => 'success', 'read_keys' => []]);
        $params = [':user_id' => $userId];
        $placeholders = [];
        foreach ($keys as $index => $key) {
            $name = ':key' . $index;
            $placeholders[] = $name;
            $params[$name] = $key;
        }
        $stmt = $pdo->prepare(
            'SELECT notification_key FROM notification_reads WHERE user_id = :user_id AND notification_key IN (' . implode(',', $placeholders) . ')'
        );
        $stmt->execute($params);
        notification_read_respond(['status' => 'success', 'read_keys' => array_column($stmt->fetchAll(), 'notification_key')]);
    }

    if ($action !== 'mark' && $action !== 'mark_many') {
        notification_read_respond(['status' => 'error', 'message' => 'Unknown action.'], 400);
    }

    if ($action === 'mark' && count($keys) !== 1) {
        notification_read_respond(['status' => 'error', 'message' => 'A single notification is required.'], 422);
    }

    if (!$keys) notification_read_respond(['status' => 'success', 'read_keys' => []]);

    $pdo->beginTransaction();
    $stmt = $pdo->prepare(
        'INSERT INTO notification_reads (user_id, notification_key, read_at)
         VALUES (:user_id, :notification_key, CURRENT_TIMESTAMP)
         ON CONFLICT (user_id, notification_key) DO NOTHING'
    );
    foreach ($keys as $key) {
        $stmt->execute([':user_id' => $userId, ':notification_key' => $key]);
    }
    $pdo->commit();
    notification_read_respond(['status' => 'success', 'read_keys' => $keys]);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack();
    error_log('Notification read state failed: ' . $e->getMessage());
    notification_read_respond(['status' => 'error', 'message' => 'Notification state could not be saved. Please try again.'], 500);
}
