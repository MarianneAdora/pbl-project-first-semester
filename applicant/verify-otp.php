<?php
session_start();
require_once __DIR__ . '/../src/Helpers/security.php';
$csrf = csrf_token();

// ---- Settings ---------------------------------------------------------------
$devMode     = false;   // true = shows the OTP on screen so you can test without email
$otpLifetime = 300;     // seconds the code stays valid (5 minutes)
$resendBase   = 30;     // seconds the applicant waits before the 1st resend
$resendStep   = 10;     // +10 seconds for every further resend (30, 40, 50, 60 ...)
$resendMax    = 10;     // resends allowed per window = the "attempts" shown on the page; after this a popup appears
$resendWindow = 3600;   // 1 hour: the resend counter renews after this
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
require_once __DIR__ . '/../src/Repositories/ApplicantRepository.php';

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
$verificationUnavailable = $applicantId > 0 && !$otpDb;
if ($applicantId > 0 && $email === '') {
    $otpDbError = 'Your email address is missing. Please reopen your invite link.';
}

/** Log an OTP request timestamp to otp_request_log (best-effort). */
$logOtpRequest = function () use ($applicantId): void {
    try {
        $pdo = db();
        $pdo->prepare(
            "INSERT INTO otp_request_log (applicant_id, requested_at) VALUES (?, NOW())"
        )->execute([$applicantId]);
    } catch (Throwable $e) {
        error_log('otp_request_log insert error: ' . $e->getMessage());
    }
};

/** Send a fresh code through the DB (hash stored, email via Brevo). Returns error message or ''. */
$issueDbOtp = function () use ($otpDb, $applicantId, $email, $applicantName, $logOtpRequest): string {
    try {
        $ok = $otpDb->generateAndSendOtp($applicantId, $email, $applicantName !== '' ? $applicantName : $email);
        if ($ok) $logOtpRequest();
        return $ok ? '' : 'We could not send the code. Please try again.';
    } catch (Throwable $e) {
        error_log('verify-otp send error: ' . $e->getMessage());
        return 'We could not send the code. Please try again.';
    }
};

/** Issue a session-backed code only after the email provider accepts it. */
$issueSessionOtp = function () use ($email, $applicantName, $otpLifetime): string {
    if ($email === '') {
        return 'Your email address is missing. Please reopen your invite link.';
    }

    $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    try {
        $sent = (new EmailService())->sendOtpEmail($email, $applicantName !== '' ? $applicantName : $email, $code);
    } catch (Throwable $e) {
        error_log('verify-otp session email error: ' . $e->getMessage());
        $sent = false;
    }
    if (!$sent) {
        return 'We could not send the code. Please try again.';
    }

    $_SESSION['otp'] = $code;
    $_SESSION['otp_expires'] = time() + $otpLifetime;
    $_SESSION['otp_sent_at'] = time();
    $_SESSION['resend_log'][] = time();
    return '';
};

/** Timestamps of every code sent in the last hour (the first one is the initial send). */
$getRequestStamps = function () use ($otpDb, $applicantId, $resendWindow): array {
    if ($otpDb) {
        try {
            $st = db()->prepare(
                "SELECT EXTRACT(EPOCH FROM (NOW() - requested_at))::int AS age_seconds FROM otp_request_log
                 WHERE applicant_id = ? AND requested_at > (NOW() - INTERVAL '{$resendWindow} seconds')"
            );
            $st->execute([$applicantId]);
            $now = time();
            return array_map(fn($age) => $now - (int) $age, $st->fetchAll(PDO::FETCH_COLUMN));
        } catch (Throwable $e) {
            error_log('otp_request_log read error: ' . $e->getMessage());
            return [];
        }
    }
    return array_values(array_filter($_SESSION['resend_log'] ?? [], fn($t) => $t > time() - $resendWindow));
};

