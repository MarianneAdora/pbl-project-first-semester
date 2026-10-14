<?php
// src/Repositories/ApplicantRepository.php — DB reads for the HR Applicant tables.
// Archive = soft-delete: deleted_at IS NULL -> Screening List, deleted_at IS NOT NULL -> Archive.

require_once __DIR__ . '/../Helpers/db.php';
require_once __DIR__ . '/../Helpers/display.php';

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
 * CV upload + invitation email are intentionally NOT functional yet: cv_file_url stays NULL
 * and no email is sent. Returns the new row mapped for the frontend.
 */
function repo_add_applicant(string $name, string $email, string $phone, PDO $pdo = null): array
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
        "INSERT INTO applicant (reference_code, secure_token, encoded_by_user_id, name, email, phone_number, status)" .
        " VALUES (:ref, :tok, :enc, :name, :email, :phone, 'pending') RETURNING applicant_id"
    );
    $enc = null;
    if (session_status() === PHP_SESSION_ACTIVE && isset($_SESSION['user_id'])) $enc = (int) $_SESSION['user_id'];
    $st->execute([':ref' => $ref, ':tok' => $token, ':enc' => $enc, ':name' => $name, ':email' => $email, ':phone' => $phone]);
    $id = (int) $st->fetchColumn();

    return ['id' => $id, 'name' => $name, 'email' => $email, 'contact' => $phone, 'status' => 'Pending', 'result' => null];
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

/**
 * Derived Recent Activities feed — NO activity_log table.
 * Unions created_at timestamps from applicant / schedule / question_config.
 */
function repo_recent_activity(PDO $pdo = null, int $limit = 10): array
{
    $pdo = $pdo ?: db();
    $st = $pdo->prepare(
        "(SELECT 'Applicant Added' AS activity, ('Applicant Added: ' || name) AS details, 'hr' AS by, created_at AS at" .
        " FROM applicant WHERE deleted_at IS NULL)" .
        " UNION ALL " .
        "(SELECT 'Interview Scheduled' AS activity, ('Interview scheduled for applicant #' || applicant_id) AS details, 'hr' AS by, created_at AS at FROM schedule)" .
        " UNION ALL " .
        "(SELECT 'Question Updated' AS activity, ('Question updated: ' || LEFT(question_text, 60)) AS details, 'admin' AS by, created_at AS at FROM question_config WHERE deleted_at IS NULL)" .
        " ORDER BY at DESC LIMIT :lim"
    );
    $st->bindValue(':lim', $limit, PDO::PARAM_INT);
    $st->execute();
    $out = [];
    foreach ($st->fetchAll() as $r) {
        $out[] = [
            'at' => $r['at'] ? date('Y-m-d H:i', strtotime((string) $r['at'])) : '',
            'activity' => (string) $r['activity'],
            'details' => (string) $r['details'],
            'by' => (string) $r['by'],
        ];
    }
    return $out;
}
