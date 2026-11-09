<?php
// src/Repositories/ApplicantRepository.php — DB reads for the HR Applicant tables.
// Archive = soft-delete: deleted_at IS NULL -> Screening List, deleted_at IS NOT NULL -> Archive.

require_once __DIR__ . '/../Helpers/db.php';
require_once __DIR__ . '/../Helpers/display.php';
require_once __DIR__ . '/ActivityRepository.php';

function repo_applicants_active(?PDO $pdo = null): array
{
    $pdo = $pdo ?: db();
    $rows = $pdo->query(
        "SELECT applicant_id, name, email, phone_number, status, result, created_at" .
        " FROM applicant WHERE deleted_at IS NULL ORDER BY applicant_id ASC"
    )->fetchAll();
    return array_map('applicant_to_display', $rows);
}

function repo_generate_reference_code(PDO $pdo): string
{
    $year = date('Y');
    $n = (int) $pdo->query("SELECT COUNT(*) FROM applicant")->fetchColumn() + 1;
    for ($i = 0; $i < 20; $i++) {
        $code = sprintf('APP-%s-%04d', $year, $n + $i);
        $st = $pdo->prepare("SELECT 1 FROM applicant WHERE reference_code = :c LIMIT 1");
        $st->execute([':c' => $code]);
        if (!$st->fetch()) return $code;
    }
    return sprintf('APP-%s-%s', $year, strtoupper(substr(bin2hex(random_bytes(4)), 0, 8)));
}

// ---------------------------------------------------------------------------
// "One CV, one name" + "applicant joins the system only after OTP"
//   applicant.verified_at IS NULL      -> invitation only (shown in Screening List as "Pending")
//   applicant.verified_at IS NOT NULL  -> real applicant (set by repo_mark_verified on OTP success)
// repo_applicants_active() no longer filters on verified_at, so invitations
// appear in the Screening List with status "Pending" until the applicant verifies.
// Other HR-facing reads (dashboard stats, recent applicants, archive) still
// filter on verified_at IS NOT NULL unless noted otherwise.
// ---------------------------------------------------------------------------

/** How long an unverified invitation (and its email link) stays usable. */
const INVITE_LIFETIME_DAYS = 7;

/** Raised when a name or CV already belongs to another applicant. $field is 'name' or 'cv'. */
class DuplicateApplicantException extends InvalidArgumentException
{
    public string $field;
    public function __construct(string $message, string $field)
    {
        parent::__construct($message);
        $this->field = $field;
    }
}

/**
 * Canonical form of an email, used to compare addresses consistently.
 * Lowercase + trim; for Gmail also ignores dots and "+tag", so
 * Juan.Cruz+test@gmail.com and juancruz@gmail.com count as the SAME address.
 */
function repo_normalize_email(string $email): string
{
    $email = strtolower(trim($email));
    $at = strrpos($email, '@');
    if ($at === false) return $email;
    $local = substr($email, 0, $at);
    $domain = substr($email, $at + 1);
    if ($domain === 'googlemail.com') $domain = 'gmail.com';
    if ($domain === 'gmail.com') {
        $local = explode('+', $local, 2)[0];
        $local = str_replace('.', '', $local);
    }
    return $local . '@' . $domain;
}

/** One-line full name from the three parts (middle name may be empty). */
function repo_compose_name(string $first, string $middle, string $last): string
{
    return trim((string) preg_replace('/\s+/u', ' ', trim($first) . ' ' . trim($middle) . ' ' . trim($last)));
}

/**
 * Canonical form of a full name, used for the "one person, one applicant" rule.
 * Lowercase, dots/commas removed, single spaces. The first/middle/last boundary is ignored
 * on purpose: "Maria Angelica" + "Reyes" equals "Maria" + "Angelica Reyes".
 */
function repo_normalize_name(string $fullName): string
{
    $n = mb_strtolower(trim($fullName), 'UTF-8');
    $n = (string) preg_replace('/[.,]/u', '', $n);
    return trim((string) preg_replace('/\s+/u', ' ', $n));
}

