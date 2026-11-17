<?php
session_start();
require_once __DIR__ . '/../src/Helpers/security.php';
$csrf = csrf_token();
date_default_timezone_set('Asia/Manila');

header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');

if (empty($_SESSION['otp_verified'])) {
    header('Location: /applicant/opening.php');
    exit;
}
if (!empty($_SESSION['screening_submitted'])) {
    header('Location: /applicant/application-status.php');
    exit;
}

require_once __DIR__ . '/../src/Repositories/ApplicantRepository.php';
try {
    if (repo_has_submitted((int) ($_SESSION['applicant_id'] ?? 0))) {
        $_SESSION['screening_submitted'] = true;
        header('Location: /applicant/application-status.php');
        exit;
    }
} catch (Throwable $e) {
    error_log('privacy page submitted check error: ' . $e->getMessage());
}

$alreadyAccepted = !empty($_SESSION['screening_privacy_accepted_at']);

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfIn = (string) ($_POST['csrf_token'] ?? '');
    if (!csrf_verify($csrfIn)) {
        http_response_code(403);
        $error = 'Your session expired. Refresh the page and try again.';
    } elseif (($_POST['privacy_ack'] ?? '') !== '1') {
        $error = 'Please confirm that you have read and agree to the privacy policy.';
    } elseif (!csrf_consume($csrfIn)) {
        http_response_code(403);
        $error = 'Your session expired. Refresh the page and try again.';
    } else {
        if (!$alreadyAccepted) {
            $_SESSION['screening_privacy_accepted_at'] = date('Y-m-d H:i:s');
        }
        header('Location: /applicant/questionnaire.php');
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Privacy Policy | Expertise Applicant Screening</title>
    <style>
        :root { --red:#e11d38; --ink:#1c1c1f; --muted:#5b5b63; --line:#e4e4e8; --bg:#f8f8f9; }
        * { box-sizing:border-box; }
        body { margin:0; background:var(--bg); color:var(--ink); font:17px/1.65 "Segoe UI",system-ui,-apple-system,Arial,sans-serif; }
        header { border-top:6px solid var(--red); background:#fff; border-bottom:1px solid var(--line); }
        .wrap { max-width:760px; margin:0 auto; padding:0 20px; }
        header .wrap { padding-top:32px; padding-bottom:24px; }
        h1 { margin:0 0 6px; font-size:2rem; line-height:1.2; }
        .meta { margin:0; color:var(--muted); font-size:.95rem; }
        main { padding:24px 0 36px; }
        h2 { margin:28px 0 8px; font-size:1.2rem; }
        p, li { max-width:68ch; }
        ul { padding-left:1.2rem; }
        li { margin:4px 0; }
        a { color:var(--red); }
        a:focus-visible, button:focus-visible, input:focus-visible { outline:3px solid var(--ink); outline-offset:2px; }
        .box { background:#fff; border:1px solid var(--line); border-left:4px solid var(--red); padding:14px 18px; border-radius:6px; }
        .consent { margin-top:24px; padding:18px; background:#fff; border:1px solid var(--line); border-radius:8px; }
        .consent label { display:flex; gap:10px; align-items:flex-start; font-weight:600; }
        .consent input { margin-top:6px; accent-color:var(--red); }
        .error { margin:0 0 12px; color:#b00020; font-weight:600; }
        .actions { display:flex; justify-content:space-between; align-items:center; gap:16px; margin-top:18px; }
        .continue { border:0; border-radius:8px; background:var(--red); color:#fff; padding:13px 22px; font:600 16px/1.2 "Segoe UI",system-ui,sans-serif; cursor:pointer; }
        .continue:hover { background:#c4122d; }
        footer { border-top:1px solid var(--line); padding:18px 0 28px; color:var(--muted); font-size:.9rem; }
        @media (max-width:560px) { .actions { align-items:stretch; flex-direction:column-reverse; } .continue { width:100%; } }
    </style>
</head>
<body>
<header>
    <div class="wrap">
        <h1>Privacy Policy</h1>
        <p class="meta">Expertise Applicant Screening &middot; Last updated: November 17, 2026</p>
    </div>
</header>

<main class="wrap">
    <p>This policy explains what personal information we collect when you complete our online screening, why we collect it, how we protect it, and what rights you have. We follow the Philippine Data Privacy Act of 2012 (Republic Act No. 10173).</p>

    <h2>Who we are</h2>
    <p>This screening site is operated by <strong>Expertise BPO Services Manila, Inc.</strong>, located at Unit 1204, 12F Fort Legend Tower, 3rd Ave. cor. 31st St., Fort Bonifacio Global City, Taguig City. For privacy questions, contact our Data Protection Officer at <a href="mailto:ebsmi.screening@gmail.com">ebsmi.screening@gmail.com</a>.</p>

    <h2>What we collect</h2>
    <p>The screening has a verification step, a questionnaire, and a video response. Depending on the step, we collect:</p>
    <ul>
        <li><strong>Verification details:</strong> your name, email address, and contact number.</li>
        <li><strong>Questionnaire answers:</strong> your responses about your experience and skills.</li>
        <li><strong>Video response:</strong> the video you upload, which shows your face and voice.</li>
        <li><strong>Technical data:</strong> your IP address, browser type, and the time of your visit, collected automatically for security and troubleshooting.</li>
    </ul>
    <p>We never ask for passwords, bank or card details, or government ID numbers through this site.</p>

    <h2>Why we use it</h2>
    <ul>
        <li>To review your application and assess your fit for the role.</li>
        <li>To contact you about your application.</li>
        <li>To keep the site secure and prevent misuse.</li>
    </ul>
    <p>We do not sell your information or use it for advertising.</p>

    <h2>Your consent</h2>
    <p>By starting the screening, you agree to the collection and use of your information as described here. You can withdraw your consent at any time by emailing us. Withdrawing means we can no longer consider your application.</p>

    <h2>Who can see it</h2>
    <p>Only authorized members of our hiring team can view your submission. We share it with other parties only if required by law, or with service providers who host or secure this site on our behalf and are bound to protect it.</p>

    <h2>How long we keep it</h2>
    <p>We keep your application for 12 months after the hiring decision, then delete it. You can ask us to delete it sooner.</p>

    <h2>How we protect it</h2>
    <p>This site uses HTTPS, access to submissions is limited to authorized staff, and uploaded files are stored on secured servers. No system is completely secure, but we work to protect your information from unauthorized access, loss, or misuse.</p>

    <h2>Your rights</h2>
    <p>Under the Data Privacy Act, you have the right to:</p>
    <ul>
        <li>be informed about how your data is processed;</li>
        <li>access a copy of your data;</li>
        <li>correct inaccurate data;</li>
        <li>object to processing, or withdraw your consent;</li>
        <li>request deletion or blocking of your data;</li>
        <li>file a complaint with the National Privacy Commission at <a href="https://privacy.gov.ph">privacy.gov.ph</a>.</li>
    </ul>
    <p>To use any of these rights, email <a href="mailto:ebsmi.screening@gmail.com">ebsmi.screening@gmail.com</a>.</p>

    <h2>Minors</h2>
    <p>This screening is for applicants aged 18 and above. We do not knowingly collect information from anyone under 18.</p>

    <div class="box"><strong>Questions?</strong> Contact us at <a href="mailto:ebsmi.screening@gmail.com">ebsmi.screening@gmail.com</a>.</div>

    <form class="consent" method="post" target="_top">
        <input type="hidden" name="csrf_token" value="<?= attr($csrf) ?>">
        <?php if ($error !== ''): ?><p class="error" role="alert"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
        <label>
            <input type="checkbox" name="privacy_ack" value="1" required <?= $alreadyAccepted ? 'checked' : '' ?>>
            <span>I have read and agree to this privacy policy.</span>
        </label>
        <div class="actions">
            <a href="/applicant/instruction.php" target="_top">Back to instructions</a>
            <button type="submit" class="continue"><?= $alreadyAccepted ? 'Continue to Questionnaire' : 'Agree &amp; Begin Screening' ?></button>
        </div>
    </form>
</main>

<footer>
    <div class="wrap">&copy; <?= date('Y') ?> Expertise BPO Services Manila, Inc.</div>
</footer>
</body>
</html>
