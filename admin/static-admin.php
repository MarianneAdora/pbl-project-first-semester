<?php
/* ==========================================================================
   STATIC ADMIN DATA: everything the Admin side needs, in ONE file.
   Location: admin/static-admin.php  (next to the admin pages)
   Temporary, like hr/static-data.php. It is deleted when the database is connected.

   Who takes from this file
     admin/dashboardadmin.php static_admin_overview()   the three summary boxes
                             static_admin_question_stats()  Questionnaires Statistics (every question + its most chosen answer)
                             static_activity()         Recent Activities (from hr/static-data.php)
     admin/questionlist.php   static_admin_payload()    view / add / edit / delete the questionnaires
     admin/hrlist.php         static_admin_payload()    add / edit / deactivate / delete HR accounts
     admin/archiveadmin.php    static_admin_payload()    deleted questions + HR accounts (30 days, then gone)

   Where the data lives (hr/data/static-store.json, the same "table file" the HR side uses)
     questions     the questionnaire. THE APPLICANT PAGE READS THE SAME LIST (static_questionnaire() in
                   applicant/static-applicant.php), so what the Admin saves is what applicants answer.
                   Until the Admin changes something, the starting list in static-applicant.php is used.
     hr_accounts   the HR logins (the login page reads them through static_accounts())
     activity      what HR and the Admin did (every change function writes its own line)
     submissions   the answers applicants sent (already written by the applicant pages)

   THIS FILE IS ALSO THE ENDPOINT the admin JavaScript calls (see the bottom): a POST to static-admin.php
   with  action = question_add | question_update | question_archive | question_restore | question_purge |
                  account_add | account_update | account_archive | account_restore | account_purge | notify
   changes the store and answers with JSON (the fresh lists, so the page can redraw itself).
   Questions can be KNOCK-OUT questions (question.knockout + question.pass_answer, and pass_follow for a Yes/No + list):
   an applicant whose answer does not match the passing answer fails the screening (applicant/static-evaluation.php)
   and HR sees why in the applicant's Questionnaires tab. The rule itself lives in applicant/static-applicant.php.
   Questions have a status (Active / Inactive) and a created date. An Inactive question stays in the Admin
   list but the applicants do not see it. HR accounts have a role ("HR Staff") and a status.
   When the Admin ticks "Send login credentials via email", a line is written to the store's "outbox".
   To reset the sample data: delete hr/data/static-store.json.
   To go live: delete this file; the backend answers the same POST requests.

   When the database is ready, replace each function with a query:
     static_admin_questions()    ->  SELECT * FROM questions ORDER BY section_no, position
     static_admin_accounts()     ->  SELECT id, name, email, status, created_at FROM users WHERE role = 'hr'
     static_admin_question_stats() -> per question: COUNT(*) of each answer value, top = the largest count
     static_admin_overview()     ->  three COUNT(*) queries
   ========================================================================== */

require_once __DIR__ . '/../applicant/static-applicant.php';   // also loads hr/static-data.php (the store)

/* ==========================================================================
   SAFETY NET: the admin pages need these functions from hr/static-data.php.
   If that file does not have them (an older copy), the versions below are used instead.
   When hr/static-data.php has them, NOTHING here runs (each one only exists "if missing").
   ========================================================================== */
if (!function_exists('static_store_read') || !function_exists('static_store_write') || !function_exists('static_now')) {
    exit('hr/static-data.php is missing its store functions (static_store_read / static_store_write / static_now). Restore your own copy of hr/static-data.php.');
}

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

/* The activity log helpers (static_seed_activity, static_actor, static_log_into, static_activity) now live in
   hr/static-data.php, because HR writes to the same log: everything HR does shows here on the Admin side under
   Recent Activities. The Admin's own change functions below still call static_log_into() exactly as before. */

if (!defined('STATIC_ARCHIVE_DAYS')) define('STATIC_ARCHIVE_DAYS', 30);

// ---------------------------------------------------------------------------
// The kinds of question the applicant page can draw (see applicant/static-applicant.php)
// ---------------------------------------------------------------------------
function static_question_types(): array
{
    return [
        'yesno'        => 'Yes / No',
        'yesno_select' => 'Yes / No + a list',
        'scale'        => 'Rating scale',
        'date'         => 'Date',
        'text'         => 'Written answer',
        'consent'      => 'Statement + checkbox'
    ];
}

/* ==========================================================================
   QUESTIONS
   ========================================================================== */

// A question saved before status / created existed gets them here (Active, created with the first HR account)
function static_admin_question_fill(array $q): array
{
    $q += ['status' => 'Active', 'created' => '2026-08-03 09:00'];
    // a question saved before the knock-out setting existed: show the rule it was already screened with
    if (!array_key_exists('knockout', $q)) {
        $rule = static_question_rule($q);
        $q['knockout'] = $rule !== null;
        if ($rule !== null) $q['pass_answer'] = $rule['pass'];
    }
    return $q;
}

// Every question, in order, INCLUDING deleted ones (they have archived_at). Not saved until a change is made.
function static_admin_all_questions(array $store): array
{
    if (isset($store['questions']) && is_array($store['questions'])) {
        return array_map('static_admin_question_fill', $store['questions']);
    }
    return array_map(fn($q) => static_admin_question_fill($q + ['archived_at' => null]), static_questionnaire_seed());
}

// Questions in the Admin list (not deleted), in order. Inactive ones are included; the applicants do not see them
// (static_questionnaire() in applicant/static-applicant.php leaves them out).
function static_admin_questions(): array
{
    $out = [];
    foreach (static_admin_all_questions(static_store_read()) as $q) {
        if (!empty($q['archived_at'])) continue;
        unset($q['archived_at']);
        $out[] = $q;
    }
    return $out;
}

// Questions that are Active and not deleted (what the applicants answer)
function static_admin_active_count(array $all, ?string $exceptId = null): int
{
    return count(array_filter($all, fn($q) => empty($q['archived_at']) && ($q['status'] ?? 'Active') === 'Active' && $q['id'] !== $exceptId));
}

