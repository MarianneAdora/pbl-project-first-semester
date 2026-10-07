<?php
/* ==========================================================================
   STATIC DATA: the ONE place for every piece of sample data in the project.
   Everything here is temporary and is deleted when the database is connected.

   Who takes from this file
     index.php                static_accounts()        the HR / Admin login accounts
     hr/screeninglist.php     static_applicants()      the applicants, with status + result
     hr/archive.php           static_applicants()      same list (the page shows the archived ones)
     hr/dashboardhr.php       static_applicants()      the cards, the Passed/Failed chart and
                              static_stats()           Recent Applicants are all worked out
                              static_completed_ids()   from this one list
                              static_recent()

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
   ========================================================================== */

// ---------------------------------------------------------------------------
// Login accounts (index.php). Passwords are plain text only because this is sample data.
// ---------------------------------------------------------------------------
function static_accounts(): array
{
    return [
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
            'redirect' => 'admindashboardadmin.php'
        ]
    ];
}

// ---------------------------------------------------------------------------
// Applicants, with their status, result and the time of their latest activity.
//   status:  'Pending' | 'In Progress' | 'Completed'
//   result:  'Passed' | 'Failed' | null   (only a Completed screening has a result)
//   updated: newest event (applied OR status change), 'YYYY-MM-DD HH:MM'
// ---------------------------------------------------------------------------
function static_applicants(): array
{
    return [
        ['id' => 101, 'name' => 'Juan Dela Cruz', 'email' => 'juandelacruz@gmail.com', 'contact' => '091234567891', 'status' => 'Pending', 'result' => null, 'updated' => '2026-10-05 08:15'],
        ['id' => 102, 'name' => 'Bruce Wayne', 'email' => 'brucewayne@gmail.com', 'contact' => '091234567854', 'status' => 'In Progress', 'result' => null, 'updated' => '2026-10-04 11:05'],
        ['id' => 103, 'name' => 'Clark Kent', 'email' => 'clarkkent@gmail.com', 'contact' => '091234567817', 'status' => 'Completed', 'result' => 'Passed', 'updated' => '2026-10-05 10:52'],
        ['id' => 104, 'name' => 'Diana Prince', 'email' => 'dianaprince@gmail.com', 'contact' => '091234567780', 'status' => 'Completed', 'result' => 'Failed', 'updated' => '2026-10-04 16:30'],
        ['id' => 105, 'name' => 'Maria Clara Santos', 'email' => 'mariaclarasantos@gmail.com', 'contact' => '091234567743', 'status' => 'Pending', 'result' => null, 'updated' => '2026-10-01 13:00'],
        ['id' => 106, 'name' => 'Jose Protacio Rizal Mercado y Alonso Realonda', 'email' => 'joseprotaciorizalmercadoyalonsorealonda@gmail.com', 'contact' => '091234567706', 'status' => 'Completed', 'result' => 'Passed', 'updated' => '2026-09-29 10:00'],
        ['id' => 107, 'name' => 'Peter Parker', 'email' => 'peterparker@gmail.com', 'contact' => '091234567669', 'status' => 'In Progress', 'result' => null, 'updated' => '2026-10-02 09:10'],
        ['id' => 108, 'name' => 'Tony Stark', 'email' => 'tonystark@gmail.com', 'contact' => '091234567632', 'status' => 'Completed', 'result' => 'Passed', 'updated' => '2026-10-02 17:45'],
        ['id' => 109, 'name' => 'Natasha Romanoff', 'email' => 'natasharomanoff@gmail.com', 'contact' => '091234567595', 'status' => 'Completed', 'result' => 'Failed', 'updated' => '2026-09-30 15:25'],
        ['id' => 110, 'name' => 'Steve Rogers', 'email' => 'steverogers@gmail.com', 'contact' => '091234567558', 'status' => 'Pending', 'result' => null, 'updated' => '2026-10-03 14:20'],
        ['id' => 111, 'name' => 'Barry Allen', 'email' => 'barryallen@gmail.com', 'contact' => '091234567521', 'status' => 'Completed', 'result' => 'Passed', 'updated' => '2026-09-28 16:40'],
        ['id' => 112, 'name' => 'Hal Jordan', 'email' => 'haljordan@gmail.com', 'contact' => '091234567484', 'status' => 'Pending', 'result' => null, 'updated' => '2026-09-28 09:05'],
        ['id' => 113, 'name' => 'Arthur Curry', 'email' => 'arthurcurry@gmail.com', 'contact' => '091234567447', 'status' => 'In Progress', 'result' => null, 'updated' => '2026-09-27 14:30'],
        ['id' => 114, 'name' => 'Victor Stone', 'email' => 'victorstone@gmail.com', 'contact' => '091234567410', 'status' => 'Completed', 'result' => 'Passed', 'updated' => '2026-09-27 10:15'],
        ['id' => 115, 'name' => 'Selina Kyle', 'email' => 'selinakyle@gmail.com', 'contact' => '091234567373', 'status' => 'Pending', 'result' => null, 'updated' => '2026-09-26 15:50'],
        ['id' => 116, 'name' => 'Oliver Queen', 'email' => 'oliverqueen@gmail.com', 'contact' => '091234567336', 'status' => 'Completed', 'result' => 'Failed', 'updated' => '2026-09-26 11:20'],
        ['id' => 117, 'name' => 'Kara Danvers', 'email' => 'karadanvers@gmail.com', 'contact' => '091234567299', 'status' => 'Pending', 'result' => null, 'updated' => '2026-09-25 13:45'],
        ['id' => 118, 'name' => 'Bruce Banner', 'email' => 'brucebanner@gmail.com', 'contact' => '091234567262', 'status' => 'In Progress', 'result' => null, 'updated' => '2026-09-25 09:30'],
        ['id' => 119, 'name' => 'Wanda Maximoff', 'email' => 'wandamaximoff@gmail.com', 'contact' => '091234567225', 'status' => 'Completed', 'result' => 'Passed', 'updated' => '2026-09-24 16:10'],
        ['id' => 120, 'name' => 'Stephen Strange', 'email' => 'stephenstrange@gmail.com', 'contact' => '091234567188', 'status' => 'Pending', 'result' => null, 'updated' => '2026-09-24 10:00'],
        ['id' => 121, 'name' => 'Carol Danvers', 'email' => 'caroldanvers@gmail.com', 'contact' => '091234567151', 'status' => 'Completed', 'result' => 'Passed', 'updated' => '2026-09-23 14:25'],
        ['id' => 122, 'name' => 'Scott Lang', 'email' => 'scottlang@gmail.com', 'contact' => '091234567114', 'status' => 'Pending', 'result' => null, 'updated' => '2026-09-23 09:40'],
        ['id' => 123, 'name' => 'Hope Van Dyne', 'email' => 'hopevandyne@gmail.com', 'contact' => '091234567077', 'status' => 'In Progress', 'result' => null, 'updated' => '2026-09-22 15:35'],
        ['id' => 124, 'name' => 'Sam Wilson', 'email' => 'samwilson@gmail.com', 'contact' => '091234567040', 'status' => 'Completed', 'result' => 'Failed', 'updated' => '2026-09-22 11:10'],
        ['id' => 125, 'name' => 'Bucky Barnes', 'email' => 'buckybarnes@gmail.com', 'contact' => '091234567003', 'status' => 'Pending', 'result' => null, 'updated' => '2026-09-21 13:00'],
        ['id' => 126, 'name' => 'Matt Murdock', 'email' => 'mattmurdock@gmail.com', 'contact' => '091234566966', 'status' => 'Completed', 'result' => 'Passed', 'updated' => '2026-09-21 09:20'],
        ['id' => 127, 'name' => 'Jessica Jones', 'email' => 'jessicajones@gmail.com', 'contact' => '091234566929', 'status' => 'Pending', 'result' => null, 'updated' => '2026-09-20 16:45'],
        ['id' => 128, 'name' => 'Luke Cage', 'email' => 'lukecage@gmail.com', 'contact' => '091234566892', 'status' => 'In Progress', 'result' => null, 'updated' => '2026-09-20 10:30'],
        ['id' => 129, 'name' => 'Danny Rand', 'email' => 'dannyrand@gmail.com', 'contact' => '091234566855', 'status' => 'Completed', 'result' => 'Passed', 'updated' => '2026-09-19 14:05'],
        ['id' => 130, 'name' => 'Kate Bishop', 'email' => 'katebishop@gmail.com', 'contact' => '091234566818', 'status' => 'Pending', 'result' => null, 'updated' => '2026-09-19 09:15'],
        ['id' => 131, 'name' => 'Clint Barton', 'email' => 'clintbarton@gmail.com', 'contact' => '091234566781', 'status' => 'Completed', 'result' => 'Failed', 'updated' => '2026-09-18 15:55'],
        ['id' => 132, 'name' => 'Pepper Potts', 'email' => 'pepperpotts@gmail.com', 'contact' => '091234566744', 'status' => 'Pending', 'result' => null, 'updated' => '2026-09-18 11:25'],
        ['id' => 133, 'name' => 'Maria Hill', 'email' => 'mariahill@gmail.com', 'contact' => '091234566707', 'status' => 'In Progress', 'result' => null, 'updated' => '2026-09-17 13:35'],
        ['id' => 134, 'name' => 'Nick Fury', 'email' => 'nickfury@gmail.com', 'contact' => '091234566670', 'status' => 'Pending', 'result' => null, 'updated' => '2026-09-17 09:50'],
        ['id' => 135, 'name' => 'Gabriel Antonio Dela Rosa Villanueva', 'email' => 'gabrielantoniodelarosavillanueva@gmail.com', 'contact' => '091234566633', 'status' => 'Completed', 'result' => 'Passed', 'updated' => '2026-09-16 14:15']
    ];
}

// ---------------------------------------------------------------------------
// Numbers for the dashboard cards and the Passed/Failed chart, counted from the list.
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
        'failed'      => $count(fn($a) => strtolower((string) $a['status']) === 'completed' && strtolower((string) $a['result']) === 'failed')
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