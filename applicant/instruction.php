<?php
session_start();
require_once __DIR__ . '/../src/Helpers/security.php';
$csrf = csrf_token();

// ---- Settings ---------------------------------------------------------------
$nextPage      = '../privacy.php';
$estimatedTime = '15–20 minutes';

// Already sent the screening (checked in the database, not just the session): no second try.
require_once __DIR__ . '/../src/Repositories/ApplicantRepository.php';
try {
    if (repo_has_submitted((int) ($_SESSION['applicant_id'] ?? 0))) {
        $_SESSION['screening_submitted'] = true;
        header('Location: application-status.php');
        exit;
    }
} catch (Throwable $e) {
    error_log('already-submitted guard error: ' . $e->getMessage());
}

// Stepper: 0 = Verification, 1 = Instructions, 2 = Questionnaires, 3 = Video, 4 = Submit
$currentStep = 1;
$stepLabels  = ['Verification', 'Instructions', 'Questionnaires', 'Video', 'Submit'];

// What the applicant will do
$requirements = [
    [
        'title' => 'Questionnaire',
        'desc'  => 'Answer the set of questions about your experience and skills.',
        'icon'  => '<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="5" y="4" width="14" height="17" rx="2.5"/><path d="M9 4V3h6v1M9 4h6"/></svg>',
    ],
    [
        'title' => 'Video Response',
        'desc'  => 'Upload your required video response.',
        'icon'  => '<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="6" width="13" height="12" rx="2.5"/><path d="M16 10.5l5-3v9l-5-3z"/></svg>',
    ],
];

// Important reminders
$reminders = [
    'Answer all questions honestly.',
    'Prepare yourself for the video response, wear appropriately.',
    'Make sure you have a stable internet connection.',
];
?>
<!DOCTYPE html>
<html lang="en" data-page="instruction">
<head>
    <meta charset="UTF-8">
    <meta name="csrf-token" content="<?= attr($csrf) ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Before You Begin | Expertise</title>

    <!-- Runs before first paint: marks JS as on (the entrance animation needs it) -->
    <script>
        document.documentElement.classList.add('js');
        // safety: if the scripts fail to load, never leave the page invisible
        setTimeout(function () { document.documentElement.classList.add('is-ready'); }, 3000);
    </script>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@500;600&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">

    <!-- applicant.css = shared applicant styles + every applicant page (this page is picked by data-page="instruction" on <html>) -->
    <link rel="stylesheet" href="../assets/css/applicant.css?v=<?= @filemtime(__DIR__ . '/../assets/css/applicant.css') ?>">
    <style>
        .privacy-modal { width:min(960px, calc(100vw - 32px)); height:min(90vh, 900px); max-width:none; max-height:none; padding:0; border:0; border-radius:14px; box-shadow:0 24px 80px #1117; }
        .privacy-modal::backdrop { background:rgba(15, 20, 32, .62); }
        .privacy-modal__head { height:54px; display:flex; align-items:center; justify-content:space-between; padding:0 18px; background:#fff; border-bottom:1px solid #e4e4e8; font:600 16px/1.2 "Plus Jakarta Sans","Segoe UI",sans-serif; }
        .privacy-modal__close { width:34px; height:34px; border:0; border-radius:50%; background:#f1f1f3; color:#222; font-size:24px; cursor:pointer; }
        .privacy-modal iframe { display:block; width:100%; height:calc(100% - 54px); border:0; background:#f8f8f9; }
    </style>
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

        <div class="main">

            <section class="intro">
                <h1 class="intro__title reveal" style="--i:6">Before You Begin</h1>
                <p class="intro__text reveal" style="--i:7">Please complete the following screening process:</p>
            </section>

            <section class="requirements">
                <?php foreach ($requirements as $n => $req): ?>
                    <div class="requirement reveal reveal-left" style="--i:<?= 8 + $n ?>">
                        <span class="requirement__icon"><?= $req['icon'] ?></span>
                        <div>
                            <h2 class="requirement__name"><?= htmlspecialchars($req['title']) ?></h2>
                            <p class="requirement__desc"><?= htmlspecialchars($req['desc']) ?></p>
                        </div>
                    </div>
                <?php endforeach; ?>
            </section>

            <section class="reminders reveal" style="--i:10">
                <h2 class="reminders__title">Important Reminders:</h2>
                <ul class="reminders__list">
                    <?php foreach ($reminders as $n => $reminder): ?>
                        <li class="reveal reveal-left" style="--i:<?= 11.5 + $n * 0.9 ?>"><?= htmlspecialchars($reminder) ?></li>
                    <?php endforeach; ?>
                </ul>
            </section>

            <div class="start">
                <p class="start__time reveal" style="--i:14.5">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 7v5.5l3.5 2"/></svg>
                    Estimated Time: <?= htmlspecialchars($estimatedTime) ?>
                </p>

                <a href="<?= $nextPage ?>" class="start__btn reveal" id="beginBtn" style="--i:15.5">
                    <span class="start__btn-label">Begin Screening</span>
                    <span class="start__btn-arrow" aria-hidden="true">&rarr;</span>
                </a>
            </div>

        </div>
    </div>
</main>

<dialog class="privacy-modal" id="privacyModal" aria-label="Privacy policy">
    <div class="privacy-modal__head">
        <span>Privacy Policy</span>
        <button type="button" class="privacy-modal__close" id="closePrivacy" aria-label="Close privacy policy">&times;</button>
    </div>
    <iframe id="privacyFrame" title="Privacy policy and consent"></iframe>
</dialog>

<script src="../assets/js/applicant.js?v=<?= @filemtime(__DIR__ . '/../assets/js/applicant.js') ?>"></script>
</body>
</html>