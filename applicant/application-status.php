<?php
session_start();

// ---- Settings ---------------------------------------------------------------
// Same rule as the other applicant pages: only a verified applicant, and only after sending.
$requireVerification = true;      // TODO: keep true in production

// FRONT-END TESTING ONLY (no backend / HR side yet). With $devMode on you can open:
//     application-status.php?demo=passed | failed | review     show that result instead of the evaluated one
//     application-status.php?hr=approve | suggest | pending     what HR "answered" to the interview the applicant sent
// TODO: set $devMode to false once the real evaluation and the HR side exist.
$devMode  = true;

$homePage = 'opening.php';        // where "Back Home" goes
$type     = 'On Site Interview';  // the interview type (BACKEND: comes from the job / HR)

// Interview time slots the applicant can choose from (BACKEND: HR's available slots)
$slots = ['09:00 AM', '10:00 AM', '11:00 AM', '01:00 PM', '02:00 PM', '03:00 PM', '04:00 PM'];

// Tracker while the evaluation runs: 0 = Submitted, 1 = Processing, 2 = Completed
$stepLabels = ['Submitted', 'Processing', 'Completed'];

// No caching, so the Back button never shows an old copy of the page
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');

if ($requireVerification) {
    if (empty($_SESSION['otp_verified']))         { header('Location: opening.php');      exit; }
    if (empty($_SESSION['screening_submitted']))  { header('Location: questionnaire.php'); exit; }   // nothing sent yet
}

require_once __DIR__ . '/../src/Repositories/ApplicantRepository.php';

require_once __DIR__ . '/../src/Helpers/display.php';

// Interview functions now live in ScheduleRepository (DB-backed).
require_once __DIR__ . '/../src/Repositories/ScheduleRepository.php';

// Applicant-side static helpers (static_reference, etc.) live in applicant/static-applicant.php.
require_once __DIR__ . '/applicant-helpers.php';

// Risk thresholds (loading_ms for the evaluation animation) live in applicant/static-evaluation.php.
require_once __DIR__ . '/evaluation-helpers.php';

// Email service for sending applicant notifications (rejection, etc.)
require_once __DIR__ . '/../src/Services/EmailService.php';

// ---- The evaluation result (read from live DB, set by RiskScoringService after submission) ---
// The applicant.result column holds 'accepted' (Passed), 'rejected' (Rejected), or NULL (review pending).
$applicantRowForResult = repo_find_applicant((int) ($_SESSION['applicant_id'] ?? 0));
$dbResult  = (string) ($applicantRowForResult['result'] ?? '');
$dbStatus  = (string) ($applicantRowForResult['status'] ?? '');

if (in_array(strtolower($dbResult), ['accepted', 'passed'], true)) {
    $outcome = 'passed';
} elseif (in_array(strtolower($dbResult), ['rejected', 'failed'], true)) {
    $outcome = 'failed';
} else {
    // No result yet — screening is under review
    $outcome = 'review';
}

$result = [
    'outcome' => $outcome,
    'band'    => '',
    'points'  => 0,
    'factors' => [],
    'knockouts' => [],
    'unmet'   => [],
];

if ($devMode && in_array($_GET['demo'] ?? '', ['passed', 'failed', 'review'], true)) {
    $outcome = $_GET['demo'];
    $result['outcome'] = $outcome;
}
// Applicant-facing flow is pass/fail only: a "review" (high-risk) outcome is
// surfaced to the applicant as not passed; HR still reviews the record.
if ($outcome === 'review') {
    $outcome = 'failed';
    $result['outcome'] = 'failed';
}

// ---- Send rejection email (once per session) --------------------------------
if ($outcome === 'failed' && empty($_SESSION['rejection_email_sent'])) {
    $toEmail   = (string) ($_SESSION['applicant_email'] ?? '');
    $fullName  = (string) ($_SESSION['applicant_name'] ?? '');
    $firstName = trim(explode(' ', trim($fullName))[0] ?? $fullName);
    if ($toEmail !== '' && $firstName !== '') {
        $emailService = new EmailService();
        $emailService->sendScreeningRejectedEmail($toEmail, $firstName);
        $_SESSION['rejection_email_sent'] = true;
    }
}



