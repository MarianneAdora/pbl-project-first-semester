<?php
/* ==========================================================================
   STATIC DATA: the ONE place for every piece of sample data in the project.
   Everything here is temporary and is deleted when the database is connected.

   Who takes from this file
     index.php                static_accounts()        the HR / Admin login accounts
     hr/screeninglist.php     static_applicants()      the applicants, with status + result
     hr/archive.php           static_applicants()      same list (the page shows the archived ones)
     hr/dashboardhr.php       static_applicants()      the cards, the Passed/Rejected chart and
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
     static_add_applicant()         Add Applicant           -> Pending
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
            'redirect' => 'hr/dashboardhr.php'
        ],
        'admin@expertise.com' => [
            'password' => 'admin123',
            'role'     => 'admin',
            'name'     => 'Admin',
            'redirect' => 'admin/dashboardadmin.php'
        ]
    ];

    // Once the Admin has created / changed an HR account (admin/hrlist.php), the HR logins come from the
    // store instead: only Active accounts that are not in the Archive can log in, and a password the
    // Admin typed is kept as a hash ('hash'), checked with password_verify() in index.php.
    // BACKEND: SELECT name, email, password_hash FROM users WHERE role = 'hr' AND status = 'Active'
    if (is_file(STATIC_STORE_FILE)) {
        $store = json_decode((string) @file_get_contents(STATIC_STORE_FILE), true);
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
                    'redirect' => 'hr/dashboardhr.php'
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
    return [
        ['id' => 101, 'name' => 'Juan Dela Cruz', 'email' => 'juandelacruz@gmail.com', 'contact' => '091234567891', 'status' => 'Pending', 'result' => null, 'updated' => '2026-10-05 08:15'],
        ['id' => 102, 'name' => 'Bruce Wayne', 'email' => 'brucewayne@gmail.com', 'contact' => '091234567854', 'status' => 'In Progress', 'result' => null, 'updated' => '2026-10-04 11:05'],
        ['id' => 103, 'name' => 'Clark Kent', 'email' => 'clarkkent@gmail.com', 'contact' => '091234567817', 'status' => 'Completed', 'result' => 'Passed', 'updated' => '2026-10-05 10:52'],
        ['id' => 104, 'name' => 'Diana Prince', 'email' => 'dianaprince@gmail.com', 'contact' => '091234567780', 'status' => 'Completed', 'result' => 'Rejected', 'updated' => '2026-10-04 16:30'],
        ['id' => 105, 'name' => 'Maria Clara Santos', 'email' => 'mariaclarasantos@gmail.com', 'contact' => '091234567743', 'status' => 'Pending', 'result' => null, 'updated' => '2026-10-01 13:00'],
        ['id' => 106, 'name' => 'Jose Protacio Rizal Mercado y Alonso Realonda', 'email' => 'joseprotaciorizalmercadoyalonsorealonda@gmail.com', 'contact' => '091234567706', 'status' => 'Completed', 'result' => 'Passed', 'updated' => '2026-09-29 10:00'],
        ['id' => 107, 'name' => 'Peter Parker', 'email' => 'peterparker@gmail.com', 'contact' => '091234567669', 'status' => 'In Progress', 'result' => null, 'updated' => '2026-10-02 09:10'],
        ['id' => 108, 'name' => 'Tony Stark', 'email' => 'tonystark@gmail.com', 'contact' => '091234567632', 'status' => 'Completed', 'result' => 'Passed', 'updated' => '2026-10-02 17:45'],
        ['id' => 109, 'name' => 'Natasha Romanoff', 'email' => 'natasharomanoff@gmail.com', 'contact' => '091234567595', 'status' => 'Completed', 'result' => 'Rejected', 'updated' => '2026-09-30 15:25'],
        ['id' => 110, 'name' => 'Steve Rogers', 'email' => 'steverogers@gmail.com', 'contact' => '091234567558', 'status' => 'Pending', 'result' => null, 'updated' => '2026-10-03 14:20'],
        ['id' => 111, 'name' => 'Barry Allen', 'email' => 'barryallen@gmail.com', 'contact' => '091234567521', 'status' => 'Completed', 'result' => 'Passed', 'updated' => '2026-09-28 16:40'],
        ['id' => 112, 'name' => 'Hal Jordan', 'email' => 'haljordan@gmail.com', 'contact' => '091234567484', 'status' => 'Pending', 'result' => null, 'updated' => '2026-09-28 09:05'],
        ['id' => 113, 'name' => 'Arthur Curry', 'email' => 'arthurcurry@gmail.com', 'contact' => '091234567447', 'status' => 'In Progress', 'result' => null, 'updated' => '2026-09-27 14:30'],
        ['id' => 114, 'name' => 'Victor Stone', 'email' => 'victorstone@gmail.com', 'contact' => '091234567410', 'status' => 'Completed', 'result' => 'Passed', 'updated' => '2026-09-27 10:15'],
        ['id' => 115, 'name' => 'Selina Kyle', 'email' => 'selinakyle@gmail.com', 'contact' => '091234567373', 'status' => 'Pending', 'result' => null, 'updated' => '2026-09-26 15:50'],
        ['id' => 116, 'name' => 'Oliver Queen', 'email' => 'oliverqueen@gmail.com', 'contact' => '091234567336', 'status' => 'Completed', 'result' => 'Rejected', 'updated' => '2026-09-26 11:20'],
        ['id' => 117, 'name' => 'Kara Danvers', 'email' => 'karadanvers@gmail.com', 'contact' => '091234567299', 'status' => 'Pending', 'result' => null, 'updated' => '2026-09-25 13:45'],
        ['id' => 118, 'name' => 'Bruce Banner', 'email' => 'brucebanner@gmail.com', 'contact' => '091234567262', 'status' => 'In Progress', 'result' => null, 'updated' => '2026-09-25 09:30'],
        ['id' => 119, 'name' => 'Wanda Maximoff', 'email' => 'wandamaximoff@gmail.com', 'contact' => '091234567225', 'status' => 'Completed', 'result' => 'Passed', 'updated' => '2026-09-24 16:10'],
        ['id' => 120, 'name' => 'Stephen Strange', 'email' => 'stephenstrange@gmail.com', 'contact' => '091234567188', 'status' => 'Pending', 'result' => null, 'updated' => '2026-09-24 10:00'],
        ['id' => 121, 'name' => 'Carol Danvers', 'email' => 'caroldanvers@gmail.com', 'contact' => '091234567151', 'status' => 'Completed', 'result' => 'Passed', 'updated' => '2026-09-23 14:25'],
        ['id' => 122, 'name' => 'Scott Lang', 'email' => 'scottlang@gmail.com', 'contact' => '091234567114', 'status' => 'Pending', 'result' => null, 'updated' => '2026-09-23 09:40'],
        ['id' => 123, 'name' => 'Hope Van Dyne', 'email' => 'hopevandyne@gmail.com', 'contact' => '091234567077', 'status' => 'In Progress', 'result' => null, 'updated' => '2026-09-22 15:35'],
        ['id' => 124, 'name' => 'Sam Wilson', 'email' => 'samwilson@gmail.com', 'contact' => '091234567040', 'status' => 'Completed', 'result' => 'Rejected', 'updated' => '2026-09-22 11:10'],
        ['id' => 125, 'name' => 'Bucky Barnes', 'email' => 'buckybarnes@gmail.com', 'contact' => '091234567003', 'status' => 'Pending', 'result' => null, 'updated' => '2026-09-21 13:00'],
        ['id' => 126, 'name' => 'Matt Murdock', 'email' => 'mattmurdock@gmail.com', 'contact' => '091234566966', 'status' => 'Completed', 'result' => 'Passed', 'updated' => '2026-09-21 09:20'],
        ['id' => 127, 'name' => 'Jessica Jones', 'email' => 'jessicajones@gmail.com', 'contact' => '091234566929', 'status' => 'Pending', 'result' => null, 'updated' => '2026-09-20 16:45'],
        ['id' => 128, 'name' => 'Luke Cage', 'email' => 'lukecage@gmail.com', 'contact' => '091234566892', 'status' => 'In Progress', 'result' => null, 'updated' => '2026-09-20 10:30'],
        ['id' => 129, 'name' => 'Danny Rand', 'email' => 'dannyrand@gmail.com', 'contact' => '091234566855', 'status' => 'Completed', 'result' => 'Passed', 'updated' => '2026-09-19 14:05'],
        ['id' => 130, 'name' => 'Kate Bishop', 'email' => 'katebishop@gmail.com', 'contact' => '091234566818', 'status' => 'Pending', 'result' => null, 'updated' => '2026-09-19 09:15'],
        ['id' => 131, 'name' => 'Clint Barton', 'email' => 'clintbarton@gmail.com', 'contact' => '091234566781', 'status' => 'Completed', 'result' => 'Rejected', 'updated' => '2026-09-18 15:55'],
        ['id' => 132, 'name' => 'Pepper Potts', 'email' => 'pepperpotts@gmail.com', 'contact' => '091234566744', 'status' => 'Pending', 'result' => null, 'updated' => '2026-09-18 11:25'],
        ['id' => 133, 'name' => 'Maria Hill', 'email' => 'mariahill@gmail.com', 'contact' => '091234566707', 'status' => 'In Progress', 'result' => null, 'updated' => '2026-09-17 13:35'],
        ['id' => 134, 'name' => 'Nick Fury', 'email' => 'nickfury@gmail.com', 'contact' => '091234566670', 'status' => 'Pending', 'result' => null, 'updated' => '2026-09-17 09:50'],
        ['id' => 135, 'name' => 'Gabriel Antonio Dela Rosa Villanueva', 'email' => 'gabrielantoniodelarosavillanueva@gmail.com', 'contact' => '091234566633', 'status' => 'Completed', 'result' => 'Passed', 'updated' => '2026-09-16 14:15']
    ];
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
function static_dashboard_insights(array $applicants): array
{
    $out        = static_dashboard_empty_insights();
    $store      = static_store_read();
    $interviews = is_array($store['interviews'] ?? null) ? $store['interviews'] : [];

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
    return file_put_contents(STATIC_STORE_FILE, $json, LOCK_EX) !== false;
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
        return [
            ['at' => '2026-10-01 13:00', 'activity' => 'Added An Applicant',    'details' => 'Maria Clara Santos',        'by' => 'HR'],
            ['at' => '2026-10-03 14:20', 'activity' => 'Added An Applicant',    'details' => 'Steve Rogers',              'by' => 'HR'],
            ['at' => '2026-10-05 08:15', 'activity' => 'Added An Applicant',    'details' => 'Juan Dela Cruz',            'by' => 'HR'],
            ['at' => '2026-10-08 11:45', 'activity' => 'Questionnaires Edited', 'details' => 'Q5 · Consent & readiness',  'by' => 'Admin']
        ];
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

// What HR did, newest first: the list behind the bell on the HR Dashboard (the Admin's own lines are left out)
function static_hr_feed(int $limit = 30): array
{
    $out = [];
    foreach (static_activity() as $row) {
        if (strcasecmp((string) ($row['by'] ?? ''), 'Admin') === 0) continue;
        $out[] = ['at' => (string) $row['at'], 'activity' => (string) $row['activity'], 'details' => (string) $row['details'], 'by' => (string) $row['by']];
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

// "Maria Clara Santos" / "Maria, Steve" / "Maria, Steve +3 more"
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
// (see the <script> line above hr-common.js in dashboardhr.php, screeninglist.php, archive.php
// and addapplicants.php).
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
// Add Applicant: a new applicant starts as Pending. Throws InvalidArgumentException with a
// message that is safe to show to HR.
function static_add_applicant(string $name, string $email, string $contact, ?array $cvUpload = null): array
{
    $name    = trim($name);
    $email   = trim($email);
    $contact = trim($contact);
    if ($name === '')                                 throw new InvalidArgumentException('Please enter the full name.');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL))   throw new InvalidArgumentException('Enter a valid email address (example: name@gmail.com).');
    if ($contact === '')                              throw new InvalidArgumentException('Please enter the contact number.');

    return static_store_update(function (array &$store) use ($name, $email, $contact, $cvUpload) {
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
            'id'      => static_next_id($store),
            'name'    => $name,
            'email'   => $email,
            'contact' => $contact,
            'status'  => 'Pending',
            'result'  => null,
            'updated' => static_now()->format('Y-m-d H:i'),
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
   ENDPOINT: the JavaScript posts here  ( fetch('static-data.php', {method:'POST', ...}) )
   It only runs when this file is opened DIRECTLY; when a page does require_once on it,
   nothing below happens.

   Request (form fields or a JSON body):
       action = add | archive | restore | purge | status
       add      name, email, contact                 (the cv file is ignored for now)
       archive  ids = [101, 102], at = { id: ms }     Screening List "Delete" (at is optional)
       restore  ids = [101, 102]                      Archive "Restore"
       purge    ids = [101, 102]                      Archive "Delete forever"
       status   id, status, result                    Pending | In Progress | Completed (+ Passed | Rejected)
       activity (nothing)                             the latest HR activity, for the bell on the HR Dashboard
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

    $action = (string) ($input['action'] ?? '');
    $ids    = array_values(array_filter((array) ($input['ids'] ?? []), 'is_numeric'));

    try {
        $extra = [];

        switch ($action) {
            case 'add':
                $extra['applicant'] = static_add_applicant(
                    (string) ($input['name'] ?? ''),
                    (string) ($input['email'] ?? ''),
                    (string) ($input['contact'] ?? ''),
                    isset($_FILES['cv']) && is_array($_FILES['cv']) ? $_FILES['cv'] : null
                );
                static_log('Added An Applicant', (string) $extra['applicant']['name']);
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

    } catch (InvalidArgumentException $e) {
        static_respond(['status' => 'error', 'message' => $e->getMessage()], 422);
    } catch (Throwable $e) {
        static_respond(['status' => 'error', 'message' => 'Something went wrong while saving. Please try again.'], 500);
    }
}