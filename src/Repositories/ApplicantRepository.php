<?php
// src/Repositories/ApplicantRepository.php — DB reads for the HR Applicant tables.
// Archive = soft-delete: deleted_at IS NULL -> Screening List, deleted_at IS NOT NULL -> Archive.

require_once __DIR__ . '/../Helpers/db.php';
require_once __DIR__ . '/../Helpers/display.php';
require_once __DIR__ . '/ActivityRepository.php';

function repo_applicants_active(PDO $pdo = null): array
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

/**
 * Insert a new applicant (Add Applicant page).
 * Pass $cvUrl (Cloudinary secure URL) to store the uploaded CV; otherwise cv_file_url stays NULL.
 * Invitation email is still not sent here. Returns the new row mapped for the frontend.
 */
function repo_add_applicant(string $name, string $email, string $phone, PDO $pdo = null, ?string $cvUrl = null): array
{
    $pdo = $pdo ?: db();

    $name = trim($name);
    $email = trim($email);
    $phone = trim($phone);
    if ($name === '' || mb_strlen($name) < 2) throw new InvalidArgumentException('Please enter the full name.');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new InvalidArgumentException('Enter a valid email address.');
    if ($phone === '') throw new InvalidArgumentException('Please enter the contact number.');

    $ref = repo_generate_reference_code($pdo);
    for ($i = 0; $i < 5; $i++) {
        $token = bin2hex(random_bytes(32));
        $st = $pdo->prepare("SELECT 1 FROM applicant WHERE secure_token = :t LIMIT 1");
        $st->execute([':t' => $token]);
        if (!$st->fetch()) break;
    }

    $st = $pdo->prepare(
        "INSERT INTO applicant (reference_code, secure_token, encoded_by_user_id, name, email, phone_number, cv_file_url, status)" .
        " VALUES (:ref, :tok, :enc, :name, :email, :phone, :cv, 'pending') RETURNING applicant_id"
    );
    $enc = null;
    if (session_status() === PHP_SESSION_ACTIVE && isset($_SESSION['user_id'])) $enc = (int) $_SESSION['user_id'];
    $st->execute([':ref' => $ref, ':tok' => $token, ':enc' => $enc, ':name' => $name, ':email' => $email, ':phone' => $phone, ':cv' => $cvUrl]);
    $id = (int) $st->fetchColumn();

    return ['id' => $id, 'name' => $name, 'email' => $email, 'contact' => $phone, 'status' => 'Pending', 'result' => null, 'cv_file_url' => $cvUrl, 'secure_token' => $token];
}

function repo_find_applicant(int $id, PDO $pdo = null): ?array
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
function repo_find_applicant_by_token(string $token, PDO $pdo = null): ?array
{
    $pdo = $pdo ?: db();
    $token = trim($token);
    if ($token === '') return null;
    $st = $pdo->prepare(
        "SELECT applicant_id, name, email, phone_number, status, result, reference_code, secure_token, cv_file_url, created_at" .
        " FROM applicant WHERE secure_token = :t AND deleted_at IS NULL LIMIT 1"
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
 * Mark an applicant In Progress when they open the questionnaire
 * (Screening.startOnce). No-op for other statuses.
 */
function repo_mark_in_progress(int $id, PDO $pdo = null): int
{
    $pdo = $pdo ?: db();
    $st = $pdo->prepare("UPDATE applicant SET status = 'in_progress' WHERE applicant_id = :id AND status = 'pending' AND deleted_at IS NULL");
    $st->execute([':id' => $id]);
    return $st->rowCount();
}

/**
 * Archive (soft-delete) applicants: Screening List Delete -> Archive page.
 * Sets deleted_at = NOW(); archived rows disappear from the list, appear in Archive.
 * Returns number of rows actually archived.
 */
function repo_archive_applicants(array $ids, PDO $pdo = null): int
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
function repo_restore_applicants(array $ids, PDO $pdo = null): int
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
function repo_purge_applicants(array $ids, PDO $pdo = null): int
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
function repo_purge_expired_applicants(int $days = 30, PDO $pdo = null): int
{
    $pdo = $pdo ?: db();
    $days = max(1, $days);
    $st = $pdo->prepare("DELETE FROM applicant WHERE deleted_at IS NOT NULL AND deleted_at < NOW() - (:days || ' days')::INTERVAL");
    $st->bindValue(':days', (string) $days, PDO::PARAM_STR);
    $st->execute();
    return $st->rowCount();
}

function repo_applicants_archived(PDO $pdo = null): array
{
    $pdo = $pdo ?: db();
    $rows = $pdo->query(
        "SELECT applicant_id, name, email, phone_number, status, result, deleted_at, created_at" .
        " FROM applicant WHERE deleted_at IS NOT NULL ORDER BY deleted_at DESC"
    )->fetchAll();
    $out = [];
    foreach ($rows as $r) {
        $d = applicant_to_display($r);
        $d['archived_at'] = $r['deleted_at'] ? date('c', strtotime((string) $r['deleted_at'])) : null;
        $out[] = $d;
    }
    return $out;
}

function repo_dashboard_stats(PDO $pdo = null): array
{
    $pdo = $pdo ?: db();
    $rows = $pdo->query(
        "SELECT LOWER(status) AS s, LOWER(COALESCE(result,'')) AS r, COUNT(*) AS c" .
        " FROM applicant WHERE deleted_at IS NULL GROUP BY 1, 2"
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
function repo_applicants_recent(PDO $pdo = null, int $limit = 10): array
{
    $pdo = $pdo ?: db();
    $st = $pdo->prepare(
        "SELECT applicant_id, name, email, phone_number, status, result, created_at" .
        " FROM applicant WHERE deleted_at IS NULL ORDER BY created_at DESC, applicant_id DESC LIMIT :lim"
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
