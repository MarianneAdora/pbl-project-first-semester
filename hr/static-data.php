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
function static_seed_applicants(): array
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
        static_store_write($store);
    }
    return $store;
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
            'updated' => static_now()->format('Y-m-d H:i')
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
    if ($status !== 'Completed' || !in_array($result, ['Passed', 'Failed'], true)) $result = null;

    return static_store_update(function (array &$store) use ($id, $status, $result) {
        foreach ($store['applicants'] as &$a) {
            if ((int) $a['id'] !== $id) continue;
            $a['status']  = $status;
            $a['result']  = $result;
            $a['updated'] = static_now()->format('Y-m-d H:i');
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
       status   id, status, result                    Pending | In Progress | Completed (+ Passed | Failed)
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
                break;

            case 'archive':
                $extra['count'] = static_archive($ids, (array) ($input['at'] ?? []));
                break;

            case 'restore':
                $extra['count'] = static_restore($ids);
                break;

            case 'purge':
                $extra['count'] = static_purge($ids);
                break;

            case 'status':
                $ok = static_set_status(
                    (int) ($input['id'] ?? 0),
                    (string) ($input['status'] ?? ''),
                    isset($input['result']) && $input['result'] !== '' ? (string) $input['result'] : null
                );
                if (!$ok) static_respond(['status' => 'error', 'message' => 'That applicant or status was not found.'], 404);
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