// Next id: q14, q15, ... (never reuses an id, even of a deleted question)
function static_admin_next_question_id(array $all): string
{
    $max = 0;
    foreach ($all as $q) $max = max($max, (int) substr((string) $q['id'], 1));
    return 'q' . ($max + 1);
}

// Short text for the activity log: "Q5 · Consent & readiness"
function static_admin_question_label(array $q): string
{
    return strtoupper((string) $q['id']) . ' · ' . $q['section'];
}

// Checks what the Admin typed and builds the question exactly like the ones in static_questionnaire_seed().
// Throws InvalidArgumentException with a message that is safe to show.
function static_admin_clean_question(array $in, array $all, ?array $old = null): array
{
    $types = static_question_types();
    $type  = (string) ($in['type'] ?? '');
    if (!isset($types[$type])) throw new InvalidArgumentException('Please choose the type of question.');

    $text = trim((string) ($in['text'] ?? ''));
    if ($text === '')              throw new InvalidArgumentException('Please write the question.');
    if (mb_strlen($text) > 400)    throw new InvalidArgumentException('The question is too long (400 characters at most).');

    $section = trim((string) ($in['section'] ?? ''));
    if ($section === '')           throw new InvalidArgumentException('Please choose a section or write a new one.');
    if (mb_strlen($section) > 60)  throw new InvalidArgumentException('The section name is too long (60 characters at most).');

    // An existing section keeps its number; a new section goes last
    $sectionNo = 0;
    $maxNo     = 0;
    foreach ($all as $q) {
        $maxNo = max($maxNo, (int) $q['section_no']);
        if (strcasecmp((string) $q['section'], $section) === 0) {
            $sectionNo = (int) $q['section_no'];
            $section   = (string) $q['section'];
        }
    }
    if ($sectionNo === 0) $sectionNo = $maxNo + 1;

    // Active (applicants see it) or Inactive (kept in the list, hidden from applicants)
    $status = (string) ($in['status'] ?? ($old['status'] ?? 'Active'));
    $status = $status === 'Inactive' ? 'Inactive' : 'Active';

    $q = [
        'id'         => $old['id'] ?? static_admin_next_question_id($all),
        'section_no' => $sectionNo,
        'section'    => $section,
        'type'       => $type,
        'text'       => $text,
        'status'     => $status,
        'created'    => $old['created'] ?? static_now()->format('Y-m-d H:i')
    ];

    $line = fn(string $key, int $limit = 200): string => mb_substr(trim((string) ($in[$key] ?? '')), 0, $limit);

    switch ($type) {
        case 'yesno':
            $note = trim((string) ($in['no_note'] ?? ''));
            if (mb_strlen($note) > 200) throw new InvalidArgumentException('The reminder for "No" is too long (200 characters at most).');
            if ($note !== '') $q['no_note'] = $note;
            // which answer counts as good in the Questionnaires Statistics (Yes unless the Admin says otherwise)
            if (($in['favorable'] ?? 'Yes') === 'No') $q['favorable'] = 'No';
            break;

        case 'yesno_select':
            $label   = $line('followup_label', 80);
            $options = array_values(array_filter(array_map('trim', preg_split('/\R/', (string) ($in['followup_options'] ?? ''))), fn($o) => $o !== ''));
            if ($label === '')        throw new InvalidArgumentException('Please write the label above the list (example: Your notice period).');
            if (count($options) < 2)  throw new InvalidArgumentException('Add at least two choices for the list, one per line.');
            if (count($options) > 12) throw new InvalidArgumentException('The list can have 12 choices at most.');
            $q['followup'] = [
                'when'    => (($in['followup_when'] ?? 'Yes') === 'No') ? 'No' : 'Yes',
                'label'   => $label,
                'options' => $options
            ];
            break;

        case 'scale':
            $max = (int) ($in['max'] ?? 5);
            if ($max < 3 || $max > 10) throw new InvalidArgumentException('The rating scale must go from 3 up to 10.');
            $q['max']    = $max;
            $q['labels'] = [$line('label_left', 40), $line('label_right', 40)];
            break;

        case 'date':
            if ($line('hint') !== '') $q['hint'] = $line('hint');
            break;

        case 'text':
            $min  = max(0, (int) ($in['min_chars'] ?? 0));
            $maxc = (int) ($in['max_chars'] ?? 500);
            if ($maxc < 10 || $maxc > 2000) throw new InvalidArgumentException('The longest answer must be between 10 and 2000 characters.');
            if ($min > $maxc)               throw new InvalidArgumentException('The shortest answer cannot be longer than the longest answer.');
            if ($line('hint') !== '')        $q['hint'] = $line('hint');
            $q['placeholder'] = $line('placeholder') !== '' ? $line('placeholder') : 'Type your answer here...';
            $q['min_chars']   = $min;
            $q['max_chars']   = $maxc;
            break;

        case 'consent':
            $body = array_values(array_filter(array_map('trim', preg_split('/\R{2,}/', trim((string) ($in['body'] ?? '')))), fn($p) => $p !== ''));
            if (!$body)                 throw new InvalidArgumentException('Please write the statement the applicant has to read.');
            if ($line('checkbox') === '') throw new InvalidArgumentException('Please write the text next to the checkbox (example: I agree).');
            $q['body']     = $body;
            $q['checkbox'] = $line('checkbox');
            break;
    }

    // Knock-out question (screening rubric): the passing answer. Only Yes/No, Yes/No + list and rating questions.
    $ko = !empty($in['knockout']) && in_array($type, ['yesno', 'yesno_select', 'scale'], true);
    $q['knockout'] = $ko;
    unset($q['pass_answer'], $q['pass_follow']);
    if ($ko) {
        if ($type === 'scale') {
            $max  = (int) $q['max'];
            $pass = trim((string) ($in['pass_answer'] ?? ''));
            if (!ctype_digit($pass) || (int) $pass < 1 || (int) $pass > $max) {
                throw new InvalidArgumentException('The lowest passing rating must be a whole number from 1 up to the highest rating (' . $max . ').');
            }
            $q['pass_answer'] = (int) $pass;
        } else {
            $pass = (string) ($in['pass_answer'] ?? '');
            if ($pass !== 'Yes' && $pass !== 'No') throw new InvalidArgumentException('Choose the passing answer (Yes or No) for the knock-out question.');
            $q['pass_answer'] = $pass;
            if ($type === 'yesno') {
                // the Questionnaires Statistics count the passing answer as the good one
                unset($q['favorable']);
                if ($pass === 'No') $q['favorable'] = 'No';
            } elseif ($pass === $q['followup']['when']) {
                // optional: only some of the list choices pass (none ticked = every choice passes)
                $raw      = is_array($in['pass_follow'] ?? null) ? $in['pass_follow'] : preg_split('/\R/', (string) ($in['pass_follow'] ?? ''));
                $accepted = array_values(array_intersect($q['followup']['options'], array_map('trim', array_map('strval', $raw))));
                if ($accepted && count($accepted) < count($q['followup']['options'])) $q['pass_follow'] = $accepted;
            }
        }
    }

    // {link} inside the question text: the word that is clickable + where it goes
    if (strpos($text, '{link}') !== false) {
        $linkText = $line('link_text', 60);
        $linkUrl  = $line('link_url', 500);
        if ($linkText === '' || !preg_match('#^https?://#i', $linkUrl) || !filter_var($linkUrl, FILTER_VALIDATE_URL)) {
            throw new InvalidArgumentException('The question has {link}. Add the link text and a web address that starts with http:// or https://.');
        }
        $q['link'] = ['text' => $linkText, 'url' => $linkUrl];
    }

    return $q;
}

