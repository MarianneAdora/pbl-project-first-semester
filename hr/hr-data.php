<?php
/*
 * Shared HR/admin account, applicant, position, profile, and activity helpers.
 * The application uses PostgreSQL for its live records. The JSON state file and
 * static_-prefixed function names are retained for legacy compatibility.
 * This file also handles HR-side AJAX actions when requested directly.
 */

require_once __DIR__ . '/../src/Helpers/db.php';
require_once __DIR__ . '/../src/Repositories/ActivityRepository.php';

/*
 * The legacy application-state.json reader remains below for compatibility.
 * Writes through application_state_write() are intentionally disabled; live
 * applicant, account, and position changes are stored in PostgreSQL.
 */

// ---------------------------------------------------------------------------
// Login accounts (index.php). Passwords are plain text only because this is sample data.
// ---------------------------------------------------------------------------
function static_accounts(): array
{
    $pdo = db();
    $accounts = [];

    // HR accounts from users table
    $stmt = $pdo->query("SELECT name, email, password_hash FROM users WHERE role = 'hr' AND deleted_at IS NULL");
    while ($row = $stmt->fetch()) {
        $email = strtolower(trim((string) $row['email']));
        if ($email === '') continue;
        $accounts[$email] = [
            'hash'     => (string) $row['password_hash'],
            'role'     => 'hr',
'name' => (string) $row['job_name'],
            'redirect' => 'hr/dashboard-hr.php'
        ];
    }

    // Admin account from users table
    $stmt = $pdo->query("SELECT name, email, password_hash FROM users WHERE role = 'admin' AND deleted_at IS NULL LIMIT 1");
    $admin = $stmt->fetch();
    if ($admin) {
        $adminEmail = strtolower(trim((string) $admin['email']));
        if ($adminEmail !== '') {
            $accounts[$adminEmail] = [
                'hash'     => (string) $admin['password_hash'],
                'role'     => 'admin',
                'name'     => (string) $admin['name'],
                'redirect' => 'admin/dashboard-admin.php'
            ];
        }
    }

    // Fall back to default accounts if the DB has none
    if (empty($accounts)) {
        $accounts['hr@expertise.com'] = [
            'password' => 'hr123',
            'role'     => 'hr',
            'name'     => 'HR',
            'redirect' => 'hr/dashboard-hr.php'
        ];
        $accounts['admin@expertise.com'] = [
            'password' => 'admin123',
            'role'     => 'admin',
            'name'     => 'Admin',
            'redirect' => 'admin/dashboard-admin.php'
        ];
    }

    return $accounts;
}

// ---------------------------------------------------------------------------
// Applicants, with their status, result and the time of their latest activity.
//   status:  'Pending' | 'In Progress' | 'Completed'
//   result:  'Passed' | 'Rejected' | null   (HR may reject before screening completion)
//   updated: newest event (applied OR status change), 'YYYY-MM-DD HH:MM'
// ---------------------------------------------------------------------------
function static_seed_applicants(): array
{
    return [];
}

// ---------------------------------------------------------------------------
// Numbers for the dashboard cards and the Passed/Rejected chart, counted from the list.
// ---------------------------------------------------------------------------
function static_stats(array $applicants): array
{
    $count = function (callable $test) use ($applicants): int {
        return count(array_filter($applicants, $test));
    };
    $is = fn(string $key, string $value) => fn($a) => strtolower((string) ($a[$key] ?? '')) === strtolower($value);

    return [
        'total'       => count($applicants),
        'pending'     => $count($is('status', 'Pending')),
        'in_progress' => $count($is('status', 'In Progress')),
        'completed'   => $count($is('status', 'Completed')),
        'passed'      => $count(fn($a) => strtolower((string) $a['status']) === 'completed' && strtolower((string) $a['result']) === 'passed'),
        'failed'      => $count(fn($a) => strtolower((string) $a['result']) === 'rejected')
    ];
}

// IDs of EVERY applicant whose status is Completed (drives the red marks + the Dashboard badge)
function static_completed_ids(array $applicants): array
{
    $ids = [];
    foreach ($applicants as $a) {
        if (strtolower((string) $a['status']) === 'completed') $ids[] = (int) $a['id'];
    }
    return $ids;
}

// Recent Applicants: newest activity first, never more than $limit
function static_recent(array $applicants, int $limit = 10): array
{
    usort($applicants, fn($a, $b) => strtotime($b['updated']) <=> strtotime($a['updated']));
    return array_slice($applicants, 0, $limit);
}

// ---------------------------------------------------------------------------
// DASHBOARD INSIGHTS: the three analytics blocks on the HR Dashboard
//   1. Funnel Drop-Off                 how many applicants make it through each stage
//   2. Time-to-Complete Distribution   how long applicants take from invite to submission (histogram)
//   3. Scheduling Turnaround Time      time between PASS and HR entering the final interview schedule
//
// The dashboard reads all non-archived applicants, including unverified invitations:
//   invited_at   when HR added the applicant
//   submitted_at when the screening became Completed
//   passed_at    when the result became Passed
//   rejected_at  when the result became Rejected
// Confirmed/rescheduled schedule rows provide the final interview time and answer timestamp.
// ---------------------------------------------------------------------------
const STATIC_TURNAROUND_TARGET_HOURS = 72;     // HR's goal: enter the final schedule within 3 days of the pass

// Bucket edges in hours (lower edge inclusive). Labels: short (x-axis) and long (tooltip).
function static_time_bins(): array
{
    return [
        ['from' => 0,   'to' => 12,   'short' => '<12h',   'long' => 'Under 12 hours'],
        ['from' => 12,  'to' => 24,   'short' => '12-24h', 'long' => '12 to 24 hours'],
        ['from' => 24,  'to' => 48,   'short' => '1-2d',   'long' => '1 to 2 days'],
        ['from' => 48,  'to' => 72,   'short' => '2-3d',   'long' => '2 to 3 days'],
        ['from' => 72,  'to' => 120,  'short' => '3-5d',   'long' => '3 to 5 days'],
        ['from' => 120, 'to' => null, 'short' => '5d+',    'long' => '5 days or more']
    ];
}

// "Y-m-d H:i" (Manila time) -> unix time, or null
function static_ts($value): ?int
{
    $value = trim((string) $value);
    if ($value === '') return null;
    try {
        return (new DateTimeImmutable($value, new DateTimeZone('Asia/Manila')))->getTimestamp();
    } catch (Throwable $e) {
        return null;
    }
}

// 43.7 -> ['value' => 1.8, 'unit' => 'days', 'decimals' => 1, 'label' => '1.8 days']
function static_hours_label(float $hours): array
{
    if ($hours < 1) {
        $m = max(1, (int) round($hours * 60));
        return ['value' => $m, 'unit' => $m === 1 ? 'min' : 'mins', 'decimals' => 0, 'label' => $m . ($m === 1 ? ' min' : ' mins')];
    }
    if ($hours < 24) {
        $h = (int) round($hours);
        return ['value' => $h, 'unit' => $h === 1 ? 'hr' : 'hrs', 'decimals' => 0, 'label' => $h . ($h === 1 ? ' hr' : ' hrs')];
    }
    $d = round($hours / 24, 1);
    $unit = abs($d - 1.0) < 0.05 ? 'day' : 'days';
    return ['value' => $d, 'unit' => $unit, 'decimals' => 1, 'label' => number_format($d, 1) . ' ' . $unit];
}

function static_dashboard_empty_insights(): array
{
    $bins = static_time_bins();
    $stages = [
        ['key' => 'applied',   'label' => 'Applied',                'count' => 0, 'url' => 'screening-list.php?status=all'],
        ['key' => 'started',   'label' => 'Started Screening',      'count' => 0, 'url' => ''],
        ['key' => 'completed', 'label' => 'Completed Questionnaire', 'count' => 0, 'url' => 'screening-list.php?status=Completed'],
        ['key' => 'passed',    'label' => 'Passed Screening',       'count' => 0, 'url' => 'screening-list.php?status=Passed'],
        ['key' => 'scheduled', 'label' => 'Interview Scheduled',    'count' => 0, 'url' => '']
    ];
    foreach ($stages as $i => &$s) { $s['lost'] = 0; $s['lost_pct'] = 0; $s['of_applied'] = 0; }
    unset($s);
    return [
        'funnel'      => ['stages' => $stages, 'overall_pct' => 0, 'biggest' => null],
        'duration'    => [
            'labels' => array_column($bins, 'short'), 'titles' => array_column($bins, 'long'),
            'counts' => array_fill(0, count($bins), 0), 'n' => 0,
            'median' => null, 'fastest' => null, 'slowest' => null
        ],
        'turnaround'  => [
            'n' => 0, 'waiting' => 0, 'passed' => 0, 'avg' => null, 'fastest' => null, 'slowest' => null,
            'target_hours' => STATIC_TURNAROUND_TARGET_HOURS, 'target_label' => '3-day',
            'bar_pct' => 0, 'state' => 'none'
        ]
    ];
}

// All three blocks use non-archived applicants (including pending invitations) + interview rows.
function static_dashboard_insights(array $applicants, array $interviews = []): array
{
    $out        = static_dashboard_empty_insights();

    // when HR answered the interview (null = still waiting / no row yet)
    $answeredAt = function (int $id) use ($interviews): ?int {
        $iv = $interviews[$id] ?? null;
        if (!is_array($iv) || !in_array($iv['status'] ?? '', ['confirmed', 'rescheduled'], true)) return null;
        return static_ts($iv['answered_at'] ?? ($iv['updated'] ?? ''));
    };

    $total = count($applicants);
    $started = $completed = $passed = $scheduled = 0;
    $durations = [];            // invite -> submission, in hours
    $turnarounds = [];          // pass -> schedule entered, in hours

    foreach ($applicants as $a) {
        $status = strtolower((string) ($a['status'] ?? ''));
        $result = strtolower((string) ($a['result'] ?? ''));
        $id     = (int) $a['id'];

        if ($status === 'in progress' || $status === 'completed') $started++;
        if ($status !== 'completed') continue;
        $completed++;

        $inv = static_ts($a['invited_at'] ?? '');
        $sub = static_ts($a['submitted_at'] ?? '');
        if ($inv !== null && $sub !== null && $sub >= $inv) $durations[] = ($sub - $inv) / 3600;

        if ($result !== 'passed') continue;
        $passed++;
        $answered = $answeredAt($id);
        if ($answered === null) continue;
        $scheduled++;
        $pass = static_ts($a['passed_at'] ?? '') ?? $sub;
        if ($pass !== null && $answered >= $pass) $turnarounds[] = ($answered - $pass) / 3600;
    }

    // ---- 1. funnel ----
    $counts = [$total, $started, $completed, $passed, $scheduled];
    $stages = $out['funnel']['stages'];
    $biggest = null;
    foreach ($stages as $i => &$s) {
        $s['count']      = $counts[$i];
        $s['of_applied'] = $total > 0 ? (int) round($counts[$i] / $total * 100) : 0;
        $prev            = $i > 0 ? $counts[$i - 1] : $counts[$i];
        $s['lost']       = max(0, $prev - $counts[$i]);
        $s['lost_pct']   = $prev > 0 ? (int) round($s['lost'] / $prev * 100) : 0;
        if ($i > 0 && $s['lost'] > 0 && ($biggest === null || $s['lost'] > $biggest['lost'])) {
            $biggest = ['from' => $stages[$i - 1]['label'], 'to' => $s['label'], 'lost' => $s['lost'], 'pct' => $s['lost_pct']];
        }
    }
    unset($s);
    $out['funnel'] = [
        'stages'      => $stages,
        'overall_pct' => $total > 0 ? (int) round($scheduled / $total * 100) : 0,
        'biggest'     => $biggest
    ];

    // ---- 2. time-to-complete histogram ----
    $bins   = static_time_bins();
    $bucket = array_fill(0, count($bins), 0);
    foreach ($durations as $h) {
        foreach ($bins as $i => $b) {
            if ($h >= $b['from'] && ($b['to'] === null || $h < $b['to'])) { $bucket[$i]++; break; }
        }
    }
    $out['duration']['counts'] = $bucket;
    $out['duration']['n']      = count($durations);
    if ($durations) {
        sort($durations);
        $n   = count($durations);
        $mid = $n % 2 ? $durations[intdiv($n, 2)] : ($durations[$n / 2 - 1] + $durations[$n / 2]) / 2;
        $out['duration']['median']  = static_hours_label($mid)['label'];
        $out['duration']['fastest'] = static_hours_label($durations[0])['label'];
        $out['duration']['slowest'] = static_hours_label($durations[$n - 1])['label'];
    }

    // ---- 3. scheduling turnaround ----
    $t = &$out['turnaround'];
    $t['passed']  = $passed;
    $t['n']       = count($turnarounds);
    $t['waiting'] = max(0, $passed - $scheduled);
    if ($turnarounds) {
        $avg = array_sum($turnarounds) / count($turnarounds);
        $t['avg']     = static_hours_label($avg);
        $t['fastest'] = static_hours_label(min($turnarounds))['label'];
        $t['slowest'] = static_hours_label(max($turnarounds))['label'];
        // the bar's middle mark is the target, so a full bar means "twice as slow as the target"
        $t['bar_pct'] = (int) min(100, round($avg / (STATIC_TURNAROUND_TARGET_HOURS * 2) * 100));
        $t['state']   = $avg <= STATIC_TURNAROUND_TARGET_HOURS ? 'ok' : ($avg <= STATIC_TURNAROUND_TARGET_HOURS * 1.5 ? 'warn' : 'slow');
    }
    unset($t);

    return $out;
}


