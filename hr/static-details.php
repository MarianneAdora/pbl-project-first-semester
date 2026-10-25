<?php
/* ==========================================================================
   STATIC DETAILS DATA: everything the "Applicant Details" page (hr/details.php) needs.
   Temporary, like hr/static-data.php and applicant/static-applicant.php. It is deleted when the
   database is connected.

   What is REAL here (read from the same store the rest of the project uses)
     the applicant                  static_applicants()            name, email, contact, status, result
     the questionnaire answers      store "submissions"            written by applicant/static-applicant.php
     the questions themselves       static_questionnaire()         so the summary always uses the real wording
     the video information          store "submissions" > video    name, size, seconds, source (+ url when uploaded)
     the AI evaluation              store "evaluations"            the RISK SCORING RUBRIC of applicant/static-evaluation.php:
                                                                   points per factor, band, outcome (passed / failed / review)
     the interview schedule         store "interviews"             the date + time the applicant picked
                                                                   (applicant/application-status.php), HR's answer goes back there
     the CV                         the "cv" record of the applicant (saved by Add Applicant)
     the emails that were sent      store "outbox"

   What is SAMPLE (shown with a small "Sample" note on the page)
     answers / video / evaluation of the 35 seed applicants (they never went through the real screening).
     Their evaluation is made by running the REAL rubric (static_evaluate) on their sample answers.
     Their interview request is made the first time HR opens a Passed seed applicant.

   Who takes from this file
     hr/details.php              every details_*() function
     assets/js/details.js        POSTs to this file (the endpoint at the bottom)

   DATABASE (later)
     details_submission()        ->  SELECT ... FROM responses WHERE applicant_id = ?
     details_find_evaluation()   ->  SELECT ... FROM evaluations WHERE applicant_id = ?
     interviews                  ->  static_interview_*() in static-data.php become queries
     details_send_email()        ->  src/Services/EmailService.php (SendGrid)
     the endpoint                ->  api/*.php answering the same two POST actions
   To reset the sample interviews / emails: delete hr/data/static-store.json (resets everything).
   ========================================================================== */

require_once __DIR__ . '/static-data.php';
require_once __DIR__ . '/../applicant/static-evaluation.php';   // the risk rubric (also loads static-applicant.php)

const DETAILS_CV_DIR    = __DIR__ . '/data/cv';     // where Add Applicant keeps the uploaded CVs
const DETAILS_COMPANY   = 'Expertise';
const DETAILS_VENUE     = 'Expertise Office, BGC, Taguig';
const DETAILS_LOGO_URL  = 'https://res.cloudinary.com/y0s0brvb/image/upload/v1792922174/expertise.png';

