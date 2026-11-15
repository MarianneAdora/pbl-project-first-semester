<?php
session_start();

require_once __DIR__ . '/../src/Helpers/db.php';

// Restore session from "remember me" cookie when no session exists
if (empty($_SESSION['user_role']) && isset($_COOKIE['remember'])) {
    $pdo = db();
    $parts = explode('|', $_COOKIE['remember'], 2);
    if (count($parts) === 2) {
        $uid = (int) $parts[0];
        $token = $parts[1];
        if ($uid > 0 && $token !== '') {
            $stmt = $pdo->prepare(
                "SELECT user_id, role, name, email, remember_token, remember_token_expires_at
                 FROM users WHERE user_id = :id AND deleted_at IS NULL LIMIT 1"
            );
            $stmt->execute([':id' => $uid]);
            $row = $stmt->fetch();
            if ($row && !empty($row['remember_token']) && hash_equals($row['remember_token'], hash('sha256', $token))) {
                $expires = $row['remember_token_expires_at'];
                if ($expires && strtotime($expires) > time()) {
                    $_SESSION['user_role'] = $row['role'];
                    $_SESSION['user_email'] = strtolower(trim((string) $row['email']));
                    $_SESSION['user_name'] = (string) $row['name'];
                    $_SESSION['user_id'] = (int) $row['user_id'];
                }
            }
        }
    }
}

// No caching, so the Back button after logout never shows this page
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');

// Protection: Ensure only HR users can access this page
if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'hr') {
    header('Location: ../index.php');
    exit();
}

require_once __DIR__ . '/../src/Helpers/security.php';
$csrf = csrf_token();

date_default_timezone_set('Asia/Manila');

// Login/dashboard data now comes from the database (applicant table).
require_once __DIR__ . '/../src/Helpers/db.php';
require_once __DIR__ . '/../src/Repositories/ApplicantRepository.php';
require_once __DIR__ . '/hr-data.php';

try {
    repo_auto_archive_terminal_applicants();
} catch (Throwable $e) {
    error_log('dashboard auto-archive error: ' . $e->getMessage());
}

// Summary cards + Passed/Rejected chart are LIVE from the applicant table.
try {
    $stats = repo_dashboard_stats();
} catch (Throwable $e) {
    $stats = ['total' => 0, 'pending' => 0, 'in_progress' => 0, 'completed' => 0, 'passed' => 0, 'failed' => 0];
}

/* Original hard-coded numbers (kept for reference):
$stats = [
    'total'       => 35,
    'pending'     => 13,
    'in_progress' => 7,
    'completed'   => 15,
    'passed'      => 22,
    'failed'      => 13
];
*/

// Share of all applicants, used by the little bar on each summary card
function share(int $part, int $total): int
{
    return $total > 0 ? (int) round($part / $total * 100) : 0;
}

$pct = [
    'pending'     => share($stats['pending'], $stats['total']),
    'in_progress' => share($stats['in_progress'], $stats['total']),
    'completed'   => share($stats['completed'], $stats['total'])
];

$screened     = $stats['passed'] + $stats['failed'];
$pass_rate    = share($stats['passed'], $screened);
$passed_pct   = $pass_rate;
$failed_pct   = $screened > 0 ? 100 - $pass_rate : 0;

// The three analytics cards (Funnel Drop-Off, Time-to-Complete Distribution, Scheduling Turnaround Time).
// All computed live from the database: applicants + interview schedules.
$all_applicants = [];
$interviews     = [];
try {
    $pdo = db();
    $rows = $pdo->query(
        "SELECT applicant_id, name, email, phone_number, status, result, created_at, verified_at,
                invited_at, submitted_at, passed_at
         FROM applicant
         WHERE deleted_at IS NULL"
    )->fetchAll();
    foreach ($rows as $r) {
        $d = applicant_to_display($r);
        $all_applicants[] = [
            'id'          => (int) $r['applicant_id'],
            'name'        => (string) $r['name'],
            'email'       => (string) $r['email'],
            'contact'     => (string) ($r['phone_number'] ?? ''),
            'status'      => strtolower((string) ($d['status'] ?? '')),
            'result'      => strtolower((string) ($d['result'] ?? '')),
            'invited_at'  => $r['invited_at']  ? (string) $r['invited_at']  : '',
            'submitted_at'=> $r['submitted_at'] ? (string) $r['submitted_at'] : '',
            'passed_at'   => $r['passed_at']   ? (string) $r['passed_at']   : '',
        ];
    }
    // Interview answers: confirmed/rescheduled rows from the schedule table.
    // answered_at is when HR entered the final schedule; fall back to updated_at for older rows.
    $ivRows = $pdo->query(
        "SELECT applicant_id, status, answered_at, updated_at FROM schedule WHERE status IN ('confirmed', 'rescheduled')"
    )->fetchAll();
    foreach ($ivRows as $r) {
        $interviews[(int) $r['applicant_id']] = [
            'status'      => (string) $r['status'],
            'answered_at' => (string) ($r['answered_at'] ?? $r['updated_at']),
        ];
    }
} catch (Throwable $e) {
    $all_applicants = [];
    $interviews     = [];
}
try {
    $insights = static_dashboard_insights($all_applicants, $interviews);
} catch (Throwable $e) {
    $insights = static_dashboard_empty_insights();     // the cards show "no data" rather than the page failing
}
$funnel     = $insights['funnel'];
$duration   = $insights['duration'];
$turnaround = $insights['turnaround'];