/* ==========================================================================
   STORE LAYER: makes the static list behave like a real table.
   Everything below is the part that is replaced by the database later.
   ========================================================================== */
const APPLICATION_STATE_FILE   = __DIR__ . '/data/application-state.json';
const STATIC_ARCHIVE_DAYS = 30;                 // days an archived applicant stays before it is gone for good

function static_now(): DateTimeImmutable
{
    return new DateTimeImmutable('now', new DateTimeZone('Asia/Manila'));
}

// No-op: the store is now always in the database.
function application_state_write(array $store): bool
{
    return true;
}

// Read the store from the JSON file (or return default empty store)
function application_state_read(): array
{
    $file = APPLICATION_STATE_FILE;
    if (!file_exists($file)) {
        return ['applicants' => [], 'hr_accounts' => [], 'positions' => [], 'submissions' => [], 'evaluations' => [], 'activity' => []];
    }
    $json = file_get_contents($file);
    $store = json_decode($json, true);
    if ($store === null) {
        return ['applicants' => [], 'hr_accounts' => [], 'positions' => [], 'submissions' => [], 'evaluations' => [], 'activity' => []];
    }
    return $store + ['applicants' => [], 'hr_accounts' => [], 'positions' => [], 'submissions' => [], 'evaluations' => [], 'activity' => []];
}

// ---------------------------------------------------------------------------
// ACTIVITY LOG:
// ---------------------------------------------------------------------------
// ACTIVITY LOG: what HR and the Admin did (kept in the store under "activity")
//   Admin Dashboard > Recent Activities lists every line HR writes here, and the Admin's sidebar
//   Dashboard badge counts the ones the Admin has not seen yet (see static_admin_notify()).
//   HR Dashboard > the notification bell lists HR's own lines (static_hr_feed()).
//   Only real work is logged: applicants (add / delete / restore / delete forever / status) and
//   interview schedules. Preferences (HR Settings) are NOT logged.
//   These four helpers used to sit in admin/question-admin.php; the Admin side now uses them from here.
//   Admin Dashboard > Recent Activities lists every line HR writes here, and the Admin's sidebar
//   Dashboard badge counts the ones the Admin has not seen yet (see static_admin_notify()).
//   HR Dashboard > the notification bell lists HR's own lines (static_hr_feed()).
//   Only real work is logged: applicants (add / delete / restore / delete forever / status) and
//   interview schedules. Preferences (HR Settings) are NOT logged.
//   These four helpers used to sit in admin/question-admin.php; the Admin side now uses them from here.
//   BACKEND: activity_log (id, actor_role, activity, details, created_at); static_log() = one INSERT.
// ---------------------------------------------------------------------------
if (!function_exists('static_seed_activity')) {
    function static_seed_activity(): array
    {
        return [];
    }
}

if (!function_exists('static_actor')) {
    function static_actor(): ?string
    {
        $role = (string) ($_SESSION['user_role'] ?? '');
        if ($role === 'hr')    return 'HR';
        if ($role === 'admin') return 'Admin';
        return null;
    }
}

// Adds one line to the log inside a store you are already changing. The time includes seconds, so two
// things done in the same minute are still told apart by the "new" badges.
if (!function_exists('static_log_into')) {
    function static_log_into(array &$store, string $activity, string $details = '-', ?string $by = null): void
    {
        $by = $by ?? static_actor();
        if ($by === null) return;
        if (!isset($store['activity']) || !is_array($store['activity'])) $store['activity'] = static_seed_activity();
        $store['activity'][] = [
            'at'       => static_now()->format('Y-m-d H:i:s'),
            'activity' => $activity,
            'details'  => trim($details) === '' ? '-' : $details,
            'by'       => $by
        ];
        if (count($store['activity']) > 300) $store['activity'] = array_slice($store['activity'], -300);
    }
}

// Newest first (two lines with the same time keep the order they were written, newest on top)
function static_activity(): array
{
    try { return repo_recent_activity(null, 900); }
    catch (Throwable $e) { return []; }
}


// Reads, adds one line, saves. Used by the HR endpoints once a change has worked.
// A problem while writing the log line never undoes or blocks the change HR just made.
function static_log(string $activity, string $details = '-', ?string $by = null): void
{
    try { repo_log_activity($activity, $details, $by); } catch (Throwable $e) { /* ignored on purpose */ }
}

// The id of the signed-in HR account (0 when it cannot be told).
function static_hr_session_id(): int
{
    $email = strtolower(trim((string) ($_SESSION['user_email'] ?? '')));
    if ($email === '' || ($_SESSION['user_role'] ?? '') !== 'hr') return 0;
    $pdo = db();
    $stmt = $pdo->prepare("SELECT user_id AS id FROM users WHERE email = :email AND role = 'hr' AND deleted_at IS NULL LIMIT 1");
    $stmt->execute([':email' => $email]);
    $row = $stmt->fetch();
    return (int) ($row['id'] ?? 0);
}

// What HR did, newest first: the list behind the bell on the HR Dashboard (the Admin's own lines are left out).
// Two things are added for the signed-in HR: their own Profile / Password lines, and the Admin's answer to their own
// password request (that one Admin line is shown to HR, worded for HR).
function static_hr_feed(int $limit = 30): array
{
    $out  = [];
    $rows = repo_recent_activity(null, 900);
    $mine = static_hr_session_id();
    foreach ($rows as $row) {
        $by   = (string) ($row['by'] ?? '');
        $item = ['id' => (int) ($row['id'] ?? 0), 'at' => (string) ($row['at'] ?? ''), 'activity' => (string) $row['activity'], 'details' => (string) $row['details'], 'by' => $by];

        // a profile / password line belongs to one HR account: nobody else sees it
        if (isset($row['owner']) && (int) $row['owner'] !== $mine) continue;

        if (($row['activity'] ?? '') === 'Password Change Approved') {
            $item['details'] = 'The Admin approved your new password. Use it the next time you log in.';
        } elseif (strcasecmp($by, 'Admin') === 0) {
            continue;
        }

        $out[] = $item;
        if (count($out) >= $limit) break;
    }
    return $out;
}

// Names for the log line. $archived: null = any applicant, false = only active ones, true = only archived ones
function static_applicant_names(array $ids, ?bool $archived = null): array
{
    $ids = array_map('intval', $ids);
    $out = [];
    foreach (application_state_read()['applicants'] as $a) {
        if (!in_array((int) $a['id'], $ids, true)) continue;
        $isArchived = !empty($a['archived_at']);
        if ($archived !== null && $archived !== $isArchived) continue;
        $out[] = (string) $a['name'];
    }
    return $out;
}

// These helpers derive labels purely from the applicant list (empty today).
function static_names_label(array $names): string
{
    $names = array_values(array_filter(array_map('trim', $names), fn($n) => $n !== ''));
    if (!$names) return '-';
    if (count($names) <= 2) return implode(', ', $names);
    return $names[0] . ', ' . $names[1] . ' +' . (count($names) - 2) . ' more';
}

