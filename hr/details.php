<?php
session_start();

// Protection: Ensure only HR users can access this page
if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'hr') {
    header('Location: ../index.php');
    exit();
}

// No caching, so the Back button after logout never shows this page
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');

/* ==========================================================================
   APPLICANT DETAILS (opened from the Screening List "VIEW" button and the Dashboard rows)
   URL:  details.php?id=103          (&tab=overview | questionnaires | video | interview | evaluation)

   Every piece of data comes from hr/static-details.php (which reads the same store as the
   rest of the project: the applicant, their REAL screening answers and video, their CV).
   When the database is ready, only that file changes; this page does not.

   Which tabs are open (details_access):
     Overview              always
     Questionnaires, Video the applicant Completed the screening
     Interview Schedule    Completed AND Passed (the date + time the applicant picked on their result page)
     AI Evaluation         Completed AND evaluated: the risk scoring rubric (applicant/static-evaluation.php).
                           Passed and Failed applicants, and also one held for HR review.
   A closed tab is still shown, locked, so HR can see what will open later.
   ========================================================================== */
require_once __DIR__ . '/static-details.php';

$id        = (int) ($_GET['id'] ?? 0);
$applicant = details_find_applicant($id);          // active applicants only
if (!$applicant) {                                 // unknown, archived or deleted applicant
    header('Location: screening-list.php');
    exit();
}

$submission    = details_submission($applicant);                       // answers + video (real, or sample for the seed applicants)
$evaluation    = details_evaluation($applicant, $submission);          // the risk rubric result (applicant/static-evaluation.php)
$access        = details_access($applicant, $evaluation);
$questionnaire = ($access['questionnaires'] && $submission) ? details_questionnaire($submission, $evaluation) : null;
$interview     = $access['interview'] ? details_interview_view(details_interview($applicant, $submission)) : null;   // null = the applicant has not picked a date yet
$cv            = details_cv($applicant);
$video         = ($access['video'] && $submission) ? ($submission['video'] ?? null) : null;
$personal      = details_personal($applicant, $submission);

$tabs = [
    'overview'       => 'Overview',
    'questionnaires' => 'Questionnaires',
    'video'          => 'Video',
    'interview'      => 'Interview Schedule',
    'evaluation'     => 'AI Evaluation'
];
$tab = (string) ($_GET['tab'] ?? 'overview');
if (!isset($tabs[$tab]) || empty($access[$tab])) $tab = 'overview';

// Pills use the shared classes of hr-common.css
$pillClass = [
    'Pending' => 'status-pending', 'In Progress' => 'status-progress', 'Completed' => 'status-completed',
    'Passed'  => 'status-passed',  'Rejected' => 'status-failed'
];
$status = (string) $applicant['status'];
$result = (string) ($applicant['result'] ?? '');

// Overview > Screening Result (the risk rubric: points, band, outcome)
$resultLabel = $result;
$resultClass = $pillClass[$result] ?? 'status-default';
if ($result === '' && $evaluation && $evaluation['outcome'] === 'review') {
    $resultLabel = 'Under Review';                       // held for HR: Completed, no Passed / Rejected yet
    $resultClass = 'status-pending';
}
if ($evaluation) {
    $riskText       = $evaluation['points'] . ' of ' . $evaluation['max_points'] . ' points';
    $recommendation = $evaluation['recommendation'];
} else {
    $riskText       = '-';
    $recommendation = $status === 'Completed' ? 'Waiting for the evaluation'
                    : ($status === 'In Progress' ? 'The applicant is still answering the screening' : 'The applicant has not started the screening');
}

$h = 'details_h';

// Handed to assets/js/details.js
$pageData = [
    'id'        => $id,
    'name'      => $applicant['name'],
    'email'     => $applicant['email'],
    'endpoint'  => 'static-details.php',
    'today'     => (new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))->format('Y-m-d'),
    'slots'     => details_time_slots(),
    'tab'       => $tab,
    'locked'    => array_map('details_locked_message', array_combine(array_keys($tabs), array_keys($tabs))),
    'maxDate'   => (new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))->modify('+60 days')->format('Y-m-d'),
    'interview' => $interview,
    'lastEmail' => $interview ? details_last_email($id) : null,
    'videoUrl'  => $video['url'] ?? ''
];
?>
<!DOCTYPE html>
<html lang="en" data-page="details">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Applicant Details - Expertise Portal</title>

    <!-- Runs before first paint so the screen is already covered when arriving via login -->
    <script>
        document.documentElement.classList.add('js');
        // Saved Settings (theme + animations) are put on the page before the first paint, so it never flashes
        try {
            var s = JSON.parse(localStorage.getItem('hr_settings') || '{}'), t = s.theme || 'light';
            if (t === 'system') t = window.matchMedia && matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
            document.documentElement.setAttribute('data-theme', t === 'dark' ? 'dark' : 'light');
            if (s.animations === false) document.documentElement.setAttribute('data-motion', 'off');
        } catch (e) {}
        if (new URLSearchParams(location.search).get('transition') === 'out') {
            document.documentElement.classList.add('is-transitioning');
        }
        // Safety: if hr.js fails to load, never leave the page invisible
        setTimeout(function () {
            document.documentElement.classList.add('is-ready');
            document.documentElement.classList.remove('is-transitioning');
            var overlay = document.getElementById('pageOverlay');
            if (overlay) overlay.classList.add('sweep-out');
        }, 1000);
    </script>

    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">

    <!-- hr.css = shared hr styles + every hr page (this page is picked by data-page="details" on <html>) -->
    <link rel="stylesheet" href="../assets/css/hr.css?v=<?php echo @filemtime(__DIR__ . '/../assets/css/hr.css'); ?>">

