<?php
session_start();

// ---- Settings ---------------------------------------------------------------
// Only an applicant who passed the email check may be here. Set this to false to design or test
// this page on its own, without going through opening.php and verify-otp.php first.
$requireVerification = true;      // TODO: keep true in production

$nextPage = 'upload-video.php';   // the video step
$backPage = 'instruction.php';    // "Previous" on the first question

// Stepper (same as the other applicant pages): 0 = Verification, 1 = Instructions, 2 = Questionnaires, 3 = Video, 4 = Submit
$currentStep = 2;
$stepLabels  = ['Verification', 'Instructions', 'Questionnaires', 'Video', 'Submit'];

// No caching, so the Back button never shows an old copy of the page
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');

if ($requireVerification) {
    if (empty($_SESSION['otp_verified']))         { header('Location: opening.php');            exit; }
    if (!empty($_SESSION['screening_submitted'])) { header('Location: application-status.php'); exit; }   // already sent: no second try
}

// ---- Questions --------------------------------------------------------------
// Served from the database (question_config via AdminRepository) so Admin edits
// show up here. Falls back to the static list when the DB is unreachable.
require_once __DIR__ . '/static-applicant.php';
try {
    require_once __DIR__ . '/../src/Repositories/AdminRepository.php';
    $questions = db_questions_for_applicant();
    if (!$questions) $questions = static_questionnaire();
} catch (Throwable $e) {
    error_log('questionnaire DB error: ' . $e->getMessage());
    $questions = static_questionnaire();
}

// What the scripts need (printed as JSON below)
$screening = [
    'questions' => $questions,
    'endpoint'  => '../api/submit-response.php',
    'session'   => substr(sha1(session_id()), 0, 16),
    'live'      => $requireVerification,
    'nextPage'  => $nextPage,
    'backPage'  => $backPage
];
?>
<!DOCTYPE html>
<html lang="en" data-page="questionnaire">
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

    <!-- applicant.css = shared applicant styles + every applicant page (this page is picked by data-page="questionnaire" on <html>) -->
    <link rel="stylesheet" href="../assets/css/applicant.css?v=<?= @filemtime(__DIR__ . '/../assets/css/applicant.css') ?>">
</head>
<body>

<main class="page stage">

    <div class="wrap">

        <!-- Progress stepper: the dots pop in one by one and the lines draw between them -->
        <ol class="stepper" aria-label="Screening progress">
            <?php foreach ($stepLabels as $i => $label):
                $state = $i < $currentStep ? 'is-done' : ($i === $currentStep ? 'is-current' : 'is-upcoming');
            ?>
                <li class="step reveal reveal-fade <?= $state ?>" style="--i:<?= 1 + $i * 1.2 ?>" <?= $i === $currentStep ? 'aria-current="step"' : '' ?>>
                    <span class="step__dot">
                        <?php if ($state === 'is-done'): ?>
                            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12.5l4.5 4.5L19 7.5"/></svg>
                        <?php else: ?>
                            <span class="step__pip"></span>
                        <?php endif; ?>
                    </span>
                    <span class="step__label"><?= htmlspecialchars($label) ?></span>
                </li>
            <?php endforeach; ?>
        </ol>

        <form class="main qcard glass reveal" style="--i:5" id="qForm" novalidate>

            <!-- Progress bar + "3 of 13": fills as the applicant moves through the questions -->
            <div class="progressrow">
                <div class="progress" id="progress" role="progressbar" aria-label="Questionnaire progress" aria-valuemin="0" aria-valuemax="<?= count($questions) ?>" aria-valuenow="1">
                    <span class="progress__fill" id="progressFill"></span>
                </div>
                <p class="counter" id="counter" aria-live="polite">
                    <span class="counter__now" id="counterNow">1</span> of <span id="counterTotal"><?= count($questions) ?></span>
                </p>
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
                <button type="button" class="btn btn--ghost qbtn qbtn--prev" id="prevBtn">
                    <span class="qbtn__arrow" aria-hidden="true">&larr;</span>
                    <span class="qbtn__label">Previous</span>
                </button>
                <button type="submit" class="btn btn--primary qbtn qbtn--next" id="nextBtn">
                    <span class="qbtn__label">Next</span>
                    <span class="qbtn__arrow" aria-hidden="true">&rarr;</span>
                </button>
            </div>
        </form>

    </div>
</main>

<script>
    window.SCREENING = <?= json_encode($screening, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>;
</script>
<script src="../assets/js/applicant.js?v=<?= @filemtime(__DIR__ . '/../assets/js/applicant.js') ?>"></script>
</body>
</html>