// Renames a section: every question of it gets the new title (deleted ones too, so a restore keeps the title).
// $current = the title of the section being renamed. Returns ['no' => , 'from' => , 'to' => ] or null when nothing changes.
function static_admin_rename_section(array &$all, string $current, string $title): ?array
{
    $title = trim($title);
    if ($title === '') return null;
    if (mb_strlen($title) > 60) throw new InvalidArgumentException('The section name is too long (60 characters at most).');

    $no  = 0;
    $old = '';
    foreach ($all as $q) {
        if (strcasecmp((string) $q['section'], trim($current)) === 0) { $no = (int) $q['section_no']; $old = (string) $q['section']; break; }
    }
    if ($no === 0)     throw new InvalidArgumentException('Choose an existing section to rename.');
    if ($title === $old) return null;

    foreach ($all as $q) {
        if ((int) $q['section_no'] !== $no && strcasecmp((string) $q['section'], $title) === 0) {
            throw new InvalidArgumentException('Another section already uses that title.');
        }
    }
    foreach ($all as &$q) {
        if ((int) $q['section_no'] === $no) $q['section'] = $title;
    }
    unset($q);
    return ['no' => $no, 'from' => $old, 'to' => $title];
}

// Applies "section_rename" (sent by the question form when the Admin retitles the section he chose) to $all and
// points $in['section'] at the new title. Returns what was renamed, or null.
function static_admin_apply_section_rename(array &$all, array &$in): ?array
{
    $title = trim((string) ($in['section_rename'] ?? ''));
    if ($title === '') return null;
    $done = static_admin_rename_section($all, (string) ($in['section'] ?? ''), $title);
    if ($done) $in['section'] = $done['to'];
    return $done;
}

function static_admin_add_question(array $in): array
{
    return static_store_update(function (array &$store) use ($in) {
        $all     = static_admin_all_questions($store);
        $renamed = static_admin_apply_section_rename($all, $in);
        $q       = static_admin_clean_question($in, $all);

        // goes at the end of its section (a new section goes at the very end)
        $at = count($all);
        foreach ($all as $i => $existing) {
            if ((int) $existing['section_no'] === (int) $q['section_no']) $at = $i + 1;
        }
        array_splice($all, $at, 0, [$q + ['archived_at' => null]]);
        $store['questions'] = $all;

        if ($renamed) static_log_into($store, 'Section Renamed', 'Sec ' . $renamed['no'] . ' · ' . $renamed['from'] . ' → ' . $renamed['to'], 'Admin');
        static_log_into($store, 'Questionnaires Added', static_admin_question_label($q), 'Admin');
        return $q;
    });
}

function static_admin_update_question(string $id, array $in): array
{
    return static_store_update(function (array &$store) use ($id, $in) {
        $all = static_admin_all_questions($store);
        $pos = null;
        foreach ($all as $i => $q) {
            if ($q['id'] === $id && empty($q['archived_at'])) { $pos = $i; break; }
        }
        if ($pos === null) throw new InvalidArgumentException('That question was not found. It may have been deleted.');

        $renamed = static_admin_apply_section_rename($all, $in);      // the Admin retitled the section he chose

        $old = $all[$pos];
        $new = static_admin_clean_question($in, $all, $old);
        if ($new['status'] === 'Inactive' && static_admin_active_count($all, $id) < 1) {
            throw new InvalidArgumentException('At least one question has to stay Active.');
        }

        if ((int) $new['section_no'] === (int) $old['section_no']) {
            $all[$pos] = $new + ['archived_at' => null];           // same place
        } else {
            array_splice($all, $pos, 1);                           // moved to another section: end of that section
            $at = count($all);
            foreach ($all as $i => $existing) {
                if ((int) $existing['section_no'] === (int) $new['section_no']) $at = $i + 1;
            }
            array_splice($all, $at, 0, [$new + ['archived_at' => null]]);
        }
        $store['questions'] = $all;

        if ($renamed) static_log_into($store, 'Section Renamed', 'Sec ' . $renamed['no'] . ' · ' . $renamed['from'] . ' → ' . $renamed['to'], 'Admin');
        static_log_into($store, 'Questionnaires Edited', static_admin_question_label($new), 'Admin');
        return $new;
    });
}

// "Delete" = move to the Archive (30 days, then gone), like the applicants
function static_admin_archive_question(string $id): bool
{
    return static_store_update(function (array &$store) use ($id) {
        $all = static_admin_all_questions($store);
        if (static_admin_active_count($all, $id) < 1) {
            throw new InvalidArgumentException('At least one Active question has to stay in the questionnaire.');
        }
        foreach ($all as &$q) {
            if ($q['id'] === $id && empty($q['archived_at'])) {
                $q['archived_at'] = static_now()->format('Y-m-d H:i:s');
                $label = static_admin_question_label($q);
                unset($q);
                $store['questions'] = $all;
                static_log_into($store, 'Questionnaires Deleted', $label, 'Admin');
                return true;
            }
        }
        return false;
    });
}