// Funnel geometry (an SVG drawn here, so it also shows without JavaScript).
// Each stage is a trapezoid: its top edge is as wide as its own count, its bottom edge as wide as the NEXT stage's count.
$fn_colors = ['#1c8adb', '#159a43', '#f0b100', '#f26a1b', '#d1122a'];     // same order as the wireframe: blue, green, yellow, orange, red
$fn_w = 300; $fn_h = 58; $fn_gap = 4; $fn_min = 54;
$fn_top = max(1, (int) $funnel['stages'][0]['count']);
$fn_widths = [];
foreach ($funnel['stages'] as $s) {
    $fn_widths[] = $s['count'] > 0 ? max($fn_min, $fn_w * $s['count'] / $fn_top) : $fn_min * 0.6;
}
// Rounds the corners of a stage: each corner is cut back by $r and joined with a small curve.
$fn_round = function (array $p, $r) {
    $n = count($p); $d = '';
    for ($k = 0; $k < $n; $k++) {
        $a = $p[($k + $n - 1) % $n]; $b = $p[$k]; $c = $p[($k + 1) % $n];
        $l1 = max(0.001, hypot($b[0] - $a[0], $b[1] - $a[1]));
        $l2 = max(0.001, hypot($c[0] - $b[0], $c[1] - $b[1]));
        $t1 = min($r, $l1 / 2); $t2 = min($r, $l2 / 2);
        $d .= sprintf('%s%.1f,%.1f Q%.1f,%.1f %.1f,%.1f ', $k ? 'L' : 'M',
            $b[0] + ($a[0] - $b[0]) / $l1 * $t1, $b[1] + ($a[1] - $b[1]) / $l1 * $t1,
            $b[0], $b[1],
            $b[0] + ($c[0] - $b[0]) / $l2 * $t2, $b[1] + ($c[1] - $b[1]) / $l2 * $t2);
    }
    return $d . 'Z';
};
$fn_height = count($funnel['stages']) * ($fn_h + $fn_gap) - $fn_gap;
$fn_has_data = $funnel['stages'][0]['count'] > 0;

// Name + greeting from the database (users table). Falls back to session data if the query fails.
$display_name = trim((string) ($_SESSION['user_name'] ?? 'HR'));
$profile_email = trim((string) ($_SESSION['user_email'] ?? ''));

try {
    $pdo = db();
    $stmt = $pdo->prepare("SELECT name, email, role FROM users WHERE user_id = :id AND deleted_at IS NULL LIMIT 1");
    $stmt->execute([':id' => (int) ($_SESSION['user_id'] ?? 0)]);
    $profileRow = $stmt->fetch();
    if ($profileRow) {
        $display_name = (string) $profileRow['name'];
        $profile_email = (string) $profileRow['email'];
        $profile_role_label = $profileRow['role'] === 'admin' ? 'Administrator' : 'HR Staff';
    } else {
        $profile_role_label = 'HR Staff';
    }
} catch (Throwable $e) {
    $profile_role_label = 'HR Staff';
}

$profile = [
    'name'           => $display_name,
    'email'          => $profile_email,
    'role'           => $profile_role_label,
    'role_label'     => $profile_role_label,
    'avatar'         => null,
    'avatar_url'     => '',
    'avatar_base'    => 'data/avatars/',
    'initials'       => static_profile_initials($display_name),
    'request'        => null
];
// Use the profile saved in Settings (static store + Cloudinary) for the picture and name.
try {
    $sp = static_profile_for_page('data/avatars/');
    if (trim((string) ($sp['name'] ?? '')) !== '') $display_name = (string) $sp['name'];
    $profile['name']       = $display_name;
    $profile['avatar']     = $sp['avatar'] ?? null;
    $profile['avatar_url'] = $sp['avatar_url'] ?? '';
    $profile['initials']   = static_profile_initials($display_name);
} catch (Throwable $e) {
    // keep fallbacks above
}
$avatar_html = static_profile_avatar_html($display_name, $profile['avatar_url']);

