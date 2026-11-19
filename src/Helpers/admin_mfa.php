<?php

function admin_mfa_ensure_schema(PDO $pdo): void
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS admin_mfa_challenges (
            challenge_id BIGSERIAL PRIMARY KEY,
            admin_user_id INTEGER NOT NULL REFERENCES users(user_id) ON DELETE CASCADE,
            purpose VARCHAR(40) NOT NULL,
            session_hash CHAR(64) NOT NULL,
            token_hash CHAR(64) NOT NULL UNIQUE,
            code_hash VARCHAR(255) NOT NULL,
            operation_hash CHAR(64) NOT NULL,
            attempt_count SMALLINT NOT NULL DEFAULT 0,
            expires_at TIMESTAMPTZ NOT NULL,
            consumed_at TIMESTAMPTZ,
            created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
        )"
    );
    $pdo->exec(
        'CREATE INDEX IF NOT EXISTS idx_admin_mfa_challenges_owner ON admin_mfa_challenges (admin_user_id, purpose, created_at DESC)'
    );
}

function admin_mfa_operation_hash(string $sessionId, array $account): string
{
    $operation = json_encode([
        'name' => (string) $account['name'],
        'email' => (string) $account['email'],
        'password' => (string) $account['password'],
        'status' => (string) $account['status'],
        'send_email' => (bool) $account['send_email'],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);

    return hash_hmac('sha256', (string) $operation, $sessionId);
}

function admin_mfa_issue_challenge(PDO $pdo, int $adminId, string $sessionId, string $code, array $account): string
{
    admin_mfa_ensure_schema($pdo);
    $pdo->exec("DELETE FROM admin_mfa_challenges WHERE expires_at < NOW() - INTERVAL '1 day'");

    $token = bin2hex(random_bytes(32));
    $stmt = $pdo->prepare(
        "INSERT INTO admin_mfa_challenges
            (admin_user_id, purpose, session_hash, token_hash, code_hash, operation_hash, expires_at)
         VALUES
            (:admin_id, 'add_hr', :session_hash, :token_hash, :code_hash, :operation_hash, NOW() + INTERVAL '5 minutes')"
    );
    $stmt->execute([
        ':admin_id' => $adminId,
        ':session_hash' => hash('sha256', $sessionId),
        ':token_hash' => hash('sha256', $token),
        ':code_hash' => password_hash($code, PASSWORD_DEFAULT),
        ':operation_hash' => admin_mfa_operation_hash($sessionId, $account),
    ]);

    return $token;
}

function admin_mfa_invalidate_other_challenges(PDO $pdo, int $adminId, string $sessionId, string $token): void
{
    $stmt = $pdo->prepare(
        "UPDATE admin_mfa_challenges
         SET consumed_at = CURRENT_TIMESTAMP
         WHERE admin_user_id = :admin_id
           AND purpose = 'add_hr'
           AND session_hash = :session_hash
           AND token_hash <> :token_hash
           AND consumed_at IS NULL"
    );
    $stmt->execute([
        ':admin_id' => $adminId,
        ':session_hash' => hash('sha256', $sessionId),
        ':token_hash' => hash('sha256', $token),
    ]);
}

function admin_mfa_delete_challenge(PDO $pdo, string $token): void
{
    if (preg_match('/^[a-f0-9]{64}$/D', $token) !== 1) return;

    $stmt = $pdo->prepare('DELETE FROM admin_mfa_challenges WHERE token_hash = :token_hash');
    $stmt->execute([':token_hash' => hash('sha256', $token)]);
}

function admin_mfa_verify_challenge(PDO $pdo, int $adminId, string $sessionId, string $token, string $code, array $account): array
{
    if (preg_match('/^[a-f0-9]{64}$/D', $token) !== 1) {
        return ['ok' => false, 'error' => 'The verification session is invalid. Request a new code.'];
    }

    admin_mfa_ensure_schema($pdo);
    $pdo->beginTransaction();

    try {
        $stmt = $pdo->prepare(
            "SELECT challenge_id, code_hash, operation_hash, attempt_count, consumed_at,
                    EXTRACT(EPOCH FROM (expires_at - NOW()))::int AS seconds_left
             FROM admin_mfa_challenges
             WHERE admin_user_id = :admin_id
               AND purpose = 'add_hr'
               AND session_hash = :session_hash
               AND token_hash = :token_hash
             FOR UPDATE"
        );
        $stmt->execute([
            ':admin_id' => $adminId,
            ':session_hash' => hash('sha256', $sessionId),
            ':token_hash' => hash('sha256', $token),
        ]);
        $challenge = $stmt->fetch();
        if (!$challenge || !empty($challenge['consumed_at'])) {
            $pdo->commit();
            return ['ok' => false, 'error' => 'The verification session is invalid. Request a new code.'];
        }

        if ((int) $challenge['seconds_left'] <= 0) {
            $pdo->commit();
            return ['ok' => false, 'error' => 'The verification code expired. Request a new code.', 'expired' => true];
        }

        if (!hash_equals((string) $challenge['operation_hash'], admin_mfa_operation_hash($sessionId, $account))) {
            $pdo->commit();
            return ['ok' => false, 'error' => 'HR account details changed after the code was sent. Request a new code.'];
        }

        $attempts = (int) $challenge['attempt_count'];
        if ($attempts >= 5) {
            $pdo->commit();
            return ['ok' => false, 'error' => 'Too many incorrect codes. Request a new code.', 'locked' => true];
        }

        if (preg_match('/^\d{6}$/D', $code) !== 1 || !password_verify($code, (string) $challenge['code_hash'])) {
            $attempts++;
            $update = $pdo->prepare('UPDATE admin_mfa_challenges SET attempt_count = :attempts WHERE challenge_id = :id');
            $update->execute([':attempts' => $attempts, ':id' => (int) $challenge['challenge_id']]);
            $pdo->commit();
            if ($attempts >= 5) {
                return ['ok' => false, 'error' => 'Too many incorrect codes. Request a new code.', 'locked' => true];
            }
            return [
                'ok' => false,
                'error' => 'That verification code is incorrect. ' . (5 - $attempts) . ' attempts remain.',
                'attempts_left' => 5 - $attempts,
            ];
        }

        $update = $pdo->prepare(
            'UPDATE admin_mfa_challenges SET consumed_at = CURRENT_TIMESTAMP WHERE challenge_id = :id'
        );
        $update->execute([':id' => (int) $challenge['challenge_id']]);
        $pdo->commit();
        return ['ok' => true, 'error' => ''];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function admin_mfa_mask_email(string $email): string
{
    $parts = explode('@', $email, 2);
    if (count($parts) !== 2) return '';

    $local = $parts[0];
    $visible = substr($local, 0, 1);
    return $visible . str_repeat('*', max(3, min(8, strlen($local) - 1))) . '@' . $parts[1];
}