function static_admin_restore_question(string $id): bool
{
    return static_store_update(function (array &$store) use ($id) {
        $all = static_admin_all_questions($store);
        foreach ($all as &$q) {
            if ($q['id'] === $id && !empty($q['archived_at'])) {
                $q['archived_at'] = null;
                $label = static_admin_question_label($q);
                unset($q);
                $store['questions'] = $all;
                static_log_into($store, 'Questionnaires Restored', $label, 'Admin');
                return true;
            }
        }
        return false;
    });
}

function static_admin_purge_question(string $id): bool
{
    return static_store_update(function (array &$store) use ($id) {
        $all   = static_admin_all_questions($store);
        $label = '';
        $keep  = [];
        foreach ($all as $q) {
            if ($q['id'] === $id && !empty($q['archived_at'])) { $label = static_admin_question_label($q); continue; }
            $keep[] = $q;
        }
        if ($label === '') return false;
        $store['questions'] = $keep;
        static_log_into($store, 'Questionnaires Deleted Forever', $label, 'Admin');
        return true;
    });
}

/* ==========================================================================
   HR ACCOUNTS
   ========================================================================== */

// The account without its password (this is what the pages and the JavaScript get)
function static_admin_public_account(array $a): array
{
    unset($a['password'], $a['hash']);
    $a['role'] = (string) ($a['role'] ?? 'HR Staff');
    return $a;
}

// "Sends" the login-credentials email by writing it to the outbox of the store (like hr/static-details.php does).
// The password is NOT written there; it was typed by the Admin and only passes through this request.
// BACKEND: call src/Services/EmailService.php here and put the password in the email.
function static_admin_credentials_mail(array &$store, string $name, string $email, bool $isNew): void
{
    if (!isset($store['outbox']) || !is_array($store['outbox'])) $store['outbox'] = [];
    $subject = $isNew ? 'Your HR account is ready | Expertise' : 'Your login credentials were updated | Expertise';
    $line    = $isNew ? 'An HR account was created for you.' : 'Your login credentials were updated by the Admin.';
    $store['outbox'][] = [
        'applicant_id' => 0,
        'kind'         => 'hr_credentials',
        'to'           => $email,
        'subject'      => $subject,
        'html'         => '<p>Hello ' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . ',</p><p>' . $line .
                          ' Log in to the Expertise HR portal with this email and the password the Admin gave you.</p>',
        'sent_at'      => static_now()->format('Y-m-d H:i:s')
    ];
    $store['outbox'] = array_slice($store['outbox'], -200);
}

// HR accounts that are not in the Archive (Active or Inactive), oldest first
function static_admin_accounts(): array
{
    $out = [];
    foreach (static_hr_accounts() as $a) {
        if (!empty($a['archived_at'])) continue;
        $out[] = static_admin_public_account($a);
    }
    return $out;
}

function static_admin_next_account_id(array $list): int
{
    $max = 0;
    foreach ($list as $a) $max = max($max, (int) $a['id']);
    return $max + 1;
}

// Name, email (and password) rules, shared by add and edit. $skipId = the account being edited.
function static_admin_check_account(array $in, array $list, ?int $skipId, bool $passwordRequired): array
{
    $name  = trim((string) ($in['name'] ?? ''));
    $email = strtolower(trim((string) ($in['email'] ?? '')));
    $pass  = (string) ($in['password'] ?? '');

    if ($name === '')                                throw new InvalidArgumentException('Please enter the full name.');
    if (mb_strlen($name) > 100)                      throw new InvalidArgumentException('The name is too long (100 characters at most).');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL))  throw new InvalidArgumentException('Enter a valid email address (example: name@expertise.com).');
    if ($email === 'admin@expertise.com')            throw new InvalidArgumentException('That email belongs to the Admin account.');

    foreach ($list as $a) {
        if ((int) $a['id'] === $skipId) continue;
        if (strcasecmp((string) $a['email'], $email) === 0) {
            throw new InvalidArgumentException(empty($a['archived_at'])
                ? 'An HR account with this email already exists.'
                : 'An HR account with this email is in the Archive. Restore it instead.');
        }
    }

    if ($passwordRequired || $pass !== '') {
        if (strlen($pass) < 8 || !preg_match('/[A-Za-z]/', $pass) || !preg_match('/\d/', $pass)) {
            throw new InvalidArgumentException('The password needs at least 8 characters, with letters and a number.');
        }
        // the form has "Confirm Password": it has to be the same
        if (array_key_exists('password_confirm', $in) && (string) $in['password_confirm'] !== $pass) {
            throw new InvalidArgumentException('The passwords do not match.');
        }
    }
    return [$name, $email, $pass];
}

function static_admin_add_account(array $in): array
{
    return static_store_update(function (array &$store) use ($in) {
        $list = isset($store['hr_accounts']) && is_array($store['hr_accounts']) ? $store['hr_accounts'] : static_seed_hr_accounts();
        [$name, $email, $pass] = static_admin_check_account($in, $list, null, true);

        $account = [
            'id'          => static_admin_next_account_id($list),
            'name'        => $name,
            'email'       => $email,
            'role'        => 'HR Staff',
            'hash'        => password_hash($pass, PASSWORD_DEFAULT),
            'status'      => ($in['status'] ?? 'Active') === 'Inactive' ? 'Inactive' : 'Active',
            'created'     => static_now()->format('Y-m-d H:i'),
            'archived_at' => null
        ];
        $list[] = $account;
        $store['hr_accounts'] = $list;

        $emailed = !empty($in['send_email']);
        if ($emailed) static_admin_credentials_mail($store, $name, $email, true);

        static_log_into($store, 'HR Account Added', $name . ' (HR)', 'Admin');
        return static_admin_public_account($account) + ['emailed' => $emailed];
    });
}

