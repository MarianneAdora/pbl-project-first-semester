<?php
/* ==========================================================================
   STATIC APPLICANT DATA: everything the applicant side needs, in ONE file.
   Location: applicant/static-applicant.php  (next to the applicant pages)
   Temporary, like hr/static-data.php (which it uses for the applicant list). It is deleted
   when the database is connected.

   Who takes from this file
     applicant/questionnaire.php   static_questionnaire()   the screening questions (13 to start with;
                                                         the Admin adds / edits them in admin/question-list.php)
     applicant/upload-video.php    static_questionnaire()   (checks every answer is there)
                                   static_video_rules()     seconds / MB / formats of the video
     assets/js/screening-store.js  POSTs to this file       "start" and "submit" (see the bottom)

   The questions are edited by HR, so they must be in the database later. Until then they are
   written here, in one array, and the questionnaire page draws whatever is in it. To change a
   question, its type or its choices, change it HERE; questionnaire.js does not need to change.

   Question types
     yesno         Yes / No buttons
                     no_note   (optional) a gentle reminder that opens when the answer is "No"
     yesno_select  Yes / No + a list that opens for one of the answers
                     followup  = ['when' => 'Yes', 'label' => '...', 'options' => [...]]
     scale         dots from 1 to "max"      (max, labels = [left, right])
     date          date picker               (hint)
     text          a text box                (hint, placeholder, max_chars, min_chars)
     consent       a statement to read + one checkbox   (body = [paragraphs], checkbox)
   Knock-out (screening rubric), set by the Admin on yesno / yesno_select / scale questions
     knockout      true  = an answer that does not match the passing answer FAILS the screening
     pass_answer   yesno, yesno_select: 'Yes' or 'No'      scale: the lowest rating that still passes (number)
     pass_follow   yesno_select only (optional): the list choices that still pass; empty = any choice passes
     Read the rule with static_question_rule(), check an answer with static_knockout_check().
   Link inside a question: put {link} in "text" and give  'link' => ['text' => ..., 'url' => ...]
     (the link is drawn in the sentence, opens in a new tab)

   DATABASE (later)
     static_questionnaire()  ->  SELECT * FROM questions ORDER BY section_no, position
                                 (followup / options / body / link as JSON columns)
     static_video_rules()    ->  SELECT ... FROM settings
     "start" / "submit"      ->  api/submit-response.php answers the same two POST requests
   ========================================================================== */

// DB-backed helpers; no static store dependency.
require_once __DIR__ . '/../src/Repositories/AdminRepository.php';
require_once __DIR__ . '/../src/Repositories/ApplicantRepository.php';
require_once __DIR__ . '/../src/Repositories/ResponseRepository.php';

// ---------------------------------------------------------------------------
// The job description link in question 1.
// PLACEHOLDER: a sample PDF. Replace the url with the company's real job description.
// ---------------------------------------------------------------------------
const STATIC_JOB_DESCRIPTION_URL = 'https://www.w3.org/WAI/ER/tests/xhtml/testfiles/resources/pdf/dummy.pdf';

// Every job position has its OWN job description PDF (HR adds it with the position in Add Applicant; see
// "JOB POSITIONS" in hr/static-data.php). The applicant is shown the PDF of the position they were added under.
// A position without a PDF (or an applicant without a position) falls back to the placeholder above.
// DATABASE (later): SELECT p.jd_url FROM applicants a JOIN job_positions p ON p.id = a.position_id WHERE a.id = ?
function static_job_description_url(?array $applicant): string
{
    if ($applicant && !empty($applicant['position_id'])) {
        $url = static_position_jd_url(static_position_find((int) $applicant['position_id']));
        if ($url !== '') return $url;
    }
    return STATIC_JOB_DESCRIPTION_URL;
}

// Points every "job description" link of the questions (the {link} in question 1) at this applicant's own PDF.
// Links about anything else (the Admin can add others) are left exactly as they are.
function static_apply_job_description(array $questions, ?array $applicant): array
{
    $url = static_job_description_url($applicant);
    foreach ($questions as &$q) {
        if (strpos((string) ($q['text'] ?? ''), '{link}') === false) continue;
        if (!preg_match('/job\s*(description|posting)|\bJD\b/i', (string) ($q['link']['text'] ?? ''))) continue;
        $q['link']['url'] = $url;
    }
    unset($q);
    return $questions;
}

