<?php
// src/Helpers/security.php — centralized security helpers.
// Session hardening, CSRF, rate limiting, input validation, output escaping,
// security headers, and auth guards.

declare(strict_types=1);

require_once __DIR__ . '/db.php';

/*───────────────────────────── session hardening ─────────────────────────────*/

/**
 * CSP nonce generated once per request.
 * @var string|null
 */
$cspNonce = null;

/**
 * Generate (or return) a per-request CSP nonce for inline scripts.
 */
function csp_nonce(): string
{
    global $cspNonce;
    if ($cspNonce === null) {
        $cspNonce = base64_encode(random_bytes(16));
    }
    return $cspNonce;
}

/**
 * Call once on every request before session_start().
 * Hardens PHP session cookie settings (Secure, HttpOnly, SameSite).
 */
function secure_session(int $lifetime = 1800): void
{
    if (session_status() !== PHP_SESSION_NONE) return;

    if (headers_sent($file, $line)) {
        throw new RuntimeException('Could not start the secure session because output started at ' . $file . ':' . $line . '.');
    }

    $https = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https');
    if (!session_set_cookie_params([
        'lifetime' => $lifetime,
        'path' => '/',
        'secure' => $https,
        'httponly' => true,
        'samesite' => 'Lax',
    ])) {
        throw new RuntimeException('Could not configure secure session cookie parameters.');
    }
    foreach ([
        'session.use_strict_mode' => '1',
        'session.use_only_cookies' => '1',
        'session.use_trans_sid' => '0',
        'session.gc_maxlifetime' => (string) $lifetime,
    ] as $key => $value) {
        if (ini_set($key, $value) === false) {
            throw new RuntimeException('Could not configure the PHP session setting: ' . $key);
        }
    }
}

/**
 * Apply the session cookie settings and start the session exactly once.
 */
function start_secure_session(int $lifetime = 1800): void
{
    if (session_status() === PHP_SESSION_ACTIVE) return;
    secure_session($lifetime);
    session_start();
}

/**
 * Regenerate the session id (keeps data). Call after login / privilege change.
 */
function rotate_session(): void
{
    start_secure_session();
    session_regenerate_id(true);
}

/**
 * Restore a signed-in session from a valid Remember me cookie.
 */
function restore_remembered_session(): bool
{
    start_secure_session();
    $role = (string) ($_SESSION['user_role'] ?? '');
    $userId = (int) ($_SESSION['user_id'] ?? 0);
    if ($userId > 0 && in_array($role, [ROLE_ADMIN, ROLE_HR], true)) {
        return true;
    }

    $parts = explode('|', (string) ($_COOKIE['remember'] ?? ''), 2);
    if (count($parts) !== 2) {
        return false;
    }

    $userId = (int) $parts[0];
    $token = $parts[1];
    if ($userId <= 0 || $token === '') {
        return false;
    }

    try {
        $stmt = db()->prepare(
            "SELECT user_id, role, name, email, remember_token, remember_token_expires_at
             FROM users
             WHERE user_id = :id AND deleted_at IS NULL AND status = 'Active'
             LIMIT 1"
        );
        $stmt->execute([':id' => $userId]);
        $user = $stmt->fetch();
    } catch (Throwable $e) {
        error_log('Remember-me session restoration failed: ' . $e->getMessage());
        return false;
    }
    if (!$user || !in_array((string) ($user['role'] ?? ''), [ROLE_ADMIN, ROLE_HR], true)) {
        return false;
    }

    $storedToken = (string) ($user['remember_token'] ?? '');
    $expiresAt = strtotime((string) ($user['remember_token_expires_at'] ?? ''));
    if ($storedToken === '' || !hash_equals($storedToken, hash('sha256', $token)) || $expiresAt === false || $expiresAt <= time()) {
        return false;
    }

    rotate_session();
    $_SESSION['user_id'] = (int) $user['user_id'];
    $_SESSION['user_role'] = (string) $user['role'];
    $_SESSION['user_name'] = (string) $user['name'];
    $_SESSION['user_email'] = strtolower(trim((string) $user['email']));
    return true;
}

