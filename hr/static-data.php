<?php
/* ==========================================================================
   STATIC DATA: the ONE place for every piece of sample data in the project.
   Everything here is temporary and is deleted when the database is connected.

   Who takes from this file
     index.php                static_accounts()        the HR / Admin login accounts
     hr/screening-list.php     static_applicants()      the applicants, with status + result
     hr/archive.php           static_applicants()      same list (the page shows the archived ones)
     hr/dashboard-hr.php       static_applicants()      the cards, the Passed/Rejected chart and
                              static_stats()           Recent Applicants are all worked out
                              static_completed_ids()   from this one list
                              static_recent()
                              static_dashboard_insights()   Funnel Drop-Off, Time-to-Complete
                                                       Distribution and Scheduling Turnaround Time

   Because every page reads the SAME list, they always agree: when an applicant is deleted
   (moved to the Archive) or deleted for good, the Screening List, the dashboard numbers, the
   chart and Recent Applicants all drop them (see applyData() in assets/js/dashboardhr.js).

   To change the sample data, change it HERE and every page follows.

   When the database is ready (backend), replace each function with a query:
     static_accounts()         ->  SELECT ... FROM users            (and use password_verify)
     static_applicants()       ->  SELECT id, name, email, contact, status, result, updated_at AS updated
                                   FROM applicants WHERE archived_at IS NULL
     static_stats()            ->  SELECT status, result, COUNT(*) ... GROUP BY ...
     static_completed_ids()    ->  SELECT id FROM applicants WHERE status = 'Completed'
     static_recent()           ->  ORDER BY updated_at DESC LIMIT 10
     static_dashboard_insights()  ->  three queries, see the BACKEND notes above that function
   ========================================================================== */

/* ==========================================================================
   HOW IT WORKS NOW (store layer, added at the bottom of this file)
   The list below (static_seed_applicants) is only the STARTING data. The first time any page
   asks for applicants, it is copied into  data/static-store.json  and from then on every page
   reads and writes THAT file, exactly like a table in a database:

     static_applicants()            active applicants (Screening List, Dashboard)
     static_archived_applicants()   archived applicants + "Deleted on" + days left (Archive page)
     static_add_applicant()         Add Applicant           -> Pending (first / middle / last name + job position)
     static_positions()             Job positions for the Add Applicant list (each has its own job description PDF)
     static_position_add()          "+ Add job position"   -> new position (+ its job description PDF)
     static_set_status()            status / result change  -> Pending / In Progress / Completed
     static_archive($ids)           Screening List "Delete" -> moves to the Archive
     static_restore($ids)           Archive "Restore"       -> back to the Screening List
     static_purge($ids)             Archive "Delete forever"

   THIS FILE IS ALSO THE ENDPOINT the JavaScript calls (see the bottom of the file): a POST to
   static-data.php with  action = add | archive | restore | purge | status  changes the store and
   answers with JSON. So everything static lives in this ONE file.
   data/static-store.json is only the saved data (created automatically, not code).
   To reset the sample data: delete data/static-store.json.
   To go live: delete this file + data/, and the backend answers the same POST requests.
   ========================================================================== */

// ---------------------------------------------------------------------------
// Login accounts (index.php). Passwords are plain text only because this is sample data.
// ---------------------------------------------------------------------------
function static_accounts(): array
{
    $accounts = [
        'hr@expertise.com' => [
            'password' => 'hr123',
            'role'     => 'hr',
            'name'     => 'HR',                       // shown in "Hello, ...!"
            'redirect' => 'hr/dashboard-hr.php'
        ],
        'admin@expertise.com' => [
            'password' => 'admin123',
            'role'     => 'admin',
            'name'     => 'Admin',
            'redirect' => 'admin/dashboard-admin.php'
        ]
    ];

    // Once the Admin has created / changed an HR account (admin/hr-list.php), the HR logins come from the
    // store instead: only Active accounts that are not in the Archive can log in, and a password the
    // Admin typed is kept as a hash ('hash'), checked with password_verify() in index.php.
    // BACKEND: SELECT name, email, password_hash FROM users WHERE role = 'hr' AND status = 'Active'
    if (is_file(STATIC_STORE_FILE)) {
        $store = json_decode((string) @file_get_contents(STATIC_STORE_FILE), true);

        // The Admin's own profile (admin/settingsadmin.php, saved in hr/static-data.php): a changed name, email or
        // password is kept in the store as "admin_account", and the login has to follow it. This runs BEFORE the HR
        // accounts below, so an email the Admin gave up can be used by an HR account.
        // BACKEND: SELECT name, email, password_hash FROM users WHERE role = 'admin'
        if (is_array($store) && isset($store['admin_account']) && is_array($store['admin_account'])) {
            $adm   = $store['admin_account'];
            $entry = $accounts['admin@expertise.com'];
            unset($accounts['admin@expertise.com']);
            if (trim((string) ($adm['name'] ?? '')) !== '') $entry['name'] = trim((string) $adm['name']);
            if (!empty($adm['hash'])) {
                $entry['hash'] = (string) $adm['hash'];
                unset($entry['password']);
            }
            $adminEmail = strtolower(trim((string) ($adm['email'] ?? '')));
            $accounts[$adminEmail !== '' ? $adminEmail : 'admin@expertise.com'] = $entry;
        }

        if (is_array($store) && isset($store['hr_accounts']) && is_array($store['hr_accounts'])) {
            unset($accounts['hr@expertise.com']);
            foreach ($store['hr_accounts'] as $a) {
                if (!is_array($a) || !empty($a['archived_at']) || ($a['status'] ?? 'Active') !== 'Active') continue;
                $email = strtolower((string) ($a['email'] ?? ''));
                if ($email === '' || isset($accounts[$email])) continue;
                $accounts[$email] = [
                    'password' => isset($a['password']) ? (string) $a['password'] : null,
                    'hash'     => isset($a['hash']) ? (string) $a['hash'] : null,
                    'role'     => 'hr',
                    'name'     => (string) ($a['name'] ?? 'HR'),
                    'redirect' => 'hr/dashboard-hr.php'
                ];
            }
        }
    }
    return $accounts;
}