// ---------------------------------------------------------------------------
// Video rules (the video page prints them: "only 10 seconds")
// ---------------------------------------------------------------------------
function static_video_rules(): array
{
    return [
        'max_seconds' => 10,                 // the video only needs to be 10 seconds
        'max_mb'      => 50,                 // one video, 50 MB at most
        'formats'     => ['mp4', 'mov']
    ];
}

// ---------------------------------------------------------------------------
// The questionnaire (Sections 1 to 5; Section 6 is the video page)
// ---------------------------------------------------------------------------
// The questions the applicant sees. The Admin keeps them in the store (admin/question-list.php: add, edit,
// delete); until the Admin changes something, the starting list below (static_questionnaire_seed) is used.
// Questions the Admin deleted (they wait in the Admin Archive) or switched to Inactive are not shown.
// DATABASE (later): SELECT * FROM questions WHERE archived_at IS NULL ORDER BY section_no, position
function static_questionnaire(): array
{
    try {
        $dbQuestions = db_questions_for_applicant();
        if (is_array($dbQuestions) && $dbQuestions) return $dbQuestions;
    } catch (Throwable $e) {
        error_log('applicant helpers DB questions error: ' . $e->getMessage());
    }
    return static_questionnaire_seed();
}

// The STARTING questions (sections 1 to 5). Do not edit here to change live questions: use the Admin > Questions page.
function static_questionnaire_seed(): array
{
    $s1 = 'Consent & readiness';
    $s2 = 'Logistics & compliance';
    $s3 = 'Confidentiality acknowledgment';
    $s4 = 'Comprehension';
    $s5 = 'Data Protection';

    return [
        // ---- Section 1: Consent & readiness -------------------------------
        [
            'id' => 'q1', 'section_no' => 1, 'section' => $s1, 'type' => 'yesno',
            'text' => 'Have you read the {link} thoroughly?',
            'link' => ['text' => 'job description', 'url' => STATIC_JOB_DESCRIPTION_URL],
            'no_note' => 'Please read the job description first. The link above opens it in a new tab.'
        ],
        [
            'id' => 'q2', 'section_no' => 1, 'section' => $s1, 'type' => 'yesno',
            'text' => 'Are you willing to work on the schedule as advertised?',
            'knockout' => true, 'pass_answer' => 'Yes'
        ],
        [
            'id' => 'q3', 'section_no' => 1, 'section' => $s1, 'type' => 'yesno',
            'text' => 'Are you willing to work on-site in BGC, Taguig?',
            'knockout' => true, 'pass_answer' => 'Yes'
        ],
        [
            'id' => 'q4', 'section_no' => 1, 'section' => $s1, 'type' => 'yesno',
            'text' => 'Are you willing to go through the assessment and interviews?',
            'knockout' => true, 'pass_answer' => 'Yes'
        ],
        [
            'id' => 'q5', 'section_no' => 1, 'section' => $s1, 'type' => 'scale',
            'text' => 'Rate your interest level in pursuing your application.',
            'max' => 5, 'labels' => ['Not very interested', 'Extremely interested']
        ],

        // ---- Section 2: Logistics & compliance ----------------------------
        [
            'id' => 'q6', 'section_no' => 2, 'section' => $s2, 'type' => 'date',
            'text' => 'When is your earliest possible start date?',
            'hint' => 'Choose a date'
        ],
        [
            'id' => 'q7', 'section_no' => 2, 'section' => $s2, 'type' => 'yesno_select',
            'text' => 'Are you currently employed? If yes, what is your notice period?',
            'followup' => [
                'when'    => 'Yes',
                'label'   => 'Your notice period',
                'options' => ['Less than 1 week', '1 to 2 weeks', '3 to 4 weeks (30 days)', '1 to 2 months', 'More than 2 months']
            ]
        ],
        [
            'id' => 'q8', 'section_no' => 2, 'section' => $s2, 'type' => 'yesno',
            'text' => 'Do you have access to a reliable internet connection and a device suitable for completing the assessment and virtual steps?',
            'no_note' => 'A stable connection and a working device are needed for the assessment and the virtual steps.',
            'knockout' => true, 'pass_answer' => 'Yes'
        ],
        [
            'id' => 'q9', 'section_no' => 2, 'section' => $s2, 'type' => 'yesno',
            'text' => 'Are you willing to undergo a background check as part of the hiring process?',
            'knockout' => true, 'pass_answer' => 'Yes'
        ],
        [
            'id' => 'q10', 'section_no' => 2, 'section' => $s2, 'type' => 'yesno',
            'text' => 'Can you provide required pre-employment documents (e.g. NBI clearance, TIN, SSS) upon request?',
            'knockout' => true, 'pass_answer' => 'Yes'
        ],

        // ---- Section 3: Confidentiality acknowledgment --------------------
        [
            'id' => 'q11', 'section_no' => 3, 'section' => $s3, 'type' => 'yesno',
            'text' => 'This role involves handling sensitive client information. Do you agree to comply with confidentiality and data protection requirements?',
            'no_note' => 'Complying with confidentiality and data protection requirements is required for this role.',
            'knockout' => true, 'pass_answer' => 'Yes'
        ],

        // ---- Section 4: Comprehension -------------------------------------
        [
            'id' => 'q12', 'section_no' => 4, 'section' => $s4, 'type' => 'text',
            'text' => 'In 2-3 sentences, describe what you understand this role to involve based on the job posting.',
            'hint' => 'Write it in your own words.',
            'placeholder' => 'Type your answer here...',
            'min_chars' => 30, 'max_chars' => 500
        ],

        // ---- Section 5: Data Protection -----------------------------------
        [
            'id' => 'q13', 'section_no' => 5, 'section' => $s5, 'type' => 'consent',
            'text' => 'Please read and accept the data protection statement.',
            'body' => [
                'By submitting your application, you agree that Expertise Company will use your data by collecting, processing, storing, and using your personal information for legitimate recruitment and employment purposes only, including applicant screening, assessment, interview scheduling, and communication. Your information will be kept confidential and secure and will only be accessed by authorized personnel or disclosed when required or permitted by law.',
                'You have the right to access, correct, and request the deletion or restriction of your personal information, subject to applicable laws and regulations.',
                'In accordance with the Data Privacy Act of 2012 (Republic Act No. 10173), The Company is committed to protecting the privacy and confidentiality of your personal information. Rest assured that Expertise will not use your personal information for purposes unrelated to recruitment without obtaining the appropriate consent or having another lawful basis for such processing.',
                'To keep the screening fair and secure, we also collect technical information such as your device identifier and IP address, and how you move through the screening (for example, the time spent on each question). This information is used only to protect the integrity of the screening and is reviewed by authorized personnel.'
            ],
            'checkbox' => 'I have read and agree to the data protection statement.'
        ]
    ];
}

