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
    rate_limit_ensure_table();
    $ini = [
        'session.cookie_httponly' => '1',
        'session.use_strict_mode' => '1',
        'session.use_only_cookies' => '1',
        'session.cookie_samesite' => 'Lax',
        'session.gc_maxlifetime' => (string) $lifetime,
        'session.cookie_lifetime' => (string) $lifetime,
    ];
    $https = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https');
    if ($https) {
        $ini['session.cookie_secure'] = '1';
    } else {
        $ini['session.cookie_secure'] = '0';
    }
    foreach ($ini as $key => $val) {
        @ini_set($key, $val);
    }
}

/**
 * Regenerate the session id (keeps data). Call after login / privilege change.
 */
function rotate_session(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    session_regenerate_id(true);
}

/*───────────────────────────── CSRF / bot protection ─────────────────────────────*/

/**
 * Generate a CSRF token and store it in the session.
 */
function csrf_token(): string
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
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
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
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
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    return true;
}

/*───────────────────────────── rate limiting ─────────────────────────────*/

/**
 * Ensure the rate_limit_log table exists. Call once during bootstrap.
 */
function rate_limit_ensure_table(): void
{
    $pdo = db();
    try {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS rate_limit_log (" .
            "id SERIAL PRIMARY KEY, " .
            "key_hash CHAR(64) NOT NULL, " .
            "created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP" .
            ")"
        );
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_rate_limit_key_time ON rate_limit_log(key_hash, created_at)");
    } catch (Throwable $e) {
        // Table may already exist; ignore and proceed
    }
}

/**
 * Database-backed rate limiter. Keyed by identifier + action.
 * Limits: $maxAttempts within $windowSeconds.
 * Returns ['allowed' => bool, 'remaining' => int, 'retry_after' => int].
 */
function rate_limit(string $key, int $maxAttempts, int $windowSeconds): array
{
    $pdo = db();
    $now = time();
    $windowStart = $now - $windowSeconds;

    $keyHash = hash('sha256', $key);

    // Clean old entries for this key + count in one pass
    $stmt = $pdo->prepare(
        "DELETE FROM rate_limit_log WHERE key_hash = :kh AND created_at <= (:ws)::TIMESTAMP"
    );
    $stmt->execute([
        ':kh' => $keyHash,
        ':ws' => date('Y-m-d H:i:s', $windowStart),
    ]);

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM rate_limit_log WHERE key_hash = :kh");
    $stmt->execute([':kh' => $keyHash]);
    $count = (int) $stmt->fetchColumn();

    if ($count >= $maxAttempts) {
        // retry_after = when the maxAttempts-th oldest entry expires (count drops below the limit)
        $stmt = $pdo->prepare(
            "SELECT EXTRACT(EPOCH FROM (created_at + INTERVAL '{$windowSeconds} seconds' - NOW()))::int AS retry_after
             FROM rate_limit_log WHERE key_hash = :kh
             ORDER BY created_at ASC LIMIT 1 OFFSET :off"
        );
        $stmt->execute([':kh' => $keyHash, ':off' => $maxAttempts - 1]);
        $row = $stmt->fetch();
        $retryAfter = $row ? max(0, (int) ($row['retry_after'] ?? 0)) : $windowSeconds;
        return ['allowed' => false, 'remaining' => 0, 'retry_after' => $retryAfter];
    }

    $pdo->prepare(
        "INSERT INTO rate_limit_log (key_hash, created_at) VALUES (:kh, NOW())"
    )->execute([':kh' => $keyHash]);

    return ['allowed' => true, 'remaining' => $maxAttempts - $count - 1, 'retry_after' => 0];
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
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
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
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
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
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
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
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    return !empty($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
}

/**
 * Return the current user role from session, or ''.
 */
function current_user_role(): string
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
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
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
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
