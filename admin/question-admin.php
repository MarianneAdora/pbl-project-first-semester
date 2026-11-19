<?php
/*
 * Admin question validation/normalization helpers. The current question and
 * account workflows use PostgreSQL through api/admin-action.php; this file is
 * also included by that API. A legacy direct JSON endpoint is retained below
 * for compatibility and is not the endpoint used by the current admin UI.
 */

require_once __DIR__ . '/../hr/hr-data.php';               // store functions (application_state_read, etc.)
require_once __DIR__ . '/../applicant/applicant-helpers.php';   // applicant helpers (DB-backed)

/* ==========================================================================
   SAFETY NET: the admin pages need these functions from hr/hr-data.php.
   If that file does not have them (an older copy), the versions below are used instead.
   When hr/hr-data.php has them, NOTHING here runs (each one only exists "if missing").
   ========================================================================== */
if (!function_exists('application_state_read') || !function_exists('application_state_write') || !function_exists('static_now')) {
    exit('hr/hr-data.php is missing its store functions (application_state_read / application_state_write / static_now). Restore your own copy of hr/hr-data.php.');
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
        $store = application_state_read();
        return isset($store['hr_accounts']) && is_array($store['hr_accounts']) ? $store['hr_accounts'] : static_seed_hr_accounts();
    }
}

/* The activity log helpers (static_seed_activity, static_actor, static_log_into, static_activity) now live in
   hr/hr-data.php, because HR writes to the same log: everything HR does shows here on the Admin side under
   Recent Activities. The Admin's own change functions below still call static_log_into() exactly as before. */

if (!defined('STATIC_ARCHIVE_DAYS')) define('STATIC_ARCHIVE_DAYS', 30);