// ---------------------------------------------------------------------------
// KNOCK-OUT QUESTIONS (the screening rubric)
// The Admin marks a question as knock-out and sets the PASSING ANSWER (admin/add-question.php).
// An applicant whose answer does not match fails the screening (static-evaluation.php), and HR sees which
// question it was and why (hr/details.php > Questionnaires).
// Only Yes/No, Yes/No + list and rating questions can be knock-out questions.
// DATABASE (later): questions.knockout (bool), questions.pass_answer, questions.pass_follow (JSON)
// ---------------------------------------------------------------------------

// Questions saved BEFORE the knock-out setting existed: these were the "must have" questions (answer Yes to pass).
// They only count while the question has no 'knockout' value of its own; once the Admin saves it, its own value wins.
const STATIC_LEGACY_KNOCKOUT_IDS = ['q2', 'q3', 'q4', 'q8', 'q9', 'q10', 'q11'];

// The question sentence as the applicant reads it ({link} replaced by its clickable words)
function static_question_plain(array $q): string
{
    $text = (string) ($q['text'] ?? '');
    if (strpos($text, '{link}') !== false) $text = str_replace('{link}', (string) ($q['link']['text'] ?? 'link'), $text);
    return $text;
}

// The knock-out rule of a question, or null when it is not a knock-out question
//   ['pass' => 'Yes'|'No'|number, 'follow' => [choices that pass], 'max' => highest rating (scale only)]
function static_question_rule(array $q): ?array
{
    $type = (string) ($q['type'] ?? '');
    if (!in_array($type, ['yesno', 'yesno_select', 'scale'], true)) return null;

    if (array_key_exists('knockout', $q)) {
        if (empty($q['knockout'])) return null;
    } elseif ($type === 'yesno' && in_array((string) ($q['id'] ?? ''), STATIC_LEGACY_KNOCKOUT_IDS, true)) {
        return ['pass' => 'Yes', 'follow' => []];
    } else {
        return null;
    }

    if ($type === 'scale') {
        $max = max(1, (int) ($q['max'] ?? 5));
        return ['pass' => max(1, min($max, (int) ($q['pass_answer'] ?? 1))), 'follow' => [], 'max' => $max];
    }

    $follow = [];
    if ($type === 'yesno_select' && !empty($q['followup']['options'])) {
        $follow = array_values(array_intersect(array_map('strval', (array) ($q['pass_follow'] ?? [])), array_map('strval', $q['followup']['options'])));
    }
    return ['pass' => (($q['pass_answer'] ?? 'Yes') === 'No') ? 'No' : 'Yes', 'follow' => $follow];
}

