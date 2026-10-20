<?php
session_start();

// ---- Settings ---------------------------------------------------------------
$devMode     = false;   // true = shows the OTP on screen so you can test without email
$otpLifetime = 300;     // seconds the code stays valid (5 minutes)
$resendWait  = 60;      // seconds the applicant must wait before asking for another code (1 minute)
$maxAttempts = 3;       // wrong codes allowed (the usual OTP limit). Reaching it locks the page
$lockSeconds = 900;     // how long the page stays locked after the last wrong attempt (15 minutes)
$nextPage    = 'instruction.php';
$backPage    = 'opening.php';

// ---- Applicant identity -----------------------------------------------------
// Set by opening.php?token=... : applicant_id drives the otp table, email gets the code.
$applicantId = (int) ($_SESSION['applicant_id'] ?? 0);
$applicantName = (string) ($_SESSION['applicant_name'] ?? '');

// ---- Applicant email --------------------------------------------------------
$email = $_SESSION['applicant_email'] ?? ($_GET['email'] ?? '');
$email = filter_var(trim($email), FILTER_VALIDATE_EMAIL) ? trim($email) : '';
$emailDisplay = $email !== '' ? htmlspecialchars($email, ENT_QUOTES, 'UTF-8') : 'your email address';

// ---- OTP backend (database via OtpService, session fallback for testing) ----
require_once __DIR__ . '/../src/Helpers/db.php';
require_once __DIR__ . '/../src/Services/OtpService.php';

$otpDb = null;          // OtpService instance when the DB + applicant are available
$otpDbError = '';
if ($applicantId > 0 && $email !== '') {
    try {
        $otpDb = new OtpService(db());
    } catch (Throwable $e) {
        error_log('verify-otp OtpService init error: ' . $e->getMessage());
        $otpDb = null;
        $otpDbError = 'Could not reach the verification service. Please try again.';
    }
}

/** Send a fresh code through the DB (hash stored, email via Brevo). Returns error message or ''. */
$issueDbOtp = function () use ($otpDb, $applicantId, $email, $applicantName): string {
    try {
        $ok = $otpDb->generateAndSendOtp($applicantId, $email, $applicantName !== '' ? $applicantName : $email);
        return $ok ? '' : 'We could not send the code. Please try again.';
    } catch (Throwable $e) {
        error_log('verify-otp send error: ' . $e->getMessage());
        return 'We could not send the code. Please try again.';
    }
};

// ---- Attempt limit ----------------------------------------------------------
// DB path: attempt_count + locked_until live in the otp table (OtpService).
// Session fallback (no applicant_id, e.g. ?email= testing): the old session logic.
$_SESSION['otp_attempts'] = $_SESSION['otp_attempts'] ?? 0;
$attemptsLeft = $maxAttempts;

if ($otpDb) {
    $row = $otpDb->latestOtp($applicantId);
    $now = time();
    $lockTs = ($row && !empty($row['locked_until'])) ? strtotime((string) $row['locked_until']) : false;
    $locked = (bool) ($lockTs && $now < $lockTs);
    if (!$locked && (!$row || $_SERVER['REQUEST_METHOD'] !== 'POST')) {
        $expTs = $row ? strtotime((string) $row['expires_at']) : false;
        if (!$row || $expTs === false || $now > $expTs) {
            $sendError = $issueDbOtp();
            if ($sendError !== '') $otpDbError = $sendError;
            $row = $otpDb->latestOtp($applicantId);
        }
    }
    if ($row) $attemptsLeft = max(0, $maxAttempts - (int) $row['attempt_count']);
} else {
    if (!empty($_SESSION['otp_locked_until']) && time() >= $_SESSION['otp_locked_until']) {
        unset($_SESSION['otp_locked_until'], $_SESSION['otp'], $_SESSION['otp_expires'], $_SESSION['otp_sent_at']);
        $_SESSION['otp_attempts'] = 0;
    }
    $locked = !empty($_SESSION['otp_locked_until']) && time() < $_SESSION['otp_locked_until'];
    if (!$locked) {
        if (!isset($_SESSION['otp'], $_SESSION['otp_expires'])) {
            $_SESSION['otp'] = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            $_SESSION['otp_expires'] = time() + $otpLifetime;
            $_SESSION['otp_sent_at'] = time();
        }
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' && time() > $_SESSION['otp_expires']) {
            $_SESSION['otp'] = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            $_SESSION['otp_expires'] = time() + $otpLifetime;
            $_SESSION['otp_sent_at'] = time();
        }
    }
    $attemptsLeft = max(0, $maxAttempts - (int) $_SESSION['otp_attempts']);
}