function static_admin_update_account(int $id, array $in): array
{
    return static_store_update(function (array &$store) use ($id, $in) {
        $list = isset($store['hr_accounts']) && is_array($store['hr_accounts']) ? $store['hr_accounts'] : static_seed_hr_accounts();
        $pos = null;
        foreach ($list as $i => $a) {
            if ((int) $a['id'] === $id && empty($a['archived_at'])) { $pos = $i; break; }
        }
        if ($pos === null) throw new InvalidArgumentException('That HR account was not found. It may have been deleted.');

        [$name, $email, $pass] = static_admin_check_account($in, $list, $id, false);
        $status = ($in['status'] ?? 'Active') === 'Inactive' ? 'Inactive' : 'Active';
        $before = $list[$pos]['status'] ?? 'Active';

        $list[$pos]['name']   = $name;
        $list[$pos]['email']  = $email;
        $list[$pos]['status'] = $status;
        if ($pass !== '') {                       // a new password replaces the old one (the old one is never shown)
            $list[$pos]['hash'] = password_hash($pass, PASSWORD_DEFAULT);
            unset($list[$pos]['password']);
        }
        $store['hr_accounts'] = $list;

        $emailed = !empty($in['send_email']);
        if ($emailed) static_admin_credentials_mail($store, $name, $email, false);

        if ($status !== $before) {
            static_log_into($store, $status === 'Inactive' ? 'HR Account Deactivated' : 'HR Account Activated', $name . ' (HR)', 'Admin');
        } else {
            static_log_into($store, 'HR Account Edited', $name . ' (HR)', 'Admin');
        }
        return static_admin_public_account($list[$pos]) + ['emailed' => $emailed];
    });
}

function static_admin_archive_account(int $id): bool
{
    return static_store_update(function (array &$store) use ($id) {
        $list = isset($store['hr_accounts']) && is_array($store['hr_accounts']) ? $store['hr_accounts'] : static_seed_hr_accounts();
        foreach ($list as &$a) {
            if ((int) $a['id'] === $id && empty($a['archived_at'])) {
                $a['archived_at'] = static_now()->format('Y-m-d H:i:s');
                $label = $a['name'] . ' (HR)';
                unset($a);
                $store['hr_accounts'] = $list;
                static_log_into($store, 'HR Account Deleted', $label, 'Admin');
                return true;
            }
        }
        return false;
    });
}

function static_admin_restore_account(int $id): bool
{
    return static_store_update(function (array &$store) use ($id) {
        $list = isset($store['hr_accounts']) && is_array($store['hr_accounts']) ? $store['hr_accounts'] : static_seed_hr_accounts();
        foreach ($list as &$a) {
            if ((int) $a['id'] === $id && !empty($a['archived_at'])) {
                $a['archived_at'] = null;
                $label = $a['name'] . ' (HR)';
                unset($a);
                $store['hr_accounts'] = $list;
                static_log_into($store, 'HR Account Restored', $label, 'Admin');
                return true;
            }
        }
        return false;
    });
}

function static_admin_purge_account(int $id): bool
{
    return static_store_update(function (array &$store) use ($id) {
        $list  = isset($store['hr_accounts']) && is_array($store['hr_accounts']) ? $store['hr_accounts'] : static_seed_hr_accounts();
        $label = '';
        $keep  = [];
        foreach ($list as $a) {
            if ((int) $a['id'] === $id && !empty($a['archived_at'])) { $label = $a['name'] . ' (HR)'; continue; }
            $keep[] = $a;
        }
        if ($label === '') return false;
        $store['hr_accounts'] = $keep;
        static_log_into($store, 'HR Account Deleted Forever', $label, 'Admin');
        return true;
    });
}

/* ==========================================================================
   ADMIN ARCHIVE: deleted questions + HR accounts, kept for STATIC_ARCHIVE_DAYS days
   ========================================================================== */

// Removes what has been in the Archive for 30 days. Nothing is logged (it is automatic).
function static_admin_purge_expired(): void
{
    $limit = static_now()->modify('-' . STATIC_ARCHIVE_DAYS . ' days')->format('Y-m-d H:i:s');
    $store = static_store_read();
    $changed = false;
    foreach (['questions', 'hr_accounts'] as $key) {
        if (!isset($store[$key]) || !is_array($store[$key])) continue;
        $kept = array_values(array_filter($store[$key], fn($row) => empty($row['archived_at']) || $row['archived_at'] > $limit));
        if (count($kept) !== count($store[$key])) { $store[$key] = $kept; $changed = true; }
    }
    if ($changed) static_store_write($store);
}

// Adds 'deleted' (when) and 'days_left' to an archived row
function static_admin_archive_row(array $row, DateTimeImmutable $now): array
{
    $deletedAt = new DateTimeImmutable($row['archived_at'], new DateTimeZone('Asia/Manila'));
    $passed    = (int) $deletedAt->diff($now)->format('%a');
    $row['deleted']   = $row['archived_at'];
    $row['days_left'] = max(0, STATIC_ARCHIVE_DAYS - $passed);
    unset($row['archived_at']);
    return $row;
}

function static_admin_archive(): array
{
    static_admin_purge_expired();
    $store = static_store_read();
    $now   = static_now();

    $questions = [];
    foreach (static_admin_all_questions($store) as $q) {
        if (!empty($q['archived_at'])) $questions[] = static_admin_archive_row($q, $now);
    }
    $accounts = [];
    $list = isset($store['hr_accounts']) && is_array($store['hr_accounts']) ? $store['hr_accounts'] : static_seed_hr_accounts();
    foreach ($list as $a) {
        if (!empty($a['archived_at'])) $accounts[] = static_admin_archive_row(static_admin_public_account($a), $now);
    }
    $newest = fn($x, $y) => strcmp($y['deleted'], $x['deleted']);
    usort($questions, $newest);
    usort($accounts, $newest);
    return ['questions' => $questions, 'accounts' => $accounts];
}

// Everything a management page needs, as one array (printed into the page and returned after every change)
function static_admin_payload(): array
{
    return [
        'questions' => static_admin_questions(),
        'accounts'  => static_admin_accounts(),
        'archive'   => static_admin_archive(),
        'types'     => static_question_types()
    ];
}

/* ==========================================================================
   DASHBOARD NUMBERS
   ========================================================================== */

