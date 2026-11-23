<?php
require_once __DIR__ . '/../src/Helpers/security.php';
start_secure_session(1800);
restore_remembered_session();
$csrf = csrf_token();

// No caching, so the Back button after logout never shows this page
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');

// Protection: Ensure only the Admin can access this page
if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
    header('Location: ../index.php');
    exit();
}

date_default_timezone_set('Asia/Manila');

require_once __DIR__ . '/../src/Repositories/AdminRepository.php';
require_once __DIR__ . '/../src/Repositories/ApplicantRepository.php';
require_once __DIR__ . '/../hr/hr-data.php';

// Profile data for the sidebar picture (clicking it opens Settings)
$profile = static_profile_for_page('../hr/data/avatars/');

$hr_accounts = repo_admin_accounts();
$activity_filter = trim((string) ($_GET['activity_filter'] ?? 'all'));
$hr_actor_name = null;
$hr_user_id = null;
$hr_only = false;
if ($activity_filter === 'hr') {
    $hr_only = true;
} elseif (strpos($activity_filter, 'account:') === 0) {
    $requested_id = filter_var(substr($activity_filter, strlen('account:')), FILTER_VALIDATE_INT);
    foreach ($hr_accounts as $account) {
        if ($requested_id !== false && (int) $account['id'] === (int) $requested_id) {
            $hr_user_id = (int) $account['id'];
            $hr_actor_name = trim((string) $account['name']);
            break;
        }
    }
    if ($hr_user_id === null) {
        $activity_filter = 'all';
    }
} else {
    $activity_filter = 'all';
}

// Applicant statistics are attributed to the HR account that added each applicant.
$overview = repo_admin_overview(null, $hr_actor_name, $hr_only);
$stats    = repo_admin_question_stats(null, $hr_user_id, $hr_only);
$failure  = repo_admin_failure_rates(20, null, $hr_user_id, $hr_only);
$risk     = repo_admin_risk_flags(null, $hr_user_id, $hr_only);
$volume   = repo_admin_volume(null, $hr_user_id, $hr_only);
$activity_feed = repo_admin_activity_feed($hr_actor_name, $hr_only, 10);
$recent = $activity_feed['rows'];
$activity_total = $activity_feed['total'];

// Share of something, used by the little bar on each summary box
function share(int $part, int $total): int
{
    return $total > 0 ? (int) round($part / $total * 100) : 0;
}

$hr_pct  = share((int) $overview['hr_active'], (int) $overview['hr_total']);
$log_pct = share((int) $overview['activities_week'], $activity_total);

$hr_note  = 'out of ' . (int) $overview['hr_total'] . ' HR account' . ($overview['hr_total'] === 1 ? '' : 's');
$q_note   = 'in ' . (int) $overview['sections'] . ' section' . ($overview['sections'] === 1 ? '' : 's');
$log_note = 'in the last 7 days';
$scope_label = $hr_user_id !== null
    ? 'HR account: ' . $hr_actor_name
    : ($hr_only ? 'All HR Accounts' : 'All Activities');
$volume_subject = $hr_user_id !== null
    ? 'Applications added by ' . $hr_actor_name
    : ($hr_only ? 'Applications added by HR accounts' : 'Applications received');

$q_count   = count($stats['questions']);
$stats_sub = $q_count . ' question' . ($q_count === 1 ? '' : 's') . ' · ' . (
    $stats['submissions'] > 0
        ? 'from ' . (int) $stats['submissions'] . ' submitted questionnaire' . ($stats['submissions'] === 1 ? '' : 's')
        : 'no applicant has submitted the questionnaire yet'
);
$log_count = $activity_total > 10 ? 'Latest 10 of ' . $activity_total : $activity_total . ' in total';