// ---------------------------------------------------------------------------
// Small helpers
// ---------------------------------------------------------------------------
function details_h($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function details_date_label(?string $date): string
{
    $d = $date ? DateTimeImmutable::createFromFormat('Y-m-d', $date) : false;
    return $d ? $d->format('F j, Y') : '-';
}

function details_datetime_label(?string $value): string
{
    $d = $value ? date_create($value) : false;
    return $d ? $d->format('F j, Y \a\t g:i A') : '-';
}

function details_time_label(?string $time): string
{
    $d = $time ? DateTimeImmutable::createFromFormat('H:i', $time) : false;
    return $d ? $d->format('h:i A') : '-';
}

// The times HR can offer: the same list the applicant picks from (static_interview_slots)
function details_time_slots(): array
{
    $out = [];
    foreach (static_interview_slots() as $label) {
        $out[] = ['value' => (string) static_interview_time24($label), 'label' => $label];
    }
    return $out;
}

function details_first_name(string $name): string
{
    $parts = preg_split('/\s+/', trim($name));
    return $parts[0] ?? $name;
}

function details_find_applicant(int $id): ?array
{
    // Database first: the Screening List now shows real DB ids (not the 101-135 seed ids).
    try {
        require_once __DIR__ . '/../src/Repositories/ApplicantRepository.php';
        $found = repo_find_applicant($id);
        if ($found) return $found;
    } catch (Throwable $e) {
        error_log('details_find_applicant DB error: ' . $e->getMessage());
    }
    foreach (static_applicants() as $a) {
        if ((int) $a['id'] === $id) return $a;
    }
    return null;
}

// ---------------------------------------------------------------------------
// Which tabs are open for this applicant
//   Questionnaires / Video    the screening is Completed
//   Interview Schedule        Completed AND Passed
//   AI Evaluation             Completed AND an evaluation exists (Passed or Rejected, and also an applicant
//                             held for HR review: that is the one HR most needs to read)
// ---------------------------------------------------------------------------
function details_access(array $a, ?array $evaluation = null): array
{
    $done   = strcasecmp((string) $a['status'], 'Completed') === 0;
    $result = strtolower((string) ($a['result'] ?? ''));
    return [
        'overview'       => true,
        'questionnaires' => $done,
        'video'          => $done,
        'interview'      => $done && $result === 'passed',
        'evaluation'     => $done && $evaluation !== null
    ];
}

function details_locked_message(string $tab): string
{
    switch ($tab) {
        case 'interview':  return 'The interview schedule is only for applicants who completed the screening and passed.';
        case 'evaluation': return 'The AI evaluation is available once the screening is completed and evaluated.';
        default:           return 'This is available after the applicant completes the screening.';
    }
}

// ---------------------------------------------------------------------------
// The applicant's screening: answers + video.
// REAL when the applicant went through applicant/questionnaire.php; for the seed applicants a
// sample is made (same every time) so the design can be tested. 'demo' tells the page which.
// ---------------------------------------------------------------------------
function details_submission(array $a): ?array
{
    // Database first: real submissions from api/submit-response.php (response table).
    try {
        require_once __DIR__ . '/../src/Repositories/ResponseRepository.php';
        require_once __DIR__ . '/../src/Repositories/AdminRepository.php';
        $dbRow = repo_response_find((int) $a['id']);
        if ($dbRow && !empty($dbRow['submitted_at'])) {
            return details_db_submission($a, $dbRow);
        }
    } catch (Throwable $e) {
        error_log('details_submission DB error: ' . $e->getMessage());
    }
    $found = null;
    foreach (static_store_read()['submissions'] ?? [] as $s) {
        $sameId    = (int) ($s['applicant_id'] ?? 0) === (int) $a['id'];
        $sameEmail = strcasecmp((string) ($s['email'] ?? ''), (string) $a['email']) === 0;
        if ($sameId || $sameEmail) $found = $s;            // the latest one wins
    }
    if ($found) {
        $found['demo'] = false;
        return $found;
    }
    if (strcasecmp((string) $a['status'], 'Completed') === 0) return details_demo_submission($a);
    return null;
}

/**
 * Shape a DB response row like the static submission details.php renders:
 * answers keyed by 'q{question_id}', video with the Cloudinary URL.
 */
function details_db_submission(array $a, array $dbRow): array
{
    $answers = is_array($dbRow['answers'] ?? null) ? $dbRow['answers'] : [];
    $shaped = [];
    foreach ($answers as $k => $v) {
        $key = (string) $k;
        // DB stores bare question_ids ("7"); the page renders q-ids ("q7").
        if (preg_match('/^\d+$/', $key)) $key = 'q' . $key;
        elseif (preg_match('/^(\d+)[:_]follow$/', $key, $m)) $key = 'q' . $m[1] . ':follow';
        $shaped[$key] = $v;
    }
    // Consent was stored as 'Yes' in answers_json; the renderer expects boolean true.
    try {
        foreach (db_questions_for_applicant() as $q) {
            if (($q['type'] ?? '') === 'consent' && ($shaped[$q['id']] ?? '') === 'Yes') {
                $shaped[$q['id']] = true;
            }
        }
    } catch (Throwable $e) { /* static fallback keeps 'Yes', still renders */ }

    $submittedAt = (string) ($dbRow['submitted_at'] ?? '');
    return [
        'reference'    => 'SCR-' . substr($submittedAt, 0, 4) . '-' . str_pad((string) $a['id'], 5, '0', STR_PAD_LEFT),
        'applicant_id' => (int) $a['id'],
        'email'        => $a['email'],
        'answers'      => $shaped,
        'video'        => [
            'name'    => 'video_response.mp4',
            'size'    => 0,
            'seconds' => null,
            'source'  => 'upload',
            'url'     => (string) ($dbRow['video_file_url'] ?? ''),
            'hash'    => (string) ($dbRow['video_file_hash'] ?? ''),
        ],
        'submitted_at' => $submittedAt ? date('Y-m-d H:i', strtotime($submittedAt)) : '',
        'demo'         => false,
    ];
}

function details_demo_submission(array $a): array
{
    $id     = (int) $a['id'];
    $failed = strcasecmp((string) ($a['result'] ?? ''), 'Rejected') === 0;

    $goodText = [
        'The role handles customer calls and chats for our clients, solves their concerns, documents every case and keeps client information confidential. It is an on-site, shifting job in BGC.',
        'From the posting, I understand I would support clients through voice and email, follow the company process for each concern, and meet quality and attendance targets while protecting client data.',
        'It is a customer-facing support role. I will answer inquiries, escalate what I cannot solve, keep accurate notes and follow the confidentiality rules the company requires.'
    ];
    $weakText = [
        'It is a call center job where I answer calls.',
        'I will help customers and do what my team leader says. I think it is a work from home job.'
    ];

    $answers = [
        'q1' => 'Yes', 'q2' => 'Yes', 'q3' => 'Yes', 'q4' => 'Yes',
        'q5' => $failed ? 2 + $id % 2 : 4 + $id % 2,
        'q6' => static_now()->modify('+' . (7 + $id % 21) . ' days')->format('Y-m-d'),
        'q7' => $id % 2 === 0 ? 'Yes' : 'No',
        'q8' => 'Yes', 'q9' => 'Yes', 'q10' => 'Yes', 'q11' => 'Yes',
        'q12' => $failed ? $weakText[$id % 2] : $goodText[$id % 3],
        'q13' => true
    ];
    if ($answers['q7'] === 'Yes') {
        $answers['q7:follow'] = ['1 to 2 weeks', '3 to 4 weeks (30 days)', '1 to 2 months'][$id % 3];
    }
    if ($failed) {                                          // what usually makes an applicant fail
        $answers[['q3', 'q2', 'q10'][$id % 3]] = 'No';
        if ($id % 2 === 1) $answers['q9'] = 'No';
    }

    return [
        'reference'    => 'SCR-' . substr((string) $a['updated'], 0, 4) . '-' . str_pad((string) $id, 5, '0', STR_PAD_LEFT),
        'applicant_id' => $id,
        'email'        => $a['email'],
        'answers'      => $answers,
        'video'        => [
            'name'    => 'video_response.mp4',
            'size'    => (int) ((3.5 + ($id % 40) / 10) * 1024 * 1024),
            'seconds' => round(8 + ($id % 20) / 10, 1),
            'source'  => $id % 3 === 0 ? 'recorded' : 'upload',
            'url'     => ''
        ],
        'submitted_at' => $a['updated'],
        'demo'         => true
    ];
}

// ---------------------------------------------------------------------------
// The questionnaire summary: every real question with the answer given
// ---------------------------------------------------------------------------
// $ko = the knock-out check of this question for this applicant (static_knockout_check), or null when it is not a knock-out question
function details_answer_view(array $q, array $answers, ?array $ko = null): array
{
    $id = $q['id'];
    $v  = $answers[$id] ?? null;

    $text = (string) ($q['text'] ?? $q['question_text'] ?? '');
    if (!empty($q['link']['text'])) $text = str_replace('{link}', $q['link']['text'], $text);

    $out = [
        'id' => $id, 'type' => $q['type'], 'question' => $text,
        'value' => '', 'tone' => 'neutral', 'extra' => '', 'scale' => null, 'max' => null,
        'flag' => '', 'missing' => false,
        // screening rubric: is this a knock-out question, what passes, and did the applicant pass it
        'knockout' => $ko !== null,
        'expected' => $ko['expected'] ?? '',
        'ko_met'   => $ko['met'] ?? null,          // true = passed, false = did not pass, null = nothing to judge
        'reason'   => $ko['reason'] ?? ''
    ];

    switch ($q['type']) {
        case 'yesno':
        case 'yesno_select':
            if ($v !== 'Yes' && $v !== 'No') { $out['missing'] = true; break; }
            $out['value'] = $v;
            $out['tone']  = $v === 'Yes' ? 'yes' : 'no';
            if (!empty($q['followup']) && $v === $q['followup']['when']) {
                $out['extra'] = trim(($q['followup']['label'] ?? '') . ': ' . ($answers[$id . ':follow'] ?? '-'));
            }
            // "No" to a plain Yes/No is worth a look (question 7 is a fact, not a requirement).
            // A knock-out question is judged by its passing answer instead (see 'ko_met').
            if ($ko === null && $q['type'] === 'yesno' && $v === 'No') $out['flag'] = 'Answered No';
            break;

        case 'scale':
            $n = (int) $v;
            if ($n < 1) { $out['missing'] = true; break; }
            $out['scale'] = $n;
            $out['max']   = (int) ($q['max'] ?? 5);
            $out['value'] = $n . ' / ' . $out['max'];
            if ($ko === null && $n <= 2) $out['flag'] = 'Low interest rating';
            break;

        case 'date':
            if (!$v) { $out['missing'] = true; break; }
            $out['value'] = details_date_label((string) $v);
            break;

        case 'text':
            if (trim((string) $v) === '') { $out['missing'] = true; break; }
            $out['value'] = trim((string) $v);
            break;

        case 'consent':
            if ($v !== true) { $out['missing'] = true; break; }
            $out['value'] = 'Accepted';
            $out['tone']  = 'yes';
            break;
    }
    return $out;
}

// $evaluation = details_evaluation() of this applicant. Its 'knockouts' are the rubric as it was when the applicant
// sent the screening, so the page still tells the truth after the Admin changes a passing answer. Without an
// evaluation the questions' current rules are used.
function details_questionnaire(array $submission, ?array $evaluation = null): array
{
    $answers  = is_array($submission['answers'] ?? null) ? $submission['answers'] : [];
    $sections = [];
    $answered = 0;
    $flags    = [];
    $total    = 0;

    $saved = null;
    if ($evaluation !== null && is_array($evaluation['knockouts'] ?? null)) {
        $saved = [];
        foreach ($evaluation['knockouts'] as $k) $saved[(string) $k['id']] = $k;
    }
    $koTotal  = 0;
    $koMet    = 0;
    $koFailed = [];

    foreach (details_questions_live() as $q) {
        $total++;
        $ko = null;
        if ($saved !== null && array_key_exists((string) $q['id'], $saved)) {
            $ko = $saved[(string) $q['id']];
            // The stored evaluation's knock-outs are read from the static store, which is only
            // a cached snapshot of the submissions. The real (DB) submission can be newer, or the
            // store can be out of sync, so an applicant who answered "Yes" may show knock-outs
            // computed from an older "No" submission. Verify the stored knock-out's "given" against
            // the current submission answer, and recompute from the real answers when they differ.
            $cur = $answers[$q['id']] ?? null;
            $given = $ko['given'] ?? null;
            if ($cur !== null && $given !== null && strcasecmp((string) $cur, (string) $given) !== 0) {
                $ko = static_knockout_check($q, $answers);
            }
        } else {
            $ko = static_knockout_check($q, $answers);
        }
        $view = details_answer_view($q, $answers, $ko);
        if (!$view['missing']) $answered++;
        if ($view['flag'] !== '') $flags[] = ['question' => $view['question'], 'note' => $view['flag']];
        if ($ko !== null) {
            $koTotal++;
            if ($ko['met'] === true)  $koMet++;
            if ($ko['met'] === false) $koFailed[] = ['question' => $view['question'], 'given' => (string) $ko['given'], 'expected' => (string) $ko['expected'], 'reason' => (string) $ko['reason']];
        }

        $no = (int) $q['section_no'];
        if (!isset($sections[$no])) $sections[$no] = ['no' => $no, 'title' => $q['section'], 'items' => []];
        $sections[$no]['items'][] = $view;
    }
    ksort($sections);

    return [
        'sections' => array_values($sections), 'answered' => $answered, 'total' => $total, 'flags' => $flags,
        // the knock-out result: how many knock-out questions there are, how many passed, and the ones that did not (with the reason)
        'ko' => ['total' => $koTotal, 'met' => $koMet, 'failed' => $koFailed]
    ];
}

// ---------------------------------------------------------------------------
// Overview: the personal information
// ---------------------------------------------------------------------------
function details_personal(array $a, ?array $submission): array
{
    $answers = $submission['answers'] ?? [];

    $employment = '-';
    if (($answers['q7'] ?? '') === 'Yes')      $employment = 'Employed, notice period: ' . ($answers['q7:follow'] ?? '-');
    elseif (($answers['q7'] ?? '') === 'No')   $employment = 'Not currently employed';

    return [
        ['label' => 'Full Name',                 'value' => $a['name']],
        ['label' => 'Email Address',             'value' => $a['email']],
        ['label' => 'Contact Number',            'value' => $a['contact']],
        ['label' => 'Earliest Start Date',       'value' => !empty($answers['q6']) ? details_date_label((string) $answers['q6']) : 'Not given yet'],
        ['label' => 'Employment / Notice Period', 'value' => $employment === '-' ? 'Not given yet' : $employment],
        ['label' => 'Last Activity',             'value' => details_datetime_label((string) $a['updated'])]
    ];
}

// ---------------------------------------------------------------------------
// The CV: the real one when Add Applicant saved it, otherwise a note
// ---------------------------------------------------------------------------
function details_cv(array $a): array
{
    // DB applicants: cv_file_url is a Cloudinary URL stored on the applicant row.
    // Serve through cv-proxy.php so Cloudinary ACL restrictions are bypassed.
    $cvUrl = trim((string) ($a['cv_file_url'] ?? ''));
    if ($cvUrl !== '') {
        $name = basename(parse_url($cvUrl, PHP_URL_PATH) ?: 'cv');
        return [
            'available' => true,
            'name'      => $name,
            'size'      => 0,
            'url'       => 'cv-proxy.php?id=' . (int) $a['id']
        ];
    }
    // Legacy static-store applicants: cv saved as a local file.
    $cv = $a['cv'] ?? null;
    if (is_array($cv) && !empty($cv['file']) && is_file(DETAILS_CV_DIR . '/' . basename((string) $cv['file']))) {
        return [
            'available' => true,
            'name'      => (string) $cv['name'],
            'size'      => (int) ($cv['size'] ?? 0),
            'url'       => 'static-details.php?file=cv&id=' . (int) $a['id']
        ];
    }
    return ['available' => false, 'name' => '', 'size' => 0, 'url' => ''];
}

function details_size(int $bytes): string
{
    if ($bytes >= 1048576) return round($bytes / 1048576, 1) . ' MB';
    if ($bytes >= 1024)    return round($bytes / 1024) . ' KB';
    return $bytes . ' B';
}

/** Questions for the HR details renderer: DB first (Admin edits), static fallback. */
function details_questions_live(): array
{
    try {
        require_once __DIR__ . '/../src/Repositories/AdminRepository.php';
        $questions = db_questions_for_applicant();
        if ($questions) return $questions;
    } catch (Throwable $e) {
        error_log('details questions DB error: ' . $e->getMessage());
    }
    return static_questionnaire();
}

// ---------------------------------------------------------------------------
// AI evaluation = the RISK SCORING RUBRIC (applicant/static-evaluation.php).
// A real applicant: the evaluation saved when they sent the screening (store "evaluations").
// A seed applicant: the same rubric is run on their sample answers (static_evaluate), so it looks and
// behaves exactly like a real one.  The thresholds always come from static_risk_config().
// DATABASE / AI (later): read the saved evaluation + its factors for this applicant.
// ---------------------------------------------------------------------------
function details_find_evaluation(array $a, ?array $submission): ?array
{
    if (!$submission || strcasecmp((string) $a['status'], 'Completed') !== 0) return null;

    if (empty($submission['demo'])) {
        // DB first: read from the score table (written by RiskScoringService)
        try {
            $pdo = db();
            $st  = $pdo->prepare(
                "SELECT s.risk_score, s.risk_flags_json, s.narrative_json, r.submitted_at
                 FROM score s
                 JOIN response r ON r.response_id = s.response_id
                 WHERE r.applicant_id = :id
                 LIMIT 1"
            );
            $st->execute([':id' => (int) $a['id']]);
            $row = $st->fetch();
            if ($row) {
                $flags = json_decode((string) ($row['risk_flags_json'] ?? '{}'), true);
                $narr  = json_decode((string) ($row['narrative_json']  ?? '{}'), true);
                if (is_array($flags) && isset($flags['outcome'])) {
                    return [
                        'reference'    => $submission['reference'] ?? '',
                        'email'        => $a['email'],
                        'points'       => (int) $row['risk_score'],
                        'band'         => $flags['band']      ?? 'Low',
                        'auto_high'    => $flags['auto_high'] ?? [],
                        'outcome'      => $flags['outcome']   ?? 'review',
                        'factors'      => $flags['factors']   ?? [],
                        'knockouts'    => $flags['knockouts'] ?? [],
                        'narrative'    => is_array($narr) ? $narr : [],
                        'evaluated_at' => $row['submitted_at'] ?? '',
                        'demo'         => false,
                    ];
                }
            }
        } catch (Throwable $e) {
            error_log('details_find_evaluation DB error: ' . $e->getMessage());
        }

        // Fall back to static store (submissions from the old static path)
        $found = null;
        $ref   = (string) ($submission['reference'] ?? '');
        foreach ((array) (static_store_read()['evaluations'] ?? []) as $e) {
            $sameRef   = $ref !== '' && ($e['reference'] ?? '') === $ref;
            $sameEmail = ($e['email'] ?? '') !== '' && strcasecmp((string) $e['email'], (string) $a['email']) === 0;
            if ($sameRef || $sameEmail) $found = $e;
        }
        if ($found) $found['demo'] = false;
        return $found;
    }

    // seed applicant: run the real rubric on the sample answers
    $id = (int) $a['id'];
    $questions = static_questionnaire();
    $timing = [];
    foreach ($questions as $k => $q) $timing[$q['id']] = 3500 + (($id * 37 + $k * 911) % 6500);   // 3.5 to 10 seconds each
    $run = static_evaluate([
        'answers' => $submission['answers'], 'questions' => $questions, 'timing' => $timing,
        'otp' => ['failed' => 0, 'requests' => [], 'locked' => false],
        'me' => $a, 'email' => $a['email'], 'device' => '', 'ip' => '', 'video_hash' => '',
        'others' => [], 'applicants' => [], 'evaluations' => [], 'now' => static_now()
    ]);
    return [
        'reference' => $submission['reference'], 'email' => $a['email'],
        'points' => $run['points'], 'band' => $run['band'], 'auto_high' => $run['auto_high'],
        'outcome' => $run['outcome'], 'factors' => $run['factors'], 'knockouts' => $run['knockouts'],
        'evaluated_at' => $a['updated'], 'demo' => true
    ];
}

// What the AI Evaluation tab and the Overview need, worked out from the saved evaluation
function details_evaluation(array $a, ?array $submission): ?array
{
    $e = details_find_evaluation($a, $submission);
    if (!$e) return null;

    $cfg     = static_risk_config();
    $outcome = in_array($e['outcome'] ?? '', ['passed', 'failed', 'review'], true) ? $e['outcome'] : 'review';
    $points  = (int) $e['points'];
    $band    = in_array($e['band'] ?? '', ['Low', 'Medium', 'High'], true) ? $e['band'] : 'Low';

    $factors = [];
    $max     = 0;
    foreach ((array) ($e['factors'] ?? []) as $f) {
        $p  = (int) ($f['points'] ?? 0);
        $mx = max(1, (int) ($f['max'] ?? 1));
        $na = strpos((string) ($f['reason'] ?? ''), 'Not scored') === 0;     // a factor the rubric does not score
        $max += $mx;
        $factors[] = [
            'label'  => (string) $f['label'],
            'points' => $p,
            'max'    => $mx,
            'reason' => (string) ($f['reason'] ?? ''),
            'pct'    => $na ? 0 : (int) round($p / $mx * 100),
            'level'  => $na ? 'na' : ($p <= 0 ? 'none' : ($p >= $mx ? 'max' : 'some'))
        ];
    }

    // the knock-out questions (these make the screening Not passed when the answer is not the passing answer).
    // Saved with the evaluation = the rubric when the applicant sent the screening; older evaluations use the current rules.
    $answers = is_array($submission['answers'] ?? null) ? $submission['answers'] : [];
    if (is_array($e['knockouts'] ?? null)) {
        $checks = array_values($e['knockouts']);
    } else {
        $checks = [];
        foreach (static_questionnaire() as $q) {
            $check = static_knockout_check($q, $answers);
            if ($check !== null) $checks[] = $check;
        }
    }
    $failedChecks = array_values(array_filter($checks, fn($k) => ($k['met'] ?? null) === false));
    $unmet = array_map(fn($k) => (string) $k['question'], $failedChecks);
    $must  = count($checks);

    $favor = [];
    $watch = [];
    if ($must > 0 && !$failedChecks) $favor[] = 'Met the passing answer of all ' . $must . ' knock-out questions.';
    foreach ($failedChecks as $k) $watch[] = 'Did not meet a knock-out question: "' . $k['question'] . '". ' . $k['reason'];
    $clean = 0;
    foreach ($factors as $f) {
        if ($f['level'] === 'none') $clean++;
        elseif ($f['level'] !== 'na') $watch[] = $f['label'] . ' (' . $f['points'] . ' of ' . $f['max'] . '): ' . $f['reason'];
    }
    if ($clean > 0) $favor[] = $clean . ' of the ' . count(array_filter($factors, fn($f) => $f['level'] !== 'na')) . ' risk checks found nothing.';
    foreach ((array) ($e['auto_high'] ?? []) as $why) $watch[] = 'Automatic High risk: ' . $why . '.';

    if ($outcome === 'passed') {
        $title   = 'Why this applicant passed';
        $summary = ($must > 0 ? 'The applicant met the passing answer of every knock-out question (' . $must . ') and the' : 'The questionnaire has no knock-out questions and the')
                 . ' risk band is ' . $band . ' (' . $points . ' of ' . $max . ' points), so the applicant moved on to schedule the interview.';
        $recommendation = 'Proceeds to Interview';
        $label   = 'Passed';
    } elseif ($outcome === 'failed') {
        $title   = 'Why this applicant failed';
        $summary = 'The applicant did not meet the passing answer of ' . count($unmet) . ' of ' . $must . ' knock-out question' . ($must === 1 ? '' : 's') . ', so the screening was not passed. Risk alone never fails an applicant: the risk band here is ' . $band . ' (' . $points . ' of ' . $max . ' points).';
        $recommendation = 'Does not meet the requirements';
        $label   = 'Rejected';
    } else {
        $title   = 'Why this applicant is under review';
        $summary = 'The risk band is High (' . $points . ' of ' . $max . ' points), so the applicant is held for HR to check before any result is released. Risk alone never fails an applicant.';
        $recommendation = 'Hold for HR review';
        $label   = 'Under Review';
    }

    // Use Gemini narrative when available (DB-scored applicants)
    $narr = is_array($e['narrative'] ?? null) ? $e['narrative'] : [];
    if (!empty($narr['title']))   $title   = (string) $narr['title'];
    if (!empty($narr['summary'])) $summary = (string) $narr['summary'];
    if (!empty($narr['favor']))   $favor   = array_values(array_filter((array) $narr['favor'], 'is_string'));
    if (!empty($narr['watch']))   $watch   = array_values(array_filter((array) $narr['watch'], 'is_string'));

    return [
        'outcome'        => $outcome,
        'label'          => $label,
        'points'         => $points,
        'max_points'     => $max,
        'band'           => $band,
        'bands'          => $cfg['bands'],
        'factors'        => $factors,
        'auto_high'      => array_values((array) ($e['auto_high'] ?? [])),
        'unmet'          => $unmet,
        'knockouts'      => $checks,
        'favor'          => $favor,
        'watch'          => $watch,
        'title'          => $title,
        'summary'        => $summary,
        'recommendation' => $recommendation,
        'evaluated_at'   => (string) ($e['evaluated_at'] ?? ''),
        'demo'           => !empty($e['demo'])
    ];
}

// ---------------------------------------------------------------------------
// Interview schedule. The rows live in the shared store (static_interview_* in static-data.php):
// the APPLICANT sends the preferred date + time (applicant/application-status.php), HR answers here.
//   awaiting     the applicant's preferred schedule, waiting for HR
//   confirmed    HR said "Available"; the applicant was emailed
//   rescheduled  HR said "Not available" (or re-scheduled) and sent a new schedule
// A REAL applicant who passed but has not picked a date yet has no row: the tab says so.
// A SEED applicant (sample answers) gets a sample request the first time HR opens them.
// ---------------------------------------------------------------------------
function details_interview(array $a, ?array $submission): ?array
{
    $id  = (int) $a['id'];
    $row = static_interview_get($id);
    if ($row || !$submission || empty($submission['demo'])) return $row;

    // sample request: a weekday 3 to 7 days from today
    $d = static_now()->modify('+' . (3 + $id % 5) . ' days');
    while ((int) $d->format('N') >= 6) $d = $d->modify('+1 day');
    $slots = details_time_slots();
    return static_interview_request($id, $d->format('Y-m-d'), $slots[$id % count($slots)]['value'], 'sample');
}

// What the page needs, with the dates already written out
function details_interview_view(?array $iv): ?array
{
    if (!$iv) return null;
    $awaiting = $iv['status'] === 'awaiting';
    $date = $awaiting ? $iv['preferred_date'] : $iv['date'];
    $time = $awaiting ? $iv['preferred_time'] : $iv['time'];
    return [
        'status'    => $iv['status'],
        'type'      => $iv['type'],
        'preferred' => ['date' => details_date_label($iv['preferred_date']), 'time' => details_time_label($iv['preferred_time']),
                        'raw_date' => $iv['preferred_date'], 'raw_time' => $iv['preferred_time']],
        'current'   => ['date' => details_date_label($date), 'time' => details_time_label($time),
                        'raw_date' => $date, 'raw_time' => $time],
        'updated'   => $iv['updated'] ?? ''
    ];
}

// HR: "Available". Throws InvalidArgumentException (safe to show to HR).
function details_interview_approve(array $a): array
{
    $iv = static_interview_get((int) $a['id']);
    if (!$iv) throw new InvalidArgumentException('This applicant has not chosen an interview schedule yet.');
    if ($iv['status'] !== 'awaiting') throw new InvalidArgumentException('This schedule was already answered. Refresh the page.');

    $iv = static_store_update(function (array &$store) use ($a) {
        $row = &$store['interviews'][(int) $a['id']];
        $row['status']  = 'confirmed';
        $row['date']    = $row['preferred_date'];
        $row['time']    = $row['preferred_time'];
        $row['updated'] = static_now()->format('Y-m-d H:i');
        return $row;
    });
    $email = details_email('confirmed', $a, $iv);
    details_send_email($email, (int) $a['id'], 'confirmed');
    return [$iv, $email];
}

// HR: "Not available" + a new schedule, or "Re-schedule" later. Same rules as the applicant's picker:
// Monday to Friday, one of the listed times.
function details_interview_reschedule(array $a, string $date, string $time): array
{
    $iv = static_interview_get((int) $a['id']);
    if (!$iv) throw new InvalidArgumentException('This applicant has not chosen an interview schedule yet.');

    $d = DateTimeImmutable::createFromFormat('Y-m-d', $date);
    if (!$d || $d->format('Y-m-d') !== $date)                       throw new InvalidArgumentException('Please choose a date.');
    $today = static_now();
    if ($date < $today->format('Y-m-d'))                            throw new InvalidArgumentException('Please choose today or a later date.');
    if ($date > $today->modify('+60 days')->format('Y-m-d'))        throw new InvalidArgumentException('Please choose a date within the next 60 days.');
    if ((int) $d->format('N') > 5)                                  throw new InvalidArgumentException('Interviews are held Monday to Friday. Please choose a weekday.');
    if (!in_array($time, array_column(details_time_slots(), 'value'), true)) throw new InvalidArgumentException('Please choose a time from the list.');

    $curDate = $iv['status'] === 'awaiting' ? $iv['preferred_date'] : $iv['date'];
    $curTime = $iv['status'] === 'awaiting' ? $iv['preferred_time'] : $iv['time'];
    if ($date === $curDate && $time === $curTime) {
        throw new InvalidArgumentException('That is the same schedule as before. Choose a different date or time.');
    }

    $iv = static_store_update(function (array &$store) use ($a, $date, $time) {
        $row = &$store['interviews'][(int) $a['id']];
        $row['status']  = 'rescheduled';
        $row['date']    = $date;
        $row['time']    = $time;
        $row['updated'] = static_now()->format('Y-m-d H:i');
        return $row;
    });
    $email = details_email('rescheduled', $a, $iv);
    details_send_email($email, (int) $a['id'], 'rescheduled');
    return [$iv, $email];
}

// ---------------------------------------------------------------------------
// The emails load the shared HTML templates from the project root
// (schedule-approved-email.html / schedule-rescheduled-email.html).
// ---------------------------------------------------------------------------
// The address of the applicant's status page (the link inside the emails).
// Set APP_URL (config/constants.php) to the real site address when it is online, for example
// define('APP_URL', 'https://expertise.example.com'); until then it is worked out from this request.
function details_status_url(): string
{
    if (defined('APP_URL') && APP_URL !== '') return rtrim((string) APP_URL, '/') . '/applicant/application-status.php';
    $https  = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    $host   = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
    $script = (string) ($_SERVER['SCRIPT_NAME'] ?? '/hr/static-details.php');
    $root   = rtrim(str_replace('\\', '/', dirname(dirname($script))), '/');      // hr/ -> the project folder
    return ($https ? 'https://' : 'http://') . $host . $root . '/applicant/application-status.php';
}

// The latest email that was sent to this applicant (so HR can open "View Email" again after a refresh)
function details_last_email(int $applicantId): ?array
{
    $outbox = static_store_read()['outbox'] ?? [];
    if (!is_array($outbox)) return null;
    foreach (array_reverse(array_values($outbox)) as $m) {
        if (is_array($m) && (int) ($m['applicant_id'] ?? 0) === $applicantId) {
            return ['to' => (string) $m['to'], 'subject' => (string) $m['subject'], 'html' => (string) $m['html']];
        }
    }
    return null;
}

function details_email_template(string $file): ?string
{
    // All email HTML templates live in includes/emails/.
    $root = realpath(__DIR__ . '/..');
    $candidates = [];
    if ($root) {
        $candidates[] = $root . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'emails' . DIRECTORY_SEPARATOR . $file;
        $candidates[] = $root . DIRECTORY_SEPARATOR . $file; // legacy: project root
        $candidates[] = $root . DIRECTORY_SEPARATOR . 'hr' . DIRECTORY_SEPARATOR . $file; // legacy fallback
    }
    $candidates[] = __DIR__ . '/../includes/emails/' . $file;
    $candidates[] = __DIR__ . '/../' . $file;
    foreach ($candidates as $path) {
        if (is_file($path)) {
            $html = file_get_contents($path);
            if ($html !== false && $html !== '') return $html;
        }
    }
    return null;
}

function details_email(string $kind, array $a, array $iv): array
{
    $firstRaw = details_first_name((string) $a['name']);
    $first   = details_h($firstRaw);
    $date    = details_h(details_date_label($iv['date']));
    $time    = details_h(details_time_label($iv['time']));
    $type    = details_h($iv['type']);
    $venue   = details_h(DETAILS_VENUE);
    $company = details_h(DETAILS_COMPANY);
    $link    = details_h(details_status_url());
    $year    = date('Y');

    if ($kind === 'confirmed') {
        $subject = 'Your interview is confirmed | ' . DETAILS_COMPANY;
        $template = details_email_template('schedule-approved-email.html');
    } else {
        $subject = 'New interview schedule | ' . DETAILS_COMPANY;
        $template = details_email_template('schedule-rescheduled-email.html');
    }

    if ($template !== null) {
        // Swap relative logo paths to the Cloudinary URL so the logo displays in email clients.
        $template = str_replace(
            ['../../assets/images/expertise.png', '../assets/images/expertise.png',
             'assets/images/expertise.png', '../pic/expertise.png'],
            DETAILS_LOGO_URL,
            $template
        );
        $html = str_replace(
            ['{{FIRST_NAME}}', '{{DATE}}', '{{TIME}}', '{{INTERVIEW_TYPE}}', '{{LOCATION}}', '{{LINK}}', '{{YEAR}}'],
            [$first, $date, $time, $type, $venue, $link, $year],
            $template
        );
        return ['to' => (string) $a['email'], 'subject' => $subject, 'html' => $html];
    }

    // Fallback (template file missing): inline email, same content as the templates.
    if ($kind === 'confirmed') {
        $kicker  = 'Interview update';
        $title   = 'Your interview is confirmed';
        $lead    = 'Good news! The schedule you chose is available. Your interview is now confirmed.';
        $closing = 'Please arrive 15 minutes early and bring a valid ID. If you can no longer make it, reply to this email as soon as possible.';
    } else {
        $kicker  = 'Interview update';
        $title   = 'We have a new schedule for you';
        $lead    = 'Thank you for choosing a schedule. Unfortunately, that date and time is not available, so we set a new one for your interview.';
        $closing = 'If this schedule does not work for you, please reply to this email and we will find another time. Please arrive 15 minutes early and bring a valid ID.';
    }

    $row = function (string $label, string $value): string {
        return '<tr><td style="padding:9px 0;width:130px;font-size:13px;font-weight:700;color:#5b5f66;">' . $label . '</td>'
             . '<td style="padding:9px 0;font-size:15px;font-weight:700;color:#1f1f23;">' . $value . '</td></tr>';
    };

    $html = '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"></head>'
        . '<body style="margin:0;padding:0;background:#f4f4f5;font-family:\'Plus Jakarta Sans\',\'Segoe UI\',Helvetica,Arial,sans-serif;">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#f4f4f5;"><tr><td align="center" style="padding:28px 12px;">'
        . '<table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" style="width:100%;max-width:600px;background:#ffffff;border-radius:16px;overflow:hidden;">'
        . '<tr><td style="height:6px;line-height:6px;font-size:0;background:#d1122a;">&nbsp;</td></tr>'
        . '<tr><td style="padding:28px 40px 0 40px;font-size:26px;font-weight:800;color:#a3081e;letter-spacing:.3px;">' . $company . '</td></tr>'
        . '<tr><td style="padding:26px 40px 0 40px;"><p style="margin:0 0 8px 0;font-size:13px;font-weight:700;color:#d1122a;">' . $kicker . '</p>'
        . '<h1 style="margin:0;font-size:26px;line-height:1.25;font-weight:800;color:#1f1f23;">' . $title . '</h1></td></tr>'
        . '<tr><td style="padding:20px 40px 0 40px;color:#3a3d44;font-size:15px;line-height:1.7;">'
        . '<p style="margin:0 0 12px 0;color:#1f1f23;font-weight:700;">Hi ' . $first . ',</p><p style="margin:0;">' . $lead . '</p></td></tr>'
        . '<tr><td style="padding:22px 40px 0 40px;"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#f8f8f9;border:1px solid #e6e7ea;border-radius:12px;"><tr><td style="padding:12px 22px;">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">'
        . $row('Date', $date) . $row('Time', $time) . $row('Interview Type', $type) . $row('Location', $venue)
        . '</table></td></tr></table></td></tr>'
        . '<tr><td style="padding:20px 40px 0 40px;color:#3a3d44;font-size:14px;line-height:1.7;">' . $closing . '</td></tr>'
        . '<tr><td style="padding:22px 40px 0 40px;"><a href="' . $link . '" style="display:inline-block;padding:13px 26px;background:#d1122a;color:#ffffff;font-size:15px;font-weight:700;text-decoration:none;border-radius:10px;">View my interview schedule</a></td></tr>'
        . '<tr><td style="padding:12px 40px 0 40px;color:#8a8f96;font-size:12px;line-height:1.6;">The link opens your application status page. You will be asked to verify your email with a one-time code first.</td></tr>'
        . '<tr><td style="padding:26px 40px 30px 40px;color:#8a8f96;font-size:12px;line-height:1.6;border-top:0;">Thank you,<br><strong style="color:#1f1f23;">' . $company . ' HR Team</strong></td></tr>'
        . '</table></td></tr></table></body></html>';

    return ['to' => (string) $a['email'], 'subject' => $subject, 'html' => $html];
}

// The email is "sent" by writing it to the outbox of the store (that is how it can be shown again).
// BACKEND: replace the body of this function with a call to src/Services/EmailService.php (SendGrid)
// and keep the return value.
function details_send_email(array $email, int $applicantId, string $kind): bool
{
    return (bool) static_store_update(function (array &$store) use ($email, $applicantId, $kind) {
        if (!isset($store['outbox']) || !is_array($store['outbox'])) $store['outbox'] = [];
        $store['outbox'][] = [
            'applicant_id' => $applicantId,
            'kind'         => $kind,
            'to'           => $email['to'],
            'subject'      => $email['subject'],
            'html'         => $email['html'],
            'sent_at'      => static_now()->format('Y-m-d H:i:s')
        ];
        $store['outbox'] = array_slice($store['outbox'], -200);      // keep the last 200
        return true;
    });
}


/* ==========================================================================
   ENDPOINT (only when this file is opened directly, never when it is included)

   GET   static-details.php?file=cv&id=101      the applicant's CV (only for a signed-in HR / Admin)
   POST  JSON body, one of
         { action: 'interview_approve',    id }                    "Available"
         { action: 'interview_reschedule', id, date, time }        "Not available" / "Re-schedule"
   Response (JSON):
         { status: 'success', interview: {...}, email: { to, subject, html } }
         { status: 'error', message: '...' }
   ========================================================================== */
if (isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) !== false
    && strcasecmp(realpath($_SERVER['SCRIPT_FILENAME']), __FILE__) === 0) {   // strcasecmp: Windows paths

    if (session_status() === PHP_SESSION_NONE) session_start();

    function details_respond(array $data, int $code = 200): void
    {
        http_response_code($code);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Only a signed-in HR or Admin user
    if (!in_array($_SESSION['user_role'] ?? '', ['hr', 'admin'], true)) {
        details_respond(['status' => 'error', 'message' => 'Your session has ended. Please log in again.'], 403);
    }

    // ---- the CV file ----
    if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['file'] ?? '') === 'cv') {
        $cv = null;
        foreach (static_store_read()['applicants'] as $row) {
            if ((int) $row['id'] === (int) ($_GET['id'] ?? 0)) { $cv = $row['cv'] ?? null; break; }
        }
        $path = is_array($cv) && !empty($cv['file']) ? DETAILS_CV_DIR . '/' . basename((string) $cv['file']) : '';
        if ($path === '' || !is_file($path)) {
            http_response_code(404);
            header('Content-Type: text/plain; charset=UTF-8');
            echo 'No CV file was found for this applicant.';
            exit;
        }
        $ext   = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $types = ['pdf' => 'application/pdf', 'doc' => 'application/msword',
                  'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'];
        header('Content-Type: ' . ($types[$ext] ?? 'application/octet-stream'));
        header('Content-Disposition: inline; filename="' . str_replace(['"', "\r", "\n"], '', (string) $cv['name']) . '"');
        header('X-Content-Type-Options: nosniff');
        header('Content-Length: ' . filesize($path));
        readfile($path);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        details_respond(['status' => 'error', 'message' => 'Invalid request.'], 405);
    }

    $json  = json_decode((string) file_get_contents('php://input'), true);
    $input = is_array($json) ? $json : $_POST;
    $action = (string) ($input['action'] ?? '');

    $applicant = details_find_applicant((int) ($input['id'] ?? 0));
    if (!$applicant) {
        details_respond(['status' => 'error', 'message' => 'That applicant was not found. They may have been moved to the Archive.'], 404);
    }
    if (!details_access($applicant)['interview']) {
        details_respond(['status' => 'error', 'message' => details_locked_message('interview')], 403);
    }

    try {
        switch ($action) {
            case 'interview_approve':
                [$iv, $email] = details_interview_approve($applicant);
                static_log('Interview Confirmed', $applicant['name'] . ' · ' . details_date_label($iv['date']) . ', ' . details_time_label($iv['time']));
                break;

            case 'interview_reschedule':
                [$iv, $email] = details_interview_reschedule($applicant, (string) ($input['date'] ?? ''), (string) ($input['time'] ?? ''));
                static_log('Interview Rescheduled', $applicant['name'] . ' · ' . details_date_label($iv['date']) . ', ' . details_time_label($iv['time']));
                break;

            default:
                details_respond(['status' => 'error', 'message' => 'Unknown action.'], 400);
        }
        details_respond([
            'status'    => 'success',
            'interview' => details_interview_view($iv),
            'email'     => $email
        ]);

    } catch (InvalidArgumentException $e) {
        details_respond(['status' => 'error', 'message' => $e->getMessage()], 422);
    } catch (Throwable $e) {
        details_respond(['status' => 'error', 'message' => 'Something went wrong while saving. Please try again.'], 500);
    }
}


