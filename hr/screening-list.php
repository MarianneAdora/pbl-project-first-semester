<?php
session_start();

// Protection: Ensure only HR users can access this page
if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'hr') {
    header('Location: ../auth/index.php');
    exit();
}

// No caching, so the Back button after logout never shows this page
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');

// All applicant data comes from the database (PostgreSQL via PDO).
// DB status (pending/in_progress/submitted/reviewed/rejected/accepted) is mapped to the
// UI labels Pending / In Progress / Completed (+ Passed/Rejected) in src/Helpers/display.php.
require_once __DIR__ . '/../src/Repositories/ApplicantRepository.php';

// Rows per page
$per_page = 6;

// Live data from the database (soft-deleted rows are excluded = the Archive).
// Keep these keys: id, name, email, contact, status, result.
//   status: 'Pending' | 'In Progress' | 'Completed' (mapped from the DB pipeline)
//   result: 'Passed' | 'Rejected' | null (null = no result yet)
try {
    $applicants = repo_applicants_active();
} catch (Throwable $e) {
    $applicants = [];
    error_log('screeninglist DB error: ' . $e->getMessage());
}
try {
    $archived_rows = repo_applicants_archived();
} catch (Throwable $e) {
    error_log('screeninglist archive DB error: ' . $e->getMessage());
    $archived_rows = [];
}
// Server-side archive sync (replaces the old static JSON + localStorage mirror).
// The Archive page is whoever has deleted_at set; localStorage is only cleared, never read as truth.
$archive_sync = ['ids' => array_map(fn($a) => (int) $a['id'], $archived_rows), 'at' => []];
foreach ($archived_rows as $a) {
    if (!empty($a['archived_at'])) $archive_sync['at'][(int) $a['id']] = (int) (strtotime((string) $a['archived_at']) * 1000);
}
?>
<!DOCTYPE html>
<html lang="en" data-page="screeninglist">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Applicants Screening List - Expertise Portal</title>

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

    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">

    <!-- hr.css = shared hr styles + every hr page (this page is picked by data-page="screeninglist" on <html>) -->
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

        <?php
        // Profile card: the name + picture saved in Settings (clicking it opens Settings)
        require_once __DIR__ . '/hr-data.php';
        $sbp_name = trim((string) ($_SESSION['user_name'] ?? 'HR'));
        $sbp_role = 'HR Staff';
        try {
            $sbp = static_profile_for_page('data/avatars/');
            if (trim((string) ($sbp['name'] ?? '')) !== '') $sbp_name = (string) $sbp['name'];
            $sbp_role = (string) ($sbp['role_label'] ?? 'HR Staff');
            $sbp_html = static_profile_avatar_html($sbp_name, (string) ($sbp['avatar_url'] ?? ''));
        } catch (Throwable $e) {
            $sbp_html = static_profile_avatar_html($sbp_name, '');
        }
        ?>
        <a class="profile-chip sidebar-profile reveal" id="profileChip" href="setting-hr.php" style="--i:1" title="<?php echo htmlspecialchars($sbp_name . ' - open your profile in Settings', ENT_QUOTES, 'UTF-8'); ?>" aria-label="<?php echo htmlspecialchars($sbp_name . ', ' . $sbp_role . '. Open your profile in Settings', ENT_QUOTES, 'UTF-8'); ?>">
            <?php echo $sbp_html; ?>
            <span class="profile-meta">
                <strong><?php echo htmlspecialchars($sbp_name, ENT_QUOTES, 'UTF-8'); ?></strong>
                <small><?php echo htmlspecialchars($sbp_role, ENT_QUOTES, 'UTF-8'); ?></small>
            </span>
        </a>

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
    <main class="main-content">
        <header class="content-header reveal" style="--i:1">
            <h1>Applicants Screening List</h1>
            <p>Manage and review all applicants.</p>
        </header>

        <!-- Search + select all + filter toggle -->
        <div class="toolbar reveal" id="toolbar" style="--i:2">
            <label class="search-box" for="searchInput">
                <svg class="search-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M15.5 14h-.79l-.28-.27A6.47 6.47 0 0 0 16 9.5 6.5 6.5 0 1 0 9.5 16c1.61 0 3.09-.59 4.23-1.57l.27.28v.79l5 4.99L20.49 19l-4.99-5zm-6 0C7.01 14 5 11.99 5 9.5S7.01 5 9.5 5 14 7.01 14 9.5 11.99 14 9.5 14z"/></svg>
                <input type="search" id="searchInput" placeholder="Search Applicants..." autocomplete="off" aria-label="Search applicants by name, email or contact">
                <button type="button" class="search-clear" id="searchClear" aria-label="Clear search" hidden>
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M19 6.41 17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"/></svg>
                </button>
            </label>

            <!-- Select all  <->  Cancel / Delete: one slot, so nothing else in the toolbar jumps -->
            <div class="select-swap" id="selectSwap">
                <!-- Normal mode: Select all -->
                <button type="button" class="tool-btn select-all-btn" id="selectAllBtn" title="Select every applicant in the list (or press and hold a row)">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 5h2V3c-1.1 0-2 .9-2 2zm0 8h2v-2H3v2zm4 8h2v-2H7v2zM3 9h2V7H3v2zm10-6h-2v2h2V3zm6 0v2h2c0-1.1-.9-2-2-2zM5 21v-2H3c0 1.1.9 2 2 2zm-2-4h2v-2H3v2zM9 3H7v2h2V3zm2 18h2v-2h-2v2zm8-8h2v-2h-2v2zm0 8c1.1 0 2-.9 2-2h-2v2zm0-12h2V7h-2v2zm0 8h2v-2h-2v2zm-4 4h2v-2h-2v2zm0-16h2V3h-2v2zM9 17h6V9H9v8zm2-6h2v4h-2v-4z"/></svg>
                    <span>Select all</span>
                </button>

                <!-- Selection mode: Cancel + Delete -->
                <div class="select-actions" id="selectActions" inert>
                    <button type="button" class="btn-cancel-sel" id="cancelSelect">Cancel</button>
                    <button type="button" class="btn-delete-sel" id="deleteSelected" disabled title="Moves the selected applicants to the Archive">
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 19c0 1.1.9 2 2 2h8c1.1 0 2-.9 2-2V7H6v12zM19 4h-3.5l-1-1h-5l-1 1H5v2h14V4z"/></svg>
                        <span>Delete <span id="deleteCount"></span></span>
                    </button>
                </div>
            </div>

            <button type="button" class="filter-toggle" id="filterToggle" aria-expanded="true" aria-controls="filterPanel" title="Show or hide filters">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4.25 5.61C6.27 8.2 10 13 10 13v6c0 .55.45 1 1 1h2c.55 0 1-.45 1-1v-6s3.72-4.8 5.74-7.39A.998.998 0 0 0 18.95 4H5.04c-.83 0-1.3.95-.79 1.61z"/></svg>
                <span class="filter-dot" id="filterDot" hidden></span>
                <span class="sr-only">Toggle filters</span>
            </button>
        </div>

        <!-- Filters -->
        <div class="filter-panel reveal is-settled" id="filterPanel" style="--i:3">
            <div class="filter-panel-inner">
                <div class="filter-card" id="statusCard">
                    <div class="filter-head">
                        <span class="filter-label" id="statusLabel">
                            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M11.99 2C6.47 2 2 6.48 2 12s4.47 10 9.99 10C17.52 22 22 17.52 22 12S17.52 2 11.99 2zM12 20c-4.42 0-8-3.58-8-8s3.58-8 8-8 8 3.58 8 8-3.58 8-8 8zm.5-13H11v6l5.25 3.15.75-1.23-4.5-2.67z"/></svg>Status
                        </span>
                        <button type="button" class="filter-reset" id="statusReset" hidden>Reset</button>
                    </div>
                    <div class="dropdown" id="statusDropdown">
                        <button type="button" class="dropdown-btn" aria-haspopup="listbox" aria-expanded="false" aria-labelledby="statusLabel statusValue">
                            <span class="dot"></span>
                            <span class="dropdown-value" id="statusValue">All Status</span>
                            <svg class="dropdown-chevron" viewBox="0 0 24 24" aria-hidden="true"><path d="M7.41 8.59 12 13.17l4.59-4.58L18 10l-6 6-6-6z"/></svg>
                        </button>
                        <ul class="dropdown-menu" role="listbox" aria-labelledby="statusLabel" tabindex="-1">
                            <li class="dropdown-option" role="option" data-value="all" data-dot="#c5c9ce" aria-selected="true" style="--k:0">
                                <span class="dot"></span><span class="opt-label">All Status</span><span class="opt-count">0</span>
                                <svg class="opt-check" viewBox="0 0 24 24" aria-hidden="true"><path d="M9 16.2 4.8 12l-1.4 1.4L9 19 21 7l-1.4-1.4z"/></svg>
                            </li>
                            <li class="dropdown-option" role="option" data-value="Pending" data-dot="#f2c200" aria-selected="false" style="--k:1">
                                <span class="dot"></span><span class="opt-label">Pending</span><span class="opt-count">0</span>
                                <svg class="opt-check" viewBox="0 0 24 24" aria-hidden="true"><path d="M9 16.2 4.8 12l-1.4 1.4L9 19 21 7l-1.4-1.4z"/></svg>
                            </li>
                            <li class="dropdown-option" role="option" data-value="In Progress" data-dot="#1c8adb" aria-selected="false" style="--k:2">
                                <span class="dot"></span><span class="opt-label">In Progress</span><span class="opt-count">0</span>
                                <svg class="opt-check" viewBox="0 0 24 24" aria-hidden="true"><path d="M9 16.2 4.8 12l-1.4 1.4L9 19 21 7l-1.4-1.4z"/></svg>
                            </li>
                            <li class="dropdown-option" role="option" data-value="Completed" data-dot="#159a43" aria-selected="false" style="--k:3">
                                <span class="dot"></span><span class="opt-label">Completed</span><span class="opt-count">0</span>
                                <svg class="opt-check" viewBox="0 0 24 24" aria-hidden="true"><path d="M9 16.2 4.8 12l-1.4 1.4L9 19 21 7l-1.4-1.4z"/></svg>
                            </li>
                        </ul>
                    </div>
                </div>
                <div class="filter-card" id="resultCard">
                    <div class="filter-head">
                        <span class="filter-label" id="resultLabel">
                            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M19 5h-2V3H7v2H5c-1.1 0-2 .9-2 2v1c0 2.55 1.92 4.63 4.39 4.94.63 1.5 1.98 2.63 3.61 2.96V19H7v2h10v-2h-4v-3.1c1.63-.33 2.98-1.46 3.61-2.96C19.08 12.63 21 10.55 21 8V7c0-1.1-.9-2-2-2zM5 8V7h2v3.82C5.84 10.4 5 9.3 5 8zm14 0c0 1.3-.84 2.4-2 2.82V7h2v1z"/></svg>Result
                        </span>
                        <button type="button" class="filter-reset" id="resultReset" hidden>Reset</button>
                    </div>
                    <div class="dropdown" id="resultDropdown">
                        <button type="button" class="dropdown-btn" aria-haspopup="listbox" aria-expanded="false" aria-labelledby="resultLabel resultValue">
                            <span class="dot"></span>
                            <span class="dropdown-value" id="resultValue">All Results</span>
                            <svg class="dropdown-chevron" viewBox="0 0 24 24" aria-hidden="true"><path d="M7.41 8.59 12 13.17l4.59-4.58L18 10l-6 6-6-6z"/></svg>
                        </button>
                        <ul class="dropdown-menu" role="listbox" aria-labelledby="resultLabel" tabindex="-1">
                            <li class="dropdown-option" role="option" data-value="all" data-dot="#c5c9ce" aria-selected="true" style="--k:0">
                                <span class="dot"></span><span class="opt-label">All Results</span><span class="opt-count">0</span>
                                <svg class="opt-check" viewBox="0 0 24 24" aria-hidden="true"><path d="M9 16.2 4.8 12l-1.4 1.4L9 19 21 7l-1.4-1.4z"/></svg>
                            </li>
                            <li class="dropdown-option" role="option" data-value="Passed" data-dot="#0b6b1d" aria-selected="false" style="--k:1">
                                <span class="dot"></span><span class="opt-label">Passed</span><span class="opt-count">0</span>
                                <svg class="opt-check" viewBox="0 0 24 24" aria-hidden="true"><path d="M9 16.2 4.8 12l-1.4 1.4L9 19 21 7l-1.4-1.4z"/></svg>
                            </li>
                            <li class="dropdown-option" role="option" data-value="Rejected" data-dot="#c00000" aria-selected="false" style="--k:2">
                                <span class="dot"></span><span class="opt-label">Rejected</span><span class="opt-count">0</span>
                                <svg class="opt-check" viewBox="0 0 24 24" aria-hidden="true"><path d="M9 16.2 4.8 12l-1.4 1.4L9 19 21 7l-1.4-1.4z"/></svg>
                            </li>
                            <li class="dropdown-option" role="option" data-value="none" data-dot="#8a8f96" aria-selected="false" style="--k:3">
                                <span class="dot"></span><span class="opt-label">No Result Yet</span><span class="opt-count">0</span>
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
                <table class="screening-table" id="screeningTable">
                    <colgroup>
                        <col class="c-check">
                        <col class="c-name">
                        <col class="c-email">
                        <col class="c-contact">
                        <col class="c-status">
                        <col class="c-result">
                        <col class="c-action">
                    </colgroup>
                    <thead>
                        <tr>
                            <th class="col-check">
                                <span class="idx-wrap">
                                    <span class="idx-label">#</span>
                                    <input type="checkbox" id="selectAll" aria-label="Select all applicants" tabindex="-1">
                                </span>
                            </th>
                            <th>Name</th>
                            <th>Email</th>
                            <th>Contact</th>
                            <th>Status</th>
                            <th>Result</th>
                            <th class="col-action"><span class="sr-only">Action</span></th>
                        </tr>
                    </thead>
                    <tbody id="tableBody"></tbody>
                </table>

                <div class="empty-state" id="emptyState" hidden>
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M15.5 14h-.79l-.28-.27A6.47 6.47 0 0 0 16 9.5 6.5 6.5 0 1 0 9.5 16c1.61 0 3.09-.59 4.23-1.57l.27.28v.79l5 4.99L20.49 19l-4.99-5zm-6 0C7.01 14 5 11.99 5 9.5S7.01 5 9.5 5 14 7.01 14 9.5 11.99 14 9.5 14z"/></svg>
                    <h3>No applicants found</h3>
                    <p>Try a different search or clear the filters.</p>
                    <button type="button" class="btn-clear-filters" id="clearFilters">Clear filters</button>
                </div>
            </div>
        </section>

        <!-- Footer: count + pagination -->
        <footer class="list-footer reveal" style="--i:5">
            <p class="showing" id="showingText" aria-live="polite"></p>
            <nav class="pagination" id="pagination" aria-label="Pagination"></nav>
        </footer>
    </main>

    <!-- Pass PHP data to JavaScript -->
    <script>
        window.SCREENING = {
            perPage: <?php echo (int) $per_page; ?>,
            applicants: <?php echo json_encode($applicants, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>
        };
    </script>
    <!-- Shared HR behaviour first, then this page's script -->
    <script>window.HR_SERVER_ARCHIVE = <?php echo json_encode($archive_sync, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;</script>
    <script>window.HR_ACCOUNT = <?php echo json_encode(['id' => (int) ($_SESSION['user_id'] ?? 0)], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;</script>
    <script src="../assets/js/hr.js?v=<?php echo @filemtime(__DIR__ . '/../assets/js/hr.js'); ?>"></script>
</body>
</html>