// ---------------------------------------------------------------------------
// READ
// ---------------------------------------------------------------------------
// Active applicants (NOT archived). BACKEND: SELECT from applicant table LEFT JOIN job_positions for position name
function static_applicants(): array
{
    $pdo = db();
    $rows = $pdo->query("
        SELECT a.applicant_id AS id, a.name, a.first_name, a.middle_name, a.last_name,
               a.email, a.phone_number AS contact, a.position_id,
               p.job_name AS position,
               a.status, a.result,
               a.created_at AS updated,
               a.invited_at, a.submitted_at, a.passed_at,
               a.cv_file_url, a.cv_hash
        FROM applicant a
        LEFT JOIN job_positions p ON p.job_id = a.position_id AND p.archived_at IS NULL
        WHERE a.deleted_at IS NULL
        ORDER BY a.applicant_id ASC
    ")->fetchAll();

    $out = [];
    foreach ($rows as $r) {
        $applicant = [
            'id'          => (int) $r['id'],
            'name'        => (string) $r['name'],
            'first_name'  => (string) $r['first_name'],
            'middle_name' => (string) ($r['middle_name'] ?? ''),
            'last_name'   => (string) $r['last_name'],
            'email'       => (string) $r['email'],
            'contact'     => (string) ($r['contact'] ?? ''),
            'position_id' => (int) ($r['position_id'] ?? 0),
            'position'    => (string) ($r['position'] ?? ''),
            'status'      => (string) $r['status'],
            'result'      => $r['result'] ? (string) $r['result'] : null,
            'updated'     => (string) $r['updated'],
            'invited_at'  => $r['invited_at'] ? (string) $r['invited_at'] : null,
            'submitted_at'=> $r['submitted_at'] ? (string) $r['submitted_at'] : null,
            'passed_at'   => $r['passed_at'] ? (string) $r['passed_at'] : null,
        ];
        if (!empty($r['cv_file_url'])) {
            $applicant['cv'] = [
                'name'  => '',
                'size'  => 0,
                'file'  => basename((string) $r['cv_file_url']),
                'url'   => (string) $r['cv_file_url'],
            ];
        }
        $out[] = $applicant;
    }
    return $out;
}

// Archived applicants, newest deletion first. Adds:
//   deleted   'YYYY-MM-DD HH:MM'  when it was moved to the Archive
//   days_left days before it is removed for good (0 = today)
function static_archived_applicants(): array
{
    static_purge_expired();
    $now = static_now();
    $pdo = db();
    $rows = $pdo->query("
        SELECT a.applicant_id AS id, a.name, a.first_name, a.middle_name, a.last_name,
               a.email, a.phone_number AS contact, a.position_id,
               p.job_name AS position,
               a.status, a.result,
               a.created_at AS updated,
               a.invited_at, a.submitted_at, a.passed_at,
               a.deleted_at AS deleted,
               a.cv_file_url, a.cv_hash
        FROM applicant a
        LEFT JOIN job_positions p ON p.job_id = a.position_id AND p.archived_at IS NULL
        WHERE a.deleted_at IS NOT NULL
        ORDER BY a.deleted_at DESC
    ")->fetchAll();

    $out = [];
    foreach ($rows as $r) {
        $deletedAt = new DateTimeImmutable((string) $r['deleted'], new DateTimeZone('Asia/Manila'));
        $passed    = (int) $deletedAt->diff($now)->format('%a');
        $applicant = [
            'id'          => (int) $r['id'],
            'name'        => (string) $r['name'],
            'first_name'  => (string) $r['first_name'],
            'middle_name' => (string) ($r['middle_name'] ?? ''),
            'last_name'   => (string) $r['last_name'],
            'email'       => (string) $r['email'],
            'contact'     => (string) ($r['contact'] ?? ''),
            'position_id' => (int) ($r['position_id'] ?? 0),
            'position'    => (string) ($r['position'] ?? ''),
            'status'      => (string) $r['status'],
            'result'      => $r['result'] ? (string) $r['result'] : null,
            'updated'     => (string) $r['updated'],
            'invited_at'  => $r['invited_at'] ? (string) $r['invited_at'] : null,
            'submitted_at'=> $r['submitted_at'] ? (string) $r['submitted_at'] : null,
            'passed_at'   => $r['passed_at'] ? (string) $r['passed_at'] : null,
            'deleted'     => (string) $r['deleted'],
            'days_left'   => max(0, STATIC_ARCHIVE_DAYS - $passed),
        ];
        if (!empty($r['cv_file_url'])) {
            $applicant['cv'] = [
                'name'  => '',
                'size'  => 0,
                'file'  => basename((string) $r['cv_file_url']),
                'url'   => (string) $r['cv_file_url'],
            ];
        }
        $out[] = $applicant;
    }
    return $out;
}

// What the pages hand to hr-common.js so the browser copy of the Archive matches this file:
//   ids = ids that are in the Archive      at = { id: time it was archived, in milliseconds }
// Every HR page prints it, as JSON, into window.HR_SERVER_ARCHIVE before hr-common.js loads
// (see the <script> line above hr-common.js in dashboard-hr.php, screening-list.php, archive.php
// and add-applicants.php).
function static_archive_sync(): array
{
    $zone = new DateTimeZone('Asia/Manila');
    $ids  = [];
    $at   = [];
    foreach (static_archived_applicants() as $a) {
        $id       = (int) $a['id'];
        $ids[]    = $id;
        $at[$id]  = (new DateTimeImmutable($a['deleted'], $zone))->getTimestamp() * 1000;
    }
    return ['ids' => $ids, 'at' => (object) $at];
}

// ---------------------------------------------------------------------------
// WRITE
// ---------------------------------------------------------------------------
// One part of a person's name (first / middle / last). Same rules as the Add Applicant form (assets/js/hr.js).
// $required = false lets an empty value through (the middle name). Returns the cleaned value.
function static_check_name_part(string $value, string $label, bool $required, int $minLetters = 2): string
{
    $value = trim(preg_replace('/\s+/u', ' ', $value));
    if ($value === '') {
        if ($required) throw new InvalidArgumentException('Please enter the ' . $label . '.');
        return '';
    }
    if (preg_match('/\d/', $value))                         throw new InvalidArgumentException('The ' . $label . ' cannot contain numbers.');
    if (!preg_match('/^[\p{L}\p{M}\'.\- ]+$/u', $value))    throw new InvalidArgumentException('The ' . $label . ' can only use letters (spaces, apostrophes, periods and hyphens are fine).');
    if (!$required && $minLetters === 0 && !preg_match('/\p{L}/u', $value)) return '';   // only dots/dashes is not an initial: leave the middle name empty, no error
    if (preg_match_all('/\p{L}/u', $value) < $minLetters)   throw new InvalidArgumentException('The ' . $label . ' is too short (at least ' . $minLetters . ' letter' . ($minLetters === 1 ? '' : 's') . ').');
    if (mb_strlen($value) > 50)                             throw new InvalidArgumentException('The ' . $label . ' is too long (50 characters at most).');
    return $value;
}

// "Maria" + "Clara" + "Santos" -> "Maria Clara Santos"; no middle name -> "Maria Santos"
function static_compose_name(string $first, string $middle, string $last): string
{
    return trim(preg_replace('/\s+/u', ' ', $first . ' ' . $middle . ' ' . $last));
}

// Add Applicant: a new applicant starts as Pending. $in = { first_name, middle_name (optional), last_name, email,
// contact, position_id }. Throws InvalidArgumentException with a message that is safe to show to HR.
// The full name is still saved as 'name' (every other page reads that), and the parts are kept too.
function static_add_applicant(array $in, ?array $cvUpload = null): array
{
    $first   = static_check_name_part((string) ($in['first_name']  ?? ''), 'first name',  true);
    $middle  = static_check_name_part((string) ($in['middle_name'] ?? ''), 'middle name', false, 0);   // optional; one or two initials (B. / J.D.) are fine, no minimum length
    $last    = static_check_name_part((string) ($in['last_name']   ?? ''), 'last name',   true);
    // A surname with two or more names (e.g. "dela torre") is fully capitalized: "Dela Torre"
    $lastWords = preg_split('/\s+/u', $last);
    if (count($lastWords) > 1) {
        $last = implode(' ', array_map(fn($w) => ucfirst(strtolower($w)), $lastWords));
    }
    $name    = static_compose_name($first, $middle, $last);
    $email   = trim((string) ($in['email'] ?? ''));
    $contact = trim((string) ($in['contact'] ?? ''));
    if (mb_strlen($name) > 100)                       throw new InvalidArgumentException('The full name is too long (100 characters at most).');
    if (strlen($email) > 150)                          throw new InvalidArgumentException('The email is too long (150 characters at most).');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL))   throw new InvalidArgumentException('Enter a valid email address (example: name@gmail.com).');
    if ($contact === '')                              throw new InvalidArgumentException('Please enter the contact number.');
    if (strlen($contact) > 20 || !preg_match('/^\+?[0-9][0-9\s-]*$/', $contact)) {
        throw new InvalidArgumentException('Use digits only (you may start with + and use spaces or dashes).');
    }
    $contactDigits = strlen(preg_replace('/\D/', '', $contact));
    if ($contactDigits < 7 || $contactDigits > 15) {
        throw new InvalidArgumentException('The contact number must contain 7 to 15 digits.');
    }

    $position = static_position_find((int) ($in['position_id'] ?? 0));
    if ($position === null)                           throw new InvalidArgumentException('Please choose a job position.');

    $pdo = db();
    require_once __DIR__ . '/../src/Repositories/ApplicantRepository.php';
    require_once __DIR__ . '/../src/Services/EmailService.php';

    // One person, one email, one CV: same rule as the rest of the app (repo_assert_not_duplicate). Only VERIFIED
    // applicants block; an older invitation nobody verified yet is replaced below, so HR can fix a typo or resend.
    repo_purge_stale_invitations($pdo);
    $cvHash = null;
    if ($cvUpload && !empty($cvUpload['tmp_name']) && is_file((string) $cvUpload['tmp_name'])) {
        $cvHash = repo_cv_hash((string) $cvUpload['tmp_name']);
    }
    repo_assert_not_duplicate($email, $cvHash, $pdo, $name);

    // Upload CV to Cloudinary if provided
    $cvFileUrl = null;
    if ($cvUpload) {
        try {
            require_once __DIR__ . '/../src/Services/CloudinaryService.php';
            $cloud = new CloudinaryService();
            $tmpFile = tempnam(sys_get_temp_dir(), 'cv_');
            if (!@move_uploaded_file($cvUpload['tmp_name'], $tmpFile)) {
                throw new InvalidArgumentException('The CV file could not be uploaded. Please try again.');
            }
            $uploaded = $cloud->uploadCV(
                $tmpFile,
                0,  // applicant id not known yet
                'ebsmi-shortlist-applicant-cvs',
                (string) ($cvUpload['name'] ?? '')
            );
            $cvFileUrl = $uploaded['url'];
            $cvHash = hash_file('sha256', $tmpFile);
            @unlink($tmpFile);
        } catch (InvalidArgumentException $e) {
            throw $e;
        } catch (Throwable $e) {
            error_log('CV Cloudinary upload error: ' . $e->getMessage());
            throw new InvalidArgumentException('The CV could not be uploaded. Please try again.');
        }
    }

    // reference_code, secure_token and name_normalized are NOT NULL in the schema.
    $now       = static_now()->format('Y-m-d H:i:s');
    $refCode   = repo_generate_reference_code($pdo);
    $token     = bin2hex(random_bytes(32));
    $emailNorm = repo_normalize_email($email);
    $nameNorm  = repo_normalize_name($name);
    // Who added the applicant: only an id that really exists in users (an old session can hold a stale one).
    $encodedBy = null;
    if (session_status() === PHP_SESSION_ACTIVE && !empty($_SESSION['user_id'])) {
        $u = $pdo->prepare('SELECT user_id FROM users WHERE user_id = :id AND deleted_at IS NULL');
        $u->execute([':id' => (int) $_SESSION['user_id']]);
        $encodedBy = $u->fetchColumn() !== false ? (int) $_SESSION['user_id'] : null;
    }

    // Replace any older UNVERIFIED invitation with the same email / name.
    $pdo->prepare('DELETE FROM applicant WHERE verified_at IS NULL AND (email_normalized = :e OR name_normalized = :n)')
        ->execute([':e' => $emailNorm, ':n' => $nameNorm]);

    $stmt = $pdo->prepare("
        INSERT INTO applicant (reference_code, secure_token, encoded_by_user_id,
                               name, first_name, middle_name, last_name, name_normalized,
                               email, email_normalized, phone_number, position_id,
                               cv_file_url, cv_hash, status, result,
                               invited_at, created_at)
        VALUES (:ref, :tok, :enc, :name, :fn, :mn, :ln, :nn,
                :email, :en, :phone, :pid,
                :cvurl, :cvhash, 'pending', NULL,
                :invited, NOW())
        RETURNING applicant_id, name, first_name, middle_name, last_name, email, phone_number,
                  position_id, status, result, created_at, invited_at, cv_file_url, cv_hash
    ");
    try {
    $stmt->execute([
        ':ref'   => $refCode,
        ':tok'   => $token,
        ':enc'   => $encodedBy,
        ':name'  => $name,
        ':fn'    => $first,
        ':mn'    => $middle,
        ':ln'    => $last,
        ':nn'    => $nameNorm,
        ':email' => $email,
        ':en'    => $emailNorm,
        ':phone' => $contact,
        ':pid'   => (int) $position['id'],
        ':cvurl' => $cvFileUrl,
        ':cvhash'=> $cvHash,
        ':invited'=> $now
    ]);
    } catch (PDOException $e) {
        // The unique indexes are the final guard (e.g. two HR users at the same moment).
        if ((string) $e->getCode() === '23505') {
            $m = $e->getMessage();
            if (stripos($m, 'name_normalized') !== false) throw new DuplicateApplicantException('An applicant named "' . $name . '" already exists. Each applicant can only be added once.', 'name');
            throw new DuplicateApplicantException('This email address already belongs to an applicant. Each email can only be used once.', 'email');
        }
        throw $e;
    }
    $row = $stmt->fetch();

    // Send the screening invitation (personal link with the secure token).
    // If it cannot be sent, remove the row again so HR can simply retry with the same email.
    $sent = false;
    $emailFailure = null;
    try {
        $emailService = new EmailService();
        $sent = $emailService->sendInviteEmail($email, $name, $token);
        $emailFailure = $emailService->lastFailure();
    } catch (Throwable $e) {
        $emailFailure = 'exception';
        error_log('Invitation email error: ' . $e->getMessage());
    }
    if (!$sent) {
        try {
            $pdo->prepare('DELETE FROM applicant WHERE applicant_id = :id')->execute([':id' => (int) $row['applicant_id']]);
        } catch (Throwable $e) {
            error_log('Could not roll back applicant after email failure: ' . $e->getMessage());
        }
        $messages = [
            'missing_api_key' => 'We could not send the invitation email. Please try again later.',
            'unauthorized'    => 'We could not send the invitation email. Please try again later.',
            'transport'       => 'We could not send the invitation email. Please try again later.',
            'rejected'        => 'We could not send the invitation email. Please check the email address and try again.',
            'exception'       => 'We could not send the invitation email. Please try again later.'
        ];
        throw new InvalidArgumentException($messages[$emailFailure] ?? 'The invitation email could not be sent. Please try again later.');
    }

    $applicant = [
        'id'          => (int) $row['applicant_id'],
        'name'        => (string) $row['name'],
        'first_name'  => (string) $row['first_name'],
        'middle_name' => (string) ($row['middle_name'] ?? ''),
        'last_name'   => (string) $row['last_name'],
        'email'       => (string) $row['email'],
        'contact'     => (string) ($row['phone_number'] ?? ''),
        'position_id' => (int) ($row['position_id'] ?? 0),
        'position'    => (string) $position['name'],
        'status'      => (string) $row['status'],
        'result'      => $row['result'] ? (string) $row['result'] : null,
        'updated'     => (string) $row['created_at'],
        'invited_at'  => $row['invited_at'] ? (string) $row['invited_at'] : null,
        'submitted_at'=> null,
        'passed_at'   => null,
    ];
    if (!empty($row['cv_file_url'])) {
        $applicant['cv'] = [
            'name'  => '',
            'size'  => 0,
            'file'  => basename((string) $row['cv_file_url']),
            'url'   => (string) $row['cv_file_url'],
        ];
    }
    return $applicant;
}

/* ==========================================================================
   JOB POSITIONS (the table "job_positions")
   Add Applicant has a "Job Position" list; its last entry is "+ Add job position", where HR types a new one
   (and uploads that position's job description PDF). Every position has its OWN job description, so the
   applicant is shown the PDF of the position they were added under (applicant/questionnaire.php, question 1).
   Stored in the database table job_positions; the PDFs are kept in Cloudinary (jd_url column).
     job_id, job_name, jd_url, jd_public_id, created_at, archived_at
   An applicant keeps position_id + position (the name when they were added).
   ========================================================================== */
const STATIC_JD_BYTES = 5 * 1024 * 1024;                   // 5 MB at most, like the CV

// Active positions in the order they were created. BACKEND: SELECT job_id, job_name, jd_url FROM job_positions WHERE archived_at IS NULL ORDER BY job_id
function static_positions(): array
{
    $pdo = db();
    // Cloudinary is the source of truth: link PDFs that exist in the folder but are not saved on a position yet.
    try {
        require_once __DIR__ . '/../src/Repositories/ApplicantRepository.php';
        repo_sync_position_jds($pdo);
    } catch (Throwable $e) {
        error_log('Job description sync error: ' . $e->getMessage());
    }
    $stmt = $pdo->query("SELECT job_id, job_name, jd_url, jd_public_id, created_at, archived_at FROM job_positions WHERE archived_at IS NULL ORDER BY job_id");
    $out = [];
    while ($row = $stmt->fetch()) {
        $out[] = [
            'id'          => (int) $row['job_id'],
            'name'        => (string) $row['job_name'],
            'jd'          => !empty($row['jd_url']) ? [
                'name'       => '',
                'size'       => 0,
                'url'        => (string) $row['jd_url'],
                'public_id'  => (string) ($row['jd_public_id'] ?? '')
            ] : null,
            'created'     => (string) $row['created_at'],
            'archived_at' => empty($row['archived_at']) ? null : (string) $row['archived_at']
        ];
    }
    return $out;
}

function static_position_find(int $id): ?array
{
    if ($id <= 0) return null;
    $pdo = db();
    $stmt = $pdo->prepare("SELECT job_id, job_name, jd_url, jd_public_id, created_at, archived_at FROM job_positions WHERE job_id = :id AND archived_at IS NULL LIMIT 1");
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();
    if (!$row) return null;
    return [
        'id'          => (int) $row['job_id'],
        'name'        => (string) $row['job_name'],
        'jd'          => !empty($row['jd_url']) ? [
            'name'       => '',
            'size'       => 0,
            'url'        => (string) $row['jd_url'],
            'public_id'  => (string) ($row['jd_public_id'] ?? '')
        ] : null,
        'created'     => (string) $row['created_at'],
        'archived_at' => empty($row['archived_at']) ? null : (string) $row['archived_at']
    ];
}

// What the page / JavaScript gets for a position (never a server path)
function static_position_public(array $p): array
{
    return [
        'id' => (int) ($p['id'] ?? $p['job_id'] ?? 0),
        'name'    => (string) ($p['name'] ?? $p['job_name'] ?? ''),
        'has_jd'  => !empty($p['jd']['url']),
        'jd_name' => !empty($p['jd']['name']) ? (string) $p['jd']['name'] : '',
        'jd_url'  => !empty($p['jd']['url']) ? (string) $p['jd']['url'] : ''
    ];
}

// The address of a position's job description PDF, or '' when it has none.
// Returns the Cloudinary URL from the job_positions table, or '' when none is set.
// The JD URL is always the Cloudinary secure_url stored in the job_positions table.
function static_position_jd_url(?array $position): string
{
    if ($position && !empty($position['jd']['url'])) return (string) $position['jd']['url'];


    return '';

}

// "+ Add job position": creates a position, and keeps its job description PDF (optional here; without it the
// applicants of that position are shown the sample job description). Throws InvalidArgumentException.
function static_position_add(string $name, ?array $jdUpload = null): array
{
    $name = trim(preg_replace('/\s+/u', ' ', $name));
    if ($name === '')                                                     throw new InvalidArgumentException('Please enter the job position.');
    if (mb_strlen($name) > 80)                                            throw new InvalidArgumentException('The job position is too long (80 characters at most).');
    if (!preg_match('/^[\p{L}\p{M}\p{N}\s&\/\-.,()\'+#]+$/u', $name))     throw new InvalidArgumentException('The job position has a character that is not allowed. Use letters, numbers, spaces and & / - . , ( ) only.');
    if (preg_match_all('/\p{L}/u', $name) < 2)                            throw new InvalidArgumentException('The job position is too short (at least 2 letters).');

    // the PDF (if one was chosen): checked BEFORE anything is saved
    $hasUpload = $jdUpload && (int) ($jdUpload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
    if ($hasUpload) {
        $err = (int) $jdUpload['error'];
        if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) throw new InvalidArgumentException('The job description is too big (5 MB at most).');
        if ($err !== UPLOAD_ERR_OK)                                        throw new InvalidArgumentException('The job description could not be uploaded. Please try again.');
        $tmp = (string) ($jdUpload['tmp_name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp))                        throw new InvalidArgumentException('The job description could not be uploaded. Please try again.');
        if (strtolower(pathinfo((string) ($jdUpload['name'] ?? ''), PATHINFO_EXTENSION)) !== 'pdf') throw new InvalidArgumentException('The job description must be a PDF file.');
        if ((int) $jdUpload['size'] <= 0)                                  throw new InvalidArgumentException('This file is empty. Choose a different PDF.');
        if ((int) $jdUpload['size'] > STATIC_JD_BYTES)                     throw new InvalidArgumentException('The job description is too big (5 MB at most).');
        $head = (string) @file_get_contents($tmp, false, null, 0, 5);      // a real PDF starts with %PDF-
        if ($head !== '%PDF-')                                             throw new InvalidArgumentException('That file is not a valid PDF.');

        // It must really BE a job description, and for THIS position: a resume / CV, any other document, or
        // the job description of a different position is refused.
        require_once __DIR__ . '/../src/Services/CvParserService.php';
        try {
            $verdict = (new CvParserService())->classifyJobDescription($tmp, (string) ($jdUpload['name'] ?? ''), 'application/pdf', $name);
        } catch (Throwable $e) {
            error_log('Job description check error: ' . $e->getMessage());
            throw new InvalidArgumentException('We could not read this PDF to check that it is a job description. Upload a text-based PDF (not a scan or a photo).');
        }
        if (!$verdict['ok']) throw new InvalidArgumentException($verdict['message']);
    }

    // Check for duplicate active name in the database
    $pdo = db();
    $stmt = $pdo->prepare("SELECT job_id FROM job_positions WHERE archived_at IS NULL AND LOWER(job_name) = LOWER(:name) LIMIT 1");
    $stmt->execute([':name' => $name]);
    if ($stmt->fetch()) {
        throw new InvalidArgumentException('This job position already exists. Choose it from the list.');
    }

    $jdUrl   = null;
    $jdPubId = null;
    $jdName  = '';
    if ($hasUpload) {
        try {
            require_once __DIR__ . '/../src/Services/CloudinaryService.php';
            $cloud = new CloudinaryService();
            $uploaded = $cloud->uploadJobDescription(
                (string) $jdUpload['tmp_name'],
                0,  // position id not known yet; Cloudinary filename uses it, but we use unique_filename=false anyway
                'ebsmi-job-position-pdfs',
                (string) ($jdUpload['name'] ?? '')
            );
            $jdUrl   = $uploaded['url'];
            $jdPubId = $uploaded['public_id'] ?? '';
            $jdName  = $uploaded['name'];
        } catch (Throwable $e) {
            error_log('Job Description Cloudinary upload error: ' . $e->getMessage());
            throw new InvalidArgumentException('The job description could not be uploaded. Please try again.');
        }
    }

    $stmt = $pdo->prepare("INSERT INTO job_positions (job_name, jd_url, jd_public_id, created_at, archived_at) VALUES (:name, :jd_url, :jd_public_id, :created_at, NULL) RETURNING job_id, job_name, jd_url, jd_public_id, created_at, archived_at");
    $stmt->execute([
        ':name'         => $name,
        ':jd_url'       => $jdUrl,
        ':jd_public_id' => $jdPubId,
        ':created_at'   => static_now()->format('Y-m-d H:i:s')
    ]);
    $row = $stmt->fetch();

    $saved = [
        'id'          => (int) $row['job_id'],
        'name'        => (string) $row['job_name'],
        'jd'          => !empty($row['jd_url']) ? [
            'name'       => $jdName,
            'size'       => (int) ($jdUpload['size'] ?? 0),
            'url'        => (string) $row['jd_url'],
            'public_id'  => (string) ($row['jd_public_id'] ?? '')
        ] : null,
        'created'     => (string) $row['created_at'],
        'archived_at' => empty($row['archived_at']) ? null : (string) $row['archived_at']
    ];
    return static_position_public($saved);
}

// Archive a job position and delete its Cloudinary job-description PDF.
// Returns the archived position. Throws InvalidArgumentException if not found or in use.
function static_position_applicant_count(int $id): int
{
    $stmt = db()->prepare('SELECT COUNT(*) FROM applicant WHERE position_id = :id');
    $stmt->execute([':id' => $id]);
    return (int) $stmt->fetchColumn();
}

function static_position_delete(int $id): array
{
    $pdo = db();

    $stmt = $pdo->prepare("SELECT job_id, job_name, jd_public_id, archived_at FROM job_positions WHERE job_id = :id LIMIT 1");
    $stmt->execute([':id' => $id]);
    $pos = $stmt->fetch();
    if (!$pos) throw new InvalidArgumentException('That job position was not found.');
    if (!empty($pos['archived_at'])) throw new InvalidArgumentException('That job position is already archived.');

    $applicantCount = static_position_applicant_count($id);
    if ($applicantCount > 0) {
        throw new InvalidArgumentException(
            'This job position is assigned to ' . $applicantCount . ' applicant' . ($applicantCount === 1 ? '' : 's')
            . ' (including archived applicants) and cannot be deleted.'
        );
    }

    // Delete the job description PDF from Cloudinary if it has one
    if (!empty($pos['jd_public_id'])) {
        try {
            require_once __DIR__ . '/../src/Services/CloudinaryService.php';
            $cloud = new CloudinaryService();
            // Uploads post to /auto/upload with no explicit type; try common types.
            foreach (['upload', 'default', 'private'] as $type) {
                try { $cloud->deleteResource((string) $pos['jd_public_id'], 'auto', $type); } catch (Throwable $ignored) {}
            }
        } catch (Throwable $e) {
            error_log('Job Description Cloudinary delete error: ' . $e->getMessage());
            // continue archiving even if Cloudinary delete fails
        }
    }

    $stmt = $pdo->prepare("UPDATE job_positions SET archived_at = :archived_at WHERE job_id = :job_id RETURNING job_id, job_name, jd_url, jd_public_id, created_at, archived_at");
    $stmt->execute([
        ':archived_at' => static_now()->format('Y-m-d H:i:s'),
        ':job_id'      => $id
    ]);
    $row = $stmt->fetch();

    $saved = [
        'id'          => (int) $row['job_id'],
        'name'        => (string) $row['job_name'],
        'jd'          => !empty($row['jd_url']) ? [
            'name'       => '',
            'size'       => 0,
            'url'        => (string) $row['jd_url'],
            'public_id'  => (string) ($row['jd_public_id'] ?? '')
        ] : null,
        'created'     => (string) $row['created_at'],
        'archived_at' => empty($row['archived_at']) ? null : (string) $row['archived_at']
    ];
    return static_position_public($saved);
}

// ---------------------------------------------------------------------------
// INTERVIEWS (the table "interviews", one row per applicant). Shared by BOTH sides:
//   the APPLICANT sends a preferred date + time   applicant/application-status.php  -> static_interview_request()
//   HR confirms it or sends another one           hr/details.php (Interview Schedule)  -> hr/applicant-details-data.php
//   status: awaiting     waiting for HR                     (the applicant page calls it "pending")
//           confirmed    HR said "Available"
//           rescheduled  HR sent a new date + time          (the applicant page calls it "suggested")
// Dates are 'Y-m-d', times are 24-hour 'H:i' in the store; the applicant page shows '10:00 AM'.
// DATABASE (later): SELECT / INSERT / UPDATE on an interviews table with the same columns.
// ---------------------------------------------------------------------------
const STATIC_INTERVIEW_TYPE = 'On Site Interview';

// The times that can be chosen (same list as $slots in application-status.php)
function static_interview_slots(): array
{
    return ['06:00 PM', '07:00 PM', '08:00 PM', '09:00 PM', '10:00 PM', '11:00 PM', '12:00 AM', '01:00 AM', '02:00 AM', '03:00 AM'];
}

function static_interview_time24(string $time12): ?string
{
    $d = DateTimeImmutable::createFromFormat('h:i A', trim($time12));
    return $d ? $d->format('H:i') : null;
}

function static_interview_time12(?string $time24): string
{
    $d = $time24 ? DateTimeImmutable::createFromFormat('H:i', $time24) : false;
    return $d ? $d->format('h:i A') : '';
}

// Gets the interview row for an applicant (or null when there is none yet).
function static_interview_get(int $applicantId): ?array
{
    $pdo = db();
    $stmt = $pdo->prepare("SELECT status, preferred_date, preferred_time, date, time, type, source, updated_at AS updated FROM interviews WHERE applicant_id = :aid LIMIT 1");
    $stmt->execute([':aid' => $applicantId]);
    $row = $stmt->fetch();
    if (!$row) return null;
    return [
        'status'         => (string) $row['status'],
        'preferred_date' => (string) $row['preferred_date'],
        'preferred_time' => (string) $row['preferred_time'],
        'type'           => (string) $row['type'],
        'date'           => $row['date'] ? (string) $row['date'] : null,
        'time'           => $row['time'] ? (string) $row['time'] : null,
        'source'         => (string) $row['source'],
        'updated'        => (string) $row['updated'],
    ];
}

// The applicant picked a date and time (status: awaiting). Replaces an older request.
function static_interview_request(int $applicantId, string $date, string $time24, string $source = 'applicant'): array
{
    $pdo = db();
    $now = static_now()->format('Y-m-d H:i:s');
    $stmt = $pdo->prepare("
        INSERT INTO interviews (applicant_id, status, preferred_date, preferred_time, type, source, updated_at)
        VALUES (:aid, 'awaiting', :date, :time, 'On Site Interview', :source, :updated)
        ON CONFLICT (applicant_id) DO UPDATE SET
            status = 'awaiting',
            preferred_date = :date,
            preferred_time = :time,
            source = :source,
            updated_at = :updated
        RETURNING status, preferred_date, preferred_time, type, date, time, source, updated_at AS updated
    ");
    $stmt->execute([
        ':aid'     => $applicantId,
        ':date'    => $date,
        ':time'    => $time24,
        ':source'  => $source,
        ':updated' => $now,
    ]);
    $row = $stmt->fetch();
    return [
        'status'         => (string) $row['status'],
        'preferred_date' => (string) $row['preferred_date'],
        'preferred_time' => (string) $row['preferred_time'],
        'type'           => (string) $row['type'],
        'date'           => $row['date'] ? (string) $row['date'] : null,
        'time'           => $row['time'] ? (string) $row['time'] : null,
        'source'         => (string) $row['source'],
        'updated'        => (string) $row['updated'],
    ];
}

function static_interview_session_shape(array $row): array
{
    $shape = [
        'date'   => (string) $row['preferred_date'],
        'time'   => static_interview_time12($row['preferred_time']),
        'status' => $row['status'] === 'confirmed' ? 'confirmed' : ($row['status'] === 'rescheduled' ? 'suggested' : 'pending')
    ];
    if ($row['status'] === 'rescheduled') {
        $shape['new_date'] = (string) $row['date'];
        $shape['new_time'] = static_interview_time12($row['time']);
    }
    return $shape;
}

// Status change (this is what the screening itself will call later).
// Only a Completed screening has a result.
function static_set_status(int $id, string $status, ?string $result = null): bool
{
    $valid = ['Pending', 'In Progress', 'Completed'];
    if (!in_array($status, $valid, true)) return false;
    if ($status !== 'Completed' || !in_array($result, ['Passed', 'Rejected'], true)) $result = null;

    require_once __DIR__ . '/../src/Repositories/ApplicantRepository.php';
    return repo_applicant_set_status((int) $id, $status, $result);
}

// Screening List "Delete": moves applicants to the Archive. Returns how many were moved.
// $at (optional) = [ id => time in milliseconds ]: keeps the original archive time (used by Undo
// and by the archive.php?demo=1 test data).
function static_archive(array $ids, array $at = []): int
{
    $ids = array_map('intval', $ids);
    if (empty($ids)) return 0;
    $pdo = db();
    require_once __DIR__ . '/../src/Repositories/ApplicantRepository.php';
    $zone = new DateTimeZone('Asia/Manila');
    $now  = static_now()->format('Y-m-d H:i:s');
    $n = 0;
    $names = [];
    if (!empty($ids)) {
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $ns = $pdo->prepare("SELECT applicant_id, name FROM applicant WHERE applicant_id IN ($ph)");
        $ns->execute($ids);
        foreach ($ns->fetchAll() as $nr) $names[(int) $nr['applicant_id']] = (string) $nr['name'];
    }
    foreach ($ids as $id) {
        $deletedAt = $now;
        if (!empty($at[$id]) && is_numeric($at[$id]) && $at[$id] > 0) {
            $deletedAt = (new DateTimeImmutable('@' . intdiv((int) $at[$id], 1000)))->setTimezone($zone)->format('Y-m-d H:i:s');
        }
        $stmt = $pdo->prepare("UPDATE applicant SET deleted_at = :da WHERE applicant_id = :id AND deleted_at IS NULL");
        $stmt->execute([':da' => $deletedAt, ':id' => $id]);
        if ($stmt->rowCount() > 0) {
            try { repo_log_activity('Archived Applicant', ($names[$id] ?? 'ID #' . $id) . ' · ID #' . $id); } catch (Throwable $e) {}
            $n++;
        }
    }
    return $n;
}

// Archive "Restore": back to the Screening List with their old status and result
function static_restore(array $ids): int
{
    $ids = array_map('intval', $ids);
    if (empty($ids)) return 0;
    $pdo = db();
    $n = 0;
    $names = [];
    if (!empty($ids)) {
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $ns = $pdo->prepare("SELECT applicant_id, name FROM applicant WHERE applicant_id IN ($ph) AND deleted_at IS NOT NULL");
        $ns->execute($ids);
        foreach ($ns->fetchAll() as $nr) $names[(int) $nr['applicant_id']] = (string) $nr['name'];
    }
    foreach ($ids as $id) {
        $stmt = $pdo->prepare(
            "UPDATE applicant SET deleted_at = NULL, restored_at = NOW() AT TIME ZONE 'Asia/Manila'" .
            " WHERE applicant_id = :id AND deleted_at IS NOT NULL"
        );
        $stmt->execute([':id' => $id]);
        if ($stmt->rowCount() > 0) {
            try { repo_log_activity('Restored Applicant', ($names[$id] ?? 'ID #' . $id) . ' · ID #' . $id); } catch (Throwable $e) {}
            $n++;
        }
    }
    return $n;
}

// Archive "Delete forever": only archived applicants can be removed for good
function static_purge(array $ids): int
{
    $ids = array_map('intval', $ids);
    if (empty($ids)) return 0;
    $pdo = db();
    require_once __DIR__ . '/../src/Repositories/ApplicantRepository.php';
    repo_retain_applicant_fingerprints($ids, $pdo);
    repo_delete_applicant_media($ids, $pdo);
    $names = [];
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $ns = $pdo->prepare("SELECT applicant_id, name FROM applicant WHERE applicant_id IN ($ph) AND deleted_at IS NOT NULL");
    $ns->execute($ids);
    foreach ($ns->fetchAll() as $nr) $names[(int) $nr['applicant_id']] = (string) $nr['name'];
    $stmt = $pdo->prepare("DELETE FROM applicant WHERE applicant_id IN ($ph) AND deleted_at IS NOT NULL");
    $stmt->execute($ids);
    $n = $stmt->rowCount();
    $label = static_names_label(array_values($names));
    try { repo_log_activity('Purged Applicants', $label . ' · ' . $n . ' permanently deleted'); } catch (Throwable $e) {}
    return (int) $n;
}

// Archived applicants older than STATIC_ARCHIVE_DAYS are removed automatically
function static_purge_expired(): int
{
    $limit = static_now()->modify('-' . STATIC_ARCHIVE_DAYS . ' days')->format('Y-m-d H:i:s');
    $pdo = db();
    $st = $pdo->prepare("SELECT applicant_id FROM applicant WHERE deleted_at IS NOT NULL AND deleted_at < :limit");
    $st->execute([':limit' => $limit]);
    $ids = array_values(array_filter(array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN)), fn($i) => $i > 0));
    if ($ids) {
        require_once __DIR__ . '/../src/Repositories/ApplicantRepository.php';
        repo_retain_applicant_fingerprints($ids, $pdo);
        repo_delete_applicant_media($ids, $pdo);
    }
    $stmt = $pdo->prepare("DELETE FROM applicant WHERE deleted_at IS NOT NULL AND deleted_at < :limit");
    $stmt->execute([':limit' => $limit]);
    return (int) $stmt->rowCount();
}


/* ==========================================================================
   PROFILE (used by BOTH sides, so it lives in this shared file)
   Profile   Name, email and picture of the signed-in Admin / HR (Settings). HR changes also write a line to the
             activity log, so the Admin sees them under Recent Activities.
   Password  Changed at once for both Admin and HR -- no approval flow.
   Store     hr_accounts[].avatar | admin_account {name,email,hash,avatar} | activity lines with
             kind / owner / ref. Pictures are kept in data/avatars/.
   Endpoint  the actions profile_save, password_change at the bottom of this file.
   BACKEND   UPDATE users SET name, email, avatar_url, password_hash ...; Cloudinary for the picture.
   ========================================================================== */


const STATIC_AVATAR_DIR   = __DIR__ . '/data/avatars';       // where the pictures are kept (next to data/cv)
const STATIC_AVATAR_BYTES = 10 * 1024 * 1024;                // 10 MB at most
const STATIC_ADMIN_EMAIL  = 'admin@expertise.com';           // the Admin's email until the Admin changes it

// An error the Settings page can show under a specific field ('name', 'email', 'avatar', 'pwCurrent', 'pwNew')
class StaticProfileError extends InvalidArgumentException
{
    public $field = '';

    public function __construct(string $message, string $field = '')
    {
        parent::__construct($message);
        $this->field = $field;
    }
}

/* ==========================================================================
   SAFETY NET: the same two helpers admin/question-admin.php has, so the HR pages can use them too.
   Each one only exists "if missing", so whichever file loads first wins and nothing is defined twice.
   ========================================================================== */
if (!function_exists('static_seed_hr_accounts')) {
    function static_seed_hr_accounts(): array
    {
        $pdo = db();
        $stmt = $pdo->query("SELECT user_id AS id, name, email, role, deleted_at, created_at FROM users WHERE role = 'hr' ORDER BY user_id");
        $out = [];
        while ($row = $stmt->fetch()) {
            $out[] = [
    'id' => (int) $row['job_id'],
                'name'        => (string) $row['name'],
                'email'       => (string) $row['email'],
                'role'        => 'HR Staff',
                'status'      => empty($row['deleted_at']) ? 'Active' : 'Inactive',
                'created'     => $row['created_at'] ? date('Y-m-d H:i', strtotime((string) $row['created_at'])) : '',
                'archived_at' => $row['deleted_at'] ? (string) $row['deleted_at'] : null,
            ];
        }
        return $out;
    }
}

if (!function_exists('static_hr_accounts')) {
    function static_hr_accounts(): array
    {
        return static_seed_hr_accounts();
    }
}

/* ==========================================================================
   WHO IS SIGNED IN
   ========================================================================== */

// Every HR account row from the users table
function static_profile_hr_list(): array
{
    $pdo = db();
    $stmt = $pdo->query("SELECT user_id AS id, name, email, role, deleted_at FROM users WHERE role = 'hr' ORDER BY user_id");
    $out = [];
    while ($row = $stmt->fetch()) {
        $out[] = [
            'id'         => (int) $row['id'],
            'name'       => (string) $row['name'],
            'email'      => (string) $row['email'],
            'role'       => 'HR Staff',
            'status'     => empty($row['deleted_at']) ? 'Active' : 'Inactive',
            'archived_at' => empty($row['deleted_at']) ? null : (string) $row['deleted_at'],
        ];
    }
    return $out;
}

// The Admin's profile: from the users table
function static_profile_admin(): array
{
    require_once __DIR__ . '/../src/Helpers/db.php';
    $pdo = db();
    $stmt = $pdo->prepare("SELECT name, email, avatar_url FROM users WHERE role = 'admin' AND deleted_at IS NULL LIMIT 1");
    $stmt->execute();
    $a = $stmt->fetch();
    if (!$a) {
        return ['name' => 'Admin', 'email' => STATIC_ADMIN_EMAIL, 'avatar' => null];
    }
    return [
        'name'   => (string) ($a['name'] ?? 'Admin'),
        'email'  => strtolower(trim((string) ($a['email'] ?? STATIC_ADMIN_EMAIL))),
        'avatar' => !empty($a['avatar_url']) ? (string) $a['avatar_url'] : null
    ];
}

// The Admin's email right now (used to keep an HR account from taking it)
function static_profile_admin_email(): string
{
    return static_profile_admin()['email'];
}

// The signed-in person: ['role', 'id', 'name', 'email', 'avatar'], or null (not signed in / the account is gone).
// HR is found by the email the session holds (it is updated when HR changes the email).
function static_profile_me(): ?array
{
    require_once __DIR__ . '/../src/Helpers/db.php';
    $role = (string) ($_SESSION['user_role'] ?? '');

    if ($role === 'admin') {
        $a = static_profile_admin();
        return ['role' => 'admin', 'id' => 0, 'name' => $a['name'], 'email' => $a['email'], 'avatar' => $a['avatar']];
    }

    if ($role === 'hr') {
        $pdo = db();
        $email = strtolower(trim((string) ($_SESSION['user_email'] ?? '')));
        if ($email === '') return null;
        $stmt = $pdo->prepare("SELECT user_id AS id, name, email, avatar_url, deleted_at FROM users WHERE role = 'hr' AND deleted_at IS NULL");
        $stmt->execute();
        while ($row = $stmt->fetch()) {
            if (strcasecmp((string) ($row['email'] ?? ''), $email) === 0) {
                return [
                    'role'   => 'hr',
                    'id'     => (int) ($row['id'] ?? 0),
                    'name'   => (string) ($row['name'] ?? 'HR'),
                    'email'  => strtolower((string) $row['email']),
                    'avatar' => !empty($row['avatar_url']) ? (string) $row['avatar_url'] : null
                ];
            }
        }
    }
    return null;
}

/* ==========================================================================
   THE PICTURE
   ========================================================================== */

// "Anna Marie Reyes" -> "AR", "HR" -> "HR", "Admin" -> "AD"  (shown when there is no picture; assets/js has the same rule)
function static_profile_initials(string $name): string
{
    $parts = preg_split('/\s+/u', trim($name), -1, PREG_SPLIT_NO_EMPTY);
    if (!$parts) return '?';
    if (count($parts) === 1) return mb_strtoupper(mb_substr($parts[0], 0, 2));
    return mb_strtoupper(mb_substr($parts[0], 0, 1) . mb_substr($parts[count($parts) - 1], 0, 1));
}

// A picture file name is only ever one we made ourselves: letters, digits and dashes + jpg / png / webp
function static_profile_avatar_name_ok(?string $file): bool
{
    // Cloudinary absolute URLs (res.cloudinary.com) are also accepted.
    if (is_string($file) && strpos((string) $file, '://') !== false) return true;
    return $file !== null && $file !== '' && preg_match('/^[a-z0-9\-]+\.(?:jpg|png|webp)$/', (string) $file) === 1;
}

// The address of the picture for a page ($base = how that page reaches hr/data/avatars/), '' when there is none.
// ?v= is the file's time, so a new picture always shows (the file name changes too).
function static_profile_avatar_url(?string $file, string $base): string
{
    // Cloudinary URL: return as-is (already includes /v= timestamp).
    if (is_string($file) && strpos((string) $file, '://') !== false) return (string) $file;
    if (!static_profile_avatar_name_ok($file)) return '';
    $path = STATIC_AVATAR_DIR . '/' . $file;
    if (!is_file($path)) return '';
    return $base . rawurlencode($file) . '?v=' . (int) @filemtime($path);
}

// The little round picture (or the initials). $class adds a size, for example 'avatar-xl'.
function static_profile_avatar_html(string $name, string $url, string $class = ''): string
{
    $cls = htmlspecialchars(trim('avatar ' . $class . ($url !== '' ? ' has-img' : '')), ENT_QUOTES, 'UTF-8');
    if ($url !== '') {
        return '<span class="' . $cls . '" aria-hidden="true"><img src="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '" alt=""></span>';
    }
    return '<span class="' . $cls . '" aria-hidden="true">' . htmlspecialchars(static_profile_initials($name), ENT_QUOTES, 'UTF-8') . '</span>';
}

function static_profile_delete_avatar(?string $file): void
{
    if (!static_profile_avatar_name_ok($file)) return;
    $path = STATIC_AVATAR_DIR . '/' . $file;
    if (is_file($path)) @unlink($path);
}

// Checks an uploaded picture and keeps it in hr/data/avatars/. Returns the new file name.
// The type is read from the file itself (never from its name), and only JPG / PNG / WEBP are accepted.
function static_profile_store_avatar(array $upload, string $prefix): string
{
    $err = (int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) {
        throw new StaticProfileError('The picture is too big (10 MB at most).', 'avatar');
    }
    if ($err !== UPLOAD_ERR_OK) {
        throw new StaticProfileError('The picture could not be uploaded. Please try again.', 'avatar');
    }

    $tmp = (string) ($upload['tmp_name'] ?? '');
    if ($tmp === '' || !is_file($tmp)) {
        throw new StaticProfileError('The picture could not be uploaded. Please try again.', 'avatar');
    }
    if ((int) ($upload['size'] ?? 0) <= 0 || (int) $upload['size'] > STATIC_AVATAR_BYTES) {
        throw new StaticProfileError('The picture is too big (10 MB at most).', 'avatar');
    }

    $info  = @getimagesize($tmp);
    $types = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp'];
    if (!$info || !isset($types[$info[2]])) {
        throw new StaticProfileError('Choose a JPG, PNG or WEBP picture.', 'avatar');
    }
    // Derive the MIME type from getimagesize() so it is always a real image type,
    // even if the server's mime_content_type() extension is missing or misconfigured.
    $mimeType = $info[2] === IMAGETYPE_JPEG ? 'image/jpeg' : ($info[2] === IMAGETYPE_PNG ? 'image/png' : 'image/webp');

    // Cloudinary upload — profile pictures are stored in the eb-smssi-user-profiles folder.
    // Admin = 0, HR = numeric id extracted from the prefix ('hr-123' -> 123).
    require_once __DIR__ . '/../src/Services/CloudinaryService.php';
    $applicantId = ($prefix === 'admin') ? 0 : (int) substr($prefix, 3);
    $cloud = new CloudinaryService();
    $result = $cloud->uploadAvatar(
        $tmp,
        $applicantId,
        'ebsmi-user-profiles',
        null,
        $mimeType   // pass the confirmed MIME type instead of relying on mime_content_type()
    );

    // Return the Cloudinary secure_url (e.g. https://res.cloudinary.com/y0s0brvb/image/upload/v.../avatar-123-my-photo.jpg)
    return $result['url'];
}

/* ==========================================================================
   NAME + EMAIL (same rules as the Settings page, checked again here)
   ========================================================================== */
function static_profile_check_name(string $name): string
{
    $name = trim($name);
    if ($name === '')                                         throw new StaticProfileError('Please enter your name.', 'name');
    if (mb_strlen($name) > 100)                               throw new StaticProfileError('The name is too long (100 characters at most).', 'name');
    if (preg_match('/\d/', $name))                            throw new StaticProfileError('The name cannot contain numbers.', 'name');
    if (!preg_match('/^[\p{L}\p{M}\'.\- ]+$/u', $name))       throw new StaticProfileError('Use letters only (spaces, apostrophes, periods and hyphens are fine).', 'name');
    if (preg_match_all('/\p{L}/u', $name) < 2)                throw new StaticProfileError('The name is too short (at least 2 letters).', 'name');
    return $name;
}

function static_profile_check_email(string $email): string
{
    $email = strtolower(trim($email));
    if ($email === '')                                        throw new StaticProfileError('Please enter your email address.', 'email');
    if (strlen($email) > 150)                                 throw new StaticProfileError('The email is too long (150 characters at most).', 'email');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL))           throw new StaticProfileError('Enter a valid email address (example: name@gmail.com).', 'email');
    return $email;
}