/*───────────────────────────── CSRF / bot protection ─────────────────────────────*/

/**
 * Generate a CSRF token and store it in the session.
 */
function csrf_token(): string
{
    start_secure_session();
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Verify a submitted CSRF token against the session. Returns bool.
 * Checks the $token argument first, then the X-CSRF-Token header.
 */
function csrf_verify(?string $token = null): bool
{
    start_secure_session();
    $stored = $_SESSION['csrf_token'] ?? '';
    if ($stored === '') {
        return false;
    }
    // Check the passed token first
    if ($token !== null && hash_equals($stored, $token)) {
        return true;
    }
    // Then check the X-CSRF-Token header (standard for AJAX/fetch)
    $header = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if ($header !== '' && hash_equals($stored, $header)) {
        return true;
    }
    return false;
}

/**
 * One-time CSRF token (consumed on verification). Use for state-changing POSTs.
 */
function csrf_consume(?string $token = null): bool
{
    if (!csrf_verify($token)) {
        return false;
    }
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    return true;
}

/*───────────────────────────── rate limiting ─────────────────────────────*/

/**
 * Ensure the rate_limit_log table and indexes exist before rate-limited work.
 */
function rate_limit_ensure_table(?PDO $pdo = null): void
{
    static $ready = false;
    if ($ready) return;

    $pdo = $pdo ?: db();
    try {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS rate_limit_log (" .
            "rate_limit_id SERIAL PRIMARY KEY, " .
            "key_hash CHAR(64) NOT NULL, " .
            "created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP" .
            ")"
        );
    } catch (Throwable $e) {
        if (!$pdo->query("SELECT to_regclass('rate_limit_log')")->fetchColumn()) {
            throw $e;
        }
        error_log('Rate-limit table creation skipped; the table already exists: ' . $e->getMessage());
    }

    foreach ([
        'idx_rate_limit_key_time' => 'CREATE INDEX IF NOT EXISTS idx_rate_limit_key_time ON rate_limit_log(key_hash, created_at)',
        'idx_rate_limit_created_at' => 'CREATE INDEX IF NOT EXISTS idx_rate_limit_created_at ON rate_limit_log(created_at)',
    ] as $index => $sql) {
        try {
            $pdo->exec($sql);
        } catch (Throwable $e) {
            error_log('Rate-limit index check/creation failed for ' . $index . ': ' . $e->getMessage());
        }
    }
    $ready = true;
}

/**
 * Database-backed rate limiter. Keyed by identifier + action.
 * Limits: $maxAttempts within $windowSeconds.
 * Returns ['allowed' => bool, 'remaining' => int, 'retry_after' => int].
 */
function rate_limit(string $key, int $maxAttempts, int $windowSeconds): array
{
    if ($maxAttempts < 1 || $windowSeconds < 1) {
        throw new InvalidArgumentException('Rate limit values must be positive.');
    }

    $pdo = db();
    rate_limit_ensure_table($pdo);
    $keyHash = hash('sha256', $key);
    $ownsTransaction = !$pdo->inTransaction();
    if ($ownsTransaction) $pdo->beginTransaction();

    try {
        $lock = $pdo->prepare('SELECT pg_advisory_xact_lock(hashtextextended(:key_hash, 0))');
        $lock->execute([':key_hash' => $keyHash]);

        $pdo->exec("DELETE FROM rate_limit_log WHERE created_at < NOW() - INTERVAL '1 day'");
        $stmt = $pdo->prepare(
            "DELETE FROM rate_limit_log
             WHERE key_hash = :kh
               AND created_at <= NOW() - (CAST(:window_seconds AS INTEGER) * INTERVAL '1 second')"
        );
        $stmt->execute([':kh' => $keyHash, ':window_seconds' => $windowSeconds]);

        $stmt = $pdo->prepare("SELECT COUNT(*) FROM rate_limit_log WHERE key_hash = :kh");
        $stmt->execute([':kh' => $keyHash]);
        $count = (int) $stmt->fetchColumn();

        if ($count >= $maxAttempts) {
            // retry_after = when the maxAttempts-th oldest entry expires (count drops below the limit)
            $stmt = $pdo->prepare(
                "SELECT CEIL(EXTRACT(EPOCH FROM (created_at + (CAST(:window_seconds AS INTEGER) * INTERVAL '1 second') - NOW())))::int AS retry_after
                 FROM rate_limit_log WHERE key_hash = :kh
                 ORDER BY created_at ASC LIMIT 1 OFFSET CAST(:off AS INTEGER)"
            );
            $stmt->execute([':window_seconds' => $windowSeconds, ':kh' => $keyHash, ':off' => $maxAttempts - 1]);
            $row = $stmt->fetch();
            $retryAfter = $row ? max(0, (int) ($row['retry_after'] ?? 0)) : $windowSeconds;
            if ($ownsTransaction) $pdo->commit();
            return ['allowed' => false, 'remaining' => 0, 'retry_after' => $retryAfter];
        }

        $stmt = $pdo->prepare(
            "INSERT INTO rate_limit_log (key_hash, created_at) VALUES (:kh, NOW())"
        );
        $stmt->execute([':kh' => $keyHash]);
        if ($ownsTransaction) $pdo->commit();
        return ['allowed' => true, 'remaining' => $maxAttempts - $count - 1, 'retry_after' => 0];
    } catch (Throwable $e) {
        if ($ownsTransaction && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

/*───────────────────────────── input validation ─────────────────────────────*/

/**
 * Sanitize and validate an email address. Returns '' when invalid.
 */
function clean_email(string $value): string
{
    $v = filter_var(trim($value), FILTER_SANITIZE_EMAIL);
    if ($v === false || !filter_var($v, FILTER_VALIDATE_EMAIL)) {
        return '';
    }
    return strtolower($v);
}

/**
 * Sanitize a name (letters, spaces, hyphens, apostrophes, dots). Returns '' when invalid.
 */
function clean_name(string $value, int $maxLen = 100): string
{
    $v = trim($value);
    if ($v === '' || mb_strlen($v, 'UTF-8') > $maxLen) {
        return '';
    }
    if (!preg_match("/^[\p{L}\p{M}'.\- ]+$/u", $v)) {
        return '';
    }
    return $v;
}

/**
 * Sanitize a password: length + complexity. Returns '' when invalid.
 */
function clean_password(string $value, int $minLen = 8): string
{
    if (strlen($value) < $minLen) return '';
    if (!preg_match('/[a-z]/', $value)) return '';
    if (!preg_match('/[A-Z]/', $value)) return '';
    if (!preg_match('/\d/', $value)) return '';
    return $value;
}

/**
 * Cast an integer safely. Returns null when invalid.
 */
function clean_int($value, int $min = null, int $max = null): ?int
{
    $v = filter_var($value, FILTER_VALIDATE_INT);
    if ($v === false || $v === null) return null;
    if ($min !== null && $v < $min) return null;
    if ($max !== null && $v > $max) return null;
    return $v;
}

/**
 * Truncate a string to a max length (mb-safe).
 */
function truncate(string $value, int $max): string
{
    return mb_substr($value, 0, $max, 'UTF-8');
}

/*───────────────────────────── output escaping (XSS prevention) ─────────────────────────────*/

/**
 * HTML-escape a value for safe output in HTML context.
 */
function e(?string $value, int $flags = ENT_QUOTES | ENT_SUBSTITUTE): string
{
    if ($value === null) return '';
    return htmlspecialchars((string) $value, $flags, 'UTF-8');
}

/**
 * Escape for HTML attribute context.
 */
function attr(?string $value): string
{
    return e($value, ENT_QUOTES | ENT_SUBSTITUTE);
}

/*───────────────────────────── auth guards ─────────────────────────────*/

const ROLE_ADMIN = 'admin';
const ROLE_HR    = 'hr';
const ROLE_APPLICANT = 'applicant';

/**
 * Require a logged-in HR or Admin user. Sends 403 JSON when missing.
 */
function require_admin_or_hr(): void
{
    start_secure_session();
    $role = $_SESSION['user_role'] ?? '';
    $uid  = $_SESSION['user_id'] ?? null;
    if ($uid === null || !in_array($role, [ROLE_ADMIN, ROLE_HR], true)) {
        http_response_code(403);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['status' => 'error', 'message' => 'Your session has ended. Please log in again.']);
        exit;
    }
}

/**
 * Require a logged-in Admin only. Sends 403 JSON when missing.
 */
function require_admin(): void
{
    start_secure_session();
    $role = $_SESSION['user_role'] ?? '';
    $uid  = $_SESSION['user_id'] ?? null;
    if ($uid === null || $role !== ROLE_ADMIN) {
        http_response_code(403);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['status' => 'error', 'message' => 'Admin access required.']);
        exit;
    }
}

/**
 * Require a verified applicant session. Sends 403 JSON when missing.
 */
function require_applicant(): void
{
    start_secure_session();
    if (empty($_SESSION['otp_verified']) || empty($_SESSION['applicant_id'])) {
        http_response_code(403);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['status' => 'error', 'message' => 'Your session has ended. Please open your invite link again.']);
        exit;
    }
}

/**
 * Return the current HR/Admin user id from session, or null.
 */
function current_user_id(): ?int
{
    start_secure_session();
    return !empty($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
}

/**
 * Return the current user role from session, or ''.
 */
function current_user_role(): string
{
    start_secure_session();
    return (string) ($_SESSION['user_role'] ?? '');
}

/**
 * Assert that the current session user may access the given applicant record.
 * - admin/hr: may access any non-deleted applicant
 * - applicant: may access only their own applicant_id (from $_SESSION['applicant_id'])
 * Sends 403 JSON and exits when access is denied.
 */
function assert_applicant_access(int $applicantId): void
{
    start_secure_session();
    $role = current_user_role();
    $uid  = current_user_id();

    if (in_array($role, [ROLE_ADMIN, ROLE_HR], true) && $uid !== null) {
        return; // staff may access any applicant
    }
    if ($role === ROLE_APPLICANT || !empty($_SESSION['applicant_id'])) {
        $aid = (int) ($_SESSION['applicant_id'] ?? 0);
        if ($aid > 0 && $aid === $applicantId) {
            return; // applicant accessing their own record
        }
    }

    http_response_code(403);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['status' => 'error', 'message' => 'Access denied.']);
    exit;
}

/**
 * Assert that the current session user is staff (admin or hr).
 * Alias for require_admin_or_hr() with explicit return.
 */
function require_staff(): void
{
    require_admin_or_hr();
}

/*───────────────────────────── security headers ─────────────────────────────*/

/**
 * Send a baseline set of security headers. Call before any output.
 */
function send_security_headers(bool $forceHttps = false): void
{
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: geolocation=(), microphone=(), camera=()');

    $nonce = csp_nonce();
    $csp = "default-src 'self'; "
        . "script-src 'self' 'unsafe-inline' 'nonce-{$nonce}' https://fonts.googleapis.com https://fonts.gstatic.com; "
        . "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com https://fonts.gstatic.com; "
        . "font-src 'self' https://fonts.gstatic.com; "
        . "img-src 'self' data: https:; "
        . "connect-src 'self'; "
        . "frame-ancestors 'none'; "
        . "base-uri 'self';";
    header("Content-Security-Policy: {$csp}");

    if ($forceHttps) {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
}

/*───────────────────────────── response trimming ─────────────────────────────*/

/**
 * Trim an associative array to only the allowed keys. Prevents over-disclosure.
 */
function trim_response(array $data, array $allowedKeys): array
{
    $out = [];
    foreach ($allowedKeys as $k) {
        if (array_key_exists($k, $data)) {
            $out[$k] = $data[$k];
        }
    }
    return $out;
}