// IDs of EVERY applicant whose status is Completed (backend: SELECT id ... WHERE status = 'Completed').
// The red mark + the Dashboard notification badge are built from this list; an applicant stops
// counting as "new" once HR opens their details page.
$completed_ids = [];
try {
    foreach (repo_applicants_active() as $a) {
        if (($a['status'] ?? '') === 'Completed') $completed_ids[] = (int) $a['id'];
    }
} catch (Throwable $e) {
    $completed_ids = [];
}

// What HR did (add / delete / restore / interview ...), newest first. It is the list behind the bell in the
// header, and the same lines the Admin sees under Recent Activities. Preferences (Settings) are never in it.
// BACKEND: SELECT activity, details, created_at AS at FROM activity_log WHERE actor_role = 'hr' ORDER BY created_at DESC LIMIT 30
$hr_feed = [];
try {
    foreach (repo_recent_activity(null, 30) as $row) {
        if (strcasecmp((string) ($row['by'] ?? ''), 'Admin') === 0) continue;
        $hr_feed[] = [
            'at'       => (string) ($row['at'] ?? ''),
            'activity' => (string) ($row['activity'] ?? ''),
            'details'  => (string) ($row['details'] ?? ''),
            'by'       => (string) ($row['by'] ?? ''),
        ];
        if (count($hr_feed) >= 30) break;
    }
} catch (Throwable $e) {
    $hr_feed = [];
}
/* Original hard-coded list (kept for reference):
$completed_ids = [103, 104, 106, 108, 109, 111, 114, 116, 119, 121, 124, 126, 129, 131, 135];
*/

// Recent applicants = newest applicants by insertion order.
// 'updated' is the creation time shown to HR; applicant_id keeps newly added rows on top
// even when older imported/demo rows have a future created_at timestamp.
// Falls back to the sample list when the DB is unreachable, so the page never breaks.
//   status: 'Pending' | 'In Progress' | 'Completed'    result: 'Passed' | 'Rejected' | null
try {
    require_once __DIR__ . '/../src/Repositories/ApplicantRepository.php';
    $recent_applicants = repo_applicants_recent(null, 10);
} catch (Throwable $e) {
    $recent_applicants = [];
}
/* Original hard-coded list (kept for reference):
$recent_applicants = [];
*/

// Never more than 10, even if the query returns more.
$recent_applicants = array_slice($recent_applicants, 0, 10);

// "Today, 10:52 AM" / "Yesterday" / "Sep 30, 2026"
function format_updated(string $datetime): string
{
    $ts = strtotime($datetime);
    if ($ts === false) return $datetime;
    $day = date('Y-m-d', $ts);
    if ($day === date('Y-m-d'))                       return 'Today, ' . date('g:i A', $ts);
    if ($day === date('Y-m-d', strtotime('yesterday'))) return 'Yesterday, ' . date('g:i A', $ts);
    return date('M j, Y', $ts);
}