// True when the email belongs to somebody else: the Admin, or any HR account (an archived one too, like Add HR does)
function static_profile_email_taken(array $store, string $email, ?int $exceptHrId, bool $exceptAdmin): bool
{
    if (!$exceptAdmin && strcasecmp(static_profile_admin($store)['email'], $email) === 0) return true;
    foreach (static_profile_hr_list($store) as $a) {
        if (!is_array($a)) continue;
        if ($exceptHrId !== null && (int) ($a['id'] ?? 0) === $exceptHrId) continue;
        if (strcasecmp((string) ($a['email'] ?? ''), $email) === 0) return true;
    }
    return false;
}

// Adds one line to the activity log inside a store you are already changing, plus extra fields
// (kind / owner / ref / changes) that static_log_into() does not know about.
function static_profile_log(array &$store, string $activity, string $details, string $by, array $extra = []): void
{
    static_log_into($store, $activity, $details, $by);
    if (!$extra || empty($store['activity'])) return;
    $last = array_key_last($store['activity']);
    if ($last !== null) $store['activity'][$last] += $extra;
}

/* ==========================================================================
   SAVE THE PROFILE (name, email, picture)
   $in = { name, email, avatar_remove }   $upload = the $_FILES['avatar'] entry, or null
   HR changes are written to the activity log, so the Admin sees them under Recent Activities.
   Returns ['name', 'email', 'avatar', 'changes' => [['field', 'from', 'to'], ...]]
   ========================================================================== */