// The three summary boxes
function static_admin_overview(): array
{
    $accounts  = static_admin_accounts();
    $questions = static_admin_questions();
    $sections  = [];
    foreach ($questions as $q) $sections[(int) $q['section_no']] = true;

    $since = static_now()->modify('-7 days')->format('Y-m-d H:i');
    $week  = array_filter(static_activity(), fn($a) => $a['at'] >= $since);

    return [
        'hr_active'      => count(array_filter($accounts, fn($a) => ($a['status'] ?? '') === 'Active')),
        'hr_total'       => count($accounts),
        'questions'      => count($questions),
        'sections'       => count($sections),
        'activities_week' => count($week)
    ];
}

/* ==========================================================================
   RECENT ACTIVITIES (Admin Dashboard): an activity stays in the list for 3 days after the Admin first saw it
   The first time a line is shown on the Dashboard it gets 'admin_seen_at' in the log; once that is 3 days old
   the line is no longer listed (the next older line takes its place). Nothing is deleted from the log itself,
   so the HR bell, the "last 7 days" box and the sidebar badge are not affected.
   BACKEND: activity_log gets a nullable admin_seen_at column; "UPDATE ... SET admin_seen_at = NOW() WHERE admin_seen_at IS NULL AND id IN (shown ids)"
            and the list query adds "AND (admin_seen_at IS NULL OR admin_seen_at > NOW() - INTERVAL 3 DAY)".
   ========================================================================== */
if (!defined('STATIC_ACTIVITY_SHOW_DAYS')) define('STATIC_ACTIVITY_SHOW_DAYS', 3);

// Returns ['rows' => the lines to show (newest first, at most $limit), 'total' => how many lines are still listed]
function static_admin_recent_activity(int $limit = 10): array
{
    $store   = static_store_read();
    $log     = isset($store['activity']) && is_array($store['activity']) ? array_values($store['activity']) : static_seed_activity();
    $now     = static_now();
    $nowText = $now->format('Y-m-d H:i:s');
    $cut     = $now->modify('-' . STATIC_ACTIVITY_SHOW_DAYS . ' days')->format('Y-m-d H:i:s');

    // newest first (same order as static_activity(): two lines with the same time keep the order they were written)
    $order = array_keys($log);
    usort($order, fn($a, $b) => strcmp((string) $log[$b]['at'], (string) $log[$a]['at']) ?: ($b <=> $a));

    $rows    = [];
    $total   = 0;
    $changed = false;
    foreach ($order as $i) {
        $seen = (string) ($log[$i]['admin_seen_at'] ?? '');
        if ($seen !== '' && $seen <= $cut) continue;              // seen more than 3 days ago: gone from the list
        $total++;
        if (count($rows) >= $limit) continue;                     // listed in total, but not among the latest ones shown
        if ($seen === '') { $log[$i]['admin_seen_at'] = $nowText; $changed = true; }   // shown now = seen now
        $row = $log[$i];
        unset($row['admin_seen_at']);
        $rows[] = $row;
    }

    if ($changed) {
        $store['activity'] = $log;
        static_store_write($store);
    }
    return ['rows' => $rows, 'total' => $total];
}

// How favourable one answer is, 0 to 100 (null = this question is not scored)
//   Yes / No  : 100 when the answer is the favourable one (Yes, unless the question says otherwise), else 0
//   rating    : the rating as a share of the highest rating
// Dates, lists, written answers and checkboxes are information, not a score.
function static_admin_answer_score(array $q, array $answers): ?float
{
    $v = $answers[$q['id']] ?? null;
    if ($q['type'] === 'yesno') {
        if ($v !== 'Yes' && $v !== 'No') return null;
        return $v === ($q['favorable'] ?? 'Yes') ? 100.0 : 0.0;
    }
    if ($q['type'] === 'scale') {
        if (!is_numeric($v)) return null;
        $max = max(1, (int) ($q['max'] ?? 5));
        return min(100.0, max(0.0, (float) $v / $max * 100));
    }
    return null;
}

// Earlier months for the chart. SAMPLE numbers (the real submissions only start this month), used only
// for a month that has no real submission yet; a month with real submissions always uses those.
// Keys: 'YYYY-MM' => [section number => average %].  BACKEND: delete this, the query returns the real months.
function static_admin_sample_history(): array
{
    return [
        '2026-04' => [1 => 71.8, 2 => 64.5, 3 => 58.0],
        '2026-05' => [1 => 78.4, 2 => 71.2, 3 => 66.0],
        '2026-06' => [1 => 82.1, 2 => 68.5, 3 => 74.3],
        '2026-07' => [1 => 75.9, 2 => 64.8, 3 => 70.2],
        '2026-08' => [1 => 84.6, 2 => 73.1, 3 => 79.5],
        '2026-09' => [1 => 88.2, 2 => 76.7, 3 => 81.4]
    ];
}

// Questionnaires Statistics: for each month (last $months) and each scored section, the average
// favourable-answer % of everything the applicants submitted.
function static_admin_chart(int $months = 6): array
{
    $store = static_store_read();

    // sections that have scored questions (Yes/No + rating), taken from the CURRENT questionnaire,
    // so a section the Admin adds shows up here by itself
    $sections = [];
    foreach (static_admin_questions() as $q) {
        if (!in_array($q['type'], ['yesno', 'scale'], true)) continue;
        $no = (int) $q['section_no'];
        $sections[$no]['no']   = $no;
        $sections[$no]['name'] = (string) $q['section'];
        $sections[$no]['qs'][] = $q;
    }
    ksort($sections);

    // the months, oldest first
    $first  = static_now()->modify('first day of this month');
    $keys   = [];
    $labels = [];
    for ($i = $months - 1; $i >= 0; $i--) {
        $m        = $first->modify('-' . $i . ' months');
        $keys[]   = $m->format('Y-m');
        $labels[] = $m->format('M');
    }

    // real numbers from the submissions
    $sum = [];
    $cnt = [];
    $perMonth = [];
    foreach ((array) ($store['submissions'] ?? []) as $sub) {
        $mk = substr((string) ($sub['submitted_at'] ?? ''), 0, 7);
        if (!in_array($mk, $keys, true)) continue;
        $perMonth[$mk] = ($perMonth[$mk] ?? 0) + 1;
        $answers = (array) ($sub['answers'] ?? []);
        foreach ($sections as $no => $sec) {
            foreach ($sec['qs'] as $q) {
                $score = static_admin_answer_score($q, $answers);
                if ($score === null) continue;
                $sum[$no][$mk] = ($sum[$no][$mk] ?? 0) + $score;
                $cnt[$no][$mk] = ($cnt[$no][$mk] ?? 0) + 1;
            }
        }
    }

    $sample   = static_admin_sample_history();
    $datasets = [];
    foreach ($sections as $no => $sec) {
        $data = [];
        foreach ($keys as $mk) {
            if (!empty($cnt[$no][$mk]))              $data[] = round($sum[$no][$mk] / $cnt[$no][$mk], 1);
            elseif (isset($sample[$mk][$no]))        $data[] = $sample[$mk][$no];
            else                                     $data[] = null;
        }
        $datasets[] = ['no' => $no, 'label' => 'Section ' . $no, 'name' => $sec['name'], 'data' => $data];
    }

    // which months are real (so the page can say which bars are sample numbers)
    $real = [];
    foreach ($keys as $i => $mk) $real[] = !empty($perMonth[$mk]);

    return [
        'labels'      => $labels,
        'datasets'    => $datasets,
        'real'        => $real,
        'submissions' => array_sum($perMonth)
    ];
}

