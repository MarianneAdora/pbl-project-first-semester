<?php
session_start();

// Applicant name: session first, then ?name= in the URL (handy for testing), then a fallback
$applicantName = $_SESSION['applicant_name'] ?? ($_GET['name'] ?? 'Applicant');
$applicantName = htmlspecialchars(trim($applicantName), ENT_QUOTES, 'UTF-8');

// Where the button goes
$nextPage = 'verify-otp.php';

// Help / contact link (change to your real contact page or mailto)
$contactLink = 'mailto:hr@expertise.example';

$steps = [
    [
        'title' => 'Questionnaire',
        'desc'  => 'Answer the screening questions.',
        'icon'  => '<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="5" y="4" width="14" height="17" rx="2.5"/><path d="M9 4V3h6v1M9 4h6"/></svg>',
    ],
    [
        'title' => 'Video Response',
        'desc'  => 'Record or upload your response.',
        'icon'  => '<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="6" width="13" height="12" rx="2.5"/><path d="M16 10.5l5-3v9l-5-3z"/></svg>',
    ],
    [
        'title' => 'Automated Screening',
        'desc'  => 'Your response will be evaluated.',
        'icon'  => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 3h8l5 5v13H6z"/><path d="M14 3v5h5"/></svg>',
    ],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Applicant Screening | Expertise</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&family=Inter:wght@500;600&display=swap" rel="stylesheet">

    <link rel="stylesheet" href="../assets/css/screening-invitation.css">
</head>
<body>

<main class="invite">
    <div class="invite__content">

        <img class="invite__logo" src="../assets/images/logo.png" alt="Expertise">

        <section class="invite__intro">
            <h1 class="invite__title">Applicant Screening</h1>
            <p class="invite__greeting">Hello, <strong><?= $applicantName ?></strong>!</p>
            <p class="invite__text">You have been invited to complete the screening process for your application.</p>
        </section>

        <section class="requirements">
            <h2 class="requirements__title">Screening Requirements</h2>
            <ul class="requirements__list">
                <?php foreach ($steps as $step): ?>
                    <li class="requirement">
                        <span class="requirement__icon"><?= $step['icon'] ?></span>
                        <div>
                            <h3 class="requirement__name"><?= $step['title'] ?></h3>
                            <p class="requirement__desc"><?= $step['desc'] ?></p>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ul>
        </section>

        <a href="<?= $nextPage ?>" class="invite__btn" id="startBtn">
            <span class="invite__btn-label">View Screening Process</span>
            <span class="invite__btn-arrow" aria-hidden="true">&rarr;</span>
        </a>

        <p class="invite__help">Need help? <a href="<?= $contactLink ?>">Contact Us</a></p>

    </div>
</main>

<script src="../assets/js/screening-invitation.js"></script>
</body>
</html>