// The passing answer written for people: "Yes", "At least 3 of 5", "Yes, with Your notice period: 1 to 2 weeks or Less than 1 week"
function static_knockout_expected(array $q, array $rule): string
{
    if ($q['type'] === 'scale') return 'At least ' . $rule['pass'] . ' of ' . $rule['max'];
    $text = (string) $rule['pass'];
    $when = (string) ($q['followup']['when'] ?? '');
    if ($rule['follow'] && $rule['pass'] === $when) {
        $text .= ', with ' . trim((string) ($q['followup']['label'] ?? 'the choice')) . ': ' . implode(' or ', $rule['follow']);
    }
    return $text;
}

// Checks one applicant's answer against the rule. null = not a knock-out question.
// Otherwise: id, question, expected (the passing answer), given (what the applicant answered),
//            met (true = passes, false = does not pass, null = no answer to judge), reason (why it did not pass)
function static_knockout_check(array $q, array $answers): ?array
{
    $rule = static_question_rule($q);
    if ($rule === null) return null;

    $id  = (string) $q['id'];
    $v   = $answers[$id] ?? null;
    $out = [
        'id' => $id, 'question' => static_question_plain($q),
        'expected' => static_knockout_expected($q, $rule), 'given' => 'No answer', 'met' => null, 'reason' => ''
    ];

    if ($q['type'] === 'scale') {
        if (!is_numeric($v) || (int) $v < 1) return $out;
        $n = (int) $v;
        $out['given'] = $n . ' of ' . $rule['max'];
        $out['met']   = $n >= $rule['pass'];
        if (!$out['met']) $out['reason'] = 'Rated ' . $n . ' of ' . $rule['max'] . '; the lowest passing rating is ' . $rule['pass'] . '.';
        return $out;
    }

    if ($v !== 'Yes' && $v !== 'No') return $out;
    $out['given'] = (string) $v;
    $follow = (string) ($answers[$id . ':follow'] ?? '');
    $when   = (string) ($q['followup']['when'] ?? '');
    if ($q['type'] === 'yesno_select' && !empty($q['followup']) && $v === $when && $follow !== '') {
        $out['given'] .= ', ' . trim((string) ($q['followup']['label'] ?? 'choice')) . ': ' . $follow;
    }

    if ($v !== $rule['pass']) {
        $out['met']    = false;
        $out['reason'] = 'Answered "' . $v . '"; the passing answer is "' . $rule['pass'] . '".';
    } elseif ($rule['follow'] && $v === $when && !in_array($follow, $rule['follow'], true)) {
        $out['met']    = false;
        $out['reason'] = 'Answered "' . $v . '" but chose "' . ($follow !== '' ? $follow : 'nothing') . '"; the passing choices are: ' . implode(', ', $rule['follow']) . '.';
    } else {
        $out['met'] = true;
    }
    return $out;
}

// ---------------------------------------------------------------------------
// Reference number shown on the "Screening Submitted" page, e.g. SCR-2026-00123
//   with a known applicant:  SCR-<year>-<applicant id, 5 digits>
//   without (testing):       a number made from the visit, so it stays the same on every reload
// DATABASE (later): the id of the submitted response.
// ---------------------------------------------------------------------------
function static_reference(?array $applicant = null): string
{
    $number = $applicant ? (int) $applicant['id'] : (crc32(session_id()) % 99999) + 1;
    return 'SCR-' . (new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))->format('Y') . '-' . str_pad((string) $number, 5, '0', STR_PAD_LEFT);
}