// HR changes name / email / avatar; returns updated profile
function static_profile_save(array $in, ?array $upload): array
{
    require_once __DIR__ . '/../src/Helpers/db.php';
    $me = static_profile_me();
    if ($me === null || !in_array($me['role'], ['hr', 'admin'], true)) throw new StaticProfileError('Your account could not be found. Please log in again.');

    $name  = static_profile_check_name((string) ($in['name'] ?? ''));
    $email = static_profile_check_email((string) ($in['email'] ?? ''));

    // check email not taken by another HR or Admin
    $pdo = db();
    if ($me['role'] === 'admin') {
        // Admin id is synthetic (0); exclude the admin's own row by role instead
        $stmt = $pdo->prepare("SELECT user_id FROM users WHERE LOWER(email) = :email AND role != 'admin' AND deleted_at IS NULL");
        $stmt->execute([':email' => strtolower($email)]);
    } else {
        $stmt = $pdo->prepare("SELECT user_id FROM users WHERE LOWER(email) = :email AND user_id != :myid AND deleted_at IS NULL");
        $stmt->execute([':email' => strtolower($email), ':myid' => $me['id']]);
    }
    if ($stmt->fetch()) throw new StaticProfileError('This email is already in use by another account.');

    // User changing their own email must prove ownership with their current password
    if (strtolower(trim($email)) !== strtolower(trim((string) ($me['email'] ?? '')))) {
        $currentPassword = (string) ($in['current_password'] ?? '');
        if ($currentPassword === '') throw new StaticProfileError('Enter your current password to change your email.', 'pwCurrent');
        if (!static_profile_current_password_ok($me, $currentPassword)) {
            throw new StaticProfileError('The current password is not correct.', 'pwCurrent');
        }
    }

    $avatarUrl = $me['avatar'] ?? null;
    $avatarPublicId = null;

    if (!empty($in['avatar_remove'])) {
        $avatarUrl = null;
        if ($me['role'] === 'admin') {
            $stmt = $pdo->prepare("SELECT avatar_public_id FROM users WHERE role = 'admin' AND deleted_at IS NULL");
            $stmt->execute();
        } else {
            $stmt = $pdo->prepare("SELECT avatar_public_id FROM users WHERE user_id = :id");
            $stmt->execute([':id' => $me['id']]);
        }
        $old = $stmt->fetch();
        if ($old && !empty($old['avatar_public_id'])) {
            try {
                require_once __DIR__ . '/../src/Services/CloudinaryService.php';
                $cloud = new CloudinaryService();
                $cloud->deleteResource((string) $old['avatar_public_id'], 'image', 'upload');
            } catch (Throwable $e) { /* continue saving even if Cloudinary cleanup fails */ }
        }
    }

    // handle avatar upload
    if ($upload && !empty($upload['tmp_name']) && is_uploaded_file($upload['tmp_name'])) {
        try {
            require_once __DIR__ . '/../src/Services/CloudinaryService.php';
            $cloud = new CloudinaryService();
            $tmpFile = tempnam(sys_get_temp_dir(), 'avatar_');
            if (!@move_uploaded_file($upload['tmp_name'], $tmpFile)) {
                throw new StaticProfileError('Avatar upload failed.');
            }

            // Validate it is a real image (JPG/PNG/WEBP) and derive its MIME type,
            // exactly like static_profile_store_avatar() does, so Cloudinary always
            // receives an image with a confirmed content type.
            $info  = @getimagesize($tmpFile);
            $types = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp'];
            if (!$info || !isset($types[$info[2]])) {
                @unlink($tmpFile);
                throw new StaticProfileError('Choose a JPG, PNG or WEBP picture.', 'avatar');
            }
            $mimeType = $info[2] === IMAGETYPE_JPEG ? 'image/jpeg' : ($info[2] === IMAGETYPE_PNG ? 'image/png' : 'image/webp');

            $uploaded = $cloud->uploadAvatar(
                $tmpFile,
                $me['id'],
                'ebsmi-user-profiles',
                (string) ($upload['name'] ?? ''),
                $mimeType   // confirmed MIME type instead of relying on mime_content_type()
            );
            $avatarUrl = $uploaded['url'];
            $avatarPublicId = (string) ($uploaded['public_id'] ?? '');
            @unlink($tmpFile);

            // delete old avatar from Cloudinary if it had a public_id
            if ($me['role'] === 'admin') {
                $stmt = $pdo->prepare("SELECT avatar_public_id FROM users WHERE role = 'admin' AND deleted_at IS NULL");
                $stmt->execute();
            } else {
                $stmt = $pdo->prepare("SELECT avatar_public_id FROM users WHERE user_id = :id");
                $stmt->execute([':id' => $me['id']]);
            }
            $old = $stmt->fetch();
            if ($old && !empty($old['avatar_public_id'])) {
                try {
                    $cloud->deleteResource((string) $old['avatar_public_id'], 'image', 'upload');
                } catch (Throwable $e) { /* ignore */ }
            }
        } catch (StaticProfileError $e) {
            throw $e;
        } catch (Throwable $e) {
            error_log('Profile avatar upload failed: ' . $e->getMessage());
            throw new StaticProfileError('Avatar upload failed.');
        }
    }

    // update the database
    if ($me['role'] === 'admin') {
        // Admin id is 0 (synthetic); update by role instead
        $params = [
            ':name' => $name,
            ':email' => $email,
            ':en' => strtolower(trim($email)),
            ':avatar_url' => $avatarUrl,
        ];
        $sql = "UPDATE users SET name = :name, email = :email, email_normalized = :en, avatar_url = :avatar_url";
        if (!empty($in['avatar_remove']) || $avatarPublicId !== null) {
            $sql .= ", avatar_public_id = :avatar_public_id";
            $params[':avatar_public_id'] = $avatarPublicId;
        }
        $sql .= " WHERE role = 'admin' AND deleted_at IS NULL";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
    } else {
        $params = [
            ':name' => $name,
            ':email' => $email,
            ':en' => strtolower(trim($email)),
            ':avatar_url' => $avatarUrl,
            ':id' => $me['id']
        ];
        $sql = "UPDATE users SET name = :name, email = :email, email_normalized = :en, avatar_url = :avatar_url";
        if (!empty($in['avatar_remove']) || $avatarPublicId !== null) {
            $sql .= ", avatar_public_id = :avatar_public_id";
            $params[':avatar_public_id'] = $avatarPublicId;
        }
        $sql .= " WHERE user_id = :id";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
    }

    try { repo_log_activity('Updated ' . ucfirst($me['role']) . ' Profile', $name . ' (' . $email . ')'); } catch (Throwable $e) {}

    return [
        'id'     => $me['id'],
        'role'   => $me['role'],
        'name'   => $name,
        'email'  => strtolower(trim($email)),
        'avatar' => $avatarUrl
    ];
}

