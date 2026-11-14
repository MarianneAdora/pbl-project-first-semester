<?php
// src/Repositories/ApplicantRepository.php — DB reads for the HR Applicant tables.
// Archive = soft-delete: deleted_at IS NULL -> Screening List, deleted_at IS NOT NULL -> Archive.

require_once __DIR__ . '/../Helpers/db.php';
require_once __DIR__ . '/../Helpers/display.php';
require_once __DIR__ . '/ActivityRepository.php';
require_once __DIR__ . '/../Services/CloudinaryService.php';

function repo_applicants_active(?PDO $pdo = null): array
{
    $pdo = $pdo ?: db();
    $rows = $pdo->query(
        "SELECT applicant_id, name, email, phone_number, status, result, created_at" .
        " FROM applicant WHERE deleted_at IS NULL ORDER BY applicant_id DESC"
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
// "One CV, one email" + "applicant joins the system only after OTP"
//   applicant.verified_at IS NULL      -> invitation only (hidden from every HR list/count)
//   applicant.verified_at IS NOT NULL  -> real applicant (set by repo_mark_verified on OTP success)
// Every HR-facing read below filters on verified_at IS NOT NULL.
// ---------------------------------------------------------------------------

/** How long an unverified invitation (and its email link) stays usable. */
const INVITE_LIFETIME_DAYS = 7;

/** Raised when the email or CV already belongs to another applicant. $field is 'email' or 'cv'. */
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
 * Canonical form of an email, used for the uniqueness rule.
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
 * Throws DuplicateApplicantException when the email or CV already belongs to a
 * VERIFIED applicant (active or archived). Unverified invitations never block:
 * repo_add_applicant replaces them.
 */
function repo_assert_not_duplicate(string $email, ?string $cvHash, ?PDO $pdo = null, string $fullName = ''): void
{
    $pdo = $pdo ?: db();

    $st = $pdo->prepare(
        "SELECT deleted_at FROM applicant WHERE email_normalized = :e AND verified_at IS NOT NULL LIMIT 1"
    );
    $st->execute([':e' => repo_normalize_email($email)]);
    $row = $st->fetch();
    if ($row) {
        throw new DuplicateApplicantException(
            'This email address already belongs to an applicant'
            . (!empty($row['deleted_at']) ? ' (currently in the Archive: restore them instead of adding again).' : '.')
            . ' Each email can only be used once.',
            'email'
        );
    }

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
 *   - one email  -> one applicant (Gmail dots/+tags ignored)
 *   - one CV     -> one applicant (SHA-256 of the file)
 * An older UNVERIFIED invitation with the same email or CV is replaced, so HR can
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
    repo_assert_not_duplicate($email, $cvHash, $pdo, $name);

    // Replace any older unverified invitation for this email / CV.
    $norm = repo_normalize_email($email);
    $nameKey = repo_normalize_name($name);
    if ($cvHash !== null && $cvHash !== '') {
        $st = $pdo->prepare("DELETE FROM applicant WHERE verified_at IS NULL AND (email_normalized = :e OR name_normalized = :n OR cv_hash = :h)");
        $st->execute([':e' => $norm, ':n' => $nameKey, ':h' => $cvHash]);
    } else {
        $st = $pdo->prepare("DELETE FROM applicant WHERE verified_at IS NULL AND (email_normalized = :e OR name_normalized = :n)");
        $st->execute([':e' => $norm, ':n' => $nameKey]);
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
            throw new DuplicateApplicantException('This email address already belongs to an applicant. Each email can only be used once.', 'email');
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
        "SELECT applicant_id, name, email, phone_number, status, result, reference_code, secure_token, cv_file_url, position_id, created_at" .
        " FROM applicant WHERE applicant_id = :id AND deleted_at IS NULL AND verified_at IS NOT NULL LIMIT 1"
    );
    $st->execute([':id' => $id]);
    $row = $st->fetch();
    if (!$row) return null;
    $d = applicant_to_display($row);
    $d['reference_code'] = (string) ($row['reference_code'] ?? '');
    $d['secure_token'] = (string) ($row['secure_token'] ?? '');
    $d['cv_file_url'] = $row['cv_file_url'] ?? null;
    $d['position_id'] = isset($row['position_id']) ? (int) $row['position_id'] : null;
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
 * Find an applicant by email address.
 * Only non-deleted rows.
 */
function repo_find_applicant_by_email(string $email, ?PDO $pdo = null): ?array
{
    $pdo = $pdo ?: db();
    $email = repo_normalize_email($email);
    if ($email === '') return null;
    $st = $pdo->prepare(
        "SELECT applicant_id, name, email, phone_number, status, result, reference_code, secure_token, cv_file_url, position_id, created_at" .
        " FROM applicant WHERE email_normalized = :e AND deleted_at IS NULL AND verified_at IS NOT NULL LIMIT 1"
    );
    $st->execute([':e' => $email]);
    $row = $st->fetch();
    if (!$row) return null;
    $d = applicant_to_display($row);
    $d['reference_code'] = (string) ($row['reference_code'] ?? '');
    $d['secure_token'] = (string) ($row['secure_token'] ?? '');
    $d['cv_file_url'] = $row['cv_file_url'] ?? null;
    $d['updated'] = $row['created_at'] ? date('Y-m-d H:i', strtotime((string) $row['created_at'])) : '';
    $d['created_at'] = (string) ($row['created_at'] ?? '');
    $d['position_id'] = isset($row['position_id']) ? (int) $row['position_id'] : null;
    return $d;
}

/**
 * The job description PDF (Cloudinary URL) of the position this applicant was added under.
 * Returns '' when the applicant has no position, the position is archived, or it has no PDF.
 */
function repo_applicant_jd_url(int $positionId, ?PDO $pdo = null): string
{
    if ($positionId <= 0) return '';
    $pdo = $pdo ?: db();
    $read = function () use ($pdo, $positionId): string {
        $st = $pdo->prepare("SELECT jd_url FROM job_positions WHERE id = :id AND archived_at IS NULL LIMIT 1");
        $st->execute([':id' => $positionId]);
        return trim((string) ($st->fetchColumn() ?: ''));
    };
    $url = $read();
    if ($url === '') {                                  // not linked yet: look in the Cloudinary folder
        try { if (repo_sync_position_jds($pdo) > 0) $url = $read(); } catch (Throwable $e) {}
    }
    return $url;
}

/** "Data Analyst Job Position" / "data-analyst-job-position_ab12cd.pdf" / "jd-5-Data-Analyst" -> "dataanalyst" */
function repo_jd_key(string $s): string
{
    $s = strtolower(trim($s));
    $s = preg_replace('/\.pdf$/', '', $s);
    $s = preg_replace('/^jd-\d+-/', '', $s);
    $s = preg_replace('/[^a-z0-9]+/', ' ', $s);
    $s = preg_replace('/\b(job|position|description|descriptions|jd|pdf|for|the)\b/', ' ', $s);
    return preg_replace('/\s+/', '', $s);
}

/**
 * Makes Cloudinary the source of truth for "has a PDF": every active position that has no stored PDF
 * is matched against the PDFs in the ebsmi-job-position-pdfs folder (by file name) and, when one is found,
 * its Cloudinary URL + public_id are saved on the position. Returns how many positions were linked.
 * Only runs when some position lacks a PDF, and at most once a minute per session.
 */
function repo_sync_position_jds(?PDO $pdo = null, bool $force = false): int
{
    $pdo = $pdo ?: db();
    $rows = $pdo->query("SELECT id, name FROM job_positions WHERE archived_at IS NULL AND (jd_url IS NULL OR jd_url = '') ORDER BY id")->fetchAll();
    if (!$rows) return 0;

    if (!$force && session_status() === PHP_SESSION_ACTIVE) {
        if (isset($_SESSION['jd_sync_at']) && time() - (int) $_SESSION['jd_sync_at'] < 60) return 0;
        $_SESSION['jd_sync_at'] = time();
    }

    require_once __DIR__ . '/../Services/CloudinaryService.php';
    $assets = (new CloudinaryService())->listJobDescriptions('ebsmi-job-position-pdfs');
    if (!$assets) return 0;                             // unreachable or nothing there: change nothing

    $linked = 0;
    $taken  = [];
    $upd = $pdo->prepare("UPDATE job_positions SET jd_url = :u, jd_public_id = :p WHERE id = :id AND (jd_url IS NULL OR jd_url = '')");
    foreach ($rows as $row) {
        $key = repo_jd_key((string) $row['name']);
        if (strlen($key) < 3) continue;
        $best = null; $bestScore = 0; $tie = false;
        foreach ($assets as $i => $a) {
            if (isset($taken[$i])) continue;
            $ak = repo_jd_key($a['name'] !== '' ? $a['name'] : $a['public_id']);
            if ($ak === '') continue;
            $score = $ak === $key ? 3 : (strpos($ak, $key) === 0 ? 2 : (strpos($ak, $key) !== false ? 1 : 0));
            if ($score === 0) continue;
            if ($score > $bestScore)      { $best = $i; $bestScore = $score; $tie = false; }
            elseif ($score === $bestScore) { $tie = true; }
        }
        if ($best === null || $tie) continue;           // no match, or ambiguous: leave it as "No PDF"
        $upd->execute([':u' => $assets[$best]['url'], ':p' => $assets[$best]['public_id'], ':id' => (int) $row['id']]);
        $taken[$best] = true;
        $linked += $upd->rowCount();
    }
    return $linked;
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
 * Extract the Cloudinary public_id (folder + filename, no extension) from a
 * Cloudinary secure_url. Returns null when the URL is not a Cloudinary URL.
 */
function cloudinary_public_id_from_url(string $url): ?string
{
    if ($url === '') return null;
    if (!preg_match('#/upload(?:/[a-z]+)?/v\d+/(.+)$#', $url, $m)) return null;
    $publicId = $m[1];
    $publicId = preg_replace('/\?.*$/', '', $publicId);
    $publicId = preg_replace('/\.[^.]+$/', '', $publicId);
    return $publicId !== '' ? $publicId : null;
}

/**
 * Delete an applicant's CV and video files from Cloudinary, without touching
 * the database metadata (cv_hash, video_file_hash, answers_json are retained).
 * Called before archiving or purging so media files are removed from storage.
 */
function repo_delete_applicant_media(array $ids, ?PDO $pdo = null): void
{
    $pdo = $pdo ?: db();
    if (!$ids) return;

    try {
        $cloud = new CloudinaryService();
    } catch (Throwable $e) {
        return; // Cloudinary not configured: nothing to delete
    }

    $ph = implode(',', array_fill(0, count($ids), '?'));
    $idVals = array_values($ids);

    // CV files from applicant table
    $st = $pdo->prepare(
        "SELECT applicant_id, cv_file_url FROM applicant" .
        " WHERE applicant_id IN ($ph) AND cv_file_url IS NOT NULL AND cv_file_url <> ''"
    );
    $st->execute($idVals);
    while ($row = $st->fetch()) {
        $publicId = cloudinary_public_id_from_url((string) $row['cv_file_url']);
        if ($publicId !== null) {
            // Uploads post to /auto/upload with no explicit type, so Cloudinary stores them
            // under its default type (upload). Try the common types so deletion works
            // regardless of how the file was originally uploaded.
            foreach (['upload', 'private', 'default'] as $type) {
                try { $cloud->deleteResource($publicId, 'auto', $type); } catch (Throwable $ignored) {}
            }
        }
    }

    // Video files from response table
    $st = $pdo->prepare(
        "SELECT applicant_id, video_file_url FROM response" .
        " WHERE applicant_id IN ($ph) AND video_file_url IS NOT NULL AND video_file_url <> ''"
    );
    $st->execute($idVals);
    while ($row = $st->fetch()) {
        $publicId = cloudinary_public_id_from_url((string) $row['video_file_url']);
        if ($publicId !== null) {
            foreach (['upload', 'default', 'private'] as $type) {
                try { $cloud->deleteResource($publicId, 'video', $type); } catch (Throwable $ignored) {}
            }
        }
    }
}

/**
 * Archive (soft-delete) applicants: Screening List Delete -> Archive page.
 * Sets deleted_at = NOW(); archived rows disappear from the list, appear in Archive.
 * Also removes their CV and video files from Cloudinary (keeps cv_hash,
 * video_file_hash and answers_json in the database).
 * Returns number of rows actually archived.
 */
function repo_archive_applicants(array $ids, ?PDO $pdo = null): int
{
    $pdo = $pdo ?: db();
    $ids = array_values(array_unique(array_map('intval', $ids)));
    $ids = array_filter($ids, fn($i) => $i > 0);
    if (!$ids) return 0;
    repo_delete_applicant_media($ids, $pdo);
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
    repo_delete_applicant_media($ids, $pdo);
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
    $st = $pdo->prepare(
        "SELECT applicant_id FROM applicant WHERE deleted_at IS NOT NULL AND deleted_at < NOW() - (:days || ' days')::INTERVAL"
    );
    $st->bindValue(':days', (string) $days, PDO::PARAM_STR);
    $st->execute();
    $ids = array_values(array_filter(array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN)), fn($i) => $i > 0));
    if ($ids) {
        repo_delete_applicant_media($ids, $pdo);
    }
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

    // Auto-archive rejected (failed) applicants: they leave the Screening List and
    // stay in the Archive for 30 days before permanent deletion (purge runs on
    // archive page load and every archive-action call).
    if ($s === 'completed' && $result === 'rejected' && $st->rowCount() > 0) {
        try { repo_archive_applicants([$id], $pdo); } catch (Throwable $ignored) {}
    }

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