// Admin name from the session (set at login from the users table).
$display_name = $_SESSION['user_name'] ?? 'Admin';
$greeting     = 'Hello, ' . $display_name . '!';
?>
<!DOCTYPE html>
<html lang="en" data-page="dashboardadmin">
<head>
    <meta charset="UTF-8">
    <meta name="csrf-token" content="<?= attr($csrf) ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - Expertise Admin</title>

    <!-- Runs before first paint: the saved theme / animations are already on the page, and the screen is already covered when arriving via login -->
    <script>
        document.documentElement.classList.add('js');
        try {
            var s = JSON.parse(localStorage.getItem('admin_settings') || '{}'), t = s.theme || 'light';
            if (t === 'system') t = window.matchMedia && matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
            document.documentElement.setAttribute('data-theme', t === 'dark' ? 'dark' : 'light');
            if (s.animations === false) document.documentElement.setAttribute('data-motion', 'off');
        } catch (e) {}
        if (new URLSearchParams(location.search).get('transition') === 'out') {
            document.documentElement.classList.add('is-transitioning');
            // Never leave the page permanently covered if the shared admin script is
            // slow to load or fails. The normal script removes this flag sooner.
            window.setTimeout(function () {
                var root = document.documentElement;
                var overlay = document.getElementById('pageOverlay');
                root.classList.remove('is-transitioning');
                root.classList.add('is-ready');
                if (overlay) {
                    overlay.classList.remove('sweep-in');
                    overlay.classList.add('sweep-out');
                }
            }, 1800);
        }
    </script>

    <!-- Plus Jakarta Sans Google Font -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">

    <!-- admin.css = shared admin styles + every admin page (this page is picked by data-page="dashboardadmin" on <html>) -->
    <link rel="stylesheet" href="../assets/css/admin.css?v=<?php echo @filemtime(__DIR__ . '/../assets/css/admin.css'); ?>">
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

    <!-- Logout Confirmation Modal -->
    <div class="modal-backdrop" id="logoutModal" role="dialog" aria-modal="true" aria-labelledby="logoutTitle">
        <div class="modal-card">
            <h3 id="logoutTitle">Confirm Logout</h3>
            <p>Are you sure you want to log out of the Admin portal?</p>
            <div class="modal-actions">
                <button type="button" class="modal-btn is-cancel" id="cancelLogout">Cancel</button>
                <button type="button" class="modal-btn is-confirm" id="confirmLogout">Log Out</button>
            </div>
        </div>
    </div>

    <!-- Sidebar: Dashboard, HR Accounts, Questions, Archive (+ Settings and Log out at the bottom) -->
    <aside class="sidebar" id="sidebar">
        <div class="sidebar-header reveal" style="--i:0">
            <div class="brand-container">
                <img src="../assets/images/expertise2.png" alt="Expertise Logo" class="brand-logo">
            </div>
            <button type="button" class="toggle-btn" id="sidebarToggle" title="Toggle Sidebar" aria-label="Toggle sidebar">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M15.41 7.41L14 6l-6 6 6 6 1.41-1.41L10.83 12z"/></svg>
            </button>
        </div>

        <?php
        // Profile card: the name + picture saved in Settings (clicking it opens Settings)
        $sbp_name = $display_name;
        $sbp_role = (string) ($profile['role_label'] ?? 'Administrator');
        $sbp_html = static_profile_avatar_html($display_name, (string) ($profile['avatar_url'] ?? ''));
        ?>
        <a class="profile-chip sidebar-profile reveal" id="profileChip" href="settings-admin.php" style="--i:1" title="<?php echo htmlspecialchars($sbp_name . ' - open your profile in Settings', ENT_QUOTES, 'UTF-8'); ?>" aria-label="<?php echo htmlspecialchars($sbp_name . ', ' . $sbp_role . '. Open your profile in Settings', ENT_QUOTES, 'UTF-8'); ?>">
            <?php echo $sbp_html; ?>
            <span class="profile-meta">
                <strong><?php echo htmlspecialchars($sbp_name, ENT_QUOTES, 'UTF-8'); ?></strong>
                <small><?php echo htmlspecialchars($sbp_role, ENT_QUOTES, 'UTF-8'); ?></small>
            </span>
        </a>

        <nav class="sidebar-nav" aria-label="Admin">
            <a href="dashboard-admin.php" class="nav-item reveal active" id="dashboardLink" style="--i:1" title="Dashboard" aria-current="page">
                <svg class="nav-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M10 20v-6h4v6h5v-8h3L12 3 2 12h3v8z"/></svg>
                <span class="nav-text">Dashboard</span>
                <span class="nav-badge alert" id="dashboardBadge" hidden></span>
            </a>

            <a href="hr-list.php" class="nav-item reveal" style="--i:2" title="HR Accounts">
                <svg class="nav-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M16 11c1.66 0 2.99-1.34 2.99-3S17.66 5 16 5c-1.66 0-3 1.34-3 3s1.34 3 3 3zm-8 0c1.66 0 2.99-1.34 2.99-3S9.66 5 8 5C6.34 5 5 6.34 5 8s1.34 3 3 3zm0 2c-2.33 0-7 1.17-7 3.5V19h14v-2.5c0-2.33-4.67-3.5-7-3.5zm8 0c-.29 0-.62.02-.97.05 1.16.84 1.97 1.97 1.97 3.45V19h6v-2.5c0-2.33-4.67-3.5-7-3.5z"/></svg>
                <span class="nav-text">HR Accounts</span>
            </a>

            <a href="question-list.php" class="nav-item reveal" style="--i:3" title="Questions">
                <svg class="nav-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M19 3h-4.18C14.4 1.84 13.3 1 12 1s-2.4.84-2.82 2H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm-7 0c.55 0 1 .45 1 1s-.45 1-1 1-1-.45-1-1 .45-1 1-1zm2 14H7v-2h7v2zm3-4H7v-2h10v2zm0-4H7V7h10v2z"/></svg>
                <span class="nav-text">Questions</span>
            </a>

            <a href="archive-admin.php" class="nav-item reveal" id="archiveLink" style="--i:4" title="Archive">
                <svg class="nav-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M20.54 5.23l-1.39-1.68C18.88 3.21 18.47 3 18 3H6c-.47 0-.88.21-1.16.55L3.46 5.23C3.17 5.57 3 6.02 3 6.5V19c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V6.5c0-.48-.17-.93-.46-1.27zM12 17.5L6.5 12H10v-2h4v2h3.5L12 17.5zM5.12 5l.81-1h12l.94 1H5.12z"/></svg>
                <span class="nav-text">Archive</span>
                <span class="nav-badge alert" id="archiveBadge" hidden></span>
            </a>
        </nav>

        <div class="sidebar-footer">
            <a href="settings-admin.php" class="nav-item reveal" style="--i:5" title="Settings">
                <svg class="nav-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M19.14 12.94c.04-.3.06-.61.06-.94 0-.32-.02-.64-.07-.94l2.03-1.58c.18-.14.23-.41.12-.61l-1.92-3.32c-.12-.22-.37-.29-.59-.22l-2.39.96c-.5-.38-1.03-.7-1.62-.94l-.36-2.54c-.04-.24-.24-.41-.48-.41h-3.84c-.24 0-.43.17-.47.41l-.36 2.54c-.59.24-1.13.57-1.62.94l-2.39-.96c-.22-.08-.47 0-.59.22L2.74 8.87c-.12.21-.08.47.12.61l2.03 1.58c-.05.3-.09.63-.09.94s.02.64.07.94l-2.03 1.58c-.18.14-.23.41-.12.61l1.92 3.32c.12.22.37.29.59.22l2.39-.96c.5.38 1.03.7 1.62.94l.36 2.54c.05.24.24.41.48.41h3.84c.24 0 .44-.17.47-.41l.36-2.54c.59-.24 1.13-.56 1.62-.94l2.39.96c.22.08.47 0 .59-.22l1.92-3.32c.12-.22.07-.47-.12-.61l-2.01-1.58zM12 15.6c-1.98 0-3.6-1.62-3.6-3.6s1.62-3.6 3.6-3.6 3.6 1.62 3.6 3.6-1.62 3.6-3.6 3.6z"/></svg>
                <span class="nav-text">Settings</span>
            </a>
            <button type="button" class="nav-item btn-logout-trigger reveal" id="logoutBtn" style="--i:6" title="Log out">
                <svg class="nav-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M10.09 15.59L11.5 17l5-5-5-5-1.41 1.41L12.67 11H3v2h9.67l-2.58 2.59zM19 3H5c-1.11 0-2 .9-2 2v4h2V5h14v14H5v-4H3v4c0 1.1.89 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2z"/></svg>
                <span class="nav-text">Log out</span>
            </button>
        </div>
    </aside>

    <!-- Restore the sidebar state (collapsed / expanded) before the first paint, so it never flashes open -->
    <script>
        try { if (localStorage.getItem('admin_sidebar_collapsed') === '1') document.getElementById('sidebar').classList.add('collapsed'); } catch (e) {}
    </script>

    <!-- Main Content Panel Area -->
    <main class="main-content">
        <!-- Top Greeting Header -->
        <header class="content-header has-bell has-profile reveal" style="--i:1">
            <div class="header-text">
                <h1 id="greeting" title="<?php echo htmlspecialchars($greeting, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($greeting, ENT_QUOTES, 'UTF-8'); ?></h1>
            </div>
            <div class="header-tools">
                <button type="button" class="stats-download" id="statsDownloadBtn" title="Download dashboard statistics" aria-label="Download dashboard statistics PDF">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M19 9h-4V3H9v6H5l7 7 7-7zM5 18v2h14v-2H5z"/></svg>
                    Download Stats
                </button>
                <div class="notif-wrap">
                    <button type="button" class="notif-bell" id="notifBell" aria-haspopup="true" aria-expanded="false" aria-controls="notifPanel" aria-label="Notifications" title="Notifications">
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 22c1.1 0 2-.9 2-2h-4c0 1.1.89 2 2 2zm6-6v-5c0-3.07-1.64-5.64-4.5-6.32V4c0-.83-.67-1.5-1.5-1.5s-1.5.67-1.5 1.5v.68C7.63 5.36 6 7.92 6 11v5l-2 2v1h16v-1l-2-2z"/></svg>
                        <span class="notif-count" id="notifBadge" hidden>0</span>
                    </button>

                    <div class="notif-panel" id="notifPanel" role="dialog" aria-label="Notifications" hidden>
                        <div class="notif-head">
                            <h3>Notifications</h3>
                            <button type="button" class="notif-markall" id="notifMarkAll">Mark all as read</button>
                        </div>
                        <ul class="notif-list" id="notifList"></ul>
                    </div>
                </div>
            </div>
        </header>

        <div class="admin-dashboard">

            <div class="dashboard-scope reveal" style="--i:1">
                <form class="dashboard-scope-filter" method="get">
                    <label id="activityFilterLabel" for="activityFilter">Dashboard view</label>
                    <div class="dashboard-filter-control">
                        <select id="activityFilter" name="activity_filter">
                            <option value="all" <?= $activity_filter === 'all' ? 'selected' : '' ?>>All Activities</option>
                            <option value="hr" <?= $activity_filter === 'hr' ? 'selected' : '' ?>>All HR Accounts</option>
                            <?php foreach ($hr_accounts as $account): ?>
                                <option value="<?= 'account:' . (int) $account['id'] ?>" <?= $hr_user_id === (int) $account['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars(trim((string) $account['name']) . ' (' . (string) $account['email'] . ')', ENT_QUOTES, 'UTF-8') ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <button type="button" class="dashboard-filter-trigger" id="activityFilterTrigger" aria-haspopup="listbox" aria-expanded="false" aria-controls="activityFilterMenu" aria-labelledby="activityFilterLabel activityFilterValue" hidden>
                            <span class="dashboard-filter-value" id="activityFilterValue"></span>
                            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m7 10 5 5 5-5z"/></svg>
                        </button>
                        <ul class="dashboard-filter-menu" id="activityFilterMenu" role="listbox" aria-labelledby="activityFilterLabel" hidden>
                            <li class="dashboard-filter-option" id="activityFilterOptionAll" role="option" tabindex="-1" data-value="all" data-label="All Activities" aria-selected="<?= $activity_filter === 'all' ? 'true' : 'false' ?>">
                                <span class="dashboard-filter-option-copy"><span class="dashboard-filter-option-label">All Activities</span><span class="dashboard-filter-option-meta">System-wide activity and dashboard metrics</span></span>
                                <svg class="dashboard-filter-check" viewBox="0 0 24 24" aria-hidden="true"><path d="m9 16.2-3.5-3.5L4 14.2l5 5L20 8.2l-1.5-1.4z"/></svg>
                            </li>
                            <li class="dashboard-filter-option" id="activityFilterOptionHr" role="option" tabindex="-1" data-value="hr" data-label="All HR Accounts" aria-selected="<?= $activity_filter === 'hr' ? 'true' : 'false' ?>">
                                <span class="dashboard-filter-option-copy"><span class="dashboard-filter-option-label">All HR Accounts</span><span class="dashboard-filter-option-meta">Activity from every HR account</span></span>
                                <svg class="dashboard-filter-check" viewBox="0 0 24 24" aria-hidden="true"><path d="m9 16.2-3.5-3.5L4 14.2l5 5L20 8.2l-1.5-1.4z"/></svg>
                            </li>
                        <?php foreach ($hr_accounts as $account): ?>
                            <li class="dashboard-filter-option" id="activityFilterOption<?= (int) $account['id'] ?>" role="option" tabindex="-1" data-value="<?= 'account:' . (int) $account['id'] ?>" data-label="<?= htmlspecialchars(trim((string) $account['name']) . ' (' . (string) $account['email'] . ')', ENT_QUOTES, 'UTF-8') ?>" aria-selected="<?= $hr_user_id === (int) $account['id'] ? 'true' : 'false' ?>">
                                <span class="dashboard-filter-option-copy">
                                    <span class="dashboard-filter-option-label"><?= htmlspecialchars(trim((string) $account['name']), ENT_QUOTES, 'UTF-8') ?></span>
                                    <span class="dashboard-filter-option-meta"><?= htmlspecialchars((string) $account['email'], ENT_QUOTES, 'UTF-8') ?></span>
                                </span>
                                <svg class="dashboard-filter-check" viewBox="0 0 24 24" aria-hidden="true"><path d="m9 16.2-3.5-3.5L4 14.2l5 5L20 8.2l-1.5-1.4z"/></svg>
                            </li>
                        <?php endforeach; ?>
                        </ul>
                    </div>
                </form>
            </div>

            <!-- Summary boxes (on top, like the HR dashboard) -->
            <div class="admin-metrics">
                <a class="stat-card tone-blue reveal" style="--i:2" href="hr-list.php" title="Open HR Accounts">
                    <div class="stat-top">
                        <span class="stat-icon">
                            <svg width="22" height="22" viewBox="0 0 24 24" aria-hidden="true"><path d="M16 11c1.66 0 2.99-1.34 2.99-3S17.66 5 16 5c-1.66 0-3 1.34-3 3s1.34 3 3 3zm-8 0c1.66 0 2.99-1.34 2.99-3S9.66 5 8 5C6.34 5 5 6.34 5 8s1.34 3 3 3zm0 2c-2.33 0-7 1.17-7 3.5V19h14v-2.5c0-2.33-4.67-3.5-7-3.5zm8 0c-.29 0-.62.02-.97.05 1.16.84 1.97 1.97 1.97 3.45V19h6v-2.5c0-2.33-4.67-3.5-7-3.5z"/></svg>
                        </span>
                        <span class="card-title">Active HR Account</span>
                        <svg class="stat-go" width="22" height="22" viewBox="0 0 24 24" aria-hidden="true"><path d="M8.59 16.59L13.17 12 8.59 7.41 10 6l6 6-6 6z"/></svg>
                    </div>
                    <div class="card-value" data-count="<?php echo (int) $overview['hr_active']; ?>"><?php echo (int) $overview['hr_active']; ?></div>
                    <div class="stat-foot">
                        <div class="stat-bar"><span style="--w:<?php echo $hr_pct; ?>%"></span></div>
                        <span class="stat-note"><?php echo htmlspecialchars($hr_note, ENT_QUOTES, 'UTF-8'); ?></span>
                    </div>
                </a>

                <a class="stat-card tone-green reveal" style="--i:3" href="question-list.php" title="Open Questions">
                    <div class="stat-top">
                        <span class="stat-icon">
                            <svg width="22" height="22" viewBox="0 0 24 24" aria-hidden="true"><path d="M19 3h-4.18C14.4 1.84 13.3 1 12 1s-2.4.84-2.82 2H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm-7 0c.55 0 1 .45 1 1s-.45 1-1 1-1-.45-1-1 .45-1 1-1zm2 14H7v-2h7v2zm3-4H7v-2h10v2zm0-4H7V7h10v2z"/></svg>
                        </span>
                        <span class="card-title">Total Questionnaires</span>
                        <svg class="stat-go" width="22" height="22" viewBox="0 0 24 24" aria-hidden="true"><path d="M8.59 16.59L13.17 12 8.59 7.41 10 6l6 6-6 6z"/></svg>
                    </div>
                    <div class="card-value" data-count="<?php echo (int) $overview['questions']; ?>"><?php echo (int) $overview['questions']; ?></div>
                    <div class="stat-foot">
                        <div class="stat-bar"><span style="--w:100%"></span></div>
                        <span class="stat-note"><?php echo htmlspecialchars($q_note, ENT_QUOTES, 'UTF-8'); ?></span>
                    </div>
                </a>

                <a class="stat-card tone-red reveal" style="--i:4" href="#recentActivities" id="activityCard" title="See the activity list">
                    <div class="stat-top">
                        <span class="stat-icon">
                            <svg width="22" height="22" viewBox="0 0 24 24" aria-hidden="true"><path d="M13 3c-4.97 0-9 4.03-9 9H1l3.89 3.89.07.14L9 12H6c0-3.87 3.13-7 7-7s7 3.13 7 7-3.13 7-7 7c-1.93 0-3.68-.79-4.94-2.06l-1.42 1.42C8.27 19.99 10.51 21 13 21c4.97 0 9-4.03 9-9s-4.03-9-9-9zm-1 5v5l4.28 2.54.72-1.21-3.5-2.08V8H12z"/></svg>
                        </span>
                        <span class="card-title">Recent Activities</span>
                        <span class="new-chip" id="newChip" hidden></span>
                        <svg class="stat-go" width="22" height="22" viewBox="0 0 24 24" aria-hidden="true"><path d="M8.59 16.59L13.17 12 8.59 7.41 10 6l6 6-6 6z"/></svg>
                    </div>
                    <div class="card-value" data-count="<?php echo (int) $overview['activities_week']; ?>"><?php echo (int) $overview['activities_week']; ?></div>
                    <div class="stat-foot">
                        <div class="stat-bar"><span style="--w:<?php echo $log_pct; ?>%"></span></div>
                        <span class="stat-note"><?php echo htmlspecialchars($log_note, ENT_QUOTES, 'UTF-8'); ?></span>
                    </div>
                </a>
            </div>

            <!-- Questionnaires Statistics: one sideways bar (left to right) per question, the whole chart scrolls -->
            <section class="dash-card stats-card reveal" style="--i:5" aria-labelledby="statsTitle">
                <div class="card-top">
                    <div>
                        <h3 id="statsTitle">Questionnaires Statistics</h3>
                        <span class="chart-sub"><?php echo htmlspecialchars($stats_sub, ENT_QUOTES, 'UTF-8'); ?></span>
                    </div>
                    <div class="stats-tools">
                        <span class="stats-unit">Bar = % of applicants who chose the top answer</span>
                    </div>
                </div>

                <!-- Scrolls up/down when there are many questions, and sideways when the window is narrow -->
                <div class="stats-scroll" id="statsScroll" tabindex="0" role="region" aria-label="Questionnaires statistics chart, scrollable">
                    <div class="stats-inner">
                        <div class="chart-axis" aria-hidden="true">
                            <span></span>
                            <span class="axis-scale"><i>0</i><i>20</i><i>40</i><i>60</i><i>80</i><i>100</i></span>
                        </div>
                        <div class="stats-list" id="statsList"></div>
                    </div>
                </div>

                <!-- Legend under the chart (like the mockup); click a section to show only its questions -->
                <div class="stats-filter" id="statsFilter" role="group" aria-label="Show the questions of a section"></div>
            </section>

            <!-- Three small cards side by side: Failure Rate | Risk Flags | Applicant Volume -->
            <div class="insight-row">

                <!-- Question-Level Failure Rate (horizontal bars) -->
                <section class="dash-card insight-card reveal" style="--i:6" aria-labelledby="failTitle">
                    <div class="card-top">
                        <div>
                            <h3 id="failTitle">Question-Level Failure Rate</h3>
                            <span class="chart-sub">% of applicants failing each knockout question</span>
                        </div>
                    </div>
                    <?php if (!$failure['rows']): ?>
                    <p class="chart-empty">No knock-out questions yet.</p>
                    <?php else: ?>
                    <div class="hbar-wrap">
                    <ul class="hbar-list">
                        <?php foreach ($failure['rows'] as $k => $r): ?>
                        <li>
                            <a class="hbar<?php echo $k === 0 && $r['failed'] > 0 ? ' is-top' : ''; ?><?php echo $r['failed'] === 0 ? ' is-zero' : ''; ?>" href="question-list.php" style="--w:<?php echo (float) $r['rate']; ?>%; --c:var(--red-bright); --k:<?php echo $k; ?>"
                               title="<?php echo htmlspecialchars($r['label'] . ' - ' . $r['failed'] . ' of ' . $r['submissions'] . ' submitted questionnaires did not pass. Click to open Questions.', ENT_QUOTES, 'UTF-8'); ?>">
                                <span class="hbar-name"><b><?php echo htmlspecialchars(strtoupper($r['id']), ENT_QUOTES, 'UTF-8'); ?></b><?php echo htmlspecialchars(substr($r['label'], strlen($r['id']) + 2), ENT_QUOTES, 'UTF-8'); ?></span>
                                <span class="hbar-val"><?php echo (int) round($r['rate']); ?>%</span>
                                <span class="hbar-track"><span class="hbar-fill"></span></span>
                            </a>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                    </div>
                    <div class="ins-foot">
                        <span class="ins-chip"><span class="chip-label">Knock-out questions</span><span class="chip-value"><?php echo (int) $failure['knockouts']; ?></span></span>
                        <span class="ins-chip"><span class="chip-label">Highest failure</span><span class="chip-value"><?php echo htmlspecialchars(strtoupper($failure['rows'][0]['id']), ENT_QUOTES, 'UTF-8'); ?> &middot; <?php echo (int) round($failure['rows'][0]['rate']); ?>%</span></span>
                    </div>
                    <?php endif; ?>
                </section>

                <!-- Risk Flag Breakdown by Factor (horizontal bars) -->
                <section class="dash-card insight-card reveal" style="--i:7" aria-labelledby="riskTitle">
                    <div class="card-top">
                        <div>
                            <h3 id="riskTitle">Risk Flag Breakdown by Factor</h3>
                            <span class="chart-sub">Share of flags from each factor</span>
                        </div>
                    </div>
                    <div class="hbar-wrap">
                    <ul class="hbar-list">
                        <?php foreach ($risk['rows'] as $k => $r): ?>
                        <li>
                            <div class="hbar<?php echo $k === 0 ? ' is-top' : ''; ?><?php echo $r['count'] === 0 ? ' is-zero' : ''; ?>" style="--w:<?php echo (float) $r['share']; ?>%; --c:<?php echo htmlspecialchars($r['color'], ENT_QUOTES, 'UTF-8'); ?>; --k:<?php echo $k; ?>"
                                 title="<?php echo htmlspecialchars($r['label'] . ' - ' . $r['count'] . ' of ' . $risk['total'] . ' flags' . ($r['sample'] ? ' (sample figure)' : ''), ENT_QUOTES, 'UTF-8'); ?>">
                                <span class="hbar-name"><?php echo htmlspecialchars($r['label'], ENT_QUOTES, 'UTF-8'); ?><?php echo $r['sample'] ? '<em class="sample-tag">sample</em>' : ''; ?></span>
                                <span class="hbar-val"><?php echo (int) round($r['share']); ?>%</span>
                                <span class="hbar-track"><span class="hbar-fill"></span></span>
                            </div>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                    </div>
                    <div class="ins-foot">
                        <span class="ins-chip"><span class="chip-label">Flags raised</span><span class="chip-value"><?php echo (int) $risk['total']; ?></span></span>
                        <span class="ins-chip"><span class="chip-label">Top factor</span><span class="chip-value"><?php echo (int) $risk['total'] > 0 ? htmlspecialchars($risk['rows'][0]['label'], ENT_QUOTES, 'UTF-8') : '—'; ?></span></span>
                    </div>
                </section>

                <!-- Applicant Volume Over Time (bar chart, drawn by dashboardadmin.js) -->
                <section class="dash-card insight-card volume-card reveal" style="--i:8" aria-labelledby="volTitle">
                    <div class="card-top">
                        <div>
                            <h3 id="volTitle">Applicant Volume Over Time</h3>
                            <span class="chart-sub" id="volSub"><?= htmlspecialchars($volume_subject, ENT_QUOTES, 'UTF-8') ?> per week</span>
                        </div>
                        <div class="vol-toggle" id="volToggle" role="group" aria-label="Group applications by">
                            <button type="button" data-mode="week" aria-pressed="true">Week</button>
                            <button type="button" data-mode="month" aria-pressed="false">Month</button>
                        </div>
                    </div>
                    <div class="vol-chart" id="volChart"></div>
                    <div class="ins-foot three" id="volFoot"></div>
                </section>

            </div>

            <!-- Recent Activities: the latest 10 things HR and the Admin did in the system -->
            <section class="dash-card activity-card reveal" style="--i:9" id="recentActivities" aria-labelledby="activityTitle">
                <div class="card-top">
                    <div class="activity-title">
                        <h3 id="activityTitle">Recent Activities</h3>
                    </div>
                    <span class="activity-count"><?php echo htmlspecialchars($log_count, ENT_QUOTES, 'UTF-8'); ?></span>
                </div>
                <div class="table-responsive">
                    <table class="data-table activity-table">
                        <colgroup>
                            <col style="width: 18%">
                            <col style="width: 28%">
                            <col style="width: 38%">
                            <col style="width: 16%">
                        </colgroup>
                        <thead>
                            <tr>
                                <th>Date &amp; Time</th>
                                <th>Activities</th>
                                <th>Details</th>
                                <th>By</th>
                            </tr>
                        </thead>
                        <tbody id="activityBody"></tbody>
                    </table>
                </div>
            </section>

        </div>
    </main>

    <!-- Pass PHP Variables to JavaScript -->
    <script>
        window.ADMIN_NOTIFY = <?php
            try { echo json_encode(repo_admin_notify(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); }
            catch (Throwable $e) { echo json_encode(['activity' => [], 'recent' => [], 'expiring' => 0, 'days' => 7]); }
        ?>;
        window.ADMIN_DASH = <?php echo json_encode([
            'account_id' => (int) ($_SESSION['user_id'] ?? 0),
            'stats' => $stats,
            'overview' => [
                'hr_active' => $overview['hr_active'],
                'questions' => $overview['questions'],
                'sections' => $overview['sections'],
                'scope' => $scope_label,
            ],
            'volume_subject' => $volume_subject,
            'failure' => $failure,
            'risk' => $risk,
            'activity' => $recent,
            'volume' => $volume,
            'activity' => $recent,
        ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE); ?>;
    </script>
    <!-- Shared admin behaviour first, then this page's script -->
    <script src="../assets/js/dashboard-pdf.js?v=<?php echo @filemtime(__DIR__ . '/../assets/js/dashboard-pdf.js'); ?>"></script>
    <script src="../assets/js/admin.js?v=<?php echo @filemtime(__DIR__ . '/../assets/js/admin.js'); ?>"></script>
</body>
</html>