/* ==========================================================================
   QUESTIONNAIRES STATISTICS: every question + the answer most applicants chose
   ========================================================================== */

// The question sentence as the applicant reads it ({link} replaced by its clickable words)
function static_admin_question_plain(array $q): string
{
    $text = (string) $q['text'];
    if (strpos($text, '{link}') !== false) $text = str_replace('{link}', (string) ($q['link']['text'] ?? 'link'), $text);
    return $text;
}

// For every question that is not deleted (Active or Inactive), from everything the applicants submitted:
//   answered   how many applicants answered it
//   top        the answer most of them chose (null for a written answer: every text is different)
//   share      % of the applicants who answered that chose the top answer
//                (written answers: % of the submissions that wrote something)
//   options    every answer with its count and %, most chosen first (for the hover box)
//   note       a second line, e.g. the notice period most picked after "Yes"
// BACKEND: SELECT answer, COUNT(*) FROM responses WHERE question_id = ? GROUP BY answer ORDER BY COUNT(*) DESC
function static_admin_question_stats(): array
{
    $store = static_store_read();
    $subs  = array_values(array_filter((array) ($store['submissions'] ?? []), 'is_array'));
    $total = count($subs);

    $questions = static_admin_questions();
    $sections  = [];
    foreach ($questions as $q) {
        $no = (int) $q['section_no'];
        if (!isset($sections[$no])) $sections[$no] = ['no' => $no, 'name' => (string) $q['section'], 'count' => 0];
        $sections[$no]['count']++;
    }
    ksort($sections);

    $topOf = function (array $counts): array {      // [label, count] of the largest count (first one wins a tie)
        if (!$counts) return [null, 0];
        arsort($counts);
        $label = (string) array_key_first($counts);
        return [$label, (int) $counts[$label]];
    };

    $out = [];
    foreach ($questions as $q) {
        $id     = (string) $q['id'];
        $counts = [];
        $follow = [];
        $answered = 0;
        $written  = 0;

        foreach ($subs as $sub) {
            $a = (array) ($sub['answers'] ?? []);
            if (!array_key_exists($id, $a)) continue;
            $v = $a[$id];
            if ($v === null || $v === '' || $v === false) continue;
            $answered++;

            switch ($q['type']) {
                case 'yesno':
                case 'yesno_select':
                    $label = (string) $v;
                    $counts[$label] = ($counts[$label] ?? 0) + 1;
                    if ($q['type'] === 'yesno_select' && !empty($a[$id . ':follow'])) {
                        $f = (string) $a[$id . ':follow'];
                        $follow[$label][$f] = ($follow[$label][$f] ?? 0) + 1;
                    }
                    break;
                case 'scale':
                    $label = (string) (int) $v;
                    $counts[$label] = ($counts[$label] ?? 0) + 1;
                    break;
                case 'date':
                    $ts = strtotime((string) $v);
                    $label = $ts ? date('M j, Y', $ts) : (string) $v;
                    $counts[$label] = ($counts[$label] ?? 0) + 1;
                    break;
                case 'consent':
                    $counts['Agreed'] = ($counts['Agreed'] ?? 0) + 1;
                    break;
                default:                                    // written answer
                    $written++;
            }
        }

        $row = [
            'id'         => $id,
            'section_no' => (int) $q['section_no'],
            'section'    => (string) $q['section'],
            'text'       => static_admin_question_plain($q),
            'type'       => (string) $q['type'],
            'status'     => (string) ($q['status'] ?? 'Active'),
            'answered'   => $answered,
            'top'        => null,
            'top_count'  => 0,
            'share'      => 0,
            'options'    => [],
            'note'       => '',
            'expected'   => $q['type'] === 'yesno' ? (string) ($q['favorable'] ?? 'Yes') : null    // the answer the Admin marked as good
        ];

        if ($q['type'] === 'text') {
            $row['share'] = $total > 0 ? round($written / $total * 100, 1) : 0;
            $row['note']  = $written . ' written answer' . ($written === 1 ? '' : 's');
        } else {
            [$top, $topCount] = $topOf($counts);
            $row['top']       = $top;
            $row['top_count'] = $topCount;
            $row['share']     = $answered > 0 ? round($topCount / $answered * 100, 1) : 0;

            if ($q['type'] === 'scale' && $top !== null) $row['top'] = $top . ' of ' . (int) ($q['max'] ?? 5);

            arsort($counts);
            foreach (array_slice($counts, 0, 6, true) as $label => $n) {
                $name = ($q['type'] === 'scale') ? ((string) $label . ' of ' . (int) ($q['max'] ?? 5)) : (string) $label;
                $row['options'][] = ['label' => $name, 'count' => (int) $n, 'pct' => $answered > 0 ? round($n / $answered * 100, 1) : 0];
            }
            if ($q['type'] === 'yesno_select' && $top !== null && !empty($follow[$top])) {
                [$f, $fn] = $topOf($follow[$top]);
                $row['note'] = 'After "' . $top . '": ' . $f . ' (' . $fn . ')';
            }
        }
        $out[] = $row;
    }

    return ['submissions' => $total, 'sections' => array_values($sections), 'questions' => $out];
}