// ---- Interview scheduling ---------------------------------------------------
// The applicant sends a date + time; HR confirms it or suggests another one.
//   $_SESSION['interview'] = ['date' => 'Y-m-d', 'time' => '10:00 AM', 'status' => 'pending' | 'confirmed' | 'suggested',
//                             'new_date' => 'Y-m-d', 'new_time' => '02:00 PM']       (new_* only when suggested)
// BACKEND: an "interviews" table; HR's confirm / suggest buttons change the status.
$tz       = new DateTimeZone('Asia/Manila');
$today    = (new DateTimeImmutable('today', $tz));
$minDate  = $today->modify('+1 day');
$maxDate  = $today->modify('+60 days');

// CONNECTED TO HR: the applicant record (found by the verified email) and the shared interview row that
// HR's Interview Schedule tab reads and answers (hr/data.php -> static_interview_*).
$applicantRow = repo_find_applicant((int) ($_SESSION['applicant_id'] ?? 0));
$storedIv = null;
if ($applicantRow) {
    try {
        $storedIv = repo_schedule_find((int) $applicantRow['id']);
    } catch (Throwable $e) {
        error_log('application-status schedule DB error: ' . $e->getMessage());
    }
}

function interview_json(array $data, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $json  = json_decode((string) file_get_contents('php://input'), true);
    $input = is_array($json) ? $json : $_POST;

    if ($outcome !== 'passed') {
        interview_json(['status' => 'error', 'message' => 'Interview scheduling is not open for this result.'], 403);
    }
    if (!empty($_SESSION['interview']) || $storedIv) {
        interview_json(['status' => 'error', 'message' => 'Your interview schedule was already submitted.'], 409);
    }

    $date = DateTimeImmutable::createFromFormat('Y-m-d', (string) ($input['date'] ?? ''), $tz);
    $time = (string) ($input['time'] ?? '');

    if (!$date || $date->format('Y-m-d') !== (string) $input['date']) {
        interview_json(['status' => 'error', 'message' => 'Please choose a preferred date.'], 422);
    }
    $date = $date->setTime(0, 0);
    if ($date < $minDate || $date > $maxDate) {
        interview_json(['status' => 'error', 'message' => 'Please choose a date from tomorrow up to 60 days ahead.'], 422);
    }
    if ((int) $date->format('N') > 5) {
        interview_json(['status' => 'error', 'message' => 'Interviews are held Monday to Friday. Please choose a weekday.'], 422);
    }
    if (!in_array($time, $slots, true)) {
        interview_json(['status' => 'error', 'message' => 'Please choose an available time.'], 422);
    }

    // HR sees this request in the Interview Schedule tab (status: awaiting)
    if ($applicantRow) {
        try {
            repo_schedule_request((int) $applicantRow['id'], $date->format('Y-m-d'), (string) repo_schedule_time24($time), 'applicant');
        } catch (Throwable $e) {
            error_log('application-status schedule DB error: ' . $e->getMessage());
        }
    }
    $_SESSION['interview'] = ['date' => $date->format('Y-m-d'), 'time' => $time, 'status' => 'pending'];
    interview_json(['status' => 'success']);
}

// The stored row is the truth: whatever HR answered (confirmed / new schedule) shows up here on the next load.
if ($applicantRow) {
    $row = null;
    try {
        $row = repo_schedule_find((int) $applicantRow['id']);
    } catch (Throwable $e) {
        error_log('application-status schedule DB error: ' . $e->getMessage());
    }
    if ($row) {
        $_SESSION['interview'] = repo_schedule_session_shape($row);
    } elseif (!empty($_SESSION['interview']) && is_array($_SESSION['interview'])) {
        // sent before this page was connected to HR: hand it over now
        $old = $_SESSION['interview'];
        $t24 = repo_schedule_time24((string) ($old['time'] ?? ''));
        if ($t24 && !empty($old['date'])) {
            try {
                repo_schedule_request((int) $applicantRow['id'], (string) $old['date'], $t24);
            } catch (Throwable $e) {
                error_log('application-status schedule DB error: ' . $e->getMessage());
            }
        }
    }
}