/** Works out the current resend state: ['blocked' => bool, 'wait' => seconds]. */
$resendState = function (array $stamps) use ($resendBase, $resendStep, $resendMax, $resendWindow): array {
    if (!$stamps) return ['blocked' => false, 'wait' => 0];
    $now     = time();
    $resends = count($stamps) - 1;          // minus the initial send
    $last    = max($stamps);
    if ($resends >= $resendMax) {           // limit reached -> renewed 1 hour after the last send
        return ['blocked' => true, 'wait' => max(0, $last + $resendWindow - $now)];
    }
    $required = $resendBase + $resendStep * $resends;   // 30, 40, 50, 60 ...
    return ['blocked' => false, 'wait' => max(0, $required - ($now - $last))];
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
} elseif ($verificationUnavailable) {
    $locked = false;
    $attemptsLeft = $maxAttempts;
} else {
    if (!empty($_SESSION['otp_locked_until']) && time() >= $_SESSION['otp_locked_until']) {
        unset($_SESSION['otp_locked_until'], $_SESSION['otp'], $_SESSION['otp_expires'], $_SESSION['otp_sent_at']);
        $_SESSION['otp_attempts'] = 0;
    }
    $locked = !empty($_SESSION['otp_locked_until']) && time() < $_SESSION['otp_locked_until'];
    if (!$locked) {
        if (!isset($_SESSION['otp'], $_SESSION['otp_expires'])) {
            $sendError = $issueSessionOtp();
            if ($sendError !== '') $otpDbError = $sendError;
        }
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' && isset($_SESSION['otp_expires']) && time() > $_SESSION['otp_expires']) {
            $sendError = $issueSessionOtp();
            if ($sendError !== '') $otpDbError = $sendError;
        }
    }
    $attemptsLeft = max(0, $maxAttempts - (int) $_SESSION['otp_attempts']);
}

// ---- Handle form ------------------------------------------------------------
$error  = $otpDbError;
$notice = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$locked && !$verificationUnavailable) { // a locked page ignores every submit
    if (isset($_POST['resend'])) {
        $rs = $resendState($getRequestStamps());
        if ($rs['blocked']) {
            $error = 'You have reached the maximum number of resends. Please try again in '
                   . max(1, (int) ceil($rs['wait'] / 60)) . ' minute(s).';
        } elseif ($rs['wait'] > 0) {
            $error = 'Please wait ' . $rs['wait'] . ' second' . ($rs['wait'] === 1 ? '' : 's') . ' before asking for a new code.';
        } elseif ($otpDb) {
            $sendError = $issueDbOtp();            // this also logs the request
            if ($sendError !== '') $error = $sendError;
            else $notice = 'A new code has been sent to your email.';
        } else {
            $sendError = $issueSessionOtp();
            if ($sendError !== '') $error = $sendError;
            else $notice = 'A new code has been sent to your email.';
        }
    } else {
        $digits = array_filter((array) ($_POST['otp'] ?? []), 'is_string');
        $code   = preg_replace('/\D/', '', implode('', $digits));

        if (strlen($code) !== 6) {
            $error = 'Please enter the complete 6-digit code.';
        } elseif ($otpDb) {
            $check = $otpDb->verifyOtp($applicantId, $code, $maxAttempts, $lockSeconds);
            if ($check['ok']) {
                // The applicant has proven they own this email: only NOW do they enter the system
                // (Screening List, Dashboard counts, Recent Applicants all filter on verified_at).
                try {
                    $pdo = db();
                    repo_mark_verified($applicantId, $pdo);
                } catch (Throwable $e) {
                    error_log('verify-otp mark verified error: ' . $e->getMessage());
                    $error = 'We could not finish verifying your account. Please try again.';
                    $check['ok'] = false;
                }
            }
            if ($check['ok']) {
                $_SESSION['otp_verified'] = true;
                $dest = $nextPage;
                try {
                    // Already sent their screening? Straight to the status page, never the questionnaire.
                    if (repo_has_submitted($applicantId)) {
                        $_SESSION['screening_submitted'] = true;
                        $dest = 'application-status.php';
                    }
                } catch (Throwable $e) {
                    error_log('verify-otp submitted check error: ' . $e->getMessage());
                }
                header('Location: ' . $dest);
                exit;
            }
            if ($check['error'] !== '') $error = $check['error'];
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
            unset($_SESSION['otp'], $_SESSION['otp_expires'], $_SESSION['otp_sent_at'], $_SESSION['otp_attempts'], $_SESSION['otp_locked_until'], $_SESSION['resend_log']);
            header('Location: ' . $nextPage);
            exit;
        }
    }
}

