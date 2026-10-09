<?php
session_start();

// ---- Settings ---------------------------------------------------------------
// Same rule as the other applicant pages: only a verified applicant, and only after sending.
$requireVerification = true;      // TODO: keep true in production

// Tracker: 0 = Submitted, 1 = Processing, 2 = Completed   (right after sending: Processing)
// BACKEND: set $currentStep = 2 when the scoring is finished and the result email was sent.
$currentStep = 1;
$stepLabels  = ['Submitted', 'Processing', 'Completed'];

// No caching, so the Back button never shows an old copy of the page
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');

if ($requireVerification) {
    if (empty($_SESSION['otp_verified']))         { header('Location: opening.php');      exit; }
    if (empty($_SESSION['screening_submitted']))  { header('Location: questionnaire.php'); exit; }   // nothing sent yet
}

// Reference number (made when the screening was sent, see static_reference() in static-applicant.php)
require_once __DIR__ . '/static-applicant.php';
$reference = htmlspecialchars((string) ($_SESSION['screening_reference'] ?? static_reference()), ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Screening Submitted | Expertise</title>

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
    <link rel="stylesheet" href="../assets/css/applicant-status.css?v=<?= @filemtime(__DIR__ . '/../assets/css/applicant-status.css') ?>">
</head>
<body>

<main class="page stage">

    <img class="corner-logo reveal" style="--i:0" src="../pic/expertise.png" alt="Expertise">

    <div class="status">

        <!-- The big green tick: it pops in, then draws itself -->
        <span class="badge reveal reveal-pop" style="--i:1" aria-hidden="true">
            <svg viewBox="0 0 24 24" width="64" height="64"><path d="M5 12.5l4.5 4.5L19 7.5"/></svg>
        </span>

        <h1 class="status__title reveal" style="--i:2">Screening Submitted</h1>
        <p class="status__text reveal" style="--i:3">
            Your questionnaires and video have been submitted successfully.
        </p>

        <!-- Tracker: Submitted > Processing > Completed -->
        <ol class="stepper" aria-label="Screening progress">
            <?php foreach ($stepLabels as $i => $label):
                $state = $i < $currentStep ? 'is-done' : ($i === $currentStep ? 'is-current' : 'is-upcoming');
            ?>
                <li class="step reveal reveal-fade <?= $state ?>" style="--i:<?= 4 + $i * 1.2 ?>" <?= $i === $currentStep ? 'aria-current="step"' : '' ?>>
                    <span class="step__dot">
                        <?php if ($state === 'is-done'): ?>
                            <svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true"><path d="M5 12.5l4.5 4.5L19 7.5"/></svg>
                        <?php else: ?>
                            <span class="step__pip"></span>
                        <?php endif; ?>
                    </span>
                    <span class="step__label"><?= htmlspecialchars($label) ?></span>
                </li>
            <?php endforeach; ?>
        </ol>

        <p class="reference reveal" style="--i:8">
            <span class="reference__label">Reference No:</span>
            <strong class="reference__value"><?= $reference ?></strong>
        </p>

        <p class="note reveal" style="--i:9">
            You will receive your result via email. Please check your inbox or return to this page for updates.
        </p>
    </div>
</main>

<script src="../assets/js/applicant-common.js?v=<?= @filemtime(__DIR__ . '/../assets/js/applicant-common.js') ?>"></script>
</body>
</html>