$interview = isset($_SESSION['interview']) && is_array($_SESSION['interview']) ? $_SESSION['interview'] : null;
$ivSig     = $interview ? md5(json_encode($interview)) : '';

// The page asks this every few seconds while it waits for HR (see application-status.js)
if (($_GET['status'] ?? '') === '1') {
    interview_json(['status' => 'success', 'state' => $interview['status'] ?? 'none', 'sig' => $ivSig]);
}

// testing only: pretend HR answered
if ($devMode && $interview && in_array($_GET['hr'] ?? '', ['approve', 'suggest', 'pending'], true)) {
    $hr = $_GET['hr'];
    $interview['status'] = $hr === 'approve' ? 'confirmed' : ($hr === 'suggest' ? 'suggested' : 'pending');
    if ($hr === 'suggest') {
        $next = DateTimeImmutable::createFromFormat('Y-m-d', $interview['date'], $tz)->modify('+1 day');
        while ((int) $next->format('N') > 5) $next = $next->modify('+1 day');
        $interview['new_date'] = $next->format('Y-m-d');
        $interview['new_time'] = '02:00 PM';
    }
    $_SESSION['interview'] = $interview;
}

$pretty = function (string $ymd): string {
    $d = DateTimeImmutable::createFromFormat('Y-m-d', $ymd);
    return $d ? $d->format('F j, Y') : '';
};

// Reference number (made when the screening was sent, see static_reference() in static-applicant.php)
$reference = htmlspecialchars((string) ($_SESSION['screening_reference'] ?? static_reference()), ENT_QUOTES, 'UTF-8');

// The comments shown while the evaluation "runs"
$comments = [
    'Your screening is being evaluated...',
    'Reading through your questionnaire answers',
    'Checking your availability and logistics',
    'Looking at your video response',
    'Running a few security checks',
    'Almost there, putting your result together'
];

// Which view opens first
$loadingMs = (int) (static_risk_config()['loading_ms'] ?? 8000);
$seenKey = $reference . '|' . $outcome;                 // the loading page plays once per result (a refresh skips it)
$title   = $interview ? 'Interview Schedule' : 'Screening Result';