/** SHA-256 of the CV file contents (identical file = identical hash). */
function repo_cv_hash(string $filePath): ?string
{
    $h = is_file($filePath) ? @hash_file('sha256', $filePath) : false;
    return $h !== false ? $h : null;
}

/**
 * Throws DuplicateApplicantException when the name or CV already belongs to a
 * VERIFIED applicant (active or archived). Unverified invitations never block:
 * repo_add_applicant replaces matching names or CVs.
 */
function repo_assert_not_duplicate(?string $cvHash, ?PDO $pdo = null, string $fullName = ''): void
{
    $pdo = $pdo ?: db();

    $nameKey = repo_normalize_name($fullName);
    if ($nameKey !== '') {
        $st = $pdo->prepare("SELECT deleted_at FROM applicant WHERE name_normalized = :n AND verified_at IS NOT NULL LIMIT 1");
        $st->execute([':n' => $nameKey]);
        $row = $st->fetch();
        if ($row) {
            throw new DuplicateApplicantException(
                'An applicant named "' . trim($fullName) . '" already exists'
                . (!empty($row['deleted_at']) ? ' (currently in the Archive: restore them instead of adding again).' : '.')
                . ' Each applicant can only be added once.',
                'name'
            );
        }
    }

    if ($cvHash !== null && $cvHash !== '') {
        $st = $pdo->prepare("SELECT deleted_at FROM applicant WHERE cv_hash = :h AND verified_at IS NOT NULL LIMIT 1");
        $st->execute([':h' => $cvHash]);
        $row = $st->fetch();
        if ($row) {
            throw new DuplicateApplicantException(
                'This CV was already uploaded for another applicant'
                . (!empty($row['deleted_at']) ? ' (currently in the Archive).' : '.')
                . ' Each CV can only be used once.',
                'cv'
            );
        }
    }
}

/** Remove invitations nobody verified within INVITE_LIFETIME_DAYS (keeps the table lean). */
function repo_purge_stale_invitations(?PDO $pdo = null): int
{
    $pdo = $pdo ?: db();
    $st = $pdo->prepare(
        "DELETE FROM applicant WHERE verified_at IS NULL AND created_at < NOW() - (:d || ' days')::INTERVAL"
    );
    $st->bindValue(':d', (string) INVITE_LIFETIME_DAYS, PDO::PARAM_STR);
    $st->execute();
    return $st->rowCount();
}

/**
 * The applicant just verified their OTP: this is the moment they enter the system.
 * Returns true only the first time (so callers can log it once).
 */
function repo_mark_verified(int $id, ?PDO $pdo = null): bool
{
    $pdo = $pdo ?: db();
    $st = $pdo->prepare(
        "UPDATE applicant SET verified_at = NOW() WHERE applicant_id = :id AND verified_at IS NULL AND deleted_at IS NULL"
    );
    $st->execute([':id' => $id]);
    return $st->rowCount() > 0;
}

/**
 * Create an INVITATION (Add Applicant page). The row is hidden from HR until
 * the applicant verifies the OTP (repo_mark_verified).
 *
 * Rules (checked here and enforced again by the unique indexes):
 *   - one person -> one applicant (same full name; case, spaces, dots and commas ignored)
 *   - one CV     -> one applicant (SHA-256 of the file)
 * An older UNVERIFIED invitation with the same name or CV is replaced, so HR can
 * fix a typo or resend an invite that never arrived. Call inside a transaction.
 */