// ---------------------------------------------------------------------------
// Applicants, with their status, result and the time of their latest activity.
//   status:  'Pending' | 'In Progress' | 'Completed'
//   result:  'Passed' | 'Rejected' | null   (only a Completed screening has a result)
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
        'failed'      => $count(fn($a) => strtolower((string) $a['status']) === 'completed' && strtolower((string) $a['result']) === 'rejected')
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
// Where every number comes from (so the backend knows exactly what to query):
//   applicants.invited_at     when HR added the applicant / the invite went out   (static_add_applicant)
//   applicants.submitted_at   when the screening became Completed                 (static_set_status)
//   applicants.passed_at      when the result became Passed                       (static_set_status)
//   interviews.answered_at    when HR confirmed / re-scheduled the interview      (details.php; falls back to
//                             interviews.updated for rows saved before this field existed)
//
// BACKEND (later), one query each:
//   funnel       SELECT COUNT(*) total, SUM(status IN ('In Progress','Completed')) started,
//                       SUM(status = 'Completed') completed, SUM(result = 'Passed') passed,
//                       SUM(result = 'Passed' AND iv.status IN ('confirmed','rescheduled')) scheduled
//                FROM applicants a LEFT JOIN interviews iv ON iv.applicant_id = a.id WHERE a.archived_at IS NULL
//   histogram    SELECT TIMESTAMPDIFF(HOUR, invited_at, submitted_at) FROM applicants WHERE submitted_at IS NOT NULL
//                (bucket the hours with the edges in static_time_bins())
//   turnaround   SELECT AVG(TIMESTAMPDIFF(HOUR, a.passed_at, iv.answered_at)) FROM applicants a
//                JOIN interviews iv ON iv.applicant_id = a.id WHERE iv.status IN ('confirmed','rescheduled')
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
        ['key' => 'applied',   'label' => 'Applied',                'count' => 0, 'url' => 'screeninglist.php?status=all'],
        ['key' => 'started',   'label' => 'Started Screening',      'count' => 0, 'url' => ''],
        ['key' => 'completed', 'label' => 'Completed Questionnaire', 'count' => 0, 'url' => 'screeninglist.php?status=Completed'],
        ['key' => 'passed',    'label' => 'Passed Screening',       'count' => 0, 'url' => 'screeninglist.php?status=Passed'],
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

// All three blocks, worked out from the ACTIVE applicants (archived ones are not counted) + the interview rows.
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
const STATIC_STORE_FILE   = __DIR__ . '/data/static-store.json';
const STATIC_ARCHIVE_DAYS = 30;                 // days an archived applicant stays before it is gone for good

function static_now(): DateTimeImmutable
{
    return new DateTimeImmutable('now', new DateTimeZone('Asia/Manila'));
}

