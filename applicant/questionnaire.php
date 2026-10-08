<?php
session_start();

// ---- Settings ---------------------------------------------------------------
// Only an applicant who passed the email check may be here. Set this to false to design or test
// this page on its own, without going through opening.php and verify-otp.php first.
$requireVerification = true;      // TODO: keep true in production

$nextPage = 'upload-video.php';   // the video step
$backPage = 'instruction.php';    // "Previous" on the first question

// No caching, so the Back button never shows an old copy of the page
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');

if ($requireVerification) {
    if (empty($_SESSION['otp_verified']))         { header('Location: opening.php');            exit; }
    if (!empty($_SESSION['screening_submitted'])) { header('Location: application-status.php'); exit; }   // already sent: no second try
}

// ---- Questions --------------------------------------------------------------
// The questions are edited by HR, so they live in applicant/static-applicant.php for now (one place, like the
// applicants in hr/static-data.php). BACKEND: replace static_questionnaire() with a query on the questions table.
require_once __DIR__ . '/static-applicant.php';
$questions = static_questionnaire();

// What the scripts need (printed as JSON below)
$screening = [
    'questions' => $questions,
    'endpoint'  => 'static-applicant.php',        // sample server: moves the applicant to "In Progress" in the HR lists
    'session'   => substr(sha1(session_id()), 0, 16),   // lets the browser forget old answers when a new applicant starts
    'live'      => $requireVerification,
    'nextPage'  => $nextPage,
    'backPage'  => $backPage
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Questionnaire | Expertise</title>

    <!-- Runs before first paint: marks JS as on (the entrance animation needs it) -->
    <script>
        document.documentElement.classList.add('js');
        // safety: if the scripts fail to load, never leave the page invisible
        setTimeout(function () { document.documentElement.classList.add('is-ready'); }, 3000);
    </script>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@500;600&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">

    <link rel="stylesheet" href="../assets/css/applicant-common.css?v=<?= @filemtime(__DIR__ . '/../assets/css/applicant-common.css') ?>">
    <link rel="stylesheet" href="../assets/css/questionnaire.css?v=<?= @filemtime(__DIR__ . '/../assets/css/questionnaire.css') ?>">
</head>
<body>

<main class="page stage">

    <header class="topbar">
        <img class="corner-logo reveal" style="--i:0" src="../pic/expertise.png" alt="Expertise">

        <!-- "3 of 13" -->
        <p class="counter reveal reveal-right" style="--i:1" id="counter" aria-live="polite">
            <span class="counter__now" id="counterNow">1</span> of <span id="counterTotal"><?= count($questions) ?></span>
        </p>
    </header>

    <form class="qcard reveal reveal-pop" style="--i:2" id="qForm" novalidate>

        <!-- Progress bar: fills as the applicant moves through the questions -->
        <div class="progress" id="progress" role="progressbar" aria-label="Questionnaire progress" aria-valuemin="0" aria-valuemax="<?= count($questions) ?>" aria-valuenow="1">
            <span class="progress__fill" id="progressFill"></span>
        </div>

        <!-- The current question is drawn here by questionnaire.js -->
        <div class="qstage" id="qStage"></div>

        <!-- One message at a time; the space is always reserved so nothing jumps -->
        <div class="qerror-slot">
            <div class="q-error" id="qError" role="alert" hidden>
                <svg class="q-error__icon" viewBox="0 0 24 24" aria-hidden="true">
                    <circle cx="12" cy="12" r="10" fill="currentColor"/>
                    <path d="M12 7v6M12 16.5v.5" stroke="#fff" stroke-width="2.2" stroke-linecap="round"/>
                </svg>
                <span id="qErrorText"></span>
            </div>
        </div>

        <div class="qnav">
            <button type="button" class="qbtn qbtn--prev" id="prevBtn">
                <span class="qbtn__arrow" aria-hidden="true">&larr;</span>
                <span class="qbtn__label">Previous</span>
            </button>
            <button type="submit" class="qbtn qbtn--next" id="nextBtn">
                <span class="qbtn__label">Next</span>
                <span class="qbtn__arrow" aria-hidden="true">&rarr;</span>
            </button>
        </div>
    </form>

</main>

<script>
    window.SCREENING = <?= json_encode($screening, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>;
</script>
<script src="../assets/js/applicant-common.js?v=<?= @filemtime(__DIR__ . '/../assets/js/applicant-common.js') ?>"></script>
<script src="../assets/js/screening-store.js?v=<?= @filemtime(__DIR__ . '/../assets/js/screening-store.js') ?>"></script>
<script src="../assets/js/questionnaire.js?v=<?= @filemtime(__DIR__ . '/../assets/js/questionnaire.js') ?>"></script>
</body>
</html>