function repo_add_applicant(string $first, string $middle, string $last, string $email, string $phone, ?PDO $pdo = null, ?string $cvUrl = null, ?string $cvHash = null): array
{
    $pdo = $pdo ?: db();

    $first = trim((string) preg_replace('/\s+/u', ' ', $first));
    $middle = trim((string) preg_replace('/\s+/u', ' ', $middle));
    $last = trim((string) preg_replace('/\s+/u', ' ', $last));
    $email = trim($email);
    $phone = trim($phone);
    $partOk = fn(string $v) => preg_match('/^[\p{L}\p{M}\'.\- ]+$/u', $v) && preg_match_all('/\p{L}/u', $v) >= 2 && mb_strlen($v) <= 100;
    if ($first === '' || !$partOk($first)) throw new InvalidArgumentException('Please enter a valid first name (letters only, at least 2).');
    if ($middle !== '' && !preg_match('/^[\p{L}\p{M}\'.\- ]+$/u', $middle)) throw new InvalidArgumentException('The middle name can only have letters.');
    if ($last === '' || !$partOk($last)) throw new InvalidArgumentException('Please enter a valid last name (letters only, at least 2).');
    $name = repo_compose_name($first, $middle, $last);
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new InvalidArgumentException('Enter a valid email address.');
    if ($phone === '') throw new InvalidArgumentException('Please enter the contact number.');

    repo_purge_stale_invitations($pdo);
    repo_assert_not_duplicate($cvHash, $pdo, $name);

    // Replace any older unverified invitation for this name / CV.
    $norm = repo_normalize_email($email);
    $nameKey = repo_normalize_name($name);
    if ($cvHash !== null && $cvHash !== '') {
        $st = $pdo->prepare("DELETE FROM applicant WHERE verified_at IS NULL AND (name_normalized = :n OR cv_hash = :h)");
        $st->execute([':n' => $nameKey, ':h' => $cvHash]);
    } else {
        $st = $pdo->prepare("DELETE FROM applicant WHERE verified_at IS NULL AND name_normalized = :n");
        $st->execute([':n' => $nameKey]);
    }

    $ref = repo_generate_reference_code($pdo);
    for ($i = 0; $i < 5; $i++) {
        $token = bin2hex(random_bytes(32));
        $st = $pdo->prepare("SELECT 1 FROM applicant WHERE secure_token = :t LIMIT 1");
        $st->execute([':t' => $token]);
        if (!$st->fetch()) break;
    }

    $st = $pdo->prepare(
        "INSERT INTO applicant (reference_code, secure_token, encoded_by_user_id, name, first_name, middle_name, last_name, name_normalized, email, email_normalized, phone_number, cv_file_url, cv_hash, status, invited_at)" .
        " VALUES (:ref, :tok, :enc, :name, :first, :middle, :last, :nkey, :email, :norm, :phone, :cv, :hash, 'pending', NOW()) RETURNING applicant_id"
    );
    $enc = null;
    if (session_status() === PHP_SESSION_ACTIVE && isset($_SESSION['user_id'])) $enc = (int) $_SESSION['user_id'];
    try {
        $st->execute([
            ':ref' => $ref, ':tok' => $token, ':enc' => $enc, ':name' => $name, ':first' => $first, ':middle' => $middle, ':last' => $last,
            ':nkey' => $nameKey, ':email' => $email, ':norm' => $norm, ':phone' => $phone, ':cv' => $cvUrl, ':hash' => $cvHash,
        ]);
    } catch (PDOException $e) {
        // Two HR users submitting at the same moment: the unique index is the final guard.
        if ((string) $e->getCode() === '23505') {
            $msg = $e->getMessage();
            if (stripos($msg, 'cv_hash') !== false) {
                throw new DuplicateApplicantException('This CV was already uploaded for another applicant. Each CV can only be used once.', 'cv');
            }
            if (stripos($msg, 'name_normalized') !== false) {
                throw new DuplicateApplicantException('An applicant named "' . $name . '" already exists. Each applicant can only be added once.', 'name');
            }
            throw $e;
        }
        throw $e;
    }
    $id = (int) $st->fetchColumn();

    return ['id' => $id, 'name' => $name, 'email' => $email, 'contact' => $phone, 'status' => 'Pending', 'result' => null, 'cv_file_url' => $cvUrl, 'secure_token' => $token];
}