function static_store_write(array $store): bool
{
    $dir = dirname(STATIC_STORE_FILE);
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    $json = json_encode($store, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (file_put_contents(STATIC_STORE_FILE, $json, LOCK_EX) === false) {
        throw new RuntimeException('Could not write to static store file: ' . STATIC_STORE_FILE);
    }
    return true;
}

// Reads the whole store. First run (or a broken file): starts from the seed list.
function static_store_read(): array
{
    $store = null;
    if (is_file(STATIC_STORE_FILE)) {
        $store = json_decode((string) @file_get_contents(STATIC_STORE_FILE), true);
    }
    if (!is_array($store) || !isset($store['applicants']) || !is_array($store['applicants'])) {
        $store = ['applicants' => array_map(fn($a) => $a + ['archived_at' => null], static_seed_applicants())];
        static_store_migrate($store);
        static_store_write($store);
    } elseif (static_store_migrate($store)) {
        static_store_write($store);                 // an older store file got its new timeline fields: save them once
    }
    if (!isset($store['applicants']) || !is_array($store['applicants'])) {
        $store['applicants'] = [];
    }
    return $store;
}

// ---------------------------------------------------------------------------
// Timeline fields for the dashboard analytics (invited_at / submitted_at / passed_at, and sample interview
// answers). Fills ONLY what is missing, so it is safe to run on every read: it returns true when it changed
// something (the caller then saves the store once). Real data is never overwritten.
// DATABASE (later): these are plain columns, so this whole function is deleted.
// ---------------------------------------------------------------------------
function static_store_migrate(array &$store): bool
{
    $changed = false;
    $zone    = new DateTimeZone('Asia/Manila');
    $fmt     = 'Y-m-d H:i';

    // sample "invite -> submission" hours for the seed applicants who completed (any other id gets a spread value)
    $seedHours = [103 => 9, 104 => 30, 106 => 18, 108 => 26, 109 => 40, 111 => 52, 114 => 21, 116 => 34,
                  119 => 46, 121 => 60, 124 => 38, 126 => 15, 129 => 78, 131 => 100, 135 => 140];

    foreach ($store['applicants'] as &$a) {
        $id      = (int) $a['id'];
        $status  = (string) ($a['status'] ?? 'Pending');
        $updated = (string) ($a['updated'] ?? '');
        if ($updated === '') continue;
        $upd = new DateTimeImmutable($updated, $zone);

        if (empty($a['invited_at'])) {
            if ($status === 'Completed') {
                $hours = $seedHours[$id] ?? (8 + ($id * 7) % 90);
                $a['invited_at'] = $upd->modify('-' . $hours . ' hours')->format($fmt);
            } elseif ($status === 'In Progress') {
                $a['invited_at'] = $upd->modify('-' . (10 + $id % 30) . ' hours')->format($fmt);
            } else {
                $a['invited_at'] = $updated;
            }
            $changed = true;
        }
        if ($status === 'Completed' && empty($a['submitted_at'])) {
            $a['submitted_at'] = $updated;
            $changed = true;
        }
        if ($status === 'Completed' && ($a['result'] ?? null) === 'Passed' && empty($a['passed_at'])) {
            $a['passed_at'] = $a['submitted_at'];
            $changed = true;
        }
    }
    unset($a);

    // Sample interview answers for some of the seed applicants who passed (so Scheduling Turnaround Time has
    // something to show on a fresh install). The others stay unanswered: HR can still try the confirm flow on them.
    if (empty($store['timeline_seeded'])) {
        $sampleHours = [103 => 20, 106 => 46, 111 => 71, 114 => 28, 121 => 52, 129 => 26, 135 => 63];
        $slots       = ['09:00', '10:00', '11:00', '13:00', '14:00', '15:00', '16:00'];
        if (!isset($store['interviews']) || !is_array($store['interviews'])) $store['interviews'] = [];
        foreach ($store['applicants'] as $a) {
            $id = (int) $a['id'];
            if (!isset($sampleHours[$id]) || isset($store['interviews'][$id])) continue;
            if (($a['status'] ?? '') !== 'Completed' || ($a['result'] ?? null) !== 'Passed' || empty($a['passed_at'])) continue;

            $answered = (new DateTimeImmutable($a['passed_at'], $zone))->modify('+' . $sampleHours[$id] . ' hours');
            $day = $answered->modify('+3 days');
            while ((int) $day->format('N') >= 6) $day = $day->modify('+1 day');
            $time = $slots[$id % count($slots)];
            $store['interviews'][$id] = [
                'status'         => 'confirmed',
                'preferred_date' => $day->format('Y-m-d'),
                'preferred_time' => $time,
                'type'           => STATIC_INTERVIEW_TYPE,
                'date'           => $day->format('Y-m-d'),
                'time'           => $time,
                'source'         => 'sample',
                'updated'        => $answered->format($fmt),
                'answered_at'    => $answered->format($fmt)
            ];
        }
        $store['timeline_seeded'] = true;
        $changed = true;
    }
    return $changed;
}

// Read, change, write (one place so every change is saved the same way)
function static_store_update(callable $change)
{
    $store  = static_store_read();
    $result = $change($store);
    static_store_write($store);
    return $result;
}

function static_next_id(array $store): int
{
    $max = 100;
    foreach ($store['applicants'] as $a) $max = max($max, (int) $a['id']);
    return $max + 1;
}

// ---------------------------------------------------------------------------
// ACTIVITY LOG: what HR and the Admin did (kept in the store under "activity")
//   Admin Dashboard > Recent Activities lists every line HR writes here, and the Admin's sidebar
//   Dashboard badge counts the ones the Admin has not seen yet (see static_admin_notify()).
//   HR Dashboard > the notification bell lists HR's own lines (static_hr_feed()).
//   Only real work is logged: applicants (add / delete / restore / delete forever / status) and
//   interview schedules. Preferences (HR Settings) are NOT logged.
//   These four helpers used to sit in admin/static-admin.php; the Admin side now uses them from here.
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
if (!function_exists('static_activity')) {
    function static_activity(): array
    {
        $store = static_store_read();
        $list  = isset($store['activity']) && is_array($store['activity']) ? $store['activity'] : static_seed_activity();
        $order = [];
        foreach (array_values($list) as $i => $row) $order[] = [$row, $i];
        usort($order, fn($a, $b) => strcmp((string) $b[0]['at'], (string) $a[0]['at']) ?: ($b[1] <=> $a[1]));
        return array_map(fn($pair) => $pair[0], $order);
    }
}

// Reads, adds one line, saves. Used by the HR endpoints once a change has worked.
// A problem while writing the log line never undoes or blocks the change HR just made.
function static_log(string $activity, string $details = '-', ?string $by = null): void
{
    try {
        static_store_update(function (array &$store) use ($activity, $details, $by) {
            static_log_into($store, $activity, $details, $by);
        });
    } catch (Throwable $e) {
        // ignored on purpose
    }
}

// The id of the signed-in HR account (0 when it cannot be told). The log lines about one person's profile / password
// carry that person's id as 'owner' (see hr/static-data.php), and only that person sees them in the bell.
function static_hr_session_id(): int
{
    $email = strtolower(trim((string) ($_SESSION['user_email'] ?? '')));
    if ($email === '' || ($_SESSION['user_role'] ?? '') !== 'hr') return 0;
    $store = static_store_read();
    $list  = isset($store['hr_accounts']) && is_array($store['hr_accounts']) ? $store['hr_accounts'] : [['id' => 1, 'email' => 'hr@expertise.com']];
    foreach ($list as $a) {
        if (is_array($a) && strcasecmp((string) ($a['email'] ?? ''), $email) === 0) return (int) ($a['id'] ?? 0);
    }
    return 0;
}

// What HR did, newest first: the list behind the bell on the HR Dashboard (the Admin's own lines are left out).
// Two things are added for the signed-in HR: their own Profile / Password lines, and the Admin's answer to their own
// password request (that one Admin line is shown to HR, worded for HR).
function static_hr_feed(int $limit = 30): array
{
    $mine = static_hr_session_id();
    $out  = [];
    foreach (static_activity() as $row) {
        $by   = (string) ($row['by'] ?? '');
        $item = ['at' => (string) $row['at'], 'activity' => (string) $row['activity'], 'details' => (string) $row['details'], 'by' => $by];

        // a profile / password line belongs to one HR account: nobody else sees it
        if (isset($row['owner']) && (int) $row['owner'] !== $mine) continue;

        if (($row['kind'] ?? '') === 'pw_result') {
            $item['details'] = $item['activity'] === 'Password Change Approved'
                ? 'The Admin approved your new password. Use it the next time you log in.'
                : 'The Admin rejected your password change. Your current password stays.' . (!empty($row['reason']) ? ' Reason: ' . (string) $row['reason'] : '');
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
    foreach (static_store_read()['applicants'] as $a) {
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
// Active applicants (NOT archived), same shape as the original sample list
function static_applicants(): array
{
    $out = [];
    foreach (static_store_read()['applicants'] as $a) {
        if (!empty($a['archived_at'])) continue;
        unset($a['archived_at']);
        $out[] = $a;
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
    $out = [];
    foreach (static_store_read()['applicants'] as $a) {
        if (empty($a['archived_at'])) continue;
        $deletedAt = new DateTimeImmutable($a['archived_at'], new DateTimeZone('Asia/Manila'));
        $passed    = (int) $deletedAt->diff($now)->format('%a');
        $a['deleted']   = $a['archived_at'];
        $a['days_left'] = max(0, STATIC_ARCHIVE_DAYS - $passed);
        unset($a['archived_at']);
        $out[] = $a;
    }
    usort($out, fn($x, $y) => strcmp($y['deleted'], $x['deleted']));
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
    $middle  = static_check_name_part((string) ($in['middle_name'] ?? ''), 'middle name', false, 1);   // optional; an initial (A.) is fine
    $last    = static_check_name_part((string) ($in['last_name']   ?? ''), 'last name',   true);
    $name    = static_compose_name($first, $middle, $last);
    $email   = trim((string) ($in['email'] ?? ''));
    $contact = trim((string) ($in['contact'] ?? ''));
    if (mb_strlen($name) > 100)                       throw new InvalidArgumentException('The full name is too long (100 characters at most).');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL))   throw new InvalidArgumentException('Enter a valid email address (example: name@gmail.com).');
    if ($contact === '')                              throw new InvalidArgumentException('Please enter the contact number.');

    $position = static_position_find((int) ($in['position_id'] ?? 0));
    if ($position === null)                           throw new InvalidArgumentException('Please choose a job position.');

    return static_store_update(function (array &$store) use ($first, $middle, $last, $name, $email, $contact, $position, $cvUpload) {
        foreach ($store['applicants'] as $a) {
            if (strcasecmp($a['email'], $email) === 0) {
                throw new InvalidArgumentException(
                    empty($a['archived_at'])
                        ? 'An applicant with this email already exists.'
                        : 'An applicant with this email is in the Archive. Restore them instead.'
                );
            }
        }
        $applicant = [
            'id'          => static_next_id($store),
            'name'        => $name,
            'first_name'  => $first,
            'middle_name' => $middle,
            'last_name'   => $last,
            'email'       => $email,
            'contact'     => $contact,
            // the position decides which job description PDF the applicant is shown in the questionnaire
            'position_id' => (int) $position['id'],
            'position'    => (string) $position['name'],
            'status'      => 'Pending',
            'result'      => null,
            'updated'     => static_now()->format('Y-m-d H:i'),
            // timeline for the dashboard analytics (filled in as the applicant moves along)
            'invited_at'   => static_now()->format('Y-m-d H:i'),
            'submitted_at' => null,
            'passed_at'    => null
        ];
        // the CV file (shown on the Applicant Details page). A problem with the file never stops the applicant from being added.
        $cv = static_save_cv((int) $applicant['id'], $cvUpload);
        if ($cv) $applicant['cv'] = $cv;

        $store['applicants'][] = $applicant + ['archived_at' => null];
        return $applicant;
    });
}

// Keeps an uploaded CV in data/cv/ (PDF, DOC, DOCX, 5 MB at most). Returns ['name','size','file'] or null.
// DATABASE / CLOUDINARY (later): upload the file there and save the url instead of a file name.
function static_save_cv(int $id, ?array $upload): ?array
{
    if (!$upload || ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) return null;
    if (empty($upload['tmp_name']) || !is_uploaded_file($upload['tmp_name']))    return null;

    $ext = strtolower(pathinfo((string) ($upload['name'] ?? ''), PATHINFO_EXTENSION));
    if (!in_array($ext, ['pdf', 'doc', 'docx'], true))     return null;
    if ((int) $upload['size'] <= 0 || (int) $upload['size'] > 5 * 1024 * 1024) return null;

    $dir = __DIR__ . '/data/cv';
    if (!is_dir($dir) && !@mkdir($dir, 0775, true)) return null;

    $file = $id . '.' . $ext;
    if (!@move_uploaded_file($upload['tmp_name'], $dir . '/' . $file)) return null;

    return ['name' => basename((string) $upload['name']), 'size' => (int) $upload['size'], 'file' => $file];
}

/* ==========================================================================
   JOB POSITIONS (the table "job_positions")
   Add Applicant has a "Job Position" list; its last entry is "+ Add job position", where HR types a new one
   (and uploads that position's job description PDF). Every position has its OWN job description, so the
   applicant is shown the PDF of the position they were added under (applicant/questionnaire.php, question 1).
   Stored in the store under "job_positions"; the PDFs are kept in data/jd/ (next to data/cv).
     id, name, jd = { name, size, file } or null (no PDF uploaded yet), created, archived_at
   An applicant keeps position_id + position (the name when they were added).
   DATABASE (later): job_positions (id, name, jd_url, created_at, archived_at); the PDF goes to Cloudinary and
   jd_url is saved instead of a file name.
   ========================================================================== */
const STATIC_JD_DIR   = __DIR__ . '/data/jd';
const STATIC_JD_BYTES = 5 * 1024 * 1024;                   // 5 MB at most, like the CV

// The positions the project starts with. Their PDFs are the sample files data/jd/jd-1.pdf ... jd-3.pdf
function static_seed_positions(): array
{
    $seed = [
        [1, 'Customer Service Representative'],
        [2, 'Technical Support Representative'],
        [3, 'Back Office Associate']
    ];
    $out = [];
    foreach ($seed as [$id, $name]) {
        $file = 'jd-' . $id . '.pdf';
        $path = STATIC_JD_DIR . '/' . $file;
        $out[] = [
            'id'          => $id,
            'name'        => $name,
            'jd'          => is_file($path) ? ['name' => $name . ' - Job Description.pdf', 'size' => (int) @filesize($path), 'file' => $file] : null,
            'created'     => '2026-08-03 09:00',
            'archived_at' => null
        ];
    }
    return $out;
}

// Active positions in the order they were created (a store without positions gets the starting ones, once)
function static_positions(): array
{
    $store = static_store_read();
    if (!isset($store['job_positions']) || !is_array($store['job_positions'])) {
        $store = static_store_update(function (array &$store) {
            if (!isset($store['job_positions']) || !is_array($store['job_positions'])) $store['job_positions'] = static_seed_positions();
            return $store;
        });
    }
    $out = [];
    foreach ($store['job_positions'] as $p) {
        if (!is_array($p) || !empty($p['archived_at'])) continue;
        $out[] = $p;
    }
    usort($out, fn($a, $b) => (int) $a['id'] <=> (int) $b['id']);
    return $out;
}

function static_position_find(int $id): ?array
{
    if ($id <= 0) return null;
    foreach (static_positions() as $p) {
        if ((int) $p['id'] === $id) return $p;
    }
    return null;
}

// What the page / JavaScript gets for a position (never a server path)
function static_position_public(array $p): array
{
    return [
        'id'      => (int) $p['id'],
        'name'    => (string) $p['name'],
        'has_jd'  => !empty($p['jd']['file']),
        'jd_name' => !empty($p['jd']['name']) ? (string) $p['jd']['name'] : ''
    ];
}

// The address of a position's job description PDF, or '' when it has none.
// $base = how the calling page reaches hr/data/  (applicant pages: '../hr/data/', HR pages: 'data/').
// ?v= is the file's time, so a replaced PDF is never served from an old cache.
function static_position_jd_url(?array $position, string $base): string
{
    $file = $position['jd']['file'] ?? '';
    if (!is_string($file) || !preg_match('/^[A-Za-z0-9._\-]+\.pdf$/', $file)) return '';
    $path = STATIC_JD_DIR . '/' . $file;
    if (!is_file($path)) return '';
    return $base . 'jd/' . rawurlencode($file) . '?v=' . (int) @filemtime($path);
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
    }

    static_positions();                                                   // makes sure the starting positions exist before a new id is chosen

    $saved = static_store_update(function (array &$store) use ($name, $hasUpload, $jdUpload) {
        $list  = isset($store['job_positions']) && is_array($store['job_positions']) ? $store['job_positions'] : static_seed_positions();

        $max = 0;
        foreach ($list as $p) {
            if (!is_array($p)) continue;
            $max = max($max, (int) ($p['id'] ?? 0));
            if (empty($p['archived_at']) && strcasecmp((string) ($p['name'] ?? ''), $name) === 0) {
                throw new InvalidArgumentException('This job position already exists. Choose it from the list.');
            }
        }
        $id = $max + 1;

        $jd = null;
        if ($hasUpload) {
            if (!is_dir(STATIC_JD_DIR) && !@mkdir(STATIC_JD_DIR, 0775, true)) throw new InvalidArgumentException('The job description could not be saved. Please try again.');
            $file = 'jd-' . $id . '.pdf';
            if (!@move_uploaded_file((string) $jdUpload['tmp_name'], STATIC_JD_DIR . '/' . $file)) throw new InvalidArgumentException('The job description could not be saved. Please try again.');
            $jd = ['name' => basename((string) $jdUpload['name']), 'size' => (int) $jdUpload['size'], 'file' => $file];
        }

        $position = ['id' => $id, 'name' => $name, 'jd' => $jd, 'created' => static_now()->format('Y-m-d H:i'), 'archived_at' => null];
        $list[] = $position;
        $store['job_positions'] = $list;
        return $position;
    });
    return static_position_public($saved);
}

// ---------------------------------------------------------------------------
// INTERVIEWS (the table "interviews", one row per applicant). Shared by BOTH sides:
//   the APPLICANT sends a preferred date + time   applicant/application-status.php  -> static_interview_request()
//   HR confirms it or sends another one           hr/details.php (Interview Schedule)  -> hr/static-details.php
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
    return ['09:00 AM', '10:00 AM', '11:00 AM', '01:00 PM', '02:00 PM', '03:00 PM', '04:00 PM'];
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

// The applicant record that goes with an email address (active applicants only), or null
function static_applicant_by_email(string $email): ?array
{
    $email = strtolower(trim($email));
    if ($email === '') return null;
    foreach (static_applicants() as $a) {
        if (strtolower($a['email']) === $email) return $a;
    }
    return null;
}

function static_interview_get(int $applicantId): ?array
{
    $row = static_store_read()['interviews'][$applicantId] ?? null;
    return is_array($row) ? $row : null;
}

// The applicant picked a date and time (status: awaiting). Replaces an older request.
function static_interview_request(int $applicantId, string $date, string $time24, string $source = 'applicant'): array
{
    return static_store_update(function (array &$store) use ($applicantId, $date, $time24, $source) {
        if (!isset($store['interviews']) || !is_array($store['interviews'])) $store['interviews'] = [];
        $store['interviews'][$applicantId] = [
            'status'         => 'awaiting',
            'preferred_date' => $date,
            'preferred_time' => $time24,
            'type'           => STATIC_INTERVIEW_TYPE,
            'date'           => null,
            'time'           => null,
            'source'         => $source,
            'updated'        => static_now()->format('Y-m-d H:i')
        ];
        return $store['interviews'][$applicantId];
    });
}

// The same row in the shape application-status.php already uses ($_SESSION['interview'])
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

    return static_store_update(function (array &$store) use ($id, $status, $result) {
        foreach ($store['applicants'] as &$a) {
            if ((int) $a['id'] !== $id) continue;
            $now     = static_now()->format('Y-m-d H:i');
            $wasDone = strtolower((string) ($a['status'] ?? '')) === 'completed';
            $a['status']  = $status;
            $a['result']  = $result;
            $a['updated'] = $now;
            // timeline for the dashboard analytics
            if (empty($a['invited_at'])) $a['invited_at'] = $now;
            if ($status === 'Completed') {
                if (!$wasDone || empty($a['submitted_at'])) $a['submitted_at'] = $now;
                $a['passed_at'] = $result === 'Passed' ? (!empty($a['passed_at']) && $wasDone ? $a['passed_at'] : $now) : null;
            } else {
                $a['submitted_at'] = null;       // moved back: the screening is not submitted any more
                $a['passed_at']    = null;
            }
            return true;
        }
        return false;
    });
}

// Screening List "Delete": moves applicants to the Archive. Returns how many were moved.
// $at (optional) = [ id => time in milliseconds ]: keeps the original archive time (used by Undo
// and by the archive.php?demo=1 test data).
function static_archive(array $ids, array $at = []): int
{
    $ids = array_map('intval', $ids);
    return static_store_update(function (array &$store) use ($ids, $at) {
        $n = 0;
        $zone = new DateTimeZone('Asia/Manila');
        $now  = static_now()->format('Y-m-d H:i:s');
        foreach ($store['applicants'] as &$a) {
            if (in_array((int) $a['id'], $ids, true) && empty($a['archived_at'])) {
                $ms = $at[(int) $a['id']] ?? null;
                $a['archived_at'] = is_numeric($ms) && $ms > 0
                    ? (new DateTimeImmutable('@' . intdiv((int) $ms, 1000)))->setTimezone($zone)->format('Y-m-d H:i:s')
                    : $now;
                $n++;
            }
        }
        return $n;
    });
}

// Archive "Restore": back to the Screening List with their old status and result
function static_restore(array $ids): int
{
    $ids = array_map('intval', $ids);
    return static_store_update(function (array &$store) use ($ids) {
        $n = 0;
        foreach ($store['applicants'] as &$a) {
            if (in_array((int) $a['id'], $ids, true) && !empty($a['archived_at'])) {
                $a['archived_at'] = null;
                $n++;
            }
        }
        return $n;
    });
}

// Archive "Delete forever": only archived applicants can be removed for good
function static_purge(array $ids): int
{
    $ids = array_map('intval', $ids);
    return static_store_update(function (array &$store) use ($ids) {
        $before = count($store['applicants']);
        $store['applicants'] = array_values(array_filter(
            $store['applicants'],
            fn($a) => !(in_array((int) $a['id'], $ids, true) && !empty($a['archived_at']))
        ));
        return $before - count($store['applicants']);
    });
}

// Archived applicants older than STATIC_ARCHIVE_DAYS are removed automatically
function static_purge_expired(): int
{
    $limit = static_now()->modify('-' . STATIC_ARCHIVE_DAYS . ' days')->format('Y-m-d H:i:s');
    return static_store_update(function (array &$store) use ($limit) {
        $before = count($store['applicants']);
        $store['applicants'] = array_values(array_filter(
            $store['applicants'],
            fn($a) => empty($a['archived_at']) || $a['archived_at'] > $limit
        ));
        return $before - count($store['applicants']);
    });
}


/* ==========================================================================
   PROFILE + HR PASSWORD APPROVAL (used by BOTH sides, so it lives in this shared file)
   Profile   Name, email and picture of the signed-in Admin / HR (Settings). HR changes also write a line to the
             activity log, so the Admin sees them under Recent Activities.
   Password  Admin: changed at once. HR: becomes a REQUEST (hash only) that the Admin approves or rejects by clicking
             the line in Recent Activities (admin/static-admin.php, action password_decide). HR sees the answer in
             Settings and in the bell.
   Store     hr_accounts[].avatar | admin_account {name,email,hash,avatar} | password_requests[] | activity lines with
             kind / owner / ref. Pictures are kept in data/avatars/.
   Endpoint  the actions profile_save, password_change, password_status, password_cancel at the bottom of this file.
   BACKEND   UPDATE users SET name, email, avatar_url ...; INSERT INTO password_requests ...; Cloudinary for the picture.
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
   SAFETY NET: the same two helpers admin/static-admin.php has, so the HR pages can use them too.
   Each one only exists "if missing", so whichever file loads first wins and nothing is defined twice.
   ========================================================================== */
if (!function_exists('static_seed_hr_accounts')) {
    // The ONE HR account the project starts with (the same one index.php lets in)
    function static_seed_hr_accounts(): array
    {
        return [
            ['id' => 1, 'name' => 'HR', 'email' => 'hr@expertise.com', 'password' => 'hr123', 'role' => 'HR Staff', 'status' => 'Active', 'created' => '2026-08-03 09:00', 'archived_at' => null]
        ];
    }
}

if (!function_exists('static_hr_accounts')) {
    function static_hr_accounts(): array
    {
        $store = static_store_read();
        return isset($store['hr_accounts']) && is_array($store['hr_accounts']) ? $store['hr_accounts'] : static_seed_hr_accounts();
    }
}

/* ==========================================================================
   WHO IS SIGNED IN
   ========================================================================== */

// Every HR account row of the store (the seed one until the store has its own list)
function static_profile_hr_list(array $store): array
{
    return isset($store['hr_accounts']) && is_array($store['hr_accounts']) ? $store['hr_accounts'] : static_seed_hr_accounts();
}

// The Admin's profile: what was saved under "admin_account", otherwise the starting values
function static_profile_admin(array $store): array
{
    $a     = isset($store['admin_account']) && is_array($store['admin_account']) ? $store['admin_account'] : [];
    $name  = trim((string) ($a['name'] ?? ''));
    $email = strtolower(trim((string) ($a['email'] ?? '')));
    return [
        'name'   => $name !== '' ? $name : 'Admin',
        'email'  => $email !== '' ? $email : STATIC_ADMIN_EMAIL,
        'avatar' => !empty($a['avatar']) ? (string) $a['avatar'] : null,
        'hash'   => !empty($a['hash']) ? (string) $a['hash'] : null
    ];
}

// The Admin's email right now (used to keep an HR account from taking it)
function static_profile_admin_email(): string
{
    return static_profile_admin(static_store_read())['email'];
}

// The signed-in person: ['role', 'id', 'name', 'email', 'avatar'], or null (not signed in / the account is gone).
// HR is found by the email the session holds (it is updated when HR changes the email).
function static_profile_me(array $store): ?array
{
    $role = (string) ($_SESSION['user_role'] ?? '');

    if ($role === 'admin') {
        $a = static_profile_admin($store);
        return ['role' => 'admin', 'id' => 0, 'name' => $a['name'], 'email' => $a['email'], 'avatar' => $a['avatar']];
    }

    if ($role === 'hr') {
        $email = strtolower(trim((string) ($_SESSION['user_email'] ?? '')));
        if ($email === '') return null;
        foreach (static_profile_hr_list($store) as $a) {
            if (!is_array($a) || !empty($a['archived_at'])) continue;
            if (strcasecmp((string) ($a['email'] ?? ''), $email) !== 0) continue;
            return [
                'role'   => 'hr',
                'id'     => (int) ($a['id'] ?? 0),
                'name'   => (string) ($a['name'] ?? 'HR'),
                'email'  => strtolower((string) $a['email']),
                'avatar' => !empty($a['avatar']) ? (string) $a['avatar'] : null
            ];
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
function static_profile_save(array $in, ?array $upload): array
{
    $newFile = null;        // a picture this request saved (removed again if the change fails)
    $oldFile = null;        // the picture that was replaced (removed once the change is stored)

    try {
        $result = static_store_update(function (array &$store) use ($in, $upload, &$newFile, &$oldFile) {
            $me = static_profile_me($store);
            if ($me === null) throw new StaticProfileError('Your account could not be found. Please log in again.');

            $name  = static_profile_check_name((string) ($in['name'] ?? ''));
            $email = static_profile_check_email((string) ($in['email'] ?? ''));
            if (static_profile_email_taken($store, $email, $me['role'] === 'hr' ? $me['id'] : null, $me['role'] === 'admin')) {
                throw new StaticProfileError('That email is already used by another account.', 'email');
            }

            // the picture: a new file, or "remove", or no change
            $avatar = $me['avatar'];
            if ($upload && (int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                $newFile = static_profile_store_avatar($upload, $me['role'] === 'hr' ? 'hr-' . $me['id'] : 'admin');
                $oldFile = $me['avatar'];
                $avatar  = $newFile;
            } elseif (!empty($in['avatar_remove']) && $me['avatar'] !== null) {
                $oldFile = $me['avatar'];
                $avatar  = null;
            }

            // what changed (this is what the Admin reads)
            $changes = [];
            if ($name !== $me['name'])     $changes[] = ['field' => 'Name',  'from' => $me['name'],  'to' => $name];
            if ($email !== $me['email'])   $changes[] = ['field' => 'Email', 'from' => $me['email'], 'to' => $email];
            if ($avatar !== $me['avatar']) $changes[] = ['field' => 'Profile picture', 'from' => $me['avatar'] !== null ? 'Photo' : 'None', 'to' => $avatar !== null ? 'New photo' : 'Removed'];

            if ($me['role'] === 'hr') {
                $list = static_profile_hr_list($store);
                $pos  = null;
                foreach ($list as $i => $a) {
                    if (is_array($a) && (int) ($a['id'] ?? 0) === $me['id']) { $pos = $i; break; }
                }
                if ($pos === null) throw new StaticProfileError('Your account could not be found. Please log in again.');
                $list[$pos]['name']   = $name;
                $list[$pos]['email']  = $email;
                $list[$pos]['avatar'] = $avatar;
                $store['hr_accounts'] = $list;

                // Keep the Admin's HR list (users table) in sync with profile changes made in Settings.
                try {
                    require_once __DIR__ . '/../src/Helpers/db.php';
                    $pdo = db();
                    $uid = (int) ($_SESSION['user_id'] ?? 0);
                    if ($uid > 0) {
                        $st = $pdo->prepare("UPDATE users SET name = :n, email = :e WHERE user_id = :id AND role = 'hr' AND deleted_at IS NULL");
                        $st->execute([':n' => $name, ':e' => $email, ':id' => $uid]);
                    } else {
                        $st = $pdo->prepare("UPDATE users SET name = :n, email = :e WHERE email = :old AND role = 'hr' AND deleted_at IS NULL");
                        $st->execute([':n' => $name, ':e' => $email, ':old' => $me['email']]);
                    }
                } catch (Throwable $e) {
                    // best-effort sync; static store remains the source for Settings
                }

                if ($changes) {
                    $parts = [];
                    foreach ($changes as $c) {
                        $parts[] = $c['field'] === 'Profile picture'
                            ? ($avatar === null ? 'Profile picture removed' : 'Profile picture changed')
                            : $c['field'] . ': ' . $c['from'] . ' → ' . $c['to'];
                    }
                    $title = 'Profile Updated';
                    if (count($changes) === 1) {
                        $title = $changes[0]['field'] === 'Profile picture' ? 'Profile Picture Changed' : $changes[0]['field'] . ' Changed';
                    }
                    static_profile_log($store, $title, implode(' · ', $parts), 'HR', ['kind' => 'profile', 'owner' => $me['id'], 'changes' => $changes]);
                }
            } else {
                $adm = isset($store['admin_account']) && is_array($store['admin_account']) ? $store['admin_account'] : [];
                $adm['name']   = $name;
                $adm['email']  = $email;
                $adm['avatar'] = $avatar;
                $store['admin_account'] = $adm;
            }

            return [
                'name' => $name,
                'email' => $email,
                'avatar' => $avatar,
                'avatarUrl' => static_profile_avatar_url($avatar, $me['role'] === 'hr' ? 'data/avatars/' : '../hr/data/avatars/'),
                'changes' => $changes
            ];
        });
    } catch (Throwable $e) {
        static_profile_delete_avatar($newFile);         // the change failed: do not leave the new picture behind
        throw $e;
    }

    if ($oldFile !== null && $oldFile !== $result['avatar']) static_profile_delete_avatar($oldFile);
    return $result;
}

/* ==========================================================================
   PASSWORD
   ========================================================================== */

// Is $given the password of this signed-in person right now? (same two checks as index.php)
function static_profile_current_password_ok(array $me, string $given): bool
{
    if ($given === '') return false;
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
    if (strlen($new) < 8 || !preg_match('/[a-z]/', $new) || !preg_match('/[A-Z]/', $new) || !preg_match('/\d/', $new)) {
        throw new StaticProfileError('The new password does not meet all the requirements.', 'pwNew');
    }
    if (strlen($new) > 72)    throw new StaticProfileError('The new password is too long (72 characters at most).', 'pwNew');
    if ($new === $current)    throw new StaticProfileError('The new password must be different from the current one.', 'pwNew');
}

/* ---------- the requests (HR password changes waiting for the Admin) ---------- */

function static_profile_requests(array $store): array
{
    return isset($store['password_requests']) && is_array($store['password_requests'])
        ? array_values(array_filter($store['password_requests'], 'is_array'))
        : [];
}

function static_profile_next_request_id(array $reqs): int
{
    $max = 0;
    foreach ($reqs as $r) $max = max($max, (int) ($r['id'] ?? 0));
    return $max + 1;
}

// A request as the pages see it: never the password hash
function static_profile_request_public(array $r): array
{
    return [
        'id'           => (int) ($r['id'] ?? 0),
        'account_id'   => (int) ($r['account_id'] ?? 0),
        'name'         => (string) ($r['name'] ?? ''),
        'email'        => (string) ($r['email'] ?? ''),
        'status'       => (string) ($r['status'] ?? 'pending'),
        'requested_at' => (string) ($r['requested_at'] ?? ''),
        'decided_at'   => isset($r['decided_at']) ? (string) $r['decided_at'] : null,
        'reason'       => (string) ($r['reason'] ?? '')
    ];
}

// id => request, for the Admin's Recent Activities (each line shows the live status)
function static_profile_requests_by_id(array $store): array
{
    $out = [];
    foreach (static_profile_requests($store) as $r) $out[(int) ($r['id'] ?? 0)] = static_profile_request_public($r);
    return $out;
}

// The newest request of one HR account (null when HR never asked)
function static_profile_latest_request(array $store, int $accountId): ?array
{
    $found = null;
    foreach (static_profile_requests($store) as $r) {
        if ((int) ($r['account_id'] ?? 0) === $accountId) $found = $r;        // later ones are newer
    }
    return $found !== null ? static_profile_request_public($found) : null;
}

/* ---------- change the password ----------
   Admin: applied now.  HR: a request is created and waits for the Admin.
   Returns ['mode' => 'applied' | 'pending', 'request' => the request or null] */
function static_profile_change_password(string $current, string $new): array
{
    return static_store_update(function (array &$store) use ($current, $new) {
        $me = static_profile_me($store);
        if ($me === null) throw new StaticProfileError('Your account could not be found. Please log in again.');

        if ($current === '') throw new StaticProfileError('Enter your current password.', 'pwCurrent');
        if (!static_profile_current_password_ok($me, $current)) {
            throw new StaticProfileError('The current password is not correct.', 'pwCurrent');
        }
        static_profile_check_new_password($new, $current);

        if ($me['role'] === 'admin') {
            $adm = isset($store['admin_account']) && is_array($store['admin_account']) ? $store['admin_account'] : [];
            $adm['hash'] = password_hash($new, PASSWORD_DEFAULT);
            unset($adm['password']);
            $store['admin_account'] = $adm;
            return ['mode' => 'applied', 'request' => null];
        }

        // HR: one request at a time
        $reqs = static_profile_requests($store);
        foreach ($reqs as $r) {
            if ((int) ($r['account_id'] ?? 0) === $me['id'] && ($r['status'] ?? '') === 'pending') {
                throw new StaticProfileError('You already have a password change waiting for the Admin to approve.');
            }
        }

        $req = [
            'id'           => static_profile_next_request_id($reqs),
            'account_id'   => $me['id'],
            'name'         => $me['name'],
            'email'        => $me['email'],
            'hash'         => password_hash($new, PASSWORD_DEFAULT),
            'plain'        => $new,                                         // SAMPLE ONLY: so the Admin can see it in Edit HR after approving; removed when answered
            'status'       => 'pending',
            'requested_at' => static_now()->format('Y-m-d H:i:s'),
            'decided_at'   => null,
            'decided_by'   => null,
            'reason'       => ''
        ];
        $reqs[] = $req;
        $store['password_requests'] = array_slice($reqs, -100);

        static_profile_log($store, 'Password Change Requested', $me['name'] . ' (HR)', 'HR', ['kind' => 'pw_request', 'ref' => $req['id'], 'owner' => $me['id']]);
        return ['mode' => 'pending', 'request' => static_profile_request_public($req)];
    });
}

// HR takes back the request that is still pending
function static_profile_cancel_request(): array
{
    return static_store_update(function (array &$store) {
        $me = static_profile_me($store);
        if ($me === null || $me['role'] !== 'hr') throw new StaticProfileError('Your account could not be found. Please log in again.');

        $reqs = static_profile_requests($store);
        foreach ($reqs as $i => $r) {
            if ((int) ($r['account_id'] ?? 0) === $me['id'] && ($r['status'] ?? '') === 'pending') {
                $reqs[$i]['status']     = 'cancelled';
                $reqs[$i]['decided_at'] = static_now()->format('Y-m-d H:i:s');
                $reqs[$i]['decided_by'] = 'HR';
                unset($reqs[$i]['hash'], $reqs[$i]['plain']);
                $store['password_requests'] = $reqs;
                return static_profile_request_public($reqs[$i]);
            }
        }
        throw new StaticProfileError('There is no password change waiting for approval.');
    });
}

// The Admin answers a request. $decision = 'approve' | 'reject'.  Called from admin/static-admin.php (Admin only).
// Approve: the HR account gets the new password. Reject: nothing changes (an optional reason is kept for HR).
// Either way a line is written to the activity log; HR sees it in the bell and in Settings.
function static_profile_decide_request(int $id, string $decision, string $reason = ''): array
{
    if ($decision !== 'approve' && $decision !== 'reject') throw new InvalidArgumentException('Choose Approve or Reject.');
    $reason = trim($reason);
    if (mb_strlen($reason) > 200) throw new InvalidArgumentException('The reason is too long (200 characters at most).');

    return static_store_update(function (array &$store) use ($id, $decision, $reason) {
        $reqs = static_profile_requests($store);
        $pos  = null;
        foreach ($reqs as $i => $r) {
            if ((int) ($r['id'] ?? 0) === $id) { $pos = $i; break; }
        }
        if ($pos === null) throw new InvalidArgumentException('That request was not found.');

        $r = $reqs[$pos];
        if (($r['status'] ?? '') !== 'pending') {
            throw new InvalidArgumentException(($r['status'] ?? '') === 'cancelled'
                ? 'HR took this request back, so there is nothing to answer.'
                : 'This request was already ' . (string) $r['status'] . '.');
        }

        if ($decision === 'approve') {
            if (empty($r['hash'])) throw new InvalidArgumentException('This request has no new password. Ask HR to send it again.');
            $list = static_profile_hr_list($store);
            $at   = null;
            foreach ($list as $i => $a) {
                if (is_array($a) && (int) ($a['id'] ?? 0) === (int) $r['account_id'] && empty($a['archived_at'])) { $at = $i; break; }
            }
            if ($at === null) throw new InvalidArgumentException('That HR account no longer exists, so its password cannot be changed.');
            $list[$at]['hash'] = (string) $r['hash'];
            if (!empty($r['plain'])) $list[$at]['password_plain'] = (string) $r['plain'];
            else                     unset($list[$at]['password_plain']);      // never show an old password that is no longer right
            unset($list[$at]['password']);                     // the old plain-text sample password is gone for good
            $store['hr_accounts'] = $list;
        }

        $reqs[$pos]['status']     = $decision === 'approve' ? 'approved' : 'rejected';
        $reqs[$pos]['decided_at'] = static_now()->format('Y-m-d H:i:s');
        $reqs[$pos]['decided_by'] = 'Admin';
        $reqs[$pos]['reason']     = $decision === 'reject' ? $reason : '';
        unset($reqs[$pos]['hash'], $reqs[$pos]['plain']);
        $store['password_requests'] = $reqs;

        $extra = ['kind' => 'pw_result', 'ref' => $id, 'owner' => (int) $r['account_id']];
        if ($decision === 'reject' && $reason !== '') $extra['reason'] = $reason;
        static_profile_log(
            $store,
            $decision === 'approve' ? 'Password Change Approved' : 'Password Change Rejected',
            (string) $r['name'] . ' (HR)',
            'Admin',
            $extra
        );
        return static_profile_request_public($reqs[$pos]);
    });
}

/* ==========================================================================
   FOR THE PAGES
   ========================================================================== */

// Everything a page needs to draw the profile. $avatarBase = how that page reaches hr/data/avatars/
// (HR pages: 'data/avatars/'   Admin pages: '../hr/data/avatars/').
function static_profile_for_page(string $avatarBase): array
{
    $store = static_store_read();
    $me    = static_profile_me($store);
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
        'request'     => ($me !== null && $me['role'] === 'hr') ? static_profile_latest_request($store, $me['id']) : null
    ];
}

/* ==========================================================================
   ENDPOINT: the JavaScript posts here  ( fetch('static-data.php', {method:'POST', ...}) )
   It only runs when this file is opened DIRECTLY; when a page does require_once on it,
   nothing below happens.

   Request (form fields or a JSON body):
       action = add | archive | restore | purge | status
       add      first_name, middle_name (optional), last_name, email, contact, position_id, cv (file)
       position_add  name, jd (a PDF, optional)     "+ Add job position" in Add Applicant
       positions     (nothing)                      the list of job positions
       archive  ids = [101, 102], at = { id: ms }     Screening List "Delete" (at is optional)
       restore  ids = [101, 102]                      Archive "Restore"
       purge    ids = [101, 102]                      Archive "Delete forever"
       status   id, status, result                    Pending | In Progress | Completed (+ Passed | Rejected)
       activity (nothing)                             the latest HR activity, for the bell on the HR Dashboard
       profile_save | password_change | password_status | password_cancel   Settings > Account (see PROFILE above)
   Every change that worked also writes one line to the activity log (static_log), which the Admin
   Dashboard shows under Recent Activities.
   Response (JSON):
       { status: 'success', ..., stats: {...}, completed_ids: [...], archived: N }
       { status: 'error', message: '...' }
   ========================================================================== */
if (isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) !== false
    && strcasecmp(realpath($_SERVER['SCRIPT_FILENAME']), __FILE__) === 0) {   // strcasecmp: Windows paths

    if (session_status() === PHP_SESSION_NONE) session_start();
    header('Content-Type: application/json; charset=UTF-8');

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
    if (in_array($action, ['profile_save', 'password_change', 'password_status', 'password_cancel'], true)
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
                static_log('Added An Applicant', (string) $extra['applicant']['name']);
                break;

            case 'parse_cv':
                // CV text extraction requires a PDF parsing library (e.g. smalot/pdfparser).
                // Not installed in this project; return a clear error so the frontend can fall back to manual entry.
                static_respond(['status' => 'error', 'message' => 'CV autofill is not available on this server. Please fill in the details manually.'], 501);
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
                // The bell on the HR Dashboard asks for the latest lines (page restored from the cache, tab back in view)
                static_respond(['status' => 'success', 'activity' => static_hr_feed()]);
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
                    'message' => $res['mode'] === 'applied' ? 'Password updated.' : 'Your password change was sent to the Admin for approval.',
                    'mode'    => $res['mode'],
                    'request' => $res['request']
                ]);
                break;

            case 'password_status':
                $pstore = static_store_read();
                $pme    = static_profile_me($pstore);
                static_respond([
                    'status'  => 'success',
                    'message' => '',
                    'request' => ($pme !== null && $pme['role'] === 'hr') ? static_profile_latest_request($pstore, $pme['id']) : null
                ]);
                break;

            case 'password_cancel':
                static_respond(['status' => 'success', 'message' => 'Password change request cancelled.', 'request' => static_profile_cancel_request()]);
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
        static_respond(['status' => 'error', 'message' => 'Something went wrong while saving. Please try again.'], 500);
    }
}
