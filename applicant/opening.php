<?php
session_start();

// ---- Applicant identity -------------------------------------------------------
// The email link opens this page (opening.php?token=...). The token is looked up
// in the applicant table and these session values are set BEFORE rendering:
//     $_SESSION['applicant_id']     the applicant_id (drives OTP + submissions)
//     $_SESSION['applicant_token']  the secure_token from the link
//     $_SESSION['applicant_name']   full name (this page's greeting)
//     $_SESSION['applicant_email']  verify-otp.php reads this one
// A new token resets any previous applicant's screening state (OTP, progress).
require_once __DIR__ . '/../src/Repositories/ApplicantRepository.php';

$tokenParam = trim((string) ($_GET['token'] ?? ''));
if ($tokenParam !== '') {
    try {
        $found = repo_find_applicant_by_token($tokenParam);
    } catch (Throwable $e) {
        error_log('opening token DB error: ' . $e->getMessage());
        $found = null;
    }
    if ($found) {
        $isNewApplicant = empty($_SESSION['applicant_token'])
            || $_SESSION['applicant_token'] !== (string) ($found['secure_token'] ?? '');
        if ($isNewApplicant) {
            foreach (['otp_verified', 'otp', 'otp_expires', 'otp_sent_at', 'otp_attempts', 'otp_locked_until',
                      'otp_failed_total', 'otp_request_times', 'otp_was_locked',
                      'screening_submitted', 'screening_reference', 'screening_result', 'interview'] as $k) {
                unset($_SESSION[$k]);
            }
        }
        $_SESSION['applicant_id']    = (int) $found['id'];
        $_SESSION['applicant_token'] = (string) ($found['secure_token'] ?? '');
        $_SESSION['applicant_name']  = (string) $found['name'];
        $_SESSION['applicant_email'] = (string) $found['email'];
    }
}

// No sample data — applicant details come only from the invite email link (token) or the
// name/email query parameters submitted from the invitation page.
if ($tokenParam === '') {
    if (!empty($_GET['name']))  $_SESSION['applicant_name']  = trim((string) $_GET['name']);
    if (!empty($_GET['email'])) $_SESSION['applicant_email'] = trim((string) $_GET['email']);
}
$fullName = $_SESSION['applicant_name'] ?? '';   // empty = the page says "Applicant"

// "Hello, <full name>!"  (the wireframe shows the full name)
$fullName      = trim((string) $fullName);
$displayName   = htmlspecialchars($fullName !== '' ? $fullName : 'Applicant', ENT_QUOTES, 'UTF-8');
$estimatedTime = '15–20 minutes';

// Where the button goes (the OTP page)
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
<html lang="en" data-page="opening">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Applicant Screening | Expertise</title>

    <!-- Runs before first paint: marks JS as on (the entrance animation needs it) -->
    <script>
        document.documentElement.classList.add('js');
        // safety: if the scripts fail to load, never leave the page invisible
        setTimeout(function () { document.documentElement.classList.add('is-ready'); }, 3000);
    </script>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Inter:wght@500;600&display=swap" rel="stylesheet">

    <!-- applicant.css = shared applicant styles + every applicant page (this page is picked by data-page="opening" on <html>) -->
    <link rel="stylesheet" href="../assets/css/applicant.css?v=<?= @filemtime(__DIR__ . '/../assets/css/applicant.css') ?>">
</head>
<body>

<main class="invite stage">

    <img class="corner-logo reveal" style="--i:0" src="../assets/images/expertise.png" alt="Expertise">

    <div class="invite__content">

        <section class="invite__intro">
            <h1 class="invite__title reveal" style="--i:1">Applicant Screening</h1>
            <p class="invite__greeting reveal" style="--i:2">Hello, <strong><?= $displayName ?></strong>!</p>
            <p class="invite__text reveal" style="--i:3">You have been invited to complete the screening process for your application.</p>
        </section>

        <section class="requirements">
            <h2 class="requirements__title reveal" style="--i:4">Screening Requirements</h2>
            <ul class="requirements__list">
                <?php foreach ($steps as $n => $step): ?>
                    <li class="requirement reveal reveal-left" style="--i:<?= 5 + $n ?>">
                        <span class="requirement__icon"><?= $step['icon'] ?></span>
                        <div>
                            <h3 class="requirement__name"><?= $step['title'] ?></h3>
                            <p class="requirement__desc"><?= $step['desc'] ?></p>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ul>
        </section>

        <div class="invite__start">
            <p class="invite__time reveal" style="--i:8">
                <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 7v5.5l3.5 2"/></svg>
                Estimated Time: <?= htmlspecialchars($estimatedTime) ?>
            </p>

            <a href="<?= $nextPage ?>" class="invite__btn reveal" id="startBtn" data-leave style="--i:9">
                <span class="invite__btn-label">Start Screening</span>
                <span class="invite__btn-arrow" aria-hidden="true">&rarr;</span>
            </a>
        </div>

        <p class="invite__help reveal" style="--i:10">Need help? <a href="<?= $contactLink ?>">Contact Us</a></p>

    </div>
</main>

<script src="../assets/js/applicant.js?v=<?= @filemtime(__DIR__ . '/../assets/js/applicant.js') ?>"></script>
</body>
</html>