<?php
session_start();
require_once __DIR__ . '/../src/Helpers/security.php';
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

try {
    $archive = repo_admin_archive();
} catch (Throwable $e) {
    error_log('archive-admin.php DB error: ' . $e->getMessage());
    $archive = ['questions' => [], 'accounts' => []];
}
?>
<!DOCTYPE html>
<html lang="en" data-page="archiveadmin">
<head>
    <meta charset="UTF-8">
    <meta name="csrf-token" content="<?= attr($csrf) ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Archive - Expertise Admin</title>

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
        }
    </script>

    <!-- Plus Jakarta Sans Google Font -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">

    <!-- admin.css = shared admin styles + every admin page (this page is picked by data-page="archiveadmin" on <html>) -->
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
        require_once __DIR__ . '/../hr/hr-data.php';
        $sbp_name = trim((string) ($_SESSION['user_name'] ?? 'Administrator'));
        $sbp_role = 'Administrator';
        try {
            $sbp = static_profile_for_page('../hr/data/avatars/');
            if (trim((string) ($sbp['name'] ?? '')) !== '') $sbp_name = (string) $sbp['name'];
            $sbp_role = (string) ($sbp['role_label'] ?? 'Administrator');
            $sbp_html = static_profile_avatar_html($sbp_name, (string) ($sbp['avatar_url'] ?? ''));
        } catch (Throwable $e) {
            $sbp_html = static_profile_avatar_html($sbp_name, '');
        }
        ?>
        <a class="profile-chip sidebar-profile reveal" id="profileChip" href="settings-admin.php" style="--i:1" title="<?php echo htmlspecialchars($sbp_name . ' - open your profile in Settings', ENT_QUOTES, 'UTF-8'); ?>" aria-label="<?php echo htmlspecialchars($sbp_name . ', ' . $sbp_role . '. Open your profile in Settings', ENT_QUOTES, 'UTF-8'); ?>">
            <?php echo $sbp_html; ?>
            <span class="profile-meta">
                <strong><?php echo htmlspecialchars($sbp_name, ENT_QUOTES, 'UTF-8'); ?></strong>
                <small><?php echo htmlspecialchars($sbp_role, ENT_QUOTES, 'UTF-8'); ?></small>
            </span>
        </a>

        <nav class="sidebar-nav" aria-label="Admin">
            <a href="dashboard-admin.php" class="nav-item reveal" id="dashboardLink" style="--i:1" title="Dashboard">
                <svg class="nav-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M10 20v-6h4v6h5v-8h3L12 3 2 12h3v8z"/></svg>
                <span class="nav-text">Dashboard</span>
                <span class="nav-badge alert" id="dashboardBadge" hidden></span>
            </a>

            <a href="hr-list.php" class="nav-item reveal" style="--i:2" title="HR Accounts">
                <svg class="nav-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M16 11c1.66 0 2.99-1.34 2.99-3S17.66 5 16 5c-1.66 0-3 1.34-3 3s1.34 3 3 3zm-8 0c1.66 0 2.99-1.34 2.99-3S9.66 5 8 5C6.34 5 5 6.34 5 8s1.34 3 3 3zm0 2c-2.33 0-7 1.17-7 3.5V19h14v-2.5c0-2.33-4.67-3.5-7-3.5zm8 0c-.29 0-.62.02-.97.05 1.16.84 1.97 1.97 1.97 3.45V19h6v-2.5c0-2.33-4.67-3.5-7-3.5z"/></svg>
                <span class="nav-text">HR Accounts</span>
                <span class="nav-badge alert" id="hrBadge" hidden></span>
            </a>

            <a href="question-list.php" class="nav-item reveal" style="--i:3" title="Questions">
                <svg class="nav-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M19 3h-4.18C14.4 1.84 13.3 1 12 1s-2.4.84-2.82 2H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm-7 0c.55 0 1 .45 1 1s-.45 1-1 1-1-.45-1-1 .45-1 1-1zm2 14H7v-2h7v2zm3-4H7v-2h10v2zm0-4H7V7h10v2z"/></svg>
                <span class="nav-text">Questions</span>
            </a>

            <a href="archive-admin.php" class="nav-item active reveal" aria-current="page" id="archiveLink" style="--i:4" title="Archive">
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
        <header class="content-header reveal" style="--i:1">
            <h1>Archive</h1>
        </header>

        <!-- Search + filter toggle -->
        <div class="toolbar reveal" id="toolbar" style="--i:2">
            <label class="search-box" for="searchInput">
                <svg class="search-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M15.5 14h-.79l-.28-.27A6.47 6.47 0 0 0 16 9.5 6.5 6.5 0 1 0 9.5 16c1.61 0 3.09-.59 4.23-1.57l.27.28v.79l5 4.99L20.49 19l-4.99-5zm-6 0C7.01 14 5 11.99 5 9.5S7.01 5 9.5 5 14 7.01 14 9.5 11.99 14 9.5 14z"/></svg>
                <input type="search" id="searchInput" placeholder="Search Archive..." autocomplete="off" aria-label="Search the archive by name, email, question or section">
                <button type="button" class="search-clear" id="searchClear" aria-label="Clear search" hidden>
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M19 6.41 17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"/></svg>
                </button>
            </label>

            <button type="button" class="filter-toggle" id="filterToggle" aria-expanded="true" aria-controls="filterPanel" title="Show or hide filters">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4.25 5.61C6.27 8.2 10 13 10 13v6c0 .55.45 1 1 1h2c.55 0 1-.45 1-1v-6s3.72-4.8 5.74-7.39A.998.998 0 0 0 18.95 4H5.04c-.83 0-1.3.95-.79 1.61z"/></svg>
                <span class="filter-dot" id="filterDot" hidden></span>
                <span class="sr-only">Toggle filters</span>
            </button>
        </div>

        <div class="filter-panel reveal is-settled" id="filterPanel" style="--i:3">
            <div class="filter-panel-inner">
                <div class="filter-card" id="typeCard">
                    <div class="filter-head">
                        <span class="filter-label" id="typeLabel">
                            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 4h16v3H4zm0 6h16v3H4zm0 6h10v3H4z"/></svg>Type
                        </span>
                        <button type="button" class="filter-reset" data-filter-reset hidden>Reset</button>
                    </div>
                    <div class="dropdown" id="typeDropdown">
                <button type="button" class="dropdown-btn" aria-haspopup="listbox" aria-expanded="false" aria-labelledby="typeLabel typeDropdownValue">
                    <span class="dot"></span>
                    <span class="dropdown-value" id="typeDropdownValue">All Types</span>
                    <svg class="dropdown-chevron" viewBox="0 0 24 24" aria-hidden="true"><path d="M7.41 8.59 12 13.17l4.59-4.58L18 10l-6 6-6-6z"/></svg>
                </button>
                <ul class="dropdown-menu" role="listbox" aria-labelledby="typeLabel" tabindex="-1">
                    <li class="dropdown-option" role="option" data-value="all" data-dot="#c5c9ce" aria-selected="true" style="--k:0">
                        <span class="dot"></span><span class="opt-label">All Types</span><span class="opt-count">0</span>
                        <svg class="opt-check" viewBox="0 0 24 24" aria-hidden="true"><path d="M9 16.2 4.8 12l-1.4 1.4L9 19 21 7l-1.4-1.4z"/></svg>
                    </li>
                    <li class="dropdown-option" role="option" data-value="account" data-dot="#7ab8f0" aria-selected="false" style="--k:1">
                        <span class="dot"></span><span class="opt-label">HR Account</span><span class="opt-count">0</span>
                        <svg class="opt-check" viewBox="0 0 24 24" aria-hidden="true"><path d="M9 16.2 4.8 12l-1.4 1.4L9 19 21 7l-1.4-1.4z"/></svg>
                    </li>
                    <li class="dropdown-option" role="option" data-value="question" data-dot="#8f8aea" aria-selected="false" style="--k:2">
                        <span class="dot"></span><span class="opt-label">Questionnaire</span><span class="opt-count">0</span>
                        <svg class="opt-check" viewBox="0 0 24 24" aria-hidden="true"><path d="M9 16.2 4.8 12l-1.4 1.4L9 19 21 7l-1.4-1.4z"/></svg>
                    </li>
                </ul>
                    </div>
                </div>
                <div class="filter-card" id="leftCard">
                    <div class="filter-head">
                        <span class="filter-label" id="leftLabel">
                            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 2v6h.01L6 8.01 10 12l-4 4 .01.01H6V22h12v-5.99h-.01L18 16l-4-4 4-3.99-.01-.01H18V2H6zm10 14.5V20H8v-3.5l4-4 4 4zm-4-5-4-4V4h8v3.5l-4 4z"/></svg>Time left
                        </span>
                        <button type="button" class="filter-reset" data-filter-reset hidden>Reset</button>
                    </div>
                    <div class="dropdown" id="leftDropdown">
                <button type="button" class="dropdown-btn" aria-haspopup="listbox" aria-expanded="false" aria-labelledby="leftLabel leftDropdownValue">
                    <span class="dot"></span>
                    <span class="dropdown-value" id="leftDropdownValue">Any time left</span>
                    <svg class="dropdown-chevron" viewBox="0 0 24 24" aria-hidden="true"><path d="M7.41 8.59 12 13.17l4.59-4.58L18 10l-6 6-6-6z"/></svg>
                </button>
                <ul class="dropdown-menu" role="listbox" aria-labelledby="leftLabel" tabindex="-1">
                    <li class="dropdown-option" role="option" data-value="all" data-dot="#c5c9ce" aria-selected="true" style="--k:0">
                        <span class="dot"></span><span class="opt-label">Any time left</span><span class="opt-count">0</span>
                        <svg class="opt-check" viewBox="0 0 24 24" aria-hidden="true"><path d="M9 16.2 4.8 12l-1.4 1.4L9 19 21 7l-1.4-1.4z"/></svg>
                    </li>
                    <li class="dropdown-option" role="option" data-value="soon" data-dot="#d1122a" aria-selected="false" style="--k:1">
                        <span class="dot"></span><span class="opt-label">7 days or less</span><span class="opt-count">0</span>
                        <svg class="opt-check" viewBox="0 0 24 24" aria-hidden="true"><path d="M9 16.2 4.8 12l-1.4 1.4L9 19 21 7l-1.4-1.4z"/></svg>
                    </li>
                    <li class="dropdown-option" role="option" data-value="later" data-dot="#159a43" aria-selected="false" style="--k:2">
                        <span class="dot"></span><span class="opt-label">More than 7 days</span><span class="opt-count">0</span>
                        <svg class="opt-check" viewBox="0 0 24 24" aria-hidden="true"><path d="M9 16.2 4.8 12l-1.4 1.4L9 19 21 7l-1.4-1.4z"/></svg>
                    </li>
                </ul>
                    </div>
                </div>
            </div>
        </div>

        <!-- Table -->
        <section class="table-card reveal" style="--i:4">
            <div class="table-scroll" id="tableScroll">
                <table class="hr-table a-table" id="archiveTable">
                    <colgroup>
                        <col class="c-num"><col class="c-type"><col class="c-name"><col class="c-date"><col class="c-status"><col class="c-action">
                    </colgroup>
                    <thead>
                        <tr>
                            <th>#</th><th>Type</th><th>Name / Question</th><th>Deleted on</th><th>Time left</th>
                            <th class="col-action">Action</th>
                        </tr>
                    </thead>
                    <tbody id="tableBody"></tbody>
                </table>

                <div class="empty-state" id="emptyState" hidden>
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20.54 5.23l-1.39-1.68C18.88 3.21 18.47 3 18 3H6c-.47 0-.88.21-1.16.55L3.46 5.23C3.17 5.57 3 6.02 3 6.5V19c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V6.5c0-.48-.17-.93-.46-1.27zM12 17.5L6.5 12H10v-2h4v2h3.5L12 17.5zM5.12 5l.81-1h12l.94 1H5.12z"/></svg>
                    <h3 id="emptyTitle">The Archive is empty</h3>
                    <p id="emptyText">Deleted items stay here for <?php echo ARCHIVE_DAYS; ?> days before they are permanently deleted.</p>
                    <button type="button" class="btn-clear-filters" id="clearFilters">Clear filters</button>
                </div>
            </div>
        </section>

        <footer class="list-footer reveal" style="--i:5">
            <p class="showing" id="showingText" aria-live="polite"></p>
            <nav class="pagination" id="pagination" aria-label="Pagination"></nav>
        </footer>
    </main>

    <script>
        window.ADMIN_NOTIFY = <?php
            try { echo json_encode(repo_admin_notify(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); }
            catch (Throwable $e) { echo json_encode(['activity' => [], 'expiring' => 0, 'days' => 7]); }
        ?>;
        window.ADMIN_ARCHIVE = <?php echo json_encode(['days' => ARCHIVE_DAYS, 'archive' => $archive], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE); ?>;
    </script>
    <script src="../assets/js/admin.js?v=<?php echo @filemtime(__DIR__ . '/../assets/js/admin.js'); ?>"></script>
</body>
</html>