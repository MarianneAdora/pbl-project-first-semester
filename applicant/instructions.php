<?php
session_start();

// ---- Settings ---------------------------------------------------------------
$nextPage      = 'questionnaire.php';
$estimatedTime = '15–20 minutes';

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
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Before You Begin | Expertise</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@500;600&family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">

    <link rel="stylesheet" href="../assets/css/instructions.css">
</head>
<body>

<main class="page">
    <div class="wrap">

        <!-- Progress stepper -->
        <ol class="stepper" aria-label="Screening progress">
            <?php foreach ($stepLabels as $i => $label):
                $state = $i < $currentStep ? 'is-done' : ($i === $currentStep ? 'is-current' : 'is-upcoming');
            ?>
                <li class="step <?= $state ?>" <?= $i === $currentStep ? 'aria-current="step"' : '' ?>>
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
                <h1 class="intro__title">Before You Begin</h1>
                <p class="intro__text">Please complete the following screening process:</p>
            </section>

            <section class="requirements">
                <?php foreach ($requirements as $req): ?>
                    <div class="requirement">
                        <span class="requirement__icon"><?= $req['icon'] ?></span>
                        <div>
                            <h2 class="requirement__name"><?= htmlspecialchars($req['title']) ?></h2>
                            <p class="requirement__desc"><?= htmlspecialchars($req['desc']) ?></p>
                        </div>
                    </div>
                <?php endforeach; ?>
            </section>

            <section class="reminders">
                <h2 class="reminders__title">Important Reminders:</h2>
                <ul class="reminders__list">
                    <?php foreach ($reminders as $reminder): ?>
                        <li><?= htmlspecialchars($reminder) ?></li>
                    <?php endforeach; ?>
                </ul>
            </section>

            <div class="start">
                <p class="start__time">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 7v5.5l3.5 2"/></svg>
                    Estimated Time: <?= htmlspecialchars($estimatedTime) ?>
                </p>

                <a href="<?= $nextPage ?>" class="start__btn" id="beginBtn">
                    <span class="start__btn-label">Begin Screening</span>
                    <span class="start__btn-arrow" aria-hidden="true">&rarr;</span>
                </a>
            </div>

        </div>
    </div>
</main>

<script src="../assets/js/instructions.js"></script>
</body>
</html>