function repo_find_applicant(int $id, ?PDO $pdo = null): ?array
{
    $pdo = $pdo ?: db();
    $st = $pdo->prepare(
        "SELECT applicant_id, name, email, phone_number, status, result, reference_code, secure_token, cv_file_url, created_at" .
        " FROM applicant WHERE applicant_id = :id AND deleted_at IS NULL LIMIT 1"
    );
    $st->execute([':id' => $id]);
    $row = $st->fetch();
    if (!$row) return null;
    $d = applicant_to_display($row);
    $d['reference_code'] = (string) ($row['reference_code'] ?? '');
    $d['secure_token'] = (string) ($row['secure_token'] ?? '');
    $d['cv_file_url'] = $row['cv_file_url'] ?? null;
    $d['updated'] = $row['created_at'] ? date('Y-m-d H:i', strtotime((string) $row['created_at'])) : '';
    $d['created_at'] = (string) ($row['created_at'] ?? '');
    return $d;
}

/**
 * Find an applicant by their email-link token (opening.php?token=...).
 * Only non-deleted rows; archived applicants cannot start screening.
 */
function repo_find_applicant_by_token(string $token, ?PDO $pdo = null): ?array
{
    $pdo = $pdo ?: db();
    $token = trim($token);
    if ($token === '') return null;
    $st = $pdo->prepare(
        "SELECT applicant_id, name, email, phone_number, status, result, reference_code, secure_token, cv_file_url, created_at" .
        " FROM applicant WHERE secure_token = :t AND deleted_at IS NULL" .
        " AND (verified_at IS NOT NULL OR created_at > NOW() - INTERVAL '" . INVITE_LIFETIME_DAYS . " days') LIMIT 1"
    );
    $st->execute([':t' => $token]);
    $row = $st->fetch();
    if (!$row) return null;
    $d = applicant_to_display($row);
    $d['reference_code'] = (string) ($row['reference_code'] ?? '');
    $d['secure_token'] = (string) ($row['secure_token'] ?? '');
    $d['cv_file_url'] = $row['cv_file_url'] ?? null;
    $d['updated'] = $row['created_at'] ? date('Y-m-d H:i', strtotime((string) $row['created_at'])) : '';
    $d['created_at'] = (string) ($row['created_at'] ?? '');
    return $d;
}

/**
 * Find an applicant by email address only when the address identifies
 * exactly one active, verified applicant.
 */
function repo_find_applicant_by_email(string $email, ?PDO $pdo = null): ?array
{
    $pdo = $pdo ?: db();
    $email = repo_normalize_email($email);
    if ($email === '') return null;
    $st = $pdo->prepare(
        "SELECT applicant_id, name, email, phone_number, status, result, reference_code, secure_token, cv_file_url, created_at" .
        " FROM applicant WHERE email_normalized = :e AND deleted_at IS NULL LIMIT 2"
    );
    $st->execute([':e' => $email]);
    $rows = $st->fetchAll();
    if (count($rows) !== 1) return null;
    $row = $rows[0];
    $d = applicant_to_display($row);
    $d['reference_code'] = (string) ($row['reference_code'] ?? '');
    $d['secure_token'] = (string) ($row['secure_token'] ?? '');
    $d['cv_file_url'] = $row['cv_file_url'] ?? null;
    $d['updated'] = $row['created_at'] ? date('Y-m-d H:i', strtotime((string) $row['created_at'])) : '';
    $d['created_at'] = (string) ($row['created_at'] ?? '');
    return $d;
}

/**
 * True once the applicant has SENT their screening (questionnaire + video): status is
 * submitted / reviewed / accepted / rejected, or a result already exists.
 * The database is the source of truth, so this still works after the browser session is lost.
 */