// ---------------------------------------------------------------------------
// Checks one answer (the same rules as screening-store.js). '' = fine, otherwise the message.
// ---------------------------------------------------------------------------
function static_validate_answer(array $q, array $answers): string
{
    $id = $q['id'];
    $v  = $answers[$id] ?? null;

    switch ($q['type']) {
        case 'yesno':
        case 'yesno_select':
            if ($v !== 'Yes' && $v !== 'No') return 'Please choose Yes or No.';
            if (!empty($q['followup']) && $v === $q['followup']['when']) {
                $f = $answers[$id . ':follow'] ?? '';
                if (!in_array($f, $q['followup']['options'], true)) return 'Please choose one from the list.';
            }
            return '';

        case 'scale':
            $n = (int) $v;
            return ($n >= 1 && $n <= (int) ($q['max'] ?? 5)) ? '' : 'Please choose a rating.';

        case 'date':
            $d = DateTimeImmutable::createFromFormat('Y-m-d', (string) $v);
            if (!$d || $d->format('Y-m-d') !== $v) return 'Please choose a date.';
            return $v >= (new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))->format('Y-m-d') ? '' : 'Please choose today or a later date.';

        case 'text':
            $len = mb_strlen(trim((string) $v));
            if ($len === 0) return 'Please write your answer.';
            return $len >= (int) ($q['min_chars'] ?? 1) ? '' : 'Please write a little more (2-3 sentences).';

        case 'consent':
            return $v === true ? '' : 'Please tick the box to continue.';
    }
    return '';
}

/* ==========================================================================
   ENDPOINT (only when this file is opened directly, never when it is included)
   DB-backed version of the former static endpoint. The applicant pages now POST
   to api/submit-response.php; this block remains as a direct-access fallback.
   ========================================================================== */
if (isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) !== false
    && strcasecmp(realpath($_SERVER['SCRIPT_FILENAME']), __FILE__) === 0) {

    if (session_status() === PHP_SESSION_NONE) session_start();
    header('Content-Type: application/json; charset=UTF-8');

    function applicant_helpers_respond(array $data, int $code = 200): void
    {
        http_response_code($code);
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
        exit;
    }

    if (empty($_SESSION['otp_verified'])) {
        applicant_helpers_respond(['status' => 'error', 'message' => 'Your session has ended. Please open the link in your email again.'], 403);
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        applicant_helpers_respond(['status' => 'error', 'message' => 'Invalid request.'], 405);
    }

    $json   = json_decode((string) file_get_contents('php://input'), true);
    $input  = is_array($json) ? $json : $_POST;
    $action = (string) ($input['action'] ?? '');

    $email = strtolower(trim((string) ($_SESSION['applicant_email'] ?? '')));
    $me = $email !== '' ? repo_find_applicant_by_email($email) : null;

    try {
        switch ($action) {
            case 'start':
                if ($me && (string) ($me['status'] ?? '') === 'Pending') {
                    repo_response_start((int) $me['id']);
                }
                break;

            case 'submit':
                if (!empty($_SESSION['screening_submitted'])) {
                    applicant_helpers_respond(['status' => 'error', 'message' => 'Your application was already submitted.'], 409);
                }
                $answers = is_array($input['answers'] ?? null) ? $input['answers'] : [];
                foreach (static_questionnaire() as $q) {
                    $problem = static_validate_answer($q, $answers);
                    if ($problem !== '') {
                        applicant_helpers_respond(['status' => 'error', 'message' => 'Question ' . $q['id'] . ': ' . $problem], 422);
                    }
                }
                $video = is_array($input['video'] ?? null) ? $input['video'] : [];
                if (empty($video['name']) && empty($video['url'])) {
                    applicant_helpers_respond(['status' => 'error', 'message' => 'Please add your video first.'], 422);
                }

                $timing = [];
                foreach ((array) ($input['timing'] ?? []) as $key => $ms) {
                    if (is_string($key) && preg_match('/^q\\d+$/', $key) && is_numeric($ms)) {
                        $timing[$key] = (int) min(max(0, $ms), 3600000);
                    }
                }
                $device = substr((string) preg_replace('/[^A-Za-z0-9\\-]/', '', (string) ($input['device'] ?? '')), 0, 64);
                $hash   = substr((string) preg_replace('/[^a-f0-9]/', '', strtolower((string) ($video['hash'] ?? ''))), 0, 64);
                $video['device'] = $device;
                $video['timing'] = $timing;
                if ($hash !== '') $video['hash'] = $hash;

                $reference = static_reference($me);

                if ($me) {
                    repo_response_submit((int) $me['id'], $answers, $video);
                }

                if ($me) {
                    $_SESSION['screening_submitted'] = true;
                    $_SESSION['screening_reference'] = $reference;
                }
                break;

            default:
                applicant_helpers_respond(['status' => 'error', 'message' => 'Unknown action.'], 400);
        }
        applicant_helpers_respond(['status' => 'success']);

    } catch (Throwable $e) {
        applicant_helpers_respond(['status' => 'error', 'message' => 'Something went wrong while saving. Please try again.'], 500);
    }
}