// Maps any status text to its pill style. Unknown statuses get a neutral grey pill.
function status_badge(string $status): string
{
    $map = [
        'pending'     => 'status-pending',
        'in progress' => 'status-progress',
        'completed'   => 'status-completed',
        'passed'      => 'status-passed',
        'failed'      => 'status-failed',
        'rejected'    => 'status-failed'
    ];
    return $map[strtolower(trim($status))] ?? 'status-default';
}
?>
<!DOCTYPE html>
<html lang="en" data-page="dashboardhr">
<head>
    <meta charset="UTF-8">
    <meta name="csrf-token" content="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>HR Dashboard - Expertise Portal</title>

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

    </script>

    <!-- Google Fonts & Chart.js -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

    <!-- hr.css = shared hr styles + every hr page (this page is picked by data-page="dashboardhr" on <html>) -->
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

    <!-- Sidebar Component with Enhanced 3D Shadows -->
    <aside class="sidebar" id="sidebar">
        <!-- Top Header & Bigger Aligned Brand Logo -->
        <div class="sidebar-header reveal" style="--i:0">
            <div class="brand-container">
                <img src="../assets/images/expertise2.png" alt="Expertise Logo" class="brand-logo">
            </div>
            <button class="toggle-btn" id="sidebarToggle" title="Toggle Sidebar">
                <svg viewBox="0 0 24 24"><path d="M15.41 7.41L14 6l-6 6 6 6 1.41-1.41L10.83 12z"/></svg>
            </button>
        </div>

        <!-- Your profile: click it to open Settings (lives in the sidebar, under the logo) -->
        <a class="profile-chip sidebar-profile reveal" id="profileChip" href="setting-hr.php" style="--i:1" title="<?php echo htmlspecialchars($display_name . ' - open your profile in Settings', ENT_QUOTES, 'UTF-8'); ?>" aria-label="<?php echo htmlspecialchars($display_name . ', ' . $profile['role_label'] . '. Open your profile in Settings', ENT_QUOTES, 'UTF-8'); ?>">
            <?php echo $avatar_html; ?>
            <span class="profile-meta">
                <strong><?php echo htmlspecialchars($display_name, ENT_QUOTES, 'UTF-8'); ?></strong>
                <small><?php echo htmlspecialchars($profile['role_label'], ENT_QUOTES, 'UTF-8'); ?></small>
            </span>
        </a>

        <!-- Navigation Menu -->
        <nav class="sidebar-nav">
            <a href="dashboard-hr.php" class="nav-item active reveal" style="--i:1">
                <svg class="nav-icon" viewBox="0 0 24 24"><path d="M10 20v-6h4v6h5v-8h3L12 3 2 12h3v8z"/></svg>
                <span class="nav-text">Dashboard</span>
            </a>

            <!-- Applicants Dropdown Item -->
            <div class="nav-group reveal" id="applicantsGroup" style="--i:2">
                <div class="nav-dropdown-wrapper">
                    <!-- Main Applicants Button -->
                    <a href="screening-list.php" class="nav-item nav-main-link" id="applicantsMainLink">
                        <svg class="nav-icon" viewBox="0 0 24 24"><path d="M16 11c1.66 0 2.99-1.34 2.99-3S17.66 5 16 5c-1.66 0-3 1.34-3 3s1.34 3 3 3zm-8 0c1.66 0 2.99-1.34 2.99-3S9.66 5 8 5C6.34 5 5 6.34 5 8s1.34 3 3 3zm0 2c-2.33 0-7 1.17-7 3.5V19h14v-2.5c0-2.33-4.67-3.5-7-3.5zm8 0c-.29 0-.62.02-.97.05 1.16.84 1.97 1.97 1.97 3.45V19h6v-2.5c0-2.33-4.67-3.5-7-3.5z"/></svg>
                        <span class="nav-text">Applicants</span>
                    </a>
                    <!-- Pure White Arrow Toggle Button -->
                    <button class="dropdown-arrow-btn" id="arrowToggleBtn" title="Toggle Menu">
                        <svg class="arrow-icon" viewBox="0 0 24 24"><path d="M7.41 8.59L12 13.17l4.59-4.58L18 10l-6 6-6-6z"/></svg>
                    </button>
                </div>
                <div class="sub-menu" id="applicantsSubMenu">
                    <a href="screening-list.php" class="sub-item"><span class="sub-text">Screening List</span></a>
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

        <!-- Sidebar Footer Options -->
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

    <!-- Main Content Panel Area -->
    <main class="main-content">
        <!-- Top Greeting Header -->
        <header class="content-header has-bell has-profile reveal" style="--i:1">
            <div class="header-start">
                <div class="header-text">
                    <h1 id="greeting" title="Hello, <?php echo htmlspecialchars($display_name); ?>!">Hello, <?php echo htmlspecialchars($display_name); ?>!</h1>
                </div>
            </div>

            <div class="header-tools">
            <button type="button" class="hr-pdf-download" id="hrStatsPdfBtn" title="Download dashboard statistics as a PDF file">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M19 9h-4V3H9v6H5l7 7 7-7zM5 18v2h14v-2H5z"/></svg>
                Download PDF
            </button>
            <!-- Notification bell: your recent activity (the Admin sees the same lines) + completed screenings to review -->
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
                    <p class="notif-foot">Your activity, including changes to your name, email and picture, also appears in the Admin's Recent Activities. Theme and display preferences are not included.</p>
                </div>
            </div>
            </div>
        </header>

        <!-- Dashboard Grid -->
        <div class="dashboard-grid">

            <!-- Summary Stat Cards -->
            <div class="metrics-container">
                <div class="stat-card tone-red reveal" style="--i:2" data-url="screening-list.php?status=all">
                    <div class="stat-top">
                        <span class="stat-icon">
                            <svg viewBox="0 0 24 24"><path d="M16 11c1.66 0 2.99-1.34 2.99-3S17.66 5 16 5c-1.66 0-3 1.34-3 3s1.34 3 3 3zm-8 0c1.66 0 2.99-1.34 2.99-3S9.66 5 8 5C6.34 5 5 6.34 5 8s1.34 3 3 3zm0 2c-2.33 0-7 1.17-7 3.5V19h14v-2.5c0-2.33-4.67-3.5-7-3.5zm8 0c-.29 0-.62.02-.97.05 1.16.84 1.97 1.97 1.97 3.45V19h6v-2.5c0-2.33-4.67-3.5-7-3.5z"/></svg>
                        </span>
                        <span class="card-title">Total Applicants</span>
                        <svg class="stat-go" viewBox="0 0 24 24"><path d="M8.59 16.59L13.17 12 8.59 7.41 10 6l6 6-6 6z"/></svg>
                    </div>
                    <div class="card-value" data-count="<?php echo (int) $stats['total']; ?>"><?php echo (int) $stats['total']; ?></div>
                    <div class="stat-foot">
                        <div class="stat-bar"><span style="--w:100%"></span></div>
                        <span class="stat-note">All registered applicants</span>
                    </div>
                </div>

                <div class="stat-card tone-amber reveal" style="--i:3" data-url="screening-list.php?status=Pending">
                    <div class="stat-top">
                        <span class="stat-icon">
                            <svg viewBox="0 0 24 24"><path d="M11.99 2C6.47 2 2 6.48 2 12s4.47 10 9.99 10C17.52 22 22 17.52 22 12S17.52 2 11.99 2zM12 20c-4.42 0-8-3.58-8-8s3.58-8 8-8 8 3.58 8 8-3.58 8-8 8zm.5-13H11v6l5.25 3.15.75-1.23-4.5-2.67z"/></svg>
                        </span>
                        <span class="card-title">Pending</span>
                        <svg class="stat-go" viewBox="0 0 24 24"><path d="M8.59 16.59L13.17 12 8.59 7.41 10 6l6 6-6 6z"/></svg>
                    </div>
                    <div class="card-value" data-count="<?php echo (int) $stats['pending']; ?>"><?php echo (int) $stats['pending']; ?></div>
                    <div class="stat-foot">
                        <div class="stat-bar"><span style="--w:<?php echo $pct['pending']; ?>%"></span></div>
                        <span class="stat-note"><?php echo $pct['pending']; ?>% waiting to start</span>
                    </div>
                </div>

                <div class="stat-card tone-blue reveal" style="--i:4" data-url="screening-list.php?status=In Progress">
                    <div class="stat-top">
                        <span class="stat-icon">
                            <svg viewBox="0 0 24 24"><path d="M12 4V1L8 5l4 4V6c3.31 0 6 2.69 6 6 0 1.01-.25 1.97-.7 2.8l1.46 1.46C19.54 15.03 20 13.57 20 12c0-4.42-3.58-8-8-8zm0 14c-3.31 0-6-2.69-6-6 0-1.01.25-1.97.7-2.8L5.24 7.74C4.46 8.97 4 10.43 4 12c0 4.42 3.58 8 8 8v3l4-4-4-4v3z"/></svg>
                        </span>
                        <span class="card-title">In Progress</span>
                        <svg class="stat-go" viewBox="0 0 24 24"><path d="M8.59 16.59L13.17 12 8.59 7.41 10 6l6 6-6 6z"/></svg>
                    </div>
                    <div class="card-value" data-count="<?php echo (int) $stats['in_progress']; ?>"><?php echo (int) $stats['in_progress']; ?></div>
                    <div class="stat-foot">
                        <div class="stat-bar"><span style="--w:<?php echo $pct['in_progress']; ?>%"></span></div>
                        <span class="stat-note"><?php echo $pct['in_progress']; ?>% taking the screening</span>
                    </div>
                </div>

                <div class="stat-card tone-green reveal" style="--i:5" data-url="screening-list.php?status=Completed">
                    <div class="stat-top">
                        <span class="stat-icon">
                            <svg viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 15l-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z"/></svg>
                        </span>
                        <span class="card-title">Completed</span>
                        <span class="new-chip" id="completedNewChip" hidden>0 new</span>
                        <svg class="stat-go" viewBox="0 0 24 24"><path d="M8.59 16.59L13.17 12 8.59 7.41 10 6l6 6-6 6z"/></svg>
                    </div>
                    <div class="card-value" data-count="<?php echo (int) $stats['completed']; ?>"><?php echo (int) $stats['completed']; ?></div>
                    <div class="stat-foot">
                        <div class="stat-bar"><span style="--w:<?php echo $pct['completed']; ?>%"></span></div>
                        <span class="stat-note"><?php echo $pct['completed']; ?>% finished the screening</span>
                    </div>
                </div>
            </div>

            <!-- Funnel Drop-Off: how many applicants make it through each stage -->
            <div class="funnel-card insight-card reveal" style="--i:6">
                <div class="chart-head">
                    <h3>Funnel Drop-Off</h3>
                    <span class="chart-sub">How many applicants make it through each stage</span>
                </div>

                <?php if (!$fn_has_data): ?>
                <p class="chart-empty block-empty">No applicants yet.</p>
                <?php else: ?>
                <div class="funnel-body" id="funnelBody">
                    <div class="funnel-fig">
                        <svg class="funnel-svg" viewBox="0 0 <?php echo $fn_w; ?> <?php echo $fn_height; ?>" preserveAspectRatio="none" role="img" aria-label="Funnel of applicants through each screening stage">
                            <?php foreach ($funnel['stages'] as $i => $s):
                                $wt = $fn_widths[$i];
                                $wb = $i < count($fn_widths) - 1 ? min($wt, $fn_widths[$i + 1]) : $wt * 0.55;
                                $y0 = $i * ($fn_h + $fn_gap);
                                $cx = $fn_w / 2;
                                $pts = $fn_round([
                                    [$cx - $wt / 2, $y0],  [$cx + $wt / 2, $y0],
                                    [$cx + $wb / 2, $y0 + $fn_h],  [$cx - $wb / 2, $y0 + $fn_h]
                                ], 9);
                            ?>
                            <g class="fn-stage<?php echo $s['url'] !== '' ? ' is-link' : ''; ?>" data-stage="<?php echo $i; ?>" data-url="<?php echo htmlspecialchars($s['url']); ?>" style="--c:<?php echo $fn_colors[$i]; ?>; --k:<?php echo $i; ?>">
                                <title><?php echo htmlspecialchars($s['label']); ?>: <?php echo (int) $s['count']; ?></title>
                                <path d="<?php echo $pts; ?>"></path>
                            </g>
                            <?php endforeach; ?>
                        </svg>
                        <?php foreach ($funnel['stages'] as $i => $s): ?>
                        <span class="fn-count" data-stage="<?php echo $i; ?>" style="--k:<?php echo $i; ?>; top:<?php echo round(($i * ($fn_h + $fn_gap) + $fn_h / 2) / $fn_height * 100, 2); ?>%"><?php echo (int) $s['count']; ?></span>
                        <?php endforeach; ?>
                    </div>

                    <ul class="funnel-legend" id="funnelLegend">
                        <?php foreach ($funnel['stages'] as $i => $s): ?>
                        <li class="legend-item fl-item<?php echo $s['url'] !== '' ? ' is-link' : ''; ?>" data-stage="<?php echo $i; ?>" data-url="<?php echo htmlspecialchars($s['url']); ?>" style="--k:<?php echo $i; ?>"<?php echo $s['url'] !== '' ? ' role="link" tabindex="0"' : ''; ?>>
                            <span class="legend-dot" style="background-color:<?php echo $fn_colors[$i]; ?>"></span>
                            <span class="legend-text">
                                <?php echo htmlspecialchars($s['label']); ?>
                                <span class="fl-sub<?php echo $i > 0 && $s['lost'] > 0 ? ' is-loss' : ''; ?>"><?php
                                    if ($i === 0)              echo 'All registered applicants';
                                    elseif ($s['lost'] > 0)    echo '&minus;' . (int) $s['lost'] . ' dropped (' . (int) $s['lost_pct'] . '%)';
                                    else                       echo 'No one dropped';
                                ?></span>
                            </span>
                            <span class="legend-num"><?php echo (int) $s['count']; ?></span>
                            <span class="fl-pct"><?php echo (int) $s['of_applied']; ?>%</span>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                </div>

                <div class="insight-foot">
                    <span class="insight-chip">
                        <span class="chip-label">Reach an interview</span>
                        <span class="chip-value"><?php echo (int) $funnel['overall_pct']; ?>% of applicants</span>
                    </span>
                    <span class="insight-chip">
                        <span class="chip-label">Biggest drop-off</span>
                        <span class="chip-value"><?php echo $funnel['biggest']
                            ? htmlspecialchars($funnel['biggest']['from']) . ' &rarr; ' . htmlspecialchars($funnel['biggest']['to']) . ' (&minus;' . (int) $funnel['biggest']['lost'] . ')'
                            : 'None yet'; ?></span>
                    </span>
                </div>
                <?php endif; ?>
            </div>

            <!-- Pie Chart Component -->
            <div class="chart-card insight-card reveal" style="--i:7">
                <div class="chart-head">
                    <h3>Passed/Rejected</h3>
                    <span class="chart-sub">Out of <?php echo (int) $screened; ?> screened applicants</span>
                </div>
                <div class="chart-wrapper">
                    <canvas id="passedRejectedChart"></canvas>
                    <div class="chart-center" id="chartCenter">
                        <strong id="chartCenterValue"><?php echo $pass_rate; ?>%</strong>
                        <span id="chartCenterLabel">Pass rate</span>
                    </div>
                </div>
                <div class="chart-legend">
                    <span class="legend-item" id="legendPassed" role="link" tabindex="0">
                        <span class="legend-dot green"></span>
                        <span class="legend-text">Passed</span>
                        <span class="legend-num"><?php echo (int) $stats['passed']; ?></span>
                    </span>
                    <span class="legend-item" id="legendFailed" role="link" tabindex="0">
                        <span class="legend-dot red"></span>
                        <span class="legend-text">Rejected</span>
                        <span class="legend-num"><?php echo (int) $stats['failed']; ?></span>
                    </span>
                </div>
            </div>

            <!-- Time-to-Complete Distribution: how long applicants take from invite to submission (histogram) -->
            <div class="hist-card insight-card reveal" style="--i:8">
                <div class="chart-head">
                    <h3>Time-to-Complete Distribution</h3>
                    <span class="chart-sub">Invite to submission &middot; <?php echo (int) $duration['n']; ?> completed screening<?php echo (int) $duration['n'] === 1 ? '' : 's'; ?></span>
                </div>

                <?php if ((int) $duration['n'] === 0): ?>
                <p class="chart-empty block-empty">No completed screenings yet.</p>
                <?php else: ?>
                <div class="hist-wrap">
                    <canvas id="timeToCompleteChart" role="img" aria-label="Histogram of how long applicants take from invite to submission"></canvas>
                </div>
                <div class="insight-foot three">
                    <span class="insight-chip">
                        <span class="chip-label">Median</span>
                        <span class="chip-value"><?php echo htmlspecialchars((string) $duration['median']); ?></span>
                    </span>
                    <span class="insight-chip">
                        <span class="chip-label">Fastest</span>
                        <span class="chip-value"><?php echo htmlspecialchars((string) $duration['fastest']); ?></span>
                    </span>
                    <span class="insight-chip">
                        <span class="chip-label">Slowest</span>
                        <span class="chip-value"><?php echo htmlspecialchars((string) $duration['slowest']); ?></span>
                    </span>
                </div>
                <?php endif; ?>
            </div>

            <!-- Right column of the third row: Scheduling Turnaround Time + Quick Action -->
            <div class="side-stack">

                <!-- Scheduling Turnaround Time: time between PASS and HR entering the final interview schedule -->
                <div class="ta-card is-<?php echo htmlspecialchars($turnaround['state']); ?> reveal" style="--i:9" data-url="screening-list.php?status=Passed" role="link" tabindex="0" aria-label="Scheduling Turnaround Time">
                    <div class="ta-icon">
                        <svg viewBox="0 0 24 24" aria-hidden="true">
                            <circle class="ta-face" cx="12" cy="12" r="9.2"></circle>
                            <line class="ta-hand-h" x1="12" y1="12" x2="12" y2="8.2"></line>
                            <line class="ta-hand-m" x1="12" y1="12" x2="12" y2="5.6"></line>
                            <circle class="ta-pin" cx="12" cy="12" r="1.3"></circle>
                        </svg>
                    </div>
                    <div class="ta-body">
                        <span class="card-title">Scheduling Turnaround Time</span>
                        <?php if ($turnaround['avg']): ?>
                        <div class="ta-value">
                            <span class="card-value" data-count="<?php echo $turnaround['avg']['value']; ?>" data-decimals="<?php echo (int) $turnaround['avg']['decimals']; ?>"><?php echo number_format($turnaround['avg']['value'], (int) $turnaround['avg']['decimals']); ?></span>
                            <span class="ta-unit"><?php echo htmlspecialchars($turnaround['avg']['unit']); ?></span>
                        </div>
                        <div class="stat-bar"><span style="--w:<?php echo (int) $turnaround['bar_pct']; ?>%"></span></div>
                        <span class="stat-note"><?php
                            echo $turnaround['state'] === 'ok' ? 'Within the ' . htmlspecialchars($turnaround['target_label']) . ' target' : 'Slower than the ' . htmlspecialchars($turnaround['target_label']) . ' target';
                            echo ' &middot; ' . (int) $turnaround['n'] . ' scheduled';
                            if ($turnaround['waiting'] > 0) echo ' &middot; ' . (int) $turnaround['waiting'] . ' waiting';
                        ?></span>
                        <?php else: ?>
                        <div class="ta-value"><span class="card-value">&ndash;</span></div>
                        <div class="stat-bar"><span style="--w:0%"></span></div>
                        <span class="stat-note"><?php echo (int) $turnaround['waiting'] > 0 ? (int) $turnaround['waiting'] . ' passed, waiting for a schedule' : 'No interviews scheduled yet'; ?></span>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Quick Action Box -->
                <div class="quick-action-card reveal" style="--i:10">
                    <div class="avatar-circle">
                        <svg viewBox="0 0 24 24"><path d="M15 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm-9-2V7H4v3H1v2h3v3h2v-3h3v-2H6zm9 4c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z"/></svg>
                    </div>
                    <div class="qa-body">
                        <p class="quick-action-text">Add Shortlisted Applicants</p>
                        <p class="quick-action-sub">Register a new applicant and add them to the screening list.</p>
                        <a href="add-applicants.php" class="btn-add-new">
                            <svg viewBox="0 0 24 24"><path d="M19 13h-6v6h-2v-6H5v-2h6V5h2v6h6v2z"/></svg>
                            ADD NEW
                        </a>
                    </div>
                </div>
            </div>

            <!-- Recent Applicants Table -->
            <div class="recent-card reveal" style="--i:11">
                <div class="card-top">
                    <h3>Recent Applicants</h3>
                    <a href="screening-list.php" class="view-all">View All
                        <svg viewBox="0 0 24 24"><path d="M8.59 16.59L13.17 12 8.59 7.41 10 6l6 6-6 6z"/></svg>
                    </a>
                </div>
                <div class="table-responsive">
                    <table class="applicants-table">
                        <colgroup>
                            <col style="width: 42%">
                            <col style="width: 27%">
                            <col style="width: 31%">
                        </colgroup>
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Last Update</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody id="recentApplicantsBody">
                            <?php if (empty($recent_applicants)): ?>
                            <tr>
                                <td colspan="3" class="empty-row">No applicants yet.</td>
                            </tr>
                            <?php endif; ?>
                            <?php foreach ($recent_applicants as $applicant): ?>
                            <?php $is_completed = strtolower($applicant['status']) === 'completed'; ?>
                            <tr class="clickable-row"
                                data-id="<?php echo (int) $applicant['id']; ?>"
                                data-completed="<?php echo $is_completed ? '1' : '0'; ?>"
                                data-url="details.php?id=<?php echo (int) $applicant['id']; ?>">
                                <td title="<?php echo htmlspecialchars($applicant['name']); ?>">
                                    <span class="name-cell">
                                        <span class="unseen-dot" title="Completed screening, not viewed yet"></span>
                                        <strong><?php echo htmlspecialchars($applicant['name']); ?></strong>
                                    </span>
                                </td>
                                <td><?php echo htmlspecialchars(format_updated($applicant['updated'])); ?></td>
                                <td>
                                    <span class="status-pill <?php echo status_badge($applicant['status']); ?>">
                                        <?php echo htmlspecialchars($applicant['status']); ?>
                                    </span>
                                    <?php if ($is_completed && !empty($applicant['result'])): ?>
                                    <span class="status-pill <?php echo status_badge($applicant['result']); ?>">
                                        <?php echo htmlspecialchars($applicant['result']); ?>
                                    </span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        </div>
    </main>

    <!-- Pass PHP Variables to JavaScript -->
    <script>
        const chartData = {
            passed: <?php echo (int) $stats['passed']; ?>,
            failed: <?php echo (int) $stats['failed']; ?>
        };
        // every applicant with status Completed (drives the red marks + the Dashboard badge)
        const completedIds = <?php echo json_encode(array_map('intval', $completed_ids)); ?>;
        // numbers behind the Time-to-Complete histogram (the funnel + the turnaround card are drawn by PHP)
        window.DASH_INSIGHTS = <?php echo json_encode([
            'duration' => ['labels' => $duration['labels'], 'titles' => $duration['titles'], 'counts' => $duration['counts'], 'n' => $duration['n']]
        ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
        window.HR_DASH = <?php echo json_encode([
            'stats' => $stats,
            'funnel' => $funnel,
            'duration' => $duration,
            'turnaround' => $turnaround,
            'pass_rate' => $pass_rate
        ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE); ?>;
        // HR's recent activity (the bell in the header)
        window.HR_ACTIVITY = <?php echo json_encode($hr_feed, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE); ?>;
    </script>
    <!-- Shared HR behaviour first, then this page's script -->
    <script>window.HR_SERVER_ARCHIVE = <?php
        $archiveSync = ['ids' => [], 'at' => new stdClass()];
        try {
            $pdo = db();
            $rows = $pdo->query("SELECT applicant_id, deleted_at FROM applicant WHERE deleted_at IS NOT NULL")->fetchAll();
            $ids = []; $at = [];
            foreach ($rows as $r) {
                $id = (int) $r['applicant_id'];
                $ids[] = $id;
                $ts = strtotime((string) $r['deleted_at']);
                if ($ts !== false) $at[$id] = $ts * 1000;
            }
            $archiveSync = ['ids' => $ids, 'at' => (object) $at];
        } catch (Throwable $e) {
            // leave empty
        }
        echo json_encode($archiveSync, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    ?>;</script>
    <script>window.HR_ACCOUNT = <?php echo json_encode(['id' => (int) ($_SESSION['user_id'] ?? 0)], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;</script>
    <script src="../assets/js/dashboard-pdf.js?v=<?php echo @filemtime(__DIR__ . '/../assets/js/dashboard-pdf.js'); ?>"></script>
    <script src="../assets/js/hr.js?v=<?php echo @filemtime(__DIR__ . '/../assets/js/hr.js'); ?>"></script>
</body>
</html>