// ---------------------------------------------------------------------------
// The kinds of question the applicant page can draw (see applicant/static-applicant.php).
// Type is derived from the answer_config JSONB in the DB (statement+checkbox_label => consent,
// choices => yesno_select, max+labels => scale, hint => date, else text).
// Admin selects the type when creating/editing a question; it is not stored in the database.
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
    foreach (static_admin_all_questions(application_state_read()) as $q) {
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

// Checks what the Admin typed (section, type, status, etc.) and builds the question.
// The question_type is derived from the answer_config JSONB stored in the DB.
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
        'created'    => $old['created'] ?? static_now()->format('Y-m-d H:i'),
        'is_consent' => $type === 'consent',
    ];

    $isDataProtection = filter_var($in['is_data_protection'] ?? false, FILTER_VALIDATE_BOOLEAN);
    if ($isDataProtection && !in_array($type, ['yesno', 'yesno_select', 'consent'], true)) {
        throw new InvalidArgumentException('Data-protection tracking is only available for questions with a Yes or agree answer.');
    }
    $q['is_data_protection'] = $isDataProtection;

    $line = function (string $key, int $limit = 200, string $label = 'This value') use ($in): string {
        $value = trim((string) ($in[$key] ?? ''));
        if (mb_strlen($value, 'UTF-8') > $limit) {
            throw new InvalidArgumentException($label . ' must be ' . $limit . ' characters or fewer.');
        }
        return $value;
    };

    switch ($type) {
        case 'yesno':
            $note = trim((string) ($in['no_note'] ?? ''));
            if (mb_strlen($note) > 200) throw new InvalidArgumentException('The reminder for "No" is too long (200 characters at most).');
            if ($note !== '') $q['no_note'] = $note;
            // which answer counts as good in the Questionnaires Statistics (Yes unless the Admin says otherwise)
            if (($in['favorable'] ?? 'Yes') === 'No') $q['favorable'] = 'No';
            else $q['favorable'] = 'Yes';
            $q['answer_config'] = ['favorable' => $q['favorable']];
            if ($note !== '') $q['answer_config']['no_note'] = $note;
            break;

        case 'yesno_select':
            $label = $line('followup_label', 80, 'The list label');
            $optionsText = (string) ($in['followup_options'] ?? '');
            if (mb_strlen($optionsText, 'UTF-8') > 2000) {
                throw new InvalidArgumentException('The choices must be 2000 characters or fewer in total.');
            }
            $options = array_values(array_filter(array_map('trim', preg_split('/\R/', $optionsText)), fn($o) => $o !== ''));
            if ($label === '')        throw new InvalidArgumentException('Please write the label above the list (example: Your notice period).');
            if (count($options) < 2)  throw new InvalidArgumentException('Add at least two choices for the list, one per line.');
            if (count($options) > 12) throw new InvalidArgumentException('The list can have 12 choices at most.');
            foreach ($options as $option) {
                if (mb_strlen($option, 'UTF-8') > 100) {
                    throw new InvalidArgumentException('Each choice must be 100 characters or fewer.');
                }
            }
            $q['followup'] = [
                'when'    => (($in['followup_when'] ?? 'Yes') === 'No') ? 'No' : 'Yes',
                'label'   => $label,
                'options' => $options
            ];
            // normalize to DB keys so static_store and DB path share the same shape
            $q['answer_config'] = [
                'followup_when'    => $q['followup']['when'],
                'followup_label'   => $label,
                'followup_options' => $options
            ];
            break;

        case 'scale':
            $rawMax = trim((string) ($in['max'] ?? '5'));
            if (!ctype_digit($rawMax)) throw new InvalidArgumentException('The rating scale must be a whole number from 3 to 10.');
            $max = (int) $rawMax;
            if ($max < 3 || $max > 10) throw new InvalidArgumentException('The rating scale must go from 3 up to 10.');
            $q['max']         = $max;
            $q['labels']      = [
                $line('label_left', 40, 'The left rating label'),
                $line('label_right', 40, 'The right rating label')
            ];
            $q['answer_config'] = ['max' => $max, 'labels' => $q['labels']];
            break;

        case 'date':
            $hint = $line('date_hint', 200, 'The date hint');
            if ($hint !== '') { $q['hint'] = $hint; $q['answer_config'] = ['hint' => $hint]; }
            break;

        case 'text':
            $rawMin = trim((string) ($in['min_chars'] ?? '0'));
            $rawMax = trim((string) ($in['max_chars'] ?? '500'));
            if (!ctype_digit($rawMin)) throw new InvalidArgumentException('The shortest answer must be a whole number of 0 or more.');
            if (!ctype_digit($rawMax)) throw new InvalidArgumentException('The longest answer must be a whole number.');
            $min  = (int) $rawMin;
            $maxc = (int) $rawMax;
            if ($maxc < 10 || $maxc > 2000) throw new InvalidArgumentException('The longest answer must be between 10 and 2000 characters.');
            if ($min > $maxc)               throw new InvalidArgumentException('The shortest answer cannot be longer than the longest answer.');
            $hint = $line('text_hint', 200, 'The answer hint');
            $ph = $line('placeholder', 200, 'The answer placeholder');
            if ($ph === '') $ph = 'Type your answer here...';
            if ($hint !== '') $q['hint'] = $hint;
            $q['placeholder']   = $ph;
            $q['min_chars']     = $min;
            $q['max_chars']     = $maxc;
            $q['answer_config'] = ['hint' => $hint, 'placeholder' => $ph, 'min_chars' => $min, 'max_chars' => $maxc];
            break;

        case 'consent':
            $bodyText = trim((string) ($in['body'] ?? ''));
            if (mb_strlen($bodyText, 'UTF-8') > 10000) {
                throw new InvalidArgumentException('The statement must be 10000 characters or fewer.');
            }
            $body = array_values(array_filter(array_map('trim', preg_split('/\R{2,}/', $bodyText)), fn($p) => $p !== ''));
            if (!$body)                 throw new InvalidArgumentException('Please write the statement the applicant has to read.');
            $checkbox = $line('checkbox', 200, 'The checkbox label');
            if ($checkbox === '') throw new InvalidArgumentException('Please write the text next to the checkbox (example: I agree).');
            $q['body']          = $body;
            $q['checkbox']      = $checkbox;
            $q['answer_config'] = ['body' => $body, 'checkbox' => $q['checkbox']];
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
            $q['pass_answer']   = (int) $pass;
            // normalized shape for DB: { "min": 3 }
            $q['passing_answer'] = ['min' => (int) $pass];
        } else {
            $pass = (string) ($in['pass_answer'] ?? '');
            if ($pass !== 'Yes' && $pass !== 'No') throw new InvalidArgumentException('Choose the passing answer (Yes or No) for the knock-out question.');
            $q['pass_answer'] = $pass;
            if ($type === 'yesno') {
                unset($q['favorable']);
                if ($pass === 'No') $q['favorable'] = 'No';
            } elseif ($pass === $q['followup']['when']) {
                $raw      = is_array($in['pass_follow'] ?? null) ? $in['pass_follow'] : preg_split('/\R/', (string) ($in['pass_follow'] ?? ''));
                $accepted = array_values(array_intersect($q['followup']['options'], array_map('trim', array_map('strval', $raw))));
                if ($accepted && count($accepted) < count($q['followup']['options'])) $q['pass_follow'] = $accepted;
            }
            // normalized shape for DB: { "answer": "Yes", "pass_follow": [...] }
            $q['passing_answer'] = ['answer' => $pass];
            if (!empty($q['pass_follow'])) $q['passing_answer']['pass_follow'] = $q['pass_follow'];
        }
    }

    // {link} inside the question text: the word that is clickable + where it goes
    if (strpos($text, '{link}') !== false) {
        $linkText = $line('link_text', 60, 'The link text');
        $linkUrl  = $line('link_url', 500, 'The link URL');
        if ($linkText === '' || !preg_match('#^https?://#i', $linkUrl) || !filter_var($linkUrl, FILTER_VALIDATE_URL)) {
            throw new InvalidArgumentException('The question has {link}. Add the link text and a web address that starts with http:// or https://.');
        }
        $q['link'] = ['text' => $linkText, 'url' => $linkUrl];
        // merge into answer_config so the DB path reads it from the same place
        $q['answer_config'] = array_merge($q['answer_config'] ?? [], ['link' => $q['link']]);
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
        static_log_into($store, 'Questionnaires Added', static_admin_question_label($q) . ' · ' . mb_substr(trim($q['text']), 0, 110), 'Admin');
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
        static_log_into($store, 'Questionnaires Edited', static_admin_question_label($new) . ' · ' . mb_substr(trim($new['text']), 0, 110), 'Admin');
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
                static_log_into($store, 'Questionnaires Deleted', $label . ' · removed from the questionnaire', 'Admin');
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
                static_log_into($store, 'Questionnaires Restored', $label . ' · back on the questionnaire', 'Admin');
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
        static_log_into($store, 'Questionnaires Deleted Forever', $label . ' · removed for good', 'Admin');
        return true;
    });
}

