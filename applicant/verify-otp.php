<?php
session_start();

// ---- Settings ---------------------------------------------------------------
$devMode     = true;    // true = shows the OTP on screen so you can test without email. TODO: set to false in production
$otpLifetime = 300;    // seconds the code stays valid (5 minutes)
$resendWait  = 60;     // seconds the applicant must wait before asking for another code (1 minute)
$nextPage    = 'instruction.php';
$backPage    = 'opening.php';

// ---- Applicant email --------------------------------------------------------
$email = $_SESSION['applicant_email'] ?? ($_GET['email'] ?? '');
$email = filter_var(trim($email), FILTER_VALIDATE_EMAIL) ? trim($email) : '';
$emailDisplay = $email !== '' ? htmlspecialchars($email, ENT_QUOTES, 'UTF-8') : 'your email address';

// ---- OTP helpers ------------------------------------------------------------
function issueOtp(int $lifetime): void
{
    $_SESSION['otp']         = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $_SESSION['otp_expires'] = time() + $lifetime;
    $_SESSION['otp_sent_at'] = time();          // the resend wait starts from here
    // TODO: send $_SESSION['otp'] to the applicant's email here (PHPMailer, etc.)
}

if (!isset($_SESSION['otp'], $_SESSION['otp_expires'])) {
    issueOtp($otpLifetime);
}

// Opening the page again after the old code ran out: start fresh instead of showing "00:00 expired"
if ($_SERVER['REQUEST_METHOD'] !== 'POST' && time() > $_SESSION['otp_expires']) {
    issueOtp($otpLifetime);
}

// ---- Handle form ------------------------------------------------------------
$error  = '';
$notice = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['resend'])) {
        // the wait is checked here too, so it cannot be skipped by editing the page
        $wait = ($_SESSION['otp_sent_at'] ?? 0) + $resendWait - time();
        if ($wait > 0) {
            $error = 'Please wait ' . $wait . ' second' . ($wait === 1 ? '' : 's') . ' before asking for a new code.';
        } else {
            issueOtp($otpLifetime);
            $notice = 'A new code has been sent to your email.';
        }
    } else {
        $digits = array_filter((array) ($_POST['otp'] ?? []), 'is_string');
        $code   = preg_replace('/\D/', '', implode('', $digits));

        if (strlen($code) !== 6) {
            $error = 'Please enter the complete 6-digit code.';
        } elseif (time() > $_SESSION['otp_expires'] || !hash_equals($_SESSION['otp'], $code)) {
            $error = 'Incorrect or expired code. Please try again.';
        } else {
            $_SESSION['otp_verified'] = true;
            unset($_SESSION['otp'], $_SESSION['otp_expires'], $_SESSION['otp_sent_at']);
            header('Location: ' . $nextPage);
            exit;
        }
    }
}

$remaining = max(0, $_SESSION['otp_expires'] - time());
$timerText = sprintf('%02d:%02d', intdiv($remaining, 60), $remaining % 60);
$cooldown  = max(0, ($_SESSION['otp_sent_at'] ?? 0) + $resendWait - time());   // seconds until "Resend Code" works again

// ---- Logo: project/pic/expertise.png (same logo as the login page) ---------
$logoSrc = is_file(__DIR__ . '/../pic/expertise.png') ? '../pic/expertise.png' : null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verify Your Email | Expertise</title>

    <!-- Runs before first paint: marks JS as on (the entrance animation needs it) -->
    <script>
        document.documentElement.classList.add('js');
        // safety: if the scripts fail to load, never leave the page invisible
        setTimeout(function () { document.documentElement.classList.add('is-ready'); }, 3000);
    </script>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@500;600&family=Playfair+Display:wght@500&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">

    <link rel="stylesheet" href="../assets/css/applicant-common.css?v=<?= @filemtime(__DIR__ . '/../assets/css/applicant-common.css') ?>">
    <link rel="stylesheet" href="../assets/css/verify-otp.css?v=<?= @filemtime(__DIR__ . '/../assets/css/verify-otp.css') ?>">