function repo_has_submitted(int $id, ?PDO $pdo = null): bool
{
    if ($id <= 0) return false;
    $pdo = $pdo ?: db();
    $st = $pdo->prepare("SELECT status, result FROM applicant WHERE applicant_id = :id AND deleted_at IS NULL LIMIT 1");
    $st->execute([':id' => $id]);
    $row = $st->fetch();
    if (!$row) return false;
    if (in_array(strtolower((string) $row['status']), ['submitted', 'reviewed', 'accepted', 'rejected'], true)) return true;
    return trim((string) ($row['result'] ?? '')) !== '';
}

/**
 * Mark an applicant In Progress when they open the questionnaire
 * (Screening.startOnce). No-op for other statuses.
 */
function repo_mark_in_progress(int $id, ?PDO $pdo = null): int
{
    $pdo = $pdo ?: db();
    $st = $pdo->prepare("UPDATE applicant SET status = 'in_progress' WHERE applicant_id = :id AND status = 'pending' AND deleted_at IS NULL AND verified_at IS NOT NULL");
    $st->execute([':id' => $id]);
    return $st->rowCount();
}

/**
 * Archive (soft-delete) applicants: Screening List Delete -> Archive page.
 * Sets deleted_at = NOW(); archived rows disappear from the list, appear in Archive.
 * Returns number of rows actually archived.
 */
function repo_archive_applicants(array $ids, ?PDO $pdo = null): int
{
    $pdo = $pdo ?: db();
    $ids = array_values(array_unique(array_map('intval', $ids)));
    $ids = array_filter($ids, fn($i) => $i > 0);
    if (!$ids) return 0;
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $st = $pdo->prepare("UPDATE applicant SET deleted_at = NOW() WHERE applicant_id IN ($ph) AND deleted_at IS NULL");
    $st->execute($ids);
    return $st->rowCount();
}

/**
 * Restore archived applicants: Archive Restore -> back to Screening List.
 */
function repo_restore_applicants(array $ids, ?PDO $pdo = null): int
{
    $pdo = $pdo ?: db();
    $ids = array_values(array_unique(array_map('intval', $ids)));
    $ids = array_filter($ids, fn($i) => $i > 0);
    if (!$ids) return 0;
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $st = $pdo->prepare("UPDATE applicant SET deleted_at = NULL WHERE applicant_id IN ($ph) AND deleted_at IS NOT NULL");
    $st->execute($ids);
    return $st->rowCount();
}

/**
 * Delete forever: permanently removes ARCHIVED applicants (deleted_at IS NOT NULL).
 * Only archived rows are deleted — active Screening List rows are never touched,
 * so an applicant must be archived first. Child rows (otp, response, schedule,
 * score via response) are removed automatically by ON DELETE CASCADE.
 * Returns number of rows actually deleted.
 */
function repo_purge_applicants(array $ids, ?PDO $pdo = null): int
{
    $pdo = $pdo ?: db();
    $ids = array_values(array_unique(array_map('intval', $ids)));
    $ids = array_filter($ids, fn($i) => $i > 0);
    if (!$ids) return 0;
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $st = $pdo->prepare("DELETE FROM applicant WHERE applicant_id IN ($ph) AND deleted_at IS NOT NULL");
    $st->execute($ids);
    return $st->rowCount();
}

/**
 * Auto-expiry: permanently removes applicants archived longer than $days days.
 * Runs on the Archive page load and after every archive-action call, so the
 * "stays here for 30 days before they are permanently deleted" promise holds.
 * Returns number of rows removed.
 */
function repo_purge_expired_applicants(int $days = 30, ?PDO $pdo = null): int
{
    $pdo = $pdo ?: db();
    $days = max(1, $days);
    $st = $pdo->prepare("DELETE FROM applicant WHERE deleted_at IS NOT NULL AND deleted_at < NOW() - (:days || ' days')::INTERVAL");
    $st->bindValue(':days', (string) $days, PDO::PARAM_STR);
    $st->execute();
    return $st->rowCount();
}

/**
 * Set an applicant's screening status and result (Passed / Rejected).
 * Only a Completed screening may carry a non-null result.
 * Returns true if a row was updated.
 */