if ($otpDb) {
    $row = $otpDb->latestOtp($applicantId);
    $expTs = $row ? strtotime((string) $row['expires_at']) : false;
    $remaining = (!$locked && $expTs) ? max(0, $expTs - time()) : 0;
    if ($row) $attemptsLeft = max(0, $maxAttempts - (int) $row['attempt_count']);
    $lockLeft = ($locked && $row && !empty($row['locked_until'])) ? max(0, strtotime((string) $row['locked_until']) - time()) : 0;
} else {
    $remaining = ($locked || !isset($_SESSION['otp_expires'])) ? 0 : max(0, $_SESSION['otp_expires'] - time());
    $lockLeft  = $locked ? max(0, $_SESSION['otp_locked_until'] - time()) : 0;
}

// ---- Resend cooldown (30s, 40s, 50s ... max 10 resends, renews after 1 hour) ----
$stamps         = $getRequestStamps();
$rs             = $resendState($stamps);
$resendsUsed    = max(0, count($stamps) - 1);                 // every click on "Resend Code" uses one attempt (the first send is free)
$attemptsLeft   = max(0, $resendMax - $resendsUsed);
$resendBlocked  = $rs['blocked'];
$cooldown       = $locked ? 0 : $rs['wait'];
$showLimitPopup = $resendBlocked && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['resend']);
$blockMinutes   = max(1, (int) ceil($rs['wait'] / 60));

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
    <meta name="csrf-token" content="<?= attr($csrf) ?>">
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
                            <?= $verificationUnavailable ? 'disabled' : '' ?>
                            <?= $i === 0 ? 'autocomplete="one-time-code"' : '' ?>
                        >
                    </span>
                <?php endfor; ?>
            </div>

            <button type="submit" class="otp__submit reveal" id="verifyBtn" style="--i:8.8" <?= $verificationUnavailable ? 'disabled' : '' ?>>
                <span class="otp__submit-label">Verify OTP</span>
                <span class="otp__submit-arrow" aria-hidden="true">&rarr;</span>
            </button>

            <div class="resend reveal" style="--i:9.8">
                <button type="submit" name="resend" value="1" class="resend__btn" id="resendBtn" data-cooldown="<?= (int) $cooldown ?>" <?= $cooldown > 0 || $verificationUnavailable ? 'disabled' : '' ?>>Resend Code</button>
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
                <?= (int) $attemptsLeft ?> of <?= (int) $resendMax ?> attempt<?= $resendMax === 1 ? '' : 's' ?> left
            </p>

        </form>

        <?php if ($showLimitPopup): ?>
        <div id="limitModal" role="dialog" aria-modal="true"
             style="position:fixed;inset:0;background:rgba(0,0,0,.5);display:flex;align-items:center;justify-content:center;z-index:9999;">
            <div style="background:#fff;padding:28px 32px;border-radius:14px;max-width:380px;text-align:center;font-family:inherit;">
                <h3 style="margin:0 0 8px;color:#e11d34;">Resend Limit Reached</h3>
                <p style="margin:0 0 18px;color:#444;">
                    You&rsquo;ve used all <?= (int) $resendMax ?> resends. You can request a new code again in
                    about <?= $blockMinutes ?> minute<?= $blockMinutes === 1 ? '' : 's' ?>.
                </p>
                <button type="button" onclick="document.getElementById('limitModal').remove()"
                        style="background:#e11d34;color:#fff;border:0;border-radius:8px;padding:10px 28px;font-weight:600;cursor:pointer;">OK</button>
            </div>
        </div>
        <?php endif; ?>

        <?php endif; ?>
    </main>
</div>

<script src="../assets/js/applicant.js?v=<?= @filemtime(__DIR__ . '/../assets/js/applicant.js') ?>"></script>
</body>
</html>