// Internal: check the old password the HR typed matches their stored hash (the same hash the login uses)
function static_profile_current_password_ok(array $me, string $given): bool
{
    if ($given === '') return false;
    if (($me['role'] ?? '') === 'hr' && !empty($me['id'])) {
        try {
            require_once __DIR__ . '/../src/Helpers/db.php';
            $st = db()->prepare("SELECT password_hash FROM users WHERE user_id = :id AND deleted_at IS NULL");
            $st->execute([':id' => (int) $me['id']]);
            $hash = (string) ($st->fetchColumn() ?: '');
            if ($hash !== '') return password_verify($given, $hash);
        } catch (Throwable $e) {
            error_log('Current password check (DB) failed: ' . $e->getMessage());
        }
    }
    $acc = static_accounts()[$me['email']] ?? null;
    if (!is_array($acc)) return false;
    if (!empty($acc['hash']))    return password_verify($given, (string) $acc['hash']);
    if (isset($acc['password'])) return hash_equals((string) $acc['password'], $given);
    return false;
}

// Same three rules as the Update Password pop-up
function static_profile_check_new_password(string $new, string $current): void
{
    if ($new === '') throw new StaticProfileError('Enter a new password.', 'pwNew');
    if (strlen($new) > 72) throw new StaticProfileError('The new password must be 72 bytes or fewer.', 'pwNew');
    if (strlen($new) < 8 || !preg_match('/[a-z]/', $new) || !preg_match('/[A-Z]/', $new) || !preg_match('/\d/', $new)) {
        throw new StaticProfileError('The new password does not meet all the requirements.', 'pwNew');
    }
    if ($new === $current)    throw new StaticProfileError('The new password must be different from the current one.', 'pwNew');
}

