<?php
require_once __DIR__ . '/EmailService.php';

class OtpService {
    private $pdo;
    private $emailService;
    /** Null = unknown, true/false = whether otp.locked_until exists. */
    private $hasLockedUntil = null;

    public function __construct($pdo) {
        $this->pdo = $pdo;
        $this->emailService = new EmailService();
    }

    /**
     * Self-heal the otp table on old databases: adds locked_until and the
     * applicant_id unique constraint the upsert needs. Runs once per request
     * and silently skips when the DB user lacks ALTER rights (error pages then
     * show the friendly message instead of a fatal).
     */
    private function ensureSchema(): void
    {
        if ($this->hasLockedUntil !== null) return;
        try {
            $col = $this->pdo->query(
                "SELECT 1 FROM information_schema.columns" .
                " WHERE table_name = 'otp' AND column_name = 'locked_until' LIMIT 1"
            )->fetchColumn();
            if (!$col) {
                $this->pdo->exec("ALTER TABLE otp ADD COLUMN locked_until TIMESTAMP NULL");
            }
            $uniq = $this->pdo->query(
                "SELECT 1 FROM pg_indexes WHERE tablename = 'otp' AND indexdef ILIKE '%(applicant_id)%' AND indexdef ILIKE '%UNIQUE%' LIMIT 1"
            )->fetchColumn();
            if (!$uniq) {
                // Stale duplicates (pre-upsert rows) would block the constraint:
                // keep the newest row per applicant, drop the rest.
                $this->pdo->exec(
                    "DELETE FROM otp a USING otp b" .
                    " WHERE a.otp_id < b.otp_id AND a.applicant_id = b.applicant_id"
                );
                $this->pdo->exec("ALTER TABLE otp ADD CONSTRAINT otp_applicant_id_unique UNIQUE (applicant_id)");
            }
            $this->hasLockedUntil = true;
        } catch (Throwable $e) {
            error_log('OtpService ensureSchema skipped: ' . $e->getMessage());
            $this->hasLockedUntil = false;
        }
    }