// small icons used on the cards
$iconCalendar = '<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3.5" y="5" width="17" height="15" rx="2.5"/><path d="M3.5 10h17M8 3v4M16 3v4"/><path d="M8 14h.01M12 14h.01M16 14h.01M8 17.5h.01M12 17.5h.01"/></svg>';
$iconClock    = '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 7v5.5l3.5 2"/></svg>';
$iconChat     = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 5h10a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2H9l-4 3v-3a2 2 0 0 1-1-1.7V7a2 2 0 0 1 2-2z"/><path d="M18 9h1a2 2 0 0 1 2 2v6a2 2 0 0 1-1 1.7V21l-3.5-2.5H12"/></svg>';
$iconStatus   = '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"/><circle cx="12" cy="12" r="4" class="fill"/></svg>';
// The big animated icons (same circle + tick as the HR "Invitation Sent" window, see add-applicants.php)
$bigCheck = '<svg class="big-check" viewBox="0 0 80 80" aria-hidden="true"><circle class="bc-circle" cx="40" cy="40" r="36"/><path class="bc-tick" d="M24 41l11 11 21-23"/></svg>';
$bigCross = '<svg class="big-check big-check--red" viewBox="0 0 80 80" aria-hidden="true"><circle class="bc-circle" cx="40" cy="40" r="36"/><path class="bc-tick" pathLength="60" d="M28 28l24 24"/><path class="bc-tick" pathLength="60" d="M52 28L28 52"/></svg>';
$bigWait  = '<svg class="big-check big-check--amber" viewBox="0 0 80 80" aria-hidden="true"><circle class="bc-circle" cx="40" cy="40" r="36"/><circle class="bc-glyph" cx="40" cy="40" r="17"/><path class="bc-glyph" d="M40 29v12l8 5"/></svg>';
$bigAgain = '<svg class="big-check big-check--red" viewBox="0 0 80 80" aria-hidden="true"><circle class="bc-circle" cx="40" cy="40" r="36"/><path class="bc-glyph" d="M23 40a17 17 0 1 0 5-12"/><path class="bc-glyph" d="M22 22v9h9"/><path class="bc-glyph" d="M40 31v10l7 4"/></svg>';
$bigLoad  = '<svg class="eval-spin" viewBox="0 0 80 80" aria-hidden="true"><circle class="es-track" cx="40" cy="40" r="36"/><circle class="es-arc" cx="40" cy="40" r="36"/><g class="es-doc"><path d="M30 24h15l7 7v25H30z"/><path d="M45 24v8h7"/><path class="es-l es-l1" d="M35 41h12"/><path class="es-l es-l2" d="M35 47h12"/><path class="es-l es-l3" d="M35 53h7"/></g></svg>';
$iconChevron  = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 9l6 6 6-6"/></svg>';
?>
<!DOCTYPE html>
<html lang="en" data-page="application-status">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($title) ?> | Expertise</title>

    <!-- Runs before first paint: marks JS as on (the entrance animation needs it) -->
    <script>
        document.documentElement.classList.add('js');
        // safety: if the scripts fail to load, never leave the page invisible
        setTimeout(function () { document.documentElement.classList.add('is-ready'); }, 3000);
        // the evaluation page already played for this result: go straight to the result (no flash of the loading page)
        try {
            if (<?= $interview ? 'true' : 'false' ?> || sessionStorage.getItem('expertise.status.seen') === <?= json_encode($seenKey) ?>) {
                document.documentElement.classList.add('eval-done');
            }
        } catch (e) { <?= $interview ? '' : '/* storage blocked: the loading page plays every time */' ?> }
    </script>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@500;600&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">

    <!-- applicant.css = shared applicant styles + every applicant page (this page is picked by data-page="application-status" on <html>) -->
    <link rel="stylesheet" href="../assets/css/applicant.css?v=<?= @filemtime(__DIR__ . '/../assets/css/applicant.css') ?>">
</head>
<body>

<main class="page stage">

    <img class="corner-logo reveal" style="--i:0" src="../assets/images/expertise.png" alt="Expertise">

<?php if ($interview): ?>
    <?php
        $state   = $interview['status'];
        $isNew   = $state === 'suggested';
        $showDate = $isNew ? ($interview['new_date'] ?? $interview['date']) : $interview['date'];
        $showTime = $isNew ? ($interview['new_time'] ?? $interview['time']) : $interview['time'];
    ?>
    <!-- ===== The interview the applicant sent: waiting for HR / confirmed / HR suggested another time ===== -->
    <section class="status view view--interview" aria-live="polite" data-state="<?= htmlspecialchars($state) ?>" data-sig="<?= $ivSig ?>">

        <?php if ($isNew): ?>
            <?= $bigAgain ?>
            <h1 class="status__title reveal" style="--i:2">New Interview Schedule</h1>
            <p class="status__text reveal" style="--i:3">Your HR representative has suggested a new interview schedule.</p>
        <?php else: ?>
            <?= $bigCheck ?>
            <h1 class="status__title reveal" style="--i:2"><?= $state === 'confirmed' ? 'Interview Confirmed' : 'Interview Schedule' ?></h1>
            <p class="status__text reveal" style="--i:3">
                <?= $state === 'confirmed' ? 'HR has confirmed your interview schedule. We look forward to meeting you.' : 'Your interview schedule has been submitted successfully.' ?>
            </p>
        <?php endif; ?>

        <div class="panel panel--details reveal" style="--i:4">
            <?php if ($isNew): ?><h2 class="panel__title">Updated Interview Schedule</h2><?php endif; ?>
            <ul class="details">
                <li class="detail"><span class="detail__icon"><?= $iconCalendar ?></span><span class="detail__label">Date</span><span class="detail__value"><?= htmlspecialchars($pretty($showDate)) ?></span></li>
                <li class="detail"><span class="detail__icon"><?= $iconClock ?></span><span class="detail__label">Time</span><span class="detail__value"><?= htmlspecialchars($showTime) ?></span></li>
                <li class="detail"><span class="detail__icon"><?= $iconChat ?></span><span class="detail__label">Interview Type</span><span class="detail__value"><?= htmlspecialchars($type) ?></span></li>
                <?php if (!$isNew): ?>
                    <li class="detail"><span class="detail__icon"><?= $iconStatus ?></span><span class="detail__label">Status</span>
                        <span class="detail__value"><?= $state === 'confirmed' ? 'Confirmed' : 'Awaiting HR Confirmation' ?></span></li>
                <?php endif; ?>
            </ul>
            <?php if ($state === 'pending'): ?>
                <p class="panel__text panel__text--muted live-note"><span class="live-dot" aria-hidden="true"></span>This page updates by itself when HR responds.</p>
            <?php endif; ?>
        </div>

        <a class="btn btn--primary btn--wide reveal" style="--i:5" href="<?= htmlspecialchars($homePage) ?>" data-leave>Back Home</a>
    </section>