/* ---------- change the password ----------
   Applied at once for both Admin and HR. No approval flow.
   Returns ['mode' => 'applied', 'request' => null] */
function static_profile_change_password(string $current, string $new): array
{
    require_once __DIR__ . '/../src/Helpers/db.php';
    $me = static_profile_me();
    if ($me === null) throw new StaticProfileError('Your account could not be found. Please log in again.');

    if ($current === '') throw new StaticProfileError('Enter your current password.', 'pwCurrent');
    if (!static_profile_current_password_ok($me, $current)) {
        throw new StaticProfileError('The current password is not correct.', 'pwCurrent');
    }
    static_profile_check_new_password($new, $current);

    $pdo = db();
    $stmt = $pdo->prepare(
        "UPDATE users SET password_hash = :hash, remember_token = NULL, remember_token_expires_at = NULL WHERE user_id = :id"
    );
    $stmt->execute([':hash' => password_hash($new, PASSWORD_DEFAULT), ':id' => $me['id']]);
    if ($stmt->rowCount() === 0) throw new StaticProfileError('Your account could not be found. Please log in again.');
    try { repo_log_activity('Changed Password', $me['name'] . ' (' . $me['email'] . ')'); } catch (Throwable $e) {}
    return ['mode' => 'applied', 'request' => null];
}

/* ==========================================================================
   FOR THE PAGES
   ========================================================================== */

// Everything a page needs to draw the profile. $avatarBase = how that page reaches hr/data/avatars/
// (HR pages: 'data/avatars/'   Admin pages: '../hr/data/avatars/').
function static_profile_for_page(string $avatarBase): array
{
    $store = application_state_read();
    $me = static_profile_me();
    $role  = (string) ($_SESSION['user_role'] ?? '');

    $name  = $me !== null ? $me['name']  : trim((string) ($_SESSION['user_name'] ?? ''));
    $email = $me !== null ? $me['email'] : trim((string) ($_SESSION['user_email'] ?? ''));
    $file  = $me !== null ? $me['avatar'] : null;
    if ($name === '') $name = $role === 'admin' ? 'Admin' : 'HR';

    return [
        'role'        => $role,
        'role_label'  => $role === 'admin' ? 'Administrator' : 'HR Staff',
        'name'        => $name,
        'email'       => $email,
        'avatar'      => $file,
        'avatar_url'  => static_profile_avatar_url($file, $avatarBase),
        'avatar_base' => $avatarBase,
        'initials'    => static_profile_initials($name),
    ];
}