</head>
<body>

    <!-- 8-Stripe Transition Overlay (login arrival + logout only) -->
    <div class="page-overlay" id="pageOverlay">
        <div class="overlay-stripe stripe-1"></div>
        <div class="overlay-stripe stripe-2"></div>
        <div class="overlay-stripe stripe-3"></div>
        <div class="overlay-stripe stripe-4"></div>
        <div class="overlay-stripe stripe-5"></div>
        <div class="overlay-stripe stripe-6"></div>
        <div class="overlay-stripe stripe-7"></div>
        <div class="overlay-stripe stripe-8"></div>
    </div>
    <!-- Start sweep-out immediately without waiting for hr.js to load -->
    <script>
        requestAnimationFrame(() => {
            document.documentElement.classList.remove('is-transitioning');
            document.documentElement.classList.add('is-ready');
            var overlay = document.getElementById('pageOverlay');
            if (overlay) overlay.classList.add('sweep-out');
        });
    </script>

    <!-- Logout Confirmation Modal -->
    <div class="modal-backdrop" id="logoutModal">
        <div class="modal-card">
            <h3>Confirm Logout</h3>
            <p>Are you sure you want to log out of the HR portal?</p>
            <div class="modal-actions">
                <button class="btn-cancel" id="cancelLogout">Cancel</button>
                <button class="btn-confirm" id="confirmLogout">Log Out</button>
            </div>
        </div>
    </div>

    <!-- Sidebar -->
    <aside class="sidebar" id="sidebar">
        <div class="sidebar-header reveal" style="--i:0">
            <div class="brand-container">
                <img src="../assets/images/expertise2.png" alt="Expertise Logo" class="brand-logo">
            </div>
            <button class="toggle-btn" id="sidebarToggle" title="Toggle Sidebar">
                <svg viewBox="0 0 24 24"><path d="M15.41 7.41L14 6l-6 6 6 6 1.41-1.41L10.83 12z"/></svg>
            </button>
        </div>

        <nav class="sidebar-nav">
            <a href="dashboard-hr.php" class="nav-item reveal" style="--i:1">
                <svg class="nav-icon" viewBox="0 0 24 24"><path d="M10 20v-6h4v6h5v-8h3L12 3 2 12h3v8z"/></svg>
                <span class="nav-text">Dashboard</span>
            </a>

            <!-- Applicants section: open, and highlighted because this page belongs to it -->
            <div class="nav-group active current reveal" id="applicantsGroup" style="--i:2">
                <div class="nav-dropdown-wrapper">
                    <a href="screening-list.php" class="nav-item nav-main-link" id="applicantsMainLink">
                        <svg class="nav-icon" viewBox="0 0 24 24"><path d="M16 11c1.66 0 2.99-1.34 2.99-3S17.66 5 16 5c-1.66 0-3 1.34-3 3s1.34 3 3 3zm-8 0c1.66 0 2.99-1.34 2.99-3S9.66 5 8 5C6.34 5 5 6.34 5 8s1.34 3 3 3zm0 2c-2.33 0-7 1.17-7 3.5V19h14v-2.5c0-2.33-4.67-3.5-7-3.5zm8 0c-.29 0-.62.02-.97.05 1.16.84 1.97 1.97 1.97 3.45V19h6v-2.5c0-2.33-4.67-3.5-7-3.5z"/></svg>
                        <span class="nav-text">Applicants</span>
                    </a>
                    <button class="dropdown-arrow-btn" id="arrowToggleBtn" title="Toggle Menu">
                        <svg class="arrow-icon" viewBox="0 0 24 24"><path d="M7.41 8.59L12 13.17l4.59-4.58L18 10l-6 6-6-6z"/></svg>
                    </button>
                </div>
                <div class="sub-menu" id="applicantsSubMenu">
                    <a href="screening-list.php" class="sub-item active" aria-current="page"><span class="sub-text">Screening List</span></a>
                    <a href="add-applicants.php" class="sub-item"><span class="sub-text">Add Applicant</span></a>
                </div>
            </div>

            <!-- Archive -->
            <a href="archive.php" id="archiveLink" class="nav-item reveal" style="--i:3">
                <svg class="nav-icon" viewBox="0 0 24 24"><path d="M20.54 5.23l-1.39-1.68C18.88 3.21 18.47 3 18 3H6c-.47 0-.88.21-1.16.55L3.46 5.23C3.17 5.57 3 6.02 3 6.5V19c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V6.5c0-.48-.17-.93-.46-1.27zM12 17.5L6.5 12H10v-2h4v2h3.5L12 17.5zM5.12 5l.81-1h12l.94 1H5.12z"/></svg>
                <span class="nav-text">Archive</span>
                <span class="nav-badge" id="archiveBadge" hidden>0</span>
            </a>
        </nav>

        <div class="sidebar-footer">
            <a href="setting-hr.php" class="nav-item reveal" style="--i:3">
                <svg class="nav-icon" viewBox="0 0 24 24"><path d="M19.14 12.94c.04-.3.06-.61.06-.94 0-.32-.02-.64-.07-.94l2.03-1.58c.18-.14.23-.41.12-.61l-1.92-3.32c-.12-.22-.37-.29-.59-.22l-2.39.96c-.5-.38-1.03-.7-1.62-.94l-.36-2.54c-.04-.24-.24-.41-.48-.41h-3.84c-.24 0-.43.17-.47.41l-.36 2.54c-.59.24-1.13.57-1.62.94l-2.39-.96c-.22-.08-.47 0-.59.22L2.74 8.87c-.12.21-.08.47.12.61l2.03 1.58c-.05.3-.09.63-.09.94s.02.64.07.94l-2.03 1.58c-.18.14-.23.41-.12.61l1.92 3.32c.12.22.37.29.59.22l2.39-.96c.5.38 1.03.7 1.62.94l.36 2.54c.05.24.24.41.48.41h3.84c.24 0 .44-.17.47-.41l.36-2.54c.59-.24 1.13-.56 1.62-.94l2.39.96c.22.08.47 0 .59-.22l1.92-3.32c.12-.22.07-.47-.12-.61l-2.01-1.58zM12 15.6c-1.98 0-3.6-1.62-3.6-3.6s1.62-3.6 3.6-3.6 3.6 1.62 3.6 3.6-1.62 3.6-3.6-3.6z"/></svg>
                <span class="nav-text">Settings</span>
            </a>
            <button class="nav-item btn-logout-trigger reveal" id="logoutBtn" style="--i:4">
                <svg class="nav-icon" viewBox="0 0 24 24"><path d="M10.09 15.59L11.5 17l5-5-5-5-1.41 1.41L12.67 11H3v2h9.67l-2.58 2.59zM19 3H5c-1.11 0-2 .9-2 2v4h2V5h14v14H5v-4H3v4c0 1.1.89 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2z"/></svg>
                <span class="nav-text">Log out</span>
            </button>
        </div>
    </aside>

    <!-- Restore the sidebar state (collapsed / expanded) before the first paint, so it never flashes open -->
    <script>
        try { if (localStorage.getItem('hr_sidebar_collapsed') === '1') document.getElementById('sidebar').classList.add('collapsed'); } catch (e) {}
    </script>

    <!-- Main Content -->
    <main class="main-content details" id="details">
        <!-- This whole top part (title, applicant card, tabs) stays in place while the page scrolls -->
        <div class="details-sticky" id="detailsSticky">
        <header class="content-header details-head reveal" style="--i:1">
            <h1>Applicant Details</h1>
            <a href="screening-list.php" class="back-link" id="backLink">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20 11H7.83l5.59-5.59L12 4l-8 8 8 8 1.41-1.41L7.83 13H20v-2z"/></svg>
                <span>Back</span>
            </a>
        </header>

        <!-- Who this is -->
        <section class="profile-card reveal" style="--i:2" aria-label="Applicant">
            <div class="avatar" aria-hidden="true">
                <svg viewBox="0 0 24 24"><path d="M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z"/></svg>
            </div>
            <div class="profile-info">
                <h2 class="profile-name" title="<?php echo $h($applicant['name']); ?>"><?php echo $h($applicant['name']); ?></h2>
                <p class="profile-line" title="<?php echo $h($applicant['email']); ?>">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20 4H4c-1.1 0-1.99.9-1.99 2L2 18c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2zm0 4l-8 5-8-5V6l8 5 8-5v2z"/></svg>
                    <span><?php echo $h($applicant['email']); ?></span>
                </p>
                <p class="profile-line">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6.62 10.79c1.44 2.83 3.76 5.14 6.59 6.59l2.2-2.2c.27-.27.67-.36 1.02-.24 1.12.37 2.33.57 3.57.57.55 0 1 .45 1 1V20c0 .55-.45 1-1 1-9.39 0-17-7.61-17-17 0-.55.45-1 1-1h3.5c.55 0 1 .45 1 1 0 1.25.2 2.45.57 3.57.11.35.03.74-.25 1.02l-2.2 2.2z"/></svg>
                    <span><?php echo $h($applicant['contact']); ?></span>
                </p>
            </div>
            <div class="profile-status">
                <span class="status-pill big <?php echo $pillClass[$status] ?? 'status-default'; ?>"><?php echo $h($status); ?></span>
                <?php if ($resultLabel !== ''): ?>
                    <span class="status-pill big <?php echo $resultClass; ?>"><?php echo $h($resultLabel); ?></span>
                <?php endif; ?>
            </div>
        </section>

        <!-- Tabs -->
        <div class="tabs reveal" id="tabs" role="tablist" aria-label="Applicant sections" style="--i:3">
            <?php foreach ($tabs as $key => $label):
                $open = !empty($access[$key]);
            ?>
                <button type="button" class="tab<?php echo $key === $tab ? ' is-active' : ''; ?><?php echo $open ? '' : ' is-locked'; ?>"
                        role="tab" id="tab-<?php echo $key; ?>" data-tab="<?php echo $key; ?>"
                        aria-controls="panel-<?php echo $key; ?>" aria-selected="<?php echo $key === $tab ? 'true' : 'false'; ?>"
                        <?php echo $open ? '' : 'aria-disabled="true" title="' . $h(details_locked_message($key)) . '"'; ?>
                        tabindex="<?php echo $key === $tab ? '0' : '-1'; ?>">
                    <span><?php echo $h($label); ?></span>
                    <?php if (!$open): ?>
                        <svg class="tab-lock" viewBox="0 0 24 24" aria-hidden="true"><path d="M18 8h-1V6c0-2.76-2.24-5-5-5S7 3.24 7 6v2H6c-1.1 0-2 .9-2 2v10c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V10c0-1.1-.9-2-2-2zm-6 9c-1.1 0-2-.9-2-2s.9-2 2-2 2 .9 2 2-.9 2-2 2zm3.1-9H8.9V6c0-1.71 1.39-3.1 3.1-3.1 1.71 0 3.1 1.39 3.1 3.1v2z"/></svg>
                    <?php endif; ?>
                </button>
            <?php endforeach; ?>
        </div>
        </div><!-- /details-sticky -->

        <section class="panel-card reveal" style="--i:4">

            <!-- ===================== OVERVIEW ===================== -->
            <div class="panel<?php echo $tab === 'overview' ? ' is-active' : ''; ?>" id="panel-overview" role="tabpanel" aria-labelledby="tab-overview" <?php echo $tab === 'overview' ? '' : 'hidden'; ?>>
                <div class="overview-grid">
                    <div class="ov-col">
                        <h3 class="panel-title">Personal Information</h3>
                        <dl class="info-list">
                            <?php foreach ($personal as $row): ?>
                                <div class="info-row">
                                    <dt><?php echo $h($row['label']); ?></dt>
                                    <dd title="<?php echo $h($row['value']); ?>"><?php echo $h($row['value']); ?></dd>
                                </div>
                            <?php endforeach; ?>
                        </dl>
                    </div>

                    <div class="ov-col">
                        <h3 class="panel-title">CV</h3>
                        <?php if ($cv['available']): ?>
                            <div class="file-card">
                                <svg class="file-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M14 2H6c-1.1 0-2 .9-2 2v16c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V8l-6-6zm2 16H8v-2h8v2zm0-4H8v-2h8v2zm-3-5V3.5L18.5 9H13z"/></svg>
                                <div class="file-meta">
                                    <strong title="<?php echo $h($cv['name']); ?>"><?php echo $h($cv['name']); ?></strong>
                                    <span><?php echo $h(details_size($cv['size'])); ?></span>
                                </div>
                                <a class="btn-outline" href="<?php echo $h($cv['url']); ?>" target="_blank" rel="noopener">VIEW</a>
                            </div>
                        <?php else: ?>
                            <div class="file-card is-empty">
                                <svg class="file-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M14 2H6c-1.1 0-2 .9-2 2v16c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V8l-6-6zm2 16H8v-2h8v2zm0-4H8v-2h8v2zm-3-5V3.5L18.5 9H13z"/></svg>
                                <div class="file-meta">
                                    <strong>No CV file on record</strong>
                                    <span>This applicant was added without an uploaded CV.</span>
                                </div>
                            </div>
                        <?php endif; ?>

                        <h3 class="panel-title spaced">Screening Result</h3>
                        <dl class="result-list">
                            <div class="result-row">
                                <dt>Result:</dt>
                                <dd>
                                    <?php if ($resultLabel !== ''): ?>
                                        <span class="status-pill <?php echo $resultClass; ?>"><?php echo $h($resultLabel); ?></span>
                                    <?php else: ?>
                                        <span class="muted">No result yet</span>
                                    <?php endif; ?>
                                </dd>
                            </div>
                            <div class="result-row">
                                <dt>Risk Score:</dt>
                                <dd>
                                    <span class="score-text"><?php echo $h($riskText); ?></span>
                                    <?php if ($evaluation): ?><span class="band-chip band-<?php echo strtolower($evaluation['band']); ?>"><?php echo $h($evaluation['band']); ?> risk</span><?php endif; ?>
                                </dd>
                            </div>
                            <div class="result-row">
                                <dt>Recommendation:</dt>
                                <dd><?php echo $h($recommendation); ?></dd>
                            </div>
                        </dl>
                    </div>
                </div>
            </div>

            <!-- ===================== QUESTIONNAIRES ===================== -->
            <?php if ($questionnaire): ?>
            <div class="panel<?php echo $tab === 'questionnaires' ? ' is-active' : ''; ?>" id="panel-questionnaires" role="tabpanel" aria-labelledby="tab-questionnaires" <?php echo $tab === 'questionnaires' ? '' : 'hidden'; ?>>
                <div class="panel-head">
                    <h3 class="panel-title">Questionnaires Result</h3>
                    <div class="chips">
                        <span class="chip"><?php echo (int) $questionnaire['answered']; ?> of <?php echo (int) $questionnaire['total']; ?> answered</span>
                        <?php if (!empty($submission['submitted_at'])): ?>
                            <span class="chip">Submitted <?php echo $h(details_datetime_label($submission['submitted_at'])); ?></span>
                        <?php endif; ?>
                        <?php if (!empty($submission['reference'])): ?>
                            <span class="chip"><?php echo $h($submission['reference']); ?></span>
                        <?php endif; ?>
                        <?php if (!empty($submission['demo'])): ?>
                            <span class="chip chip-sample" title="This applicant did not go through the real screening, so these answers are an example.">Sample answers</span>
                        <?php endif; ?>
                    </div>
                </div>

                <?php $ko = $questionnaire['ko']; ?>
                <?php if ($ko['failed']): ?>
                    <!-- Screening rubric: the knock-out questions the applicant did not pass (this is why the screening was failed) -->
                    <div class="notice notice-bad ko-notice" role="note">
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2C6.47 2 2 6.47 2 12s4.47 10 10 10 10-4.47 10-10S17.53 2 12 2zm5 13.59L15.59 17 12 13.41 8.41 17 7 15.59 10.59 12 7 8.41 8.41 7 12 10.59 15.59 7 17 8.41 13.41 12 17 15.59z"/></svg>
                        <div>
                            <strong>Failed the screening: <?php echo count($ko['failed']); ?> of <?php echo (int) $ko['total']; ?> knock-out question<?php echo $ko['total'] === 1 ? '' : 's'; ?> did not match the passing answer</strong>
                            <ul>
                                <?php foreach ($ko['failed'] as $f): ?>
                                    <li>
                                        <?php echo $h($f['question']); ?>
                                        <span class="ko-line">Applicant answered: <b><?php echo $h($f['given']); ?></b> &middot; Passing answer: <b><?php echo $h($f['expected']); ?></b></span>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    </div>
                <?php elseif ($ko['total'] > 0): ?>
                    <div class="notice notice-ok ko-notice" role="note">
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 15-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z"/></svg>
                        <div>
                            <strong>Passed the knock-out questions</strong>
                            <ul><li>The applicant matched the passing answer of all <?php echo (int) $ko['total']; ?> knock-out question<?php echo $ko['total'] === 1 ? '' : 's'; ?>.</li></ul>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if ($questionnaire['flags']): ?>
                    <div class="notice notice-warn" role="note">
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M1 21h22L12 2 1 21zm12-3h-2v-2h2v2zm0-4h-2v-4h2v4z"/></svg>
                        <div>
                            <strong><?php echo count($questionnaire['flags']); ?> answer<?php echo count($questionnaire['flags']) === 1 ? '' : 's'; ?> need<?php echo count($questionnaire['flags']) === 1 ? 's' : ''; ?> your attention</strong>
                            <ul>
                                <?php foreach ($questionnaire['flags'] as $f): ?>
                                    <li><?php echo $h($f['note']); ?>: <?php echo $h($f['question']); ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    </div>
                <?php endif; ?>

                <?php foreach ($questionnaire['sections'] as $sec): ?>
                    <div class="q-section">
                        <h4 class="q-section-title"><span class="q-no">Section <?php echo (int) $sec['no']; ?></span><?php echo $h($sec['title']); ?></h4>
                        <?php foreach ($sec['items'] as $it): ?>
                            <div class="q-row<?php echo $it['type'] === 'text' || $it['type'] === 'consent' ? ' is-wide' : ''; ?><?php echo $it['flag'] !== '' ? ' is-flag' : ''; ?><?php echo $it['ko_met'] === false ? ' is-fail' : ''; ?>">
                                <div class="q-main">
                                    <p class="q-text"><?php echo $h($it['question']); ?></p>
                                    <?php if ($it['knockout']): ?>
                                        <p class="q-rule"><span class="ko-tag">Knock-out</span> Passing answer: <b><?php echo $h($it['expected']); ?></b></p>
                                    <?php endif; ?>
                                </div>
                                <div class="q-answer">
                                    <?php if ($it['missing']): ?>
                                        <span class="muted">Not answered</span>
                                    <?php elseif ($it['type'] === 'text'): ?>
                                        <blockquote class="q-quote"><?php echo nl2br($h($it['value'])); ?></blockquote>
                                    <?php elseif ($it['type'] === 'scale'): ?>
                                        <span class="scale" role="img" aria-label="<?php echo $h($it['value']); ?>">
                                            <?php for ($i = 1; $i <= $it['max']; $i++): ?><i class="<?php echo $i <= $it['scale'] ? 'on' : ''; ?>"></i><?php endfor; ?>
                                        </span>
                                        <strong><?php echo $h($it['value']); ?></strong>
                                    <?php elseif ($it['tone'] === 'yes' || $it['tone'] === 'no'): ?>
                                        <span class="ans ans-<?php echo $it['tone']; ?>"><?php echo $h($it['value']); ?></span>
                                        <?php if ($it['extra'] !== ''): ?><span class="q-extra"><?php echo $h($it['extra']); ?></span><?php endif; ?>
                                    <?php else: ?>
                                        <strong><?php echo $h($it['value']); ?></strong>
                                    <?php endif; ?>
                                    <?php if ($it['knockout'] && $it['ko_met'] !== null): ?>
                                        <span class="ko-result <?php echo $it['ko_met'] ? 'is-met' : 'is-fail'; ?>"><?php echo $it['ko_met'] ? 'Met' : 'Did not meet'; ?></span>
                                    <?php endif; ?>
                                </div>
                                <?php if ($it['ko_met'] === false && $it['reason'] !== ''): ?>
                                    <p class="q-why"><?php echo $h($it['reason']); ?></p>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <!-- ===================== VIDEO ===================== -->
            <?php if ($video): ?>
            <div class="panel<?php echo $tab === 'video' ? ' is-active' : ''; ?>" id="panel-video" role="tabpanel" aria-labelledby="tab-video" <?php echo $tab === 'video' ? '' : 'hidden'; ?>>
                <div class="panel-head">
                    <h3 class="panel-title">Video Proof</h3>
                    <div class="chips">
                        <?php if (!empty($submission['demo'])): ?>
                            <span class="chip chip-sample" title="This applicant did not go through the real screening, so this video is an example.">Sample video</span>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="video-card">
                    <button type="button" class="video-thumb" id="videoPlayBtn" aria-label="Play <?php echo $h($video['name']); ?>">
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M8 5v14l11-7z"/></svg>
                    </button>
                    <div class="video-meta">
                        <strong title="<?php echo $h($video['name']); ?>"><?php echo $h($video['name']); ?></strong>
                        <span>
                            <?php echo $h(details_size((int) ($video['size'] ?? 0))); ?>
                            <?php if (isset($video['seconds']) && $video['seconds'] !== null && $video['seconds'] !== ''): ?> &middot; <?php echo $h(rtrim(rtrim(number_format((float) $video['seconds'], 1), '0'), '.')); ?> sec<?php endif; ?>
                            <?php if (!empty($video['source'])): ?> &middot; <?php echo $video['source'] === 'recorded' ? 'Recorded on the camera' : 'Uploaded file'; ?><?php endif; ?>
                        </span>
                        <span class="video-line" aria-hidden="true"></span>
                    </div>
                </div>

                <div class="video-stage" id="videoStage">
                    <?php if (!empty($video['url'])): ?>
                        <video id="videoEl" controls preload="metadata" playsinline src="<?php echo $h($video['url']); ?>">
                            Your browser can not play this video.
                        </video>
                        <a class="btn-outline small" href="<?php echo $h($video['url']); ?>" target="_blank" rel="noopener">Open in a new tab</a>
                    <?php else: ?>
                        <div class="video-empty" id="videoEmpty">
                            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M18 4l2 4h-3l-2-4h-2l2 4h-3l-2-4H8l2 4H7L5 4H4c-1.1 0-1.99.9-1.99 2L2 18c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V4h-4z"/></svg>
                            <strong>The video preview is not available yet</strong>
                            <p>The details above were saved from the applicant's submission. Once the video storage (Cloudinary upload) is connected, the video plays right here.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- ===================== INTERVIEW SCHEDULE (drawn by details.js) ===================== -->
            <?php if ($access['interview']): ?>
            <div class="panel<?php echo $tab === 'interview' ? ' is-active' : ''; ?>" id="panel-interview" role="tabpanel" aria-labelledby="tab-interview" <?php echo $tab === 'interview' ? '' : 'hidden'; ?>>
                <?php if ($interview): ?>
                    <div id="interviewRoot" class="iv" aria-live="polite"></div>
                <?php else: ?>
                    <h3 class="iv-title">Interview Schedule</h3>
                    <div class="video-empty iv-wait">
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M19 3h-1V1h-2v2H8V1H6v2H5c-1.11 0-1.99.9-1.99 2L3 19c0 1.1.89 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm0 16H5V8h14v11zM7 10h5v5H7z"/></svg>
                        <strong>Waiting for the applicant to choose a schedule</strong>
                        <p>This applicant passed. They pick a preferred date and time on their result page; it will show up here for you to confirm or change.</p>
                    </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <!-- ===================== AI EVALUATION (the risk scoring rubric) ===================== -->
            <?php if ($evaluation):
                $circ = round(2 * M_PI * 84, 2);
                $frac = $evaluation['max_points'] > 0 ? min(1, $evaluation['points'] / $evaluation['max_points']) : 0;
                $bandKey = strtolower($evaluation['band']);
                $outClass = $evaluation['outcome'] === 'passed' ? 'status-passed' : ($evaluation['outcome'] === 'failed' ? 'status-failed' : 'status-pending');
            ?>
            <div class="panel<?php echo $tab === 'evaluation' ? ' is-active' : ''; ?>" id="panel-evaluation" role="tabpanel" aria-labelledby="tab-evaluation" <?php echo $tab === 'evaluation' ? '' : 'hidden'; ?>>
                <div class="panel-head">
                    <h3 class="panel-title">Evaluation Criteria</h3>
                    <div class="chips">
                        <span class="chip">Risk scoring rubric</span>
                        <?php if (!empty($evaluation['evaluated_at'])): ?>
                            <span class="chip">Evaluated <?php echo $h(details_datetime_label($evaluation['evaluated_at'])); ?></span>
                        <?php endif; ?>
                        <?php if ($evaluation['demo']): ?>
                            <span class="chip chip-sample" title="This applicant did not go through the real screening. The rubric was run on sample answers.">Sample answers</span>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if ($evaluation['auto_high']): ?>
                    <div class="notice notice-bad" role="note">
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M1 21h22L12 2 1 21zm12-3h-2v-2h2v2zm0-4h-2v-4h2v4z"/></svg>
                        <div>
                            <strong>Automatic High risk</strong>
                            <ul><?php foreach ($evaluation['auto_high'] as $why): ?><li><?php echo $h($why); ?></li><?php endforeach; ?></ul>
                        </div>
                    </div>
                <?php endif; ?>

                <div class="eval-grid band-<?php echo $bandKey; ?>">
                    <div class="eval-criteria">
                        <?php foreach ($evaluation['factors'] as $f): ?>
                            <div class="crit">
                                <div class="crit-top">
                                    <span class="crit-name"><?php echo $h($f['label']); ?></span>
                                    <?php if ($f['level'] === 'na'): ?>
                                        <span class="crit-score lvl-na">Not scored</span>
                                    <?php else: ?>
                                        <span class="crit-score lvl-<?php echo $h($f['level']); ?>"><?php echo (int) $f['points']; ?> / <?php echo (int) $f['max']; ?></span>
                                    <?php endif; ?>
                                </div>
                                <div class="bar" role="progressbar" aria-valuemin="0" aria-valuemax="<?php echo (int) $f['max']; ?>" aria-valuenow="<?php echo (int) $f['points']; ?>" aria-label="<?php echo $h($f['label']); ?>">
                                    <span class="bar-fill lvl-<?php echo $h($f['level']); ?>" style="--w:<?php echo (int) $f['pct']; ?>%"></span>
                                </div>
                                <p class="crit-note"><?php echo $h($f['reason']); ?></p>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <div class="eval-score">
                        <div class="donut" style="--c:<?php echo $circ; ?>; --off:<?php echo round($circ * (1 - $frac), 2); ?>">
                            <svg viewBox="0 0 200 200" aria-hidden="true">
                                <circle class="donut-track" cx="100" cy="100" r="84"></circle>
                                <circle class="donut-ring" cx="100" cy="100" r="84" transform="rotate(-90 100 100)"></circle>
                            </svg>
                            <span class="donut-value"><b><?php echo (int) $evaluation['points']; ?></b><small>of <?php echo (int) $evaluation['max_points']; ?></small></span>
                        </div>
                        <p class="eval-label">Risk Score</p>
                        <span class="band-chip big band-<?php echo $bandKey; ?>"><?php echo $h($evaluation['band']); ?> risk</span>
                        <span class="status-pill big <?php echo $outClass; ?>"><?php echo $h($evaluation['label']); ?></span>
                        <ul class="band-legend" aria-label="Risk bands">
                            <li class="<?php echo $evaluation['band'] === 'Low' ? 'is-now' : ''; ?>"><i class="band-low"></i>Low <span>0&ndash;<?php echo (int) $evaluation['bands']['low_max']; ?></span></li>
                            <li class="<?php echo $evaluation['band'] === 'Medium' ? 'is-now' : ''; ?>"><i class="band-medium"></i>Medium <span><?php echo (int) $evaluation['bands']['low_max'] + 1; ?>&ndash;<?php echo (int) $evaluation['bands']['medium_max']; ?></span></li>
                            <li class="<?php echo $evaluation['band'] === 'High' ? 'is-now' : ''; ?>"><i class="band-high"></i>High <span><?php echo (int) $evaluation['bands']['medium_max'] + 1; ?>+</span></li>
                        </ul>
                    </div>
                </div>

                <div class="why why-<?php echo $evaluation['outcome']; ?>">
                    <h4 class="why-title"><?php echo $h($evaluation['title']); ?></h4>
                    <p class="why-summary"><?php echo $h($evaluation['summary']); ?></p>
                    <div class="why-cols">
                        <div>
                            <h5>In their favor</h5>
                            <?php if ($evaluation['favor']): ?>
                                <ul><?php foreach ($evaluation['favor'] as $line): ?><li><?php echo $h($line); ?></li><?php endforeach; ?></ul>
                            <?php else: ?><p class="muted">Nothing stood out in their favor.</p><?php endif; ?>
                        </div>
                        <div>
                            <h5>Needs attention</h5>
                            <?php if ($evaluation['watch']): ?>
                                <ul><?php foreach ($evaluation['watch'] as $line): ?><li><?php echo $h($line); ?></li><?php endforeach; ?></ul>
                            <?php else: ?><p class="muted">No concerns were found.</p><?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>

        </section>
    </main>

    <!-- Approve the interview schedule (an email goes out right after) -->
    <div class="modal-backdrop" id="approveModal" aria-hidden="true">
        <div class="modal-card approve-card" role="dialog" aria-modal="true" aria-labelledby="approveTitle">
            <h3 id="approveTitle">Confirm this schedule?</h3>
            <div class="ap-line" id="approveWhen"></div>
            <p id="approveText"></p>
            <div class="modal-actions">
                <button type="button" class="btn-cancel" id="approveCancel">Cancel</button>
                <button type="button" class="btn-confirm" id="approveConfirm">Confirm &amp; Send Email</button>
            </div>
        </div>
    </div>

    <!-- The email that was sent (so HR can see exactly what the applicant received) -->
    <div class="modal-backdrop" id="emailModal" aria-hidden="true">
        <div class="modal-card email-card" role="dialog" aria-modal="true" aria-labelledby="emailTitle">
            <div class="email-head">
                <h3 id="emailTitle">Email sent</h3>
                <button type="button" class="email-close" id="emailClose" aria-label="Close">&times;</button>
            </div>
            <p class="email-meta"><span><b>To:</b> <span id="emailTo"></span></span><span><b>Subject:</b> <span id="emailSubject"></span></span></p>
            <iframe id="emailFrame" title="Email preview" sandbox=""></iframe>
        </div>
    </div>

    <!-- Pass PHP data to JavaScript -->
    <script>
        window.DETAILS = <?php echo json_encode($pageData, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;
    </script>
    <!-- Shared HR behaviour first, then this page's script -->
    <script>window.HR_SERVER_ARCHIVE = <?php
try {
    require_once __DIR__ . '/../src/Repositories/ApplicantRepository.php';
    $archivedRows = repo_applicants_archived();
    $ids = [];
    $at = [];
    foreach ($archivedRows as $a) {
        $aid = (int) $a['id'];
        $ids[] = $aid;
        if (!empty($a['archived_at'])) $at[$aid] = (int) (strtotime((string) $a['archived_at']) * 1000);
    }
    echo json_encode(['ids' => $ids, 'at' => (object) $at], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
} catch (Throwable $e) {
    echo json_encode(['ids' => [], 'at' => new stdClass()], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
}
?>;</script>
    <script>window.HR_ACCOUNT = <?php echo json_encode(['id' => (int) ($_SESSION['user_id'] ?? 0)], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;</script>
    <script src="../assets/js/hr.js?v=<?php echo @filemtime(__DIR__ . '/../assets/js/hr.js'); ?>"></script>
</body>
</html>