<?php else: ?>

    <!-- ===== 1. The evaluation is running (comments change while it "loads") ===== -->
    <section class="status view view--loading" id="evalView"
             data-duration="<?= $loadingMs ?>" data-seen="<?= htmlspecialchars($seenKey, ENT_QUOTES, 'UTF-8') ?>"
             data-comments="<?= htmlspecialchars(json_encode($comments), ENT_QUOTES, 'UTF-8') ?>" aria-live="polite">

        <?= $bigLoad ?>

        <h1 class="status__title reveal" style="--i:2">Evaluating Your Screening</h1>
        <p class="eval__line reveal" style="--i:3" id="evalLine"><?= htmlspecialchars($comments[0]) ?></p>

        <div class="eval__bar reveal" style="--i:3.6" role="progressbar" aria-label="Evaluation progress" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0" id="evalBar"><span></span></div>

        <!-- Tracker: Submitted > Processing > Completed -->
        <ol class="stepper" aria-label="Screening progress" id="evalSteps">
            <?php foreach ($stepLabels as $i => $label):
                $stepState = $i < 1 ? 'is-done' : ($i === 1 ? 'is-current' : 'is-upcoming');
            ?>
                <li class="step reveal reveal-fade <?= $stepState ?>" style="--i:<?= 4 + $i * 1.2 ?>" <?= $i === 1 ? 'aria-current="step"' : '' ?>>
                    <span class="step__dot">
                        <?php if ($stepState === 'is-done'): ?>
                            <svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true"><path d="M5 12.5l4.5 4.5L19 7.5"/></svg>
                        <?php else: ?>
                            <span class="step__pip"></span>
                        <?php endif; ?>
                    </span>
                    <span class="step__label"><?= htmlspecialchars($label) ?></span>
                </li>
            <?php endforeach; ?>
        </ol>
    </section>

    <?php if ($outcome === 'passed'): ?>
    <!-- ===== 2a. Passed: the applicant picks the interview date and time ===== -->
    <section class="status view view--result">

        <?= $bigCheck ?>
        <h1 class="status__title reveal" style="--i:2">You Passed!</h1>
        <p class="status__text reveal" style="--i:3">Congratulations! You have completed the screening process.</p>

        <form class="schedule" id="scheduleForm" novalidate
              data-min="<?= $minDate->format('Y-m-d') ?>" data-max="<?= $maxDate->format('Y-m-d') ?>">

            <div class="panel reveal" style="--i:4">
                <h2 class="panel__title">Next Step: Schedule Your Interview</h2>
                <p class="panel__sub">Please select your preferred date and time for your interview.</p>

                <div class="fields">
                    <div class="field">
                        <span class="field__label" id="dateLabel">Preferred Date <span class="req" aria-hidden="true">*</span></span>
                        <div class="picker" id="datePicker">
                            <button type="button" class="picker__btn" id="dateBtn" aria-haspopup="dialog" aria-expanded="false" aria-labelledby="dateLabel dateValue">
                                <span class="picker__icon"><?= $iconCalendar ?></span>
                                <span class="picker__value is-placeholder" id="dateValue">Select a date</span>
                                <span class="picker__chevron"><?= $iconChevron ?></span>
                            </button>
                            <input type="hidden" id="prefDate" name="date">
                            <div class="picker__pop pcal" id="datePop" role="dialog" aria-label="Choose a date"></div>
                        </div>
                    </div>
                    <div class="field">
                        <span class="field__label" id="timeLabel">Available Time <span class="req" aria-hidden="true">*</span></span>
                        <div class="picker" id="timePicker">
                            <button type="button" class="picker__btn" id="timeBtn" aria-haspopup="listbox" aria-expanded="false" aria-labelledby="timeLabel timeValue">
                                <span class="picker__icon"><?= $iconClock ?></span>
                                <span class="picker__value is-placeholder" id="timeValue">Select a time</span>
                                <span class="picker__chevron"><?= $iconChevron ?></span>
                            </button>
                            <input type="hidden" id="prefTime" name="time">
                            <ul class="picker__pop picker__menu" id="timeMenu" role="listbox" aria-labelledby="timeLabel" tabindex="-1">
                                <?php foreach ($slots as $k => $slot): ?>
                                    <li class="picker__option" role="option" aria-selected="false" data-value="<?= htmlspecialchars($slot) ?>" style="--k:<?= $k ?>">
                                        <span class="picker__optlabel"><?= htmlspecialchars($slot) ?></span>
                                        <svg class="picker__check" viewBox="0 0 24 24" aria-hidden="true"><path d="M9 16.2 4.8 12l-1.4 1.4L9 19 21 7l-1.4-1.4z"/></svg>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    </div>
                </div>

                <p class="form-msg" id="schedMsg" role="alert" hidden></p>
            </div>

            <div class="panel reveal" style="--i:5">
                <h2 class="panel__title">Selected Schedule</h2>
                <ul class="details details--compact">
                    <li class="detail"><span class="detail__icon"><?= $iconCalendar ?></span><span class="detail__value" id="pickedDate">Not chosen yet</span></li>
                    <li class="detail"><span class="detail__icon"><?= $iconClock ?></span><span class="detail__value" id="pickedTime">Not chosen yet</span></li>
                </ul>
            </div>

            <button type="submit" class="btn btn--primary btn--wide reveal" style="--i:6" id="confirmBtn" disabled>
                <span id="confirmLabel">Confirm Interview Schedule</span>
            </button>
        </form>
    </section>

    <?php elseif ($outcome === 'review'): ?>
    <!-- ===== 2b. High risk band: HR looks at it first, no result yet (the system never rejects on risk alone) ===== -->
    <section class="status view view--result">
        <?= $bigWait ?>
        <h1 class="status__title reveal" style="--i:2">Screening Under Review</h1>
        <p class="status__text reveal" style="--i:3">Thank you for completing your screening. Our HR team will take a look before your result is released.</p>

        <div class="panel reveal" style="--i:4">
            <p class="panel__text">You do not need to do anything else. We will send your result to your email as soon as the review is done.</p>
        </div>
    </section>

    <?php else: ?>
    <!-- ===== 2c. Failed: a message, and that is all ===== -->
    <section class="status view view--result">
        <?= $bigCross ?>
        <h1 class="status__title reveal" style="--i:2">Screening Not Passed</h1>
        <p class="status__text reveal" style="--i:3">Thank you for your interest in joining Expertise. Unfortunately, you did not meet the requirements for this position at this time.</p>

        <div class="panel reveal" style="--i:4">
            <?php if (!empty($result['unmet']) && ($result['outcome'] ?? '') === 'failed'): ?>
                <h2 class="panel__title">Requirements not met</h2>
                <ul class="unmet">
                    <?php foreach ($result['unmet'] as $text): ?>
                        <li><?= htmlspecialchars((string) $text) ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php else: ?>
                <p class="panel__text">Based on your answers, your screening did not meet the requirements for this position.</p>
            <?php endif; ?>
            <p class="panel__text panel__text--muted">You will also receive this result by email. We wish you the best in your job search.</p>
        </div>
    </section>
    <?php endif; ?>

<?php endif; ?>

</main>

<script src="../assets/js/applicant.js?v=<?= @filemtime(__DIR__ . '/../assets/js/applicant.js') ?>"></script>
</body>
</html>