</head>
<body>

<div class="page stage">

    <header class="topbar">
        <?php if ($logoSrc): ?>
            <img class="corner-logo reveal" style="--i:0" src="<?= $logoSrc ?>" alt="Expertise">
        <?php else: ?>
            <span class="wordmark reveal" style="--i:0">Expertise</span>
        <?php endif; ?>

        <a href="<?= $backPage ?>" class="topbar__back reveal reveal-right" style="--i:1" data-leave>
            <span aria-hidden="true">&larr;</span> Back
        </a>
    </header>

    <main class="verify">

        <div class="verify__icon reveal reveal-pop" style="--i:2" aria-hidden="true">
            <svg viewBox="0 0 24 24"><rect x="3" y="5" width="18" height="14" rx="2.5"/><path d="M3.5 7l8.5 6.5L20.5 7"/></svg>
        </div>

        <h1 class="verify__title reveal" style="--i:3.5">Verify Your Email</h1>
        <p class="verify__text reveal" style="--i:4.5">
            We&rsquo;ve sent a 6-digit code to<br>
            <strong class="verify__email"><?= $emailDisplay ?></strong>
        </p>

        <form class="otp" id="otpForm" method="post" action="" autocomplete="off" novalidate>

            <div class="otp__boxes" id="otpBoxes">
                <?php for ($i = 0; $i < 6; $i++): ?>
                    <span class="otp__cell reveal" style="--i:<?= 6 + $i * 0.4 ?>; --n:<?= $i ?>">
                        <input
                            class="otp__box"
                            type="text"
                            name="otp[]"
                            inputmode="numeric"
                            pattern="[0-9]*"
                            maxlength="1"
                            aria-label="Digit <?= $i + 1 ?>"
                            <?= $i === 0 ? 'autocomplete="one-time-code"' : '' ?>
                        >
                    </span>
                <?php endfor; ?>
            </div>

            <button type="submit" class="otp__submit reveal" id="verifyBtn" style="--i:8.8">
                <span class="otp__submit-label">Verify OTP</span>
                <span class="otp__submit-arrow" aria-hidden="true">&rarr;</span>
            </button>

            <div class="resend reveal" style="--i:9.8">
                <button type="submit" name="resend" value="1" class="resend__btn" id="resendBtn" data-cooldown="<?= (int) $cooldown ?>" <?= $cooldown > 0 ? 'disabled' : '' ?>>Resend Code</button>
                <p class="resend__timer">
                    <span id="timer" data-remaining="<?= (int) $remaining ?>"><?= $timerText ?></span>
                    <span id="timerSuffix"><?= $remaining > 0 ? 'remaining' : 'expired' ?></span>
                </p>
            </div>

            <div class="banner" id="errorBanner" role="alert" <?= $error ? '' : 'hidden' ?>>
                <svg class="banner__icon" viewBox="0 0 24 24" aria-hidden="true">
                    <circle cx="12" cy="12" r="10" fill="currentColor"/>
                    <path d="M12 7v6M12 16.5v.5" stroke="#fff" stroke-width="2.2" stroke-linecap="round"/>
                </svg>
                <span id="errorText"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></span>
            </div>

            <?php if ($notice): ?>
                <div class="banner banner--ok" id="noticeBanner" role="status"><?= htmlspecialchars($notice, ENT_QUOTES, 'UTF-8') ?></div>
            <?php endif; ?>

            <?php if ($devMode): ?>
                <p class="dev-hint">DEV MODE &mdash; current code: <strong><?= htmlspecialchars($_SESSION['otp']) ?></strong></p>
            <?php endif; ?>

        </form>
    </main>
</div>

<script src="../assets/js/applicant-common.js?v=<?= @filemtime(__DIR__ . '/../assets/js/applicant-common.js') ?>"></script>
<script src="../assets/js/verify-otp.js?v=<?= @filemtime(__DIR__ . '/../assets/js/verify-otp.js') ?>"></script>
</body>
</html>