function repo_set_status(int $id, string $status, ?string $result = null): bool
{
    $pdo = db();
    $valid = ['pending', 'in_progress', 'completed'];
    $s = strtolower(trim($status));
    if (!in_array($s, $valid, true)) return false;

    // Only Completed may carry a result; store result lowercase like the status column
    if ($s !== 'completed' || !in_array(strtolower((string) ($result ?? '')), ['passed', 'rejected'], true)) {
        $result = null;
    } else {
        $result = strtolower((string) $result);
    }

    $st = $pdo->prepare(
        "UPDATE applicant SET status = :status, result = :result" .
        ("$s" === 'completed' ? ", submitted_at = COALESCE(submitted_at, NOW()), passed_at = :pass" : "") .
        " WHERE applicant_id = :id AND deleted_at IS NULL"
    );
    $params = [
        ':id' => $id,
        ':status' => $s,
        ':result' => $result,
    ];
    if ("$s" === 'completed') $params[':pass'] = $result === 'passed' ? (new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))->format('Y-m-d H:i:s') : null;
    $st->execute($params);
    return $st->rowCount() > 0;
}

function repo_applicants_archived(?PDO $pdo = null): array
{
    $pdo = $pdo ?: db();
    $rows = $pdo->query(
        "SELECT applicant_id, name, email, phone_number, status, result, deleted_at, created_at" .
        " FROM applicant WHERE deleted_at IS NOT NULL AND verified_at IS NOT NULL ORDER BY deleted_at DESC"
    )->fetchAll();
    $out = [];
    foreach ($rows as $r) {
        $d = applicant_to_display($r);
        $d['archived_at'] = $r['deleted_at'] ? date('c', strtotime((string) $r['deleted_at'])) : null;
        $out[] = $d;
    }
    return $out;
}

function repo_dashboard_stats(?PDO $pdo = null): array
{
    $pdo = $pdo ?: db();
    $rows = $pdo->query(
        "SELECT LOWER(status) AS s, LOWER(COALESCE(result,'')) AS r, COUNT(*) AS c" .
        " FROM applicant WHERE deleted_at IS NULL AND verified_at IS NOT NULL GROUP BY 1, 2"
    )->fetchAll();

    $stats = ['total' => 0, 'pending' => 0, 'in_progress' => 0, 'completed' => 0, 'passed' => 0, 'failed' => 0];
    foreach ($rows as $row) {
        $n = (int) $row['c'];
        $stats['total'] += $n;
        $display = applicant_to_display(['applicant_id' => 0, 'name' => '', 'email' => '', 'phone_number' => '', 'status' => $row['s'], 'result' => $row['r']]);
        if ($display['status'] === 'Pending') $stats['pending'] += $n;
        elseif ($display['status'] === 'In Progress') $stats['in_progress'] += $n;
        else $stats['completed'] += $n;
        if ($display['result'] === 'Passed') $stats['passed'] += $n;
        elseif ($display['result'] === 'Rejected') $stats['failed'] += $n;
    }
    return $stats;
}

/**
 * Recent Applicants for the HR dashboard: newest created first, with a display 'updated' label.
 * (No updated_at column exists; created_at is the authoritative timestamp.)
 */
function repo_applicants_recent(?PDO $pdo = null, int $limit = 10): array
{
    $pdo = $pdo ?: db();
    $st = $pdo->prepare(
        "SELECT applicant_id, name, email, phone_number, status, result, created_at" .
        " FROM applicant WHERE deleted_at IS NULL AND verified_at IS NOT NULL ORDER BY verified_at DESC, applicant_id DESC LIMIT :lim"
    );
    $st->bindValue(':lim', $limit, PDO::PARAM_INT);
    $st->execute();
    $out = [];
    foreach ($st->fetchAll() as $r) {
        $d = applicant_to_display($r);
        $d['updated'] = $r['created_at'] ? date('Y-m-d H:i', strtotime((string) $r['created_at'])) : '';
        $out[] = $d;
    }
    return $out;
}
