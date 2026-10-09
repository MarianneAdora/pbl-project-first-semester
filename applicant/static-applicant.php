<?php
/* ==========================================================================
   STATIC APPLICANT DATA: everything the applicant side needs, in ONE file.
   Location: applicant/static-applicant.php  (next to the applicant pages)
   Temporary, like hr/static-data.php (which it uses for the applicant list). It is deleted
   when the database is connected.

   Who takes from this file
     applicant/questionnaire.php   static_questionnaire()   the 13 screening questions
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
   Link inside a question: put {link} in "text" and give  'link' => ['text' => ..., 'url' => ...]
     (the link is drawn in the sentence, opens in a new tab)

   DATABASE (later)
     static_questionnaire()  ->  SELECT * FROM questions ORDER BY section_no, position
                                 (followup / options / body / link as JSON columns)
     static_video_rules()    ->  SELECT ... FROM settings
     "start" / "submit"      ->  api/submit-response.php answers the same two POST requests
   ========================================================================== */

require_once __DIR__ . '/../hr/static-data.php';

// ---------------------------------------------------------------------------
// The job description link in question 1.
// PLACEHOLDER: a sample PDF. Replace the url with the company's real job description.
// ---------------------------------------------------------------------------
const STATIC_JOB_DESCRIPTION_URL = 'https://www.w3.org/WAI/ER/tests/xhtml/testfiles/resources/pdf/dummy.pdf';

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
function static_questionnaire(): array
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
            'text' => 'Are you willing to work on the schedule as advertised?'
        ],
        [
            'id' => 'q3', 'section_no' => 1, 'section' => $s1, 'type' => 'yesno',
            'text' => 'Are you willing to work on-site in BGC, Taguig?'
        ],
        [
            'id' => 'q4', 'section_no' => 1, 'section' => $s1, 'type' => 'yesno',
            'text' => 'Are you willing to go through the assessment and interviews?'
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
            'no_note' => 'A stable connection and a working device are needed for the assessment and the virtual steps.'
        ],
        [
            'id' => 'q9', 'section_no' => 2, 'section' => $s2, 'type' => 'yesno',
            'text' => 'Are you willing to undergo a background check as part of the hiring process?'
        ],
        [
            'id' => 'q10', 'section_no' => 2, 'section' => $s2, 'type' => 'yesno',
            'text' => 'Can you provide required pre-employment documents (e.g. NBI clearance, TIN, SSS) upon request?'
        ],

        // ---- Section 3: Confidentiality acknowledgment --------------------
        [
            'id' => 'q11', 'section_no' => 3, 'section' => $s3, 'type' => 'yesno',
            'text' => 'This role involves handling sensitive client information. Do you agree to comply with confidentiality and data protection requirements?',
            'no_note' => 'Complying with confidentiality and data protection requirements is required for this role.'
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
                'In accordance with the Data Privacy Act of 2012 (Republic Act No. 10173), The Company is committed to protecting the privacy and confidentiality of your personal information. Rest assured that Expertise will not use your personal information for purposes unrelated to recruitment without obtaining the appropriate consent or having another lawful basis for such processing.'
            ],
            'checkbox' => 'I have read and agree to the data protection statement.'
        ]
    ];
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
    return 'SCR-' . static_now()->format('Y') . '-' . str_pad((string) $number, 5, '0', STR_PAD_LEFT);
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
            return $v >= static_now()->format('Y-m-d') ? '' : 'Please choose today or a later date.';

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
   The applicant pages POST here (the applicant has passed the email check, there is no HR login):
       start    the applicant opened the questionnaire       -> status "In Progress"
       submit   answers + video info                         -> status "Completed" (no result yet:
                                                                 Passed / Failed comes from the scoring)
   Response (JSON):  { status: 'success' }   or   { status: 'error', message: '...' }
   (after "submit" the reference number is kept in $_SESSION['screening_reference'])
   ========================================================================== */
if (isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) !== false
    && strcasecmp(realpath($_SERVER['SCRIPT_FILENAME']), __FILE__) === 0) {   // strcasecmp: Windows paths

    if (session_status() === PHP_SESSION_NONE) session_start();
    header('Content-Type: application/json; charset=UTF-8');

    function static_applicant_respond(array $data, int $code = 200): void
    {
        http_response_code($code);
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
        exit;
    }

    if (empty($_SESSION['otp_verified'])) {
        static_applicant_respond(['status' => 'error', 'message' => 'Your session has ended. Please open the link in your email again.'], 403);
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        static_applicant_respond(['status' => 'error', 'message' => 'Invalid request.'], 405);
    }

    $json  = json_decode((string) file_get_contents('php://input'), true);
    $input = is_array($json) ? $json : $_POST;
    $action = (string) ($input['action'] ?? '');

    // Which applicant? The one whose email was used for the link / OTP (opening.php).
    $email = strtolower(trim((string) ($_SESSION['applicant_email'] ?? '')));
    $me = null;
    if ($email !== '') {
        foreach (static_applicants() as $a) {
            if (strtolower($a['email']) === $email) { $me = $a; break; }
        }
    }

    try {
        switch ($action) {
            case 'start':
                // only a Pending applicant moves to In Progress (never backwards)
                if ($me && $me['status'] === 'Pending') static_set_status((int) $me['id'], 'In Progress');
                break;

            case 'submit':
                if (!empty($_SESSION['screening_submitted'])) {
                    static_applicant_respond(['status' => 'error', 'message' => 'Your application was already submitted.'], 409);
                }
                $answers = is_array($input['answers'] ?? null) ? $input['answers'] : [];
                foreach (static_questionnaire() as $q) {
                    $problem = static_validate_answer($q, $answers);
                    if ($problem !== '') {
                        static_applicant_respond(['status' => 'error', 'message' => 'Question ' . $q['id'] . ': ' . $problem], 422);
                    }
                }
                $video = is_array($input['video'] ?? null) ? $input['video'] : [];
                if (empty($video['name'])) {
                    static_applicant_respond(['status' => 'error', 'message' => 'Please add your video first.'], 422);
                }

                // keep what was sent, like a "responses" table (HR review reads it later)
                $reference = static_reference($me);
                static_store_update(function (array &$store) use ($me, $email, $answers, $video, $reference) {
                    $store['submissions'][] = [
                        'reference'    => $reference,
                        'applicant_id' => $me['id'] ?? null,
                        'email'        => $email,
                        'answers'      => $answers,
                        'video'        => [
                            'name'    => (string) ($video['name'] ?? ''),
                            'size'    => (int) ($video['size'] ?? 0),
                            'seconds' => isset($video['seconds']) ? round((float) $video['seconds'], 1) : null,
                            'source'  => (string) ($video['source'] ?? '')
                        ],
                        'submitted_at' => static_now()->format('Y-m-d H:i')
                    ];
                });
                if ($me) static_set_status((int) $me['id'], 'Completed');
                $_SESSION['screening_submitted'] = true;
                $_SESSION['screening_reference'] = $reference;
                break;

            default:
                static_applicant_respond(['status' => 'error', 'message' => 'Unknown action.'], 400);
        }
        static_applicant_respond(['status' => 'success']);

    } catch (Throwable $e) {
        static_applicant_respond(['status' => 'error', 'message' => 'Something went wrong while saving. Please try again.'], 500);
    }
}