/* ==========================================================================
   ENDPOINT: the JavaScript posts here  ( fetch('hr-data.php', {method:'POST', ...}) )
   It only runs when this file is opened DIRECTLY; when a page does require_once on it,
   nothing below happens.

   Request (form fields or a JSON body):
       action = add | archive | restore | purge | status
       add      first_name, middle_name (optional), last_name, email, contact, position_id, cv (file)
       position_add  name, jd (a PDF, optional)     "+ Add job position" in Add Applicant
       position_delete_check id                    preflight the job-position deletion constraint
       position_delete id                          archive an unassigned position
       positions     (nothing)                      the list of job positions
       archive  ids = [101, 102], at = { id: ms }     Screening List "Delete" (at is optional)
       restore  ids = [101, 102]                      Archive "Restore"
       purge    ids = [101, 102]                      Archive "Delete forever"
       status   id, status, result                    Pending | In Progress | Completed (+ Passed | Rejected)
       activity (nothing)                             the latest HR activity and recent applicants for the HR Dashboard
        profile_save | password_change   Settings > Account (see PROFILE above)
   Every change that worked also writes one line to the activity log (static_log), which the Admin
   Dashboard shows under Recent Activities.
   Response (JSON):
       { status: 'success', ..., stats: {...}, completed_ids: [...], archived: N }
       { status: 'error', message: '...' }
   ========================================================================== */
if (isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) !== false
    && strcasecmp(realpath($_SERVER['SCRIPT_FILENAME']), __FILE__) === 0) {   // strcasecmp: Windows paths

    require_once __DIR__ . '/../src/Helpers/security.php';
    start_secure_session(1800);
    restore_remembered_session();
    header('Content-Type: application/json; charset=UTF-8');
    error_reporting(0);          // suppress warnings/notices that would corrupt the JSON response
    @ini_set('display_errors', '0');

    function static_respond(array $data, int $code = 200): void
    {
        http_response_code($code);
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Only a signed-in HR or Admin user may change applicants
    if (!in_array($_SESSION['user_role'] ?? '', ['hr', 'admin'], true)) {
        static_respond(['status' => 'error', 'message' => 'Your session has ended. Please log in again.'], 403);
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        static_respond(['status' => 'error', 'message' => 'Invalid request.'], 405);
    }

    // Accept a JSON body as well as normal form fields
    $input = $_POST;
    if (stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== false) {
        $json  = json_decode((string) file_get_contents('php://input'), true);
        $input = is_array($json) ? $json : [];
    }

    // the server threw a form away (post_max_size): the picture was far too big
    if (!$_POST && !$_FILES && stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') === false && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        static_respond(['status' => 'error', 'message' => 'The picture is too big (10 MB at most).', 'field' => 'avatar'], 413);
    }

    $action = (string) ($input['action'] ?? '');
    // profile / password actions: our own pages send this header, a form on another website cannot
    if (in_array($action, ['profile_save', 'password_change'], true)
        && ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'XMLHttpRequest') {
        static_respond(['status' => 'error', 'message' => 'Invalid request.'], 400);
    }
    $ids    = array_values(array_filter((array) ($input['ids'] ?? []), 'is_numeric'));

    try {
        $extra = [];

        switch ($action) {
            case 'add':
                $extra['applicant'] = static_add_applicant(
                    [
                        'first_name'  => (string) ($input['first_name']  ?? ''),
                        'middle_name' => (string) ($input['middle_name'] ?? ''),
                        'last_name'   => (string) ($input['last_name']   ?? ''),
                        'email'       => (string) ($input['email']       ?? ''),
                        'contact'     => (string) ($input['contact']     ?? ''),
                        'position_id' => (int) ($input['position_id']    ?? 0)
                    ],
                    isset($_FILES['cv']) && is_array($_FILES['cv']) ? $_FILES['cv'] : null
                );
                static_log('Added An Applicant', (string) $extra['applicant']['name'] . ' · ' . (string) ($extra['applicant']['email'] ?? '') . ' · ' . (string) ($extra['applicant']['position'] ?? ''));
                break;

            case 'parse_cv':
                if (!isset($_FILES['cv']) || !is_array($_FILES['cv']) || empty($_FILES['cv']['tmp_name'])) {
                    static_respond(['status' => 'error', 'message' => 'No CV file received. Please choose a file and try again.'], 400);
                }
                $cvFile = $_FILES['cv'];
                if ((int) ($cvFile['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                    static_respond(['status' => 'error', 'message' => 'The CV file could not be uploaded. Please try again.'], 400);
                }
                require_once __DIR__ . '/../src/Services/CvParserService.php';
                $parser = new CvParserService();
                try {
                    $parsed = $parser->parseFile(
                        $cvFile['tmp_name'],
                        $cvFile['name'] ?? null,
                        $cvFile['type'] ?? null
                    );
                } catch (Throwable $e) {
                    error_log('parse_cv failed: ' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
                    static_respond([
                        'status'  => 'error',
                        'message' => $e instanceof Exception ? $e->getMessage() : 'This CV could not be read. Try another PDF or a DOCX file.'
                    ], 422);
                }
                // A CV must show first name, last name, email and contact number (the middle name is optional).
                // If any is missing the file is refused and NOTHING is sent back to fill the form.
                if (!empty($parsed['missing'])) {
                    static_respond(['status' => 'invalid', 'message' => $parser->describeMissing($parsed['missing']), 'missing' => $parsed['missing']], 422);
                }
                static_respond([
                    'status' => 'success',
                    'parsed' => [
                        'first_name'  => (string) ($parsed['first_name']  ?? ''),
                        'middle_name' => (string) ($parsed['middle_name'] ?? ''),
                        'last_name'   => (string) ($parsed['last_name']   ?? ''),
                        'email'       => (string) ($parsed['email']       ?? ''),
                        'contact'     => (string) ($parsed['contact']     ?? ''),
                    ],
                    'missing' => $parsed['missing'] ?? [],
                ]);
                break;

            case 'position_add':
                $pos = static_position_add(
                    (string) ($input['name'] ?? ''),
                    isset($_FILES['jd']) && is_array($_FILES['jd']) ? $_FILES['jd'] : null
                );
                static_log('Job Position Added', $pos['name']);
                static_respond(['status' => 'success', 'position' => $pos, 'positions' => array_map('static_position_public', static_positions())]);
                break;

            case 'positions':
                static_respond(['status' => 'success', 'positions' => array_map('static_position_public', static_positions())]);
                break;

            case 'position_delete_check':
                $pid = (int) ($input['id'] ?? 0);
                if ($pid <= 0) static_respond(['status' => 'error', 'message' => 'Invalid position id.'], 400);
                if (static_position_find($pid) === null) {
                    static_respond(['status' => 'error', 'message' => 'That job position was not found or is already archived.'], 404);
                }
                static_respond(['status' => 'success', 'assigned_count' => static_position_applicant_count($pid)]);
                break;

            case 'position_delete':
                $pid = (int) ($input['id'] ?? 0);
                if ($pid <= 0) static_respond(['status' => 'error', 'message' => 'Invalid position id.'], 400);
                try {
                    $deleted = static_position_delete($pid);
                    static_log('Job Position Deleted', $deleted['name']);
                    static_respond([
                        'status'  => 'success',
                        'message' => 'Job position "' . htmlspecialchars($deleted['name'], ENT_QUOTES) . '" deleted.',
                        'id'      => $pid,
                    ]);
                } catch (InvalidArgumentException $e) {
                    static_respond(['status' => 'error', 'message' => $e->getMessage()], 400);
                } catch (Throwable $e) {
                    static_respond(['status' => 'error', 'message' => 'Could not delete the job position. Please try again.'], 500);
                }
                break;

            case 'archive':
                $names = static_applicant_names($ids, false);          // read BEFORE they move to the Archive
                $extra['count'] = static_archive($ids, (array) ($input['at'] ?? []));
                if ($extra['count'] > 0) static_log('Applicant Deleted', static_names_label($names));
                break;

            case 'restore':
                $names = static_applicant_names($ids, true);
                $extra['count'] = static_restore($ids);
                if ($extra['count'] > 0) static_log('Applicant Restored', static_names_label($names));
                break;

            case 'purge':
                $names = static_applicant_names($ids, true);
                $extra['count'] = static_purge($ids);
                if ($extra['count'] > 0) static_log('Applicant Deleted Forever', static_names_label($names));
                break;

            case 'status':
                $sid       = (int) ($input['id'] ?? 0);
                $newStatus = (string) ($input['status'] ?? '');
                $newResult = isset($input['result']) && $input['result'] !== '' ? (string) $input['result'] : null;
                if ($newStatus !== 'Completed' || !in_array($newResult, ['Passed', 'Rejected'], true)) $newResult = null;
                $before = null;
                foreach (static_applicants() as $row) {
                    if ((int) $row['id'] === $sid) { $before = $row; break; }
                }
                $ok = static_set_status($sid, $newStatus, $newResult);
                if (!$ok) static_respond(['status' => 'error', 'message' => 'That applicant or status was not found.'], 404);
                if ($before && ($before['status'] !== $newStatus || ($before['result'] ?? null) !== $newResult)) {
                    static_log('Applicant Status Changed', $before['name'] . ' · ' . $newStatus . ($newResult ? ' · ' . $newResult : ''));
                }
                break;

            case 'activity':
                // Refresh the bell and dashboard tables when a cached tab becomes visible again.
                require_once __DIR__ . '/../src/Repositories/ApplicantRepository.php';
                static_respond([
                    'status' => 'success',
                    'activity' => static_hr_feed(),
                    'completed_ids' => static_completed_ids(static_applicants()),
                    'recent_applicants' => repo_applicants_recent(null, 10)
                ]);
                break;

            case 'profile_save':
                $res = static_profile_save($input, isset($_FILES['avatar']) && is_array($_FILES['avatar']) ? $_FILES['avatar'] : null);
                // the greeting and the login keep following the new name / email
                $_SESSION['user_name']  = $res['name'];
                $_SESSION['user_email'] = $res['email'];
                $apath = $res['avatar'] !== null ? STATIC_AVATAR_DIR . '/' . $res['avatar'] : '';
                static_respond([
                    'status'  => 'success',
                    'message' => 'Profile saved.',
                    'profile' => [
                        'name'     => $res['name'],
                        'email'    => $res['email'],
                        'avatar'   => $res['avatar'],
                        'avatarUrl'=> $res['avatarUrl'] ?? '',
                        'avatar_v' => $apath !== '' && is_file($apath) ? (int) @filemtime($apath) : 0,
                        'initials' => static_profile_initials($res['name'])
                    ],
                    'changes' => $res['changes']
                ]);
                break;

            case 'password_change':
                $res = static_profile_change_password((string) ($input['current'] ?? ''), (string) ($input['next'] ?? ''));
                static_respond([
                'status'  => 'success',
                'message' => 'Password updated.',
                'mode'    => $res['mode'],
                'request' => $res['request']
                ]);
                break;

            default:
                static_respond(['status' => 'error', 'message' => 'Unknown action.'], 400);
        }

        // Fresh numbers so a page can update its cards, chart and badges
        $active = static_applicants();
        static_respond(['status' => 'success'] + $extra + [
            'stats'         => static_stats($active),
            'completed_ids' => static_completed_ids($active),
            'archived'      => count(static_archived_applicants())
        ]);

    } catch (StaticProfileError $e) {
        static_respond(['status' => 'error', 'message' => $e->getMessage(), 'field' => $e->field], 422);
    } catch (InvalidArgumentException $e) {
        static_respond(['status' => 'error', 'message' => $e->getMessage()], 422);
    } catch (Throwable $e) {
        error_log('hr-data.php action failed: ' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
        static_respond(['status' => 'error', 'message' => 'Something went wrong while saving. Please try again.'], 500);
    }
}