// ---- Handle form ------------------------------------------------------------
$error  = $otpDbError;
$notice = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$locked) {      // a locked page ignores every submit
    if (isset($_POST['resend'])) {
        if ($otpDb) {
            $wait = $otpDb->resendWaitLeft($applicantId, $resendWait);
            if ($wait > 0) {
                $error = 'Please wait ' . $wait . ' second' . ($wait === 1 ? '' : 's') . ' before asking for a new code.';
            } else {
                $sendError = $issueDbOtp();
                if ($sendError !== '') $error = $sendError;
                else $notice = 'A new code has been sent to your email.';
            }
        } else {
            $wait = ($_SESSION['otp_sent_at'] ?? 0) + $resendWait - time();
            if ($wait > 0) {
                $error = 'Please wait ' . $wait . ' second' . ($wait === 1 ? '' : 's') . ' before asking for a new code.';
            } else {
                $_SESSION['otp'] = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
                $_SESSION['otp_expires'] = time() + $otpLifetime;
                $_SESSION['otp_sent_at'] = time();
                $notice = 'A new code has been sent to your email.';
            }
        }
    } else {
        $digits = array_filter((array) ($_POST['otp'] ?? []), 'is_string');
        $code   = preg_replace('/\D/', '', implode('', $digits));

        if (strlen($code) !== 6) {
            $error = 'Please enter the complete 6-digit code.';
        } elseif ($otpDb) {
            $check = $otpDb->verifyOtp($applicantId, $code, $maxAttempts, $lockSeconds);
            if ($check['ok']) {
                $_SESSION['otp_verified'] = true;
                header('Location: ' . $nextPage);
                exit;
            }
            $error = $check['error'];
            $attemptsLeft = $check['attempts_left'];
            if (!empty($check['locked_until'])) {
                $locked = true;
                $_SESSION['otp_was_locked'] = true;
            } else {
                $_SESSION['otp_failed_total'] = (int) ($_SESSION['otp_failed_total'] ?? 0) + 1;
            }
        } elseif (time() > $_SESSION['otp_expires']) {
            $error = 'This code has expired. Please request a new one.';
        } elseif (!hash_equals((string) ($_SESSION['otp'] ?? ''), $code)) {
            $_SESSION['otp_attempts']++;
            $_SESSION['otp_failed_total'] = (int) ($_SESSION['otp_failed_total'] ?? 0) + 1;
            $left = $maxAttempts - $_SESSION['otp_attempts'];
            if ($left <= 0) {
                $_SESSION['otp_locked_until'] = time() + $lockSeconds;
                $_SESSION['otp_was_locked']   = true;
                unset($_SESSION['otp'], $_SESSION['otp_expires'], $_SESSION['otp_sent_at']);
                $locked = true;
            } elseif ($left === 1) {
                $error = 'Incorrect code. You have 1 attempt left.';
            } else {
                $error = 'Incorrect code. You have ' . $left . ' attempts left.';
            }
            $attemptsLeft = max(0, $left);
        } else {
            $_SESSION['otp_verified'] = true;
            unset($_SESSION['otp'], $_SESSION['otp_expires'], $_SESSION['otp_sent_at'], $_SESSION['otp_attempts'], $_SESSION['otp_locked_until']);
            header('Location: ' . $nextPage);
            exit;
        }
    }
}

if ($otpDb) {
    $row = $otpDb->latestOtp($applicantId);
    $expTs = $row ? strtotime((string) $row['expires_at']) : false;
    $remaining = (!$locked && $expTs) ? max(0, $expTs - time()) : 0;
    $cooldown = $locked ? 0 : $otpDb->resendWaitLeft($applicantId, $resendWait);
    if ($row) $attemptsLeft = max(0, $maxAttempts - (int) $row['attempt_count']);
    $lockLeft = ($locked && $row && !empty($row['locked_until'])) ? max(0, strtotime((string) $row['locked_until']) - time()) : 0;
} else {
    $remaining = ($locked || !isset($_SESSION['otp_expires'])) ? 0 : max(0, $_SESSION['otp_expires'] - time());
    $cooldown  = max(0, ($_SESSION['otp_sent_at'] ?? 0) + $resendWait - time());
    $lockLeft  = $locked ? max(0, $_SESSION['otp_locked_until'] - time()) : 0;
}
$timerText = sprintf('%02d:%02d', intdiv($remaining, 60), $remaining % 60);
$lockText     = sprintf('%02d:%02d', intdiv($lockLeft, 60), $lockLeft % 60);
$contactLink  = 'mailto:hr@expertise.example';                                    // same address as opening.php

// ---- Logo: project/assets/images/expertise.png (same logo as the login page) ---------
$logoSrc = is_file(__DIR__ . '/../assets/images/expertise.png') ? '../assets/images/expertise.png' : null;
?>
<!DOCTYPE html>
<html lang="en" data-page="verify-otp">
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

    <!-- applicant.css = shared applicant styles + every applicant page (this page is picked by data-page="verify-otp" on <html>) -->
    <link rel="stylesheet" href="../assets/css/applicant.css?v=<?= @filemtime(__DIR__ . '/../assets/css/applicant.css') ?>">
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

        <?php if ($locked): ?>

        <h1 class="verify__title reveal" style="--i:3.5">Too Many Attempts</h1>
        <p class="verify__text reveal" style="--i:4.5">
            You entered a wrong code <?= (int) $maxAttempts ?> times, so verification is locked<br>
            to keep <strong class="verify__email"><?= $emailDisplay ?></strong> safe.
        </p>

        <div class="locked reveal" style="--i:6" role="alert">
            <p class="locked__label">You can try again in</p>
            <p class="locked__time"><span id="lockTimer" data-remaining="<?= (int) $lockLeft ?>"><?= $lockText ?></span></p>
            <p class="locked__hint">A new code will be sent to you when the time is up.</p>
            <a class="locked__help" href="<?= $contactLink ?>">Need help? Contact HR</a>
        </div>

        <?php else: ?>

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

            <p class="attempts reveal<?= $attemptsLeft === 1 ? ' attempts--last' : '' ?>" id="attempts" style="--i:10.6">
                <?= (int) $attemptsLeft ?> of <?= (int) $maxAttempts ?> attempt<?= $maxAttempts === 1 ? '' : 's' ?> left
            </p>

        </form>

        <?php endif; ?>
    </main>
</div>

<script src="../assets/js/applicant.js?v=<?= @filemtime(__DIR__ . '/../assets/js/applicant.js') ?>"></script>
</body>
</html>