    public function generateAndSendOtp($applicantId, $email, $name) {
        // 1. Generate a random 6-digit code
        $otpCode = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        // 2. Hash it for security
        $otpHash = password_hash($otpCode, PASSWORD_DEFAULT);

        // 3. Set expiration to 5 minutes from now
        $expiresAt = date('Y-m-d H:i:s', strtotime('+5 minutes'));

        $this->ensureSchema();

        $ownsTransaction = !$this->pdo->inTransaction();
        if ($ownsTransaction) $this->pdo->beginTransaction();

        try {
            // Save the code transactionally so failed email delivery preserves the old code.
            try {
                $stmt = $this->pdo->prepare(
                    "INSERT INTO otp (applicant_id, otp_code_hash, request_count, attempt_count, expires_at, locked_until)" .
                    " VALUES (?, ?, 1, 0, ?, NULL)" .
                    " ON CONFLICT (applicant_id) DO UPDATE SET" .
                    " otp_code_hash = EXCLUDED.otp_code_hash," .
                    " request_count = otp.request_count + 1," .
                    " attempt_count = 0," .
                    " expires_at = EXCLUDED.expires_at," .
                    " locked_until = NULL"
                );
                $stmt->execute([$applicantId, $otpHash, $expiresAt]);
            } catch (PDOException $e) {
                if (!$this->isMissingColumnOrConflict($e)) throw $e;
                error_log('OtpService legacy otp table, using delete+insert: ' . $e->getMessage());
                $this->hasLockedUntil = false;
                $del = $this->pdo->prepare("DELETE FROM otp WHERE applicant_id = ?");
                $del->execute([$applicantId]);
                $ins = $this->pdo->prepare(
                    "INSERT INTO otp (applicant_id, otp_code_hash, request_count, attempt_count, expires_at)" .
                    " VALUES (?, ?, 1, 0, ?)"
                );
                $ins->execute([$applicantId, $otpHash, $expiresAt]);
            }

            if (!$this->emailService->sendOtpEmail($email, $name, $otpCode)) {
                if ($ownsTransaction) $this->pdo->rollBack();
                return false;
            }
            if ($ownsTransaction) $this->pdo->commit();
            return true;
        } catch (Throwable $e) {
            if ($ownsTransaction && $this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }
    }

    /** True when the PDO error is "column locked_until missing" or "no unique constraint for ON CONFLICT". */
    private function isMissingColumnOrConflict(PDOException $e): bool
    {
        $msg = strtolower($e->getMessage() . ' ' . ($e->getCode() ?: ''));
        return str_contains($msg, 'locked_until')
            || str_contains($msg, '42703')
            || str_contains($msg, 'no unique or exclusion constraint')
            || str_contains($msg, '42p10');
    }

    /** Latest OTP row for an applicant (or null when none was ever issued). */
    public function latestOtp($applicantId): ?array
    {
        $this->ensureSchema();
        try {
            $stmt = $this->pdo->prepare(
                "SELECT otp_id, otp_code_hash, request_count, attempt_count, expires_at, locked_until" .
                " FROM otp WHERE applicant_id = ? LIMIT 1"
            );
            $stmt->execute([$applicantId]);
        } catch (PDOException $e) {
            if (!$this->isMissingColumnOrConflict($e)) throw $e;
            $this->hasLockedUntil = false;
            $stmt = $this->pdo->prepare(
                "SELECT otp_id, otp_code_hash, request_count, attempt_count, expires_at, NULL AS locked_until" .
                " FROM otp WHERE applicant_id = ? ORDER BY otp_id DESC LIMIT 1"
            );
            $stmt->execute([$applicantId]);
        }
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Check a 6-digit code against the DB row.
     * Returns ['ok' => bool, 'error' => string, 'attempts_left' => int, 'locked_until' => ?string].
     * Wrong codes bump attempt_count; max attempts sets locked_until 15 min out.
     */
    public function verifyOtp($applicantId, string $code, int $maxAttempts = 5, int $lockSeconds = 900): array
    {
        $row = $this->latestOtp($applicantId);
        if (!$row) return ['ok' => false, 'error' => 'No code was sent yet. Please ask for a new one.', 'attempts_left' => $maxAttempts, 'locked_until' => null];

        $now = time();
        $lockedUntil = !empty($row['locked_until']) ? strtotime((string) $row['locked_until']) : false;
        if ($lockedUntil && $now < $lockedUntil) {
            return ['ok' => false, 'error' => 'Too many wrong attempts. Please try again later.', 'attempts_left' => 0, 'locked_until' => (string) $row['locked_until']];
        }

        $expires = strtotime((string) $row['expires_at']);
        if ($expires === false || $now > $expires) {
            return ['ok' => false, 'error' => 'That code has expired. Please ask for a new one.', 'attempts_left' => max(0, $maxAttempts - (int) $row['attempt_count']), 'locked_until' => null];
        }

        if (!preg_match('/^\d{6}$/', $code) || !password_verify($code, (string) $row['otp_code_hash'])) {
            $attempts = (int) $row['attempt_count'] + 1;
            if ($attempts >= $maxAttempts) {
                $locked = date('Y-m-d H:i:s', $now + $lockSeconds);
                if ($this->hasLockedUntil !== false) {
                    try {
                        $stmt = $this->pdo->prepare("UPDATE otp SET attempt_count = ?, locked_until = ? WHERE otp_id = ?");
                        $stmt->execute([$attempts, $locked, $row['otp_id']]);
                    } catch (PDOException $e) {
                        if (!$this->isMissingColumnOrConflict($e)) throw $e;
                        $this->hasLockedUntil = false;
                        // No lockout column: fall back to a long expiry as the lock.
                        $locked = date('Y-m-d H:i:s', $now + $lockSeconds);
                        $stmt = $this->pdo->prepare("UPDATE otp SET attempt_count = ?, expires_at = ? WHERE otp_id = ?");
                        $stmt->execute([$attempts, $locked, $row['otp_id']]);
                        return ['ok' => false, 'error' => 'Too many wrong attempts. Please try again later.', 'attempts_left' => 0, 'locked_until' => $locked];
                    }
                } else {
                    $stmt = $this->pdo->prepare("UPDATE otp SET attempt_count = ?, expires_at = ? WHERE otp_id = ?");
                    $stmt->execute([$attempts, $locked, $row['otp_id']]);
                    return ['ok' => false, 'error' => 'Too many wrong attempts. Please try again later.', 'attempts_left' => 0, 'locked_until' => $locked];
                }
                return ['ok' => false, 'error' => 'Too many wrong attempts. Please try again later.', 'attempts_left' => 0, 'locked_until' => $locked];
            }
            $stmt = $this->pdo->prepare("UPDATE otp SET attempt_count = ? WHERE otp_id = ?");
            $stmt->execute([$attempts, $row['otp_id']]);
            return ['ok' => false, 'error' => 'That code is not correct. Please try again.', 'attempts_left' => $maxAttempts - $attempts, 'locked_until' => null];
        }

        return ['ok' => true, 'error' => '', 'attempts_left' => $maxAttempts, 'locked_until' => null];
    }

    /** Seconds until a resend is allowed (resendWait - elapsed), 0 when allowed. */
    public function resendWaitLeft($applicantId, int $resendWait = 60): int
    {
        $stmt = $this->pdo->prepare(
            "SELECT EXTRACT(EPOCH FROM (NOW() - (expires_at - INTERVAL '5 minutes')))::int AS elapsed" .
            " FROM otp WHERE applicant_id = ? LIMIT 1"
        );
        $stmt->execute([$applicantId]);
        $elapsed = (int) ($stmt->fetchColumn() ?: 86400);
        return max(0, $resendWait - $elapsed);
    }
}