/* ==========================================================================
   SIDEBAR BADGES (same idea as the HR badges; every admin page prints this into window.ADMIN_NOTIFY)
     activity  the time of every HR activity (the Dashboard badge counts the ones the Admin has not seen yet)
     expiring  archived items that will be deleted for good within `days` days (the Archive badge)
   BACKEND: activity = SELECT created_at FROM activity_log WHERE actor_role = 'hr'
            expiring = COUNT(*) of archived rows older than (30 - days) days
   ========================================================================== */
function static_admin_notify(): array
{
    $activity = [];
    foreach (static_activity() as $row) {
        if (strcasecmp((string) ($row['by'] ?? ''), 'Admin') === 0) continue;     // only what HR did
        $activity[] = (string) $row['at'];
    }

    $days     = 7;
    $expiring = 0;
    $archive  = static_admin_archive();
    foreach (['questions', 'accounts'] as $kind) {
        foreach ($archive[$kind] as $row) {
            if ((int) $row['days_left'] <= $days) $expiring++;
        }
    }
    return ['activity' => array_slice($activity, 0, 100), 'expiring' => $expiring, 'days' => $days];
}

/* ==========================================================================
   ENDPOINT: the admin JavaScript posts here  ( fetch('static-admin.php', {method:'POST', ...}) )
   It only runs when this file is opened DIRECTLY; when a page does require_once on it, nothing below happens.

   Request (JSON body):  { action: '...', ...fields }
       question_add | question_update   the question fields (see static_admin_clean_question), id for update
                                        optional question.section_rename = a new title for the section named in question.section
       question_archive | question_restore | question_purge   id = 'q14'
       account_add | account_update     name, email, password (new password; empty on update = unchanged),
                                        status (update), id for update
       account_archive | account_restore | account_purge      id = 3
   Response (JSON):  { status: 'success', message: '...', result: {the saved row, or null}, data: { questions, accounts, archive, types } }
                     { status: 'error', message: '...' }
   ========================================================================== */
if (isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) !== false
    && strcasecmp(realpath($_SERVER['SCRIPT_FILENAME']), __FILE__) === 0) {   // strcasecmp: Windows paths

    if (session_status() === PHP_SESSION_NONE) session_start();
    header('Content-Type: application/json; charset=UTF-8');
    date_default_timezone_set('Asia/Manila');

    function static_admin_respond(array $data, int $code = 200): void
    {
        http_response_code($code);
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    }

    // Only a signed-in Admin may change questions and HR accounts
    if (($_SESSION['user_role'] ?? '') !== 'admin') {
        static_admin_respond(['status' => 'error', 'message' => 'Your session has ended. Please log in again.'], 403);
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        static_admin_respond(['status' => 'error', 'message' => 'Invalid request.'], 405);
    }

    $json  = json_decode((string) file_get_contents('php://input'), true);
    $input = is_array($json) ? $json : $_POST;
    $action = (string) ($input['action'] ?? '');

    try {
        $message = 'Saved.';
        $missing = 'That item was not found. It may have been removed already.';
        $result  = null;                      // the account / question that was just saved (the form pages show it)

        switch ($action) {
            case 'question_add':
                $result = static_admin_add_question((array) ($input['question'] ?? []));
                $message = 'Question added.';
                break;
            case 'question_update':
                $result = static_admin_update_question((string) ($input['id'] ?? ''), (array) ($input['question'] ?? []));
                $message = 'Question saved.';
                break;
            case 'question_archive':
                if (!static_admin_archive_question((string) ($input['id'] ?? ''))) static_admin_respond(['status' => 'error', 'message' => $missing], 404);
                $message = 'Question moved to the Archive.';
                break;
            case 'question_restore':
                if (!static_admin_restore_question((string) ($input['id'] ?? ''))) static_admin_respond(['status' => 'error', 'message' => $missing], 404);
                $message = 'Question restored.';
                break;
            case 'question_purge':
                if (!static_admin_purge_question((string) ($input['id'] ?? ''))) static_admin_respond(['status' => 'error', 'message' => $missing], 404);
                $message = 'Question deleted forever.';
                break;

            case 'account_add':
                $result = static_admin_add_account((array) ($input['account'] ?? []));
                $message = 'HR account added.';
                break;
            case 'account_update':
                $result = static_admin_update_account((int) ($input['id'] ?? 0), (array) ($input['account'] ?? []));
                $message = 'HR account saved.';
                break;
            case 'account_archive':
                if (!static_admin_archive_account((int) ($input['id'] ?? 0))) static_admin_respond(['status' => 'error', 'message' => $missing], 404);
                $message = 'HR account moved to the Archive.';
                break;
            case 'account_restore':
                if (!static_admin_restore_account((int) ($input['id'] ?? 0))) static_admin_respond(['status' => 'error', 'message' => $missing], 404);
                $message = 'HR account restored.';
                break;
            case 'account_purge':
                if (!static_admin_purge_account((int) ($input['id'] ?? 0))) static_admin_respond(['status' => 'error', 'message' => $missing], 404);
                $message = 'HR account deleted forever.';
                break;

            case 'notify':      // the sidebar badges ask for the fresh numbers every few seconds (admin-common.js)
                static_admin_respond(['status' => 'success', 'message' => '', 'result' => null, 'data' => ['notify' => static_admin_notify()]]);
                break;

            default:
                static_admin_respond(['status' => 'error', 'message' => 'Unknown action.'], 400);
        }

        static_admin_respond(['status' => 'success', 'message' => $message, 'result' => $result, 'data' => static_admin_payload()]);

    } catch (InvalidArgumentException $e) {
        static_admin_respond(['status' => 'error', 'message' => $e->getMessage()], 422);
    } catch (Throwable $e) {
        static_admin_respond(['status' => 'error', 'message' => 'Something went wrong while saving. Please try again.'], 500);
    }
}