/* ==========================================================================
   HR ACCOUNTS
   ========================================================================== */

// The account without its password (this is what the pages and the JavaScript get)
function static_admin_public_account(array $a): array
{
    unset($a['password'], $a['hash']);       // the pages never get a password
    $a['role'] = (string) ($a['role'] ?? 'HR Staff');
    return $a;
}

// "Sends" the login-credentials email by writing it to the outbox of the store (like hr/applicant-details-data.php does).
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
    if (preg_match('/\d/', $name) || !preg_match("/^[\\p{L}\\p{M}'.\\- ]+$/u", $name)) {
        throw new InvalidArgumentException('Use letters only (spaces, apostrophes, periods and hyphens are fine).');
    }
    if (preg_match_all('/\p{L}/u', $name) < 2)       throw new InvalidArgumentException('The name is too short (at least 2 letters).');
    if (strlen($email) > 150 || !filter_var($email, FILTER_VALIDATE_EMAIL)) throw new InvalidArgumentException('Enter a valid email address (150 characters maximum).');
    if ($email === 'admin@expertise.com' || $email === static_profile_admin_email()) throw new InvalidArgumentException('That email belongs to the Admin account.');

    foreach ($list as $a) {
        if ((int) $a['id'] === $skipId) continue;
        if (strcasecmp((string) $a['email'], $email) === 0) {
            throw new InvalidArgumentException(empty($a['archived_at'])
                ? 'An HR account with this email already exists.'
                : 'An HR account with this email is in the Archive. Restore it instead.');
        }
    }

    if ($passwordRequired || $pass !== '') {
        if (strlen($pass) > 72) {
            throw new InvalidArgumentException('The password must be 72 bytes or fewer.');
        }
        if (preg_match('/\s/', $pass)) {
            throw new InvalidArgumentException('The password cannot contain spaces.');
        }
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

        static_log_into($store, 'HR Account Added', $name . ' (HR) · ' . $email, 'Admin');
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
            static_log_into($store, $status === 'Inactive' ? 'HR Account Deactivated' : 'HR Account Activated', $name . ' (HR) · ' . $email, 'Admin');
        } else {
            static_log_into($store, 'HR Account Edited', $name . ' (HR) · ' . $email, 'Admin');
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
                $label = $a['name'] . ' (HR) · ' . $a['email'];
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
                $label = $a['name'] . ' (HR) · ' . $a['email'];
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
    $store = application_state_read();
    $changed = false;
    foreach (['questions', 'hr_accounts'] as $key) {
        if (!isset($store[$key]) || !is_array($store[$key])) continue;
        $kept = array_values(array_filter($store[$key], fn($row) => empty($row['archived_at']) || $row['archived_at'] > $limit));
        if (count($kept) !== count($store[$key])) { $store[$key] = $kept; $changed = true; }
    }
    if ($changed) application_state_write($store);
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
    $store = application_state_read();
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

// Returns ['rows' => the lines to show (newest first, at most $limit), 'total' => how many lines are still listed,
// A "Password Change Requested" line no longer exists: passwords are applied at once, no approval flow.
function static_admin_recent_activity(int $limit = 10): array
{
    $store   = application_state_read();
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
        $ref   = (int) ($log[$i]['ref'] ?? 0);
        $keep  = true;

        $seen = (string) ($log[$i]['admin_seen_at'] ?? '');
        if (!$seen || $seen <= $cut) continue;    // seen more than 3 days ago: gone from the list
        $total++;
        if (count($rows) >= $limit) continue;     // listed in total, but not among the latest ones shown
        if ($seen === '') { $log[$i]['admin_seen_at'] = $nowText; $changed = true; }   // shown now = seen now
        $row = $log[$i];
        unset($row['admin_seen_at']);
        $rows[] = $row;
    }

    if ($changed) {
        $store['activity'] = $log;
        application_state_write($store);
    }
    return ['rows' => $rows, 'total' => $total, 'pending' => 0];
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

// Earlier months for the chart. BACKEND: delete this, the query returns the real months.
function static_admin_sample_history(): array
{
    return [];
}

// Questionnaires Statistics: for each month (last $months) and each scored section, the average
// favourable-answer % of everything the applicants submitted.
function static_admin_chart(int $months = 6): array
{
    $store = application_state_read();

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
    $store = application_state_read();
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
   DASHBOARD INSIGHT CARDS (the three small cards under Questionnaires Statistics)
     static_admin_failure_rates()   Question-Level Failure Rate: % of applicants failing each knock-out question
     static_admin_risk_flags()      Risk Flag Breakdown by Factor: share of the flags each factor raised
     static_admin_volume()          Applicant Volume Over Time: applications received per week / per month
   Everything is counted from the store (submissions, evaluations, applicants), nothing is typed in by hand.
   BACKEND: failure  = per knock-out question, COUNT(*) of responses that do not match the passing answer / COUNT(*) answered
            risk     = COUNT(*) of evaluation_factors WHERE points > 0 GROUP BY factor group
            volume   = SELECT COUNT(*) FROM applicants GROUP BY YEAR(created_at), WEEK(created_at)
   ========================================================================== */

// Active knock-out questions, the share of applicants who did not pass each one (highest first)
function static_admin_failure_rates(int $limit = 5): array
{
    $store = application_state_read();
    $subs  = array_values(array_filter((array) ($store['submissions'] ?? []), 'is_array'));
    $rows  = [];
    $knock = 0;

    foreach (static_admin_questions() as $q) {
        if (($q['status'] ?? 'Active') !== 'Active' || static_question_rule($q) === null) continue;
        $knock++;

        $judged = 0;
        $failed = 0;
        foreach ($subs as $sub) {
            $check = static_knockout_check($q, (array) ($sub['answers'] ?? []));
            if ($check === null || $check['met'] === null) continue;      // no answer to judge
            $judged++;
            if ($check['met'] === false) $failed++;
        }

        $rows[] = [
            'id'     => (string) $q['id'],
            'label'  => strtoupper((string) $q['id']) . ': ' . static_admin_question_plain($q),
            'failed' => $failed,
            'judged' => $judged,
            'rate'   => $judged > 0 ? round($failed / $judged * 100, 1) : 0
        ];
    }

    usort($rows, fn($a, $b) => [$b['rate'], $b['failed']] <=> [$a['rate'], $a['failed']]);
    return ['submissions' => count($subs), 'knockouts' => $knock, 'rows' => array_slice($rows, 0, $limit)];
}

// Which evaluation factors raised a flag (points above 0), grouped the way the Admin reads them
function static_admin_risk_flags(): array
{
    $groups = [
        'otp'    => ['label' => 'OTP retries',      'color' => '#f5a623'],
        'fast'   => ['label' => 'Fast completion',  'color' => '#3b82f6'],
        'device' => ['label' => 'Duplicate device', 'color' => '#a855f7'],
        'video'  => ['label' => 'Duplicate video',  'color' => '#14b8a6']
    ];
    $groupOf = function (string $factor): ?string {
        if (stripos($factor, 'OTP') !== false)                return 'otp';
        if (stripos($factor, 'Questionnaire speed') === 0)    return 'fast';
        if (stripos($factor, 'Same device') === 0)            return 'device';
        if (stripos($factor, 'Video matches') === 0)          return 'video';
        return null;
    };

    $store = application_state_read();
    $evals = array_values(array_filter((array) ($store['evaluations'] ?? []), 'is_array'));
    $count = array_fill_keys(array_keys($groups), 0);

    foreach ($evals as $e) {
        $hit = [];                                                         // one flag per group per screening
        foreach ((array) ($e['factors'] ?? []) as $f) {
            $g = $groupOf((string) ($f['label'] ?? ''));
            if ($g !== null && (int) ($f['points'] ?? 0) > 0) $hit[$g] = true;
        }
        foreach ($hit as $g => $_) $count[$g]++;
    }

    // SAMPLE numbers: a factor nobody has been flagged for yet borrows these, so the chart is not three empty bars.
    // Real flags are never replaced. BACKEND: delete $sample and this loop, the query returns the real counts.
    $sample = ['otp' => 7, 'fast' => 9, 'device' => 5, 'video' => 3];
    $isSample = [];
    foreach ($count as $key => $n) {
        if ($n === 0) { $count[$key] = $sample[$key]; $isSample[$key] = true; }
    }

    $total = array_sum($count);
    $rows  = [];
    foreach ($groups as $key => $g) {
        $rows[] = [
            'key'    => $key,
            'label'  => $g['label'],
            'color'  => $g['color'],
            'count'  => $count[$key],
            'share'  => $total > 0 ? round($count[$key] / $total * 100, 1) : 0,
            'sample' => !empty($isSample[$key])
        ];
    }
    usort($rows, fn($a, $b) => $b['count'] <=> $a['count']);
    return ['evaluations' => count($evals), 'total' => $total, 'rows' => $rows, 'has_sample' => (bool) $isSample];
}

// Applications received: the last 6 weeks (Monday to Sunday) and the last 6 months, oldest first
function static_admin_volume(): array
{
    $store = application_state_read();
    $days  = [];
    foreach ((array) ($store['applicants'] ?? []) as $a) {
        $when = (string) ($a['invited_at'] ?? $a['updated'] ?? '');
        if ($when !== '') $days[] = substr($when, 0, 10);
    }

    // SAMPLE history: a week / month that ends before the first real applicant uses these (real ones are never replaced).
    // BACKEND: delete the two sample lists and the 'sample' flags, the query returns the real counts.
    $firstDay    = $days ? min($days) : '';
    $sampleWeek  = [9, 11, 10, 13, 12, 14];
    $sampleMonth = [18, 24, 21, 29, 27, 31];

    $countBetween = function (DateTimeImmutable $from, DateTimeImmutable $to) use ($days): int {
        $f = $from->format('Y-m-d');
        $t = $to->format('Y-m-d');
        return count(array_filter($days, fn($d) => $d >= $f && $d < $t));
    };

    $now    = static_now()->setTime(0, 0);
    $monday = $now->modify('monday this week');
    $week   = ['labels' => [], 'ranges' => [], 'values' => [], 'sample' => []];
    for ($i = 5; $i >= 0; $i--) {
        $start = $monday->modify('-' . $i . ' weeks');
        $end   = $start->modify('+7 days');
        $last  = $end->modify('-1 day');
        $week['labels'][] = 'W' . (6 - $i);
        $week['ranges'][] = $start->format('M j') . ' to ' . ($start->format('M') === $last->format('M') ? $last->format('j') : $last->format('M j'));
        $n = $countBetween($start, $end);
        $useSample = $n === 0 && $firstDay !== '' && $end->format('Y-m-d') <= $firstDay;
        $week['values'][] = $useSample ? $sampleWeek[5 - $i] : $n;
        $week['sample'][] = $useSample;
    }

    $first = $now->modify('first day of this month');
    $month = ['labels' => [], 'ranges' => [], 'values' => [], 'sample' => []];
    for ($i = 5; $i >= 0; $i--) {
        $start = $first->modify('-' . $i . ' months');
        $month['labels'][] = $start->format('M');
        $month['ranges'][] = $start->format('F Y');
        $end = $start->modify('+1 month');
        $n   = $countBetween($start, $end);
        $useSample = $n === 0 && $firstDay !== '' && $end->format('Y-m-d') <= $firstDay;
        $month['values'][] = $useSample ? $sampleMonth[5 - $i] : $n;
        $month['sample'][] = $useSample;
    }

    return ['week' => $week, 'month' => $month];
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
   LEGACY ENDPOINT: direct clients may post here. The current admin UI posts to
   ../api/admin-action.php instead.
   It only runs when this file is opened DIRECTLY; when a page does require_once on it, nothing below happens.

   Request (JSON body):  { action: '...', ...fields }
       question_add | question_update   the question fields (see static_admin_clean_question), id for update
                                        optional question.section_rename = a new title for the section named in question.section
       question_archive | question_restore | question_purge   id = 'q14'
       account_update     name, email, password (new password; empty on update = unchanged),
                          status (update), id for update
       account_archive | account_restore | account_purge      id = 3
       HR account creation is handled only by api/admin-action.php, which requires email OTP verification.
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
                static_admin_respond([
                    'status' => 'error',
                    'message' => 'HR accounts must be created through the secure admin workflow.'
                ], 403);
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