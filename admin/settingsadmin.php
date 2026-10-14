<?php
session_start();

// No caching, so the Back button after logout never shows this page
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');

// Protection: Ensure only the Admin can access this page
if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
    header('Location: ../index.php');
    exit();
}

date_default_timezone_set('Asia/Manila');

// Needed for the sidebar badges (static_admin_notify): new HR activity + Archive items that expire soon
require_once __DIR__ . '/static-admin.php';

// Starting values for the account fields: whoever logged in.
// BACKEND: this is the logged-in user's row (name, email). Settings > Account saves back to it.
$account_name  = trim((string) ($_SESSION['user_name'] ?? ''));
$account_email = trim((string) ($_SESSION['user_email'] ?? ''));
?>
<!DOCTYPE html>
<html lang="en" data-page="settingsadmin">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Settings - Expertise Admin</title>

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

    <!-- admin.css = shared admin styles + every admin page (this page is picked by data-page="settingsadmin" on <html>) -->
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

        <nav class="sidebar-nav" aria-label="Admin">
            <a href="dashboardadmin.php" class="nav-item reveal" id="dashboardLink" style="--i:1" title="Dashboard">
                <svg class="nav-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M10 20v-6h4v6h5v-8h3L12 3 2 12h3v8z"/></svg>
                <span class="nav-text">Dashboard</span>
                <span class="nav-badge alert" id="dashboardBadge" hidden></span>
            </a>

            <a href="hrlist.php" class="nav-item reveal" style="--i:2" title="HR Accounts">
                <svg class="nav-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M16 11c1.66 0 2.99-1.34 2.99-3S17.66 5 16 5c-1.66 0-3 1.34-3 3s1.34 3 3 3zm-8 0c1.66 0 2.99-1.34 2.99-3S9.66 5 8 5C6.34 5 5 6.34 5 8s1.34 3 3 3zm0 2c-2.33 0-7 1.17-7 3.5V19h14v-2.5c0-2.33-4.67-3.5-7-3.5zm8 0c-.29 0-.62.02-.97.05 1.16.84 1.97 1.97 1.97 3.45V19h6v-2.5c0-2.33-4.67-3.5-7-3.5z"/></svg>
                <span class="nav-text">HR Accounts</span>
            </a>

            <a href="questionlist.php" class="nav-item reveal" style="--i:3" title="Questions">
                <svg class="nav-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M19 3h-4.18C14.4 1.84 13.3 1 12 1s-2.4.84-2.82 2H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm-7 0c.55 0 1 .45 1 1s-.45 1-1 1-1-.45-1-1 .45-1 1-1zm2 14H7v-2h7v2zm3-4H7v-2h10v2zm0-4H7V7h10v2z"/></svg>
                <span class="nav-text">Questions</span>
            </a>

            <a href="archiveadmin.php" class="nav-item reveal" id="archiveLink" style="--i:4" title="Archive">
                <svg class="nav-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M20.54 5.23l-1.39-1.68C18.88 3.21 18.47 3 18 3H6c-.47 0-.88.21-1.16.55L3.46 5.23C3.17 5.57 3 6.02 3 6.5V19c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V6.5c0-.48-.17-.93-.46-1.27zM12 17.5L6.5 12H10v-2h4v2h3.5L12 17.5zM5.12 5l.81-1h12l.94 1H5.12z"/></svg>
                <span class="nav-text">Archive</span>
                <span class="nav-badge alert" id="archiveBadge" hidden></span>
            </a>
        </nav>

        <div class="sidebar-footer">
            <a href="settingsadmin.php" class="nav-item active reveal" style="--i:5" title="Settings" aria-current="page">
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
            <h1>Settings</h1>
            <p>Manage your profile and system preferences</p>
        </header>

        <form id="settingsForm" class="settings-stack" novalidate autocomplete="off">

            <!-- ================= Account ================= -->
            <section class="set-card reveal" style="--i:2" aria-labelledby="accountTitle">
                <h2 id="accountTitle">Account Settings</h2>

                <div class="set-field" data-field="name">
                    <label for="setName">Name</label>
                    <div class="set-input">
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z"/></svg>
                        <input type="text" id="setName" name="name" maxlength="100" placeholder="Enter your name" value="<?php echo htmlspecialchars($account_name, ENT_QUOTES, 'UTF-8'); ?>" aria-describedby="nameError" required>
                    </div>
                    <p class="set-error" id="nameError" role="alert"></p>
                </div>

                <div class="set-field" data-field="email">
                    <label for="setEmail">Email</label>
                    <div class="set-input">
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20 4H4c-1.1 0-2 .9-2 2v12c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2zm0 4l-8 5-8-5V6l8 5 8-5v2z"/></svg>
                        <input type="email" id="setEmail" name="email" maxlength="150" placeholder="Enter your email address" value="<?php echo htmlspecialchars($account_email, ENT_QUOTES, 'UTF-8'); ?>" aria-describedby="emailError" required>
                    </div>
                    <p class="set-error" id="emailError" role="alert"></p>
                </div>

                <div class="pw-row">
                    <div class="set-field">
                        <label for="pwMask">Password</label>
                        <!-- Only dots: the real password is never printed into the page -->
                        <div class="set-input is-readonly">
                            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2C9.24 2 7 4.24 7 7v2h2V7c0-1.66 1.34-3 3-3s3 1.34 3 3v2h2V7c0-2.76-2.24-5-5-5z"/><path d="M18 9H6c-1.1 0-2 .9-2 2v9c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2v-9c0-1.1-.9-2-2-2zm-6 7.5c-.83 0-1.5-.67-1.5-1.5s.67-1.5 1.5-1.5 1.5.67 1.5 1.5-.67 1.5-1.5 1.5z"/></svg>
                            <input type="password" id="pwMask" value="XXXXXXXXX" readonly tabindex="-1" aria-label="Password (hidden)">
                        </div>
                    </div>
                    <div class="set-field">
                        <label for="pwOpen">Change Password</label>
                        <button type="button" class="btn-update-pw" id="pwOpen" aria-haspopup="dialog">
                            <svg viewBox="0 0 24 24" aria-hidden="true"><path class="lock-shackle" d="M12 2C9.24 2 7 4.24 7 7v2h2V7c0-1.66 1.34-3 3-3s3 1.34 3 3v2h2V7c0-2.76-2.24-5-5-5z"/><path d="M18 9H6c-1.1 0-2 .9-2 2v9c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2v-9c0-1.1-.9-2-2-2zm-6 7.5c-.83 0-1.5-.67-1.5-1.5s.67-1.5 1.5-1.5 1.5.67 1.5 1.5-.67 1.5-1.5 1.5z"/></svg>
                            Update Password
                        </button>
                    </div>
                </div>
            </section>

            <!-- ================= Preferences ================= -->
            <section class="set-card reveal" style="--i:3" id="prefCard" aria-labelledby="prefTitle">
                <h2 id="prefTitle">Preferences</h2>

                <div class="pref-grid">
                    <div role="radiogroup" aria-labelledby="themeLabel">
                        <p class="pref-label" id="themeLabel">Theme</p>
                        <div class="theme-tiles">
                            <label class="theme-tile">
                                <input type="radio" name="theme" value="light">
                                <span class="tile-face" aria-hidden="true">
                                    <span class="mini"><i class="m-side"></i><span class="m-body"><i class="m-bar"></i><span class="m-cards"><b></b><b></b></span></span></span>
                                </span>
                                <span class="tile-name">Light <span class="tile-check"><svg viewBox="0 0 24 24"><path d="M5 12.5l4.5 4.5L19 7.5"/></svg></span></span>
                            </label>
                            <label class="theme-tile">
                                <input type="radio" name="theme" value="dark">
                                <span class="tile-face" aria-hidden="true">
                                    <span class="mini dark"><i class="m-side"></i><span class="m-body"><i class="m-bar"></i><span class="m-cards"><b></b><b></b></span></span></span>
                                </span>
                                <span class="tile-name">Dark <span class="tile-check"><svg viewBox="0 0 24 24"><path d="M5 12.5l4.5 4.5L19 7.5"/></svg></span></span>
                            </label>
                            <label class="theme-tile">
                                <input type="radio" name="theme" value="system">
                                <span class="tile-face" aria-hidden="true">
                                    <span class="mini-split">
                                        <span class="mini"><i class="m-side"></i><span class="m-body"><i class="m-bar"></i><span class="m-cards"><b></b><b></b></span></span></span>
                                        <span class="mini dark"><i class="m-side"></i><span class="m-body"><i class="m-bar"></i><span class="m-cards"><b></b><b></b></span></span></span>
                                    </span>
                                </span>
                                <span class="tile-name">System <span class="tile-check"><svg viewBox="0 0 24 24"><path d="M5 12.5l4.5 4.5L19 7.5"/></svg></span></span>
                            </label>
                        </div>
                    </div>

                    <div>
                        <p class="pref-label" id="notifLabel">Notifications</p>
                        <div class="toggle-row">
                            <div class="t-text">
                                <div class="t-title">Show notification badges</div>
                                <div class="t-sub">The red numbers in the sidebar</div>
                            </div>
                            <label class="switch"><input type="checkbox" role="switch" id="optNotifications" aria-labelledby="notifLabel"><span class="track"></span></label>
                        </div>
                        <div class="sub-group" id="subGroup">
                            <div class="sub-inner">
                                <div class="toggle-row">
                                    <div class="t-text">
                                        <div class="t-title" id="lblActivity">HR activity</div>
                                        <div class="t-sub">Badge on Dashboard until you open it</div>
                                    </div>
                                    <label class="switch"><input type="checkbox" role="switch" id="optActivity" aria-labelledby="lblActivity"><span class="track"></span></label>
                                </div>
                                <div class="toggle-row">
                                    <div class="t-text">
                                        <div class="t-title" id="lblArchive">Archive expiring</div>
                                        <div class="t-sub">Badge on Archive when items will be deleted soon</div>
                                    </div>
                                    <label class="switch"><input type="checkbox" role="switch" id="optArchive" aria-labelledby="lblArchive"><span class="track"></span></label>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="pref-full">
                        <div class="toggle-row">
                            <div class="t-text">
                                <div class="t-title" id="lblMotion">Page animations</div>
                                <div class="t-sub">Turn off to skip the page sweeps and rise-in motion</div>
                            </div>
                            <label class="switch"><input type="checkbox" role="switch" id="optAnimations" aria-labelledby="lblMotion"><span class="track"></span></label>
                        </div>
                    </div>
                </div>

                <p class="dirty-note" role="status" aria-live="polite">You have unsaved changes.</p>
                <div class="set-actions">
                    <button type="submit" class="set-btn set-save" id="saveBtn" disabled>
                        <span class="spinner" aria-hidden="true"></span>
                        <svg class="done-tick" viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12.5l4.5 4.5L19 7.5"/></svg>
                        <span class="btn-label">SAVE</span>
                    </button>
                    <button type="button" class="set-btn set-cancel" id="cancelBtn" disabled>CANCEL</button>
                </div>
            </section>
        </form>
    </main>

    <!-- Update Password Modal -->
    <div class="modal-backdrop" id="pwModal" role="dialog" aria-modal="true" aria-labelledby="pwTitle">
        <div class="modal-card pw-modal">
            <h3 id="pwTitle">Update Password</h3>
            <p>Choose a new password you do not use anywhere else.</p>
            <form id="pwForm" novalidate autocomplete="off">
                <div class="set-field" data-field="pwCurrent">
                    <label for="pwCurrent">Current password</label>
                    <div class="set-input">
                        <input type="password" id="pwCurrent" autocomplete="current-password">
                        <button type="button" class="eye" aria-pressed="false" aria-label="Show current password" data-what="current password"><svg class="eye-on" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 4.5C7 4.5 2.73 7.61 1 12c1.73 4.39 6 7.5 11 7.5s9.27-3.11 11-7.5c-1.73-4.39-6-7.5-11-7.5zM12 17c-2.76 0-5-2.24-5-5s2.24-5 5-5 5 2.24 5 5-2.24 5-5 5zm0-8c-1.66 0-3 1.34-3 3s1.34 3 3 3 3-1.34 3-3-1.34-3-3-3z"/></svg><svg class="eye-off" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 7c2.76 0 5 2.24 5 5 0 .65-.13 1.26-.36 1.83l2.92 2.92c1.51-1.26 2.7-2.89 3.43-4.75-1.73-4.39-6-7.5-11-7.5-1.4 0-2.74.25-3.98.7l2.16 2.16C10.74 7.13 11.35 7 12 7zM2 4.27l2.28 2.28.46.46C3.08 8.3 1.78 10.02 1 12c1.73 4.39 6 7.5 11 7.5 1.55 0 3.03-.3 4.38-.84l.42.42L19.73 22 21 20.73 3.27 3 2 4.27zM7.53 9.8l1.55 1.55c-.05.21-.08.43-.08.65 0 1.66 1.34 3 3 3 .22 0 .44-.03.65-.08l1.55 1.55c-.67.33-1.41.53-2.2.53-2.76 0-5-2.24-5-5 0-.79.2-1.53.53-2.2z"/></svg></button>
                    </div>
                    <p class="set-error" role="alert"></p>
                </div>
                <div class="set-field" data-field="pwNew">
                    <label for="pwNew">New password</label>
                    <div class="set-input">
                        <input type="password" id="pwNew" autocomplete="new-password">
                        <button type="button" class="eye" aria-pressed="false" aria-label="Show new password" data-what="new password"><svg class="eye-on" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 4.5C7 4.5 2.73 7.61 1 12c1.73 4.39 6 7.5 11 7.5s9.27-3.11 11-7.5c-1.73-4.39-6-7.5-11-7.5zM12 17c-2.76 0-5-2.24-5-5s2.24-5 5-5 5 2.24 5 5-2.24 5-5 5zm0-8c-1.66 0-3 1.34-3 3s1.34 3 3 3 3-1.34 3-3-1.34-3-3-3z"/></svg><svg class="eye-off" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 7c2.76 0 5 2.24 5 5 0 .65-.13 1.26-.36 1.83l2.92 2.92c1.51-1.26 2.7-2.89 3.43-4.75-1.73-4.39-6-7.5-11-7.5-1.4 0-2.74.25-3.98.7l2.16 2.16C10.74 7.13 11.35 7 12 7zM2 4.27l2.28 2.28.46.46C3.08 8.3 1.78 10.02 1 12c1.73 4.39 6 7.5 11 7.5 1.55 0 3.03-.3 4.38-.84l.42.42L19.73 22 21 20.73 3.27 3 2 4.27zM7.53 9.8l1.55 1.55c-.05.21-.08.43-.08.65 0 1.66 1.34 3 3 3 .22 0 .44-.03.65-.08l1.55 1.55c-.67.33-1.41.53-2.2.53-2.76 0-5-2.24-5-5 0-.79.2-1.53.53-2.2z"/></svg></button>
                    </div>
                    <div class="meter" id="pwMeter" data-level="0" aria-hidden="true"><i></i><i></i><i></i><i></i></div>
                    <div class="meter-label" id="pwMeterLabel" aria-live="polite"></div>
                    <p class="set-error" role="alert"></p>
                </div>
                <ul class="pw-rules">
                    <li data-rule="len"><span class="dot"></span>At least 8 characters</li>
                    <li data-rule="case"><span class="dot"></span>Upper and lower case letters</li>
                    <li data-rule="num"><span class="dot"></span>At least one number</li>
                </ul>
                <div class="set-field" data-field="pwConfirm">
                    <label for="pwConfirm">Confirm new password</label>
                    <div class="set-input">
                        <input type="password" id="pwConfirm" autocomplete="new-password">
                        <button type="button" class="eye" aria-pressed="false" aria-label="Show confirmation password" data-what="confirmation password"><svg class="eye-on" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 4.5C7 4.5 2.73 7.61 1 12c1.73 4.39 6 7.5 11 7.5s9.27-3.11 11-7.5c-1.73-4.39-6-7.5-11-7.5zM12 17c-2.76 0-5-2.24-5-5s2.24-5 5-5 5 2.24 5 5-2.24 5-5 5zm0-8c-1.66 0-3 1.34-3 3s1.34 3 3 3 3-1.34 3-3-1.34-3-3-3z"/></svg><svg class="eye-off" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 7c2.76 0 5 2.24 5 5 0 .65-.13 1.26-.36 1.83l2.92 2.92c1.51-1.26 2.7-2.89 3.43-4.75-1.73-4.39-6-7.5-11-7.5-1.4 0-2.74.25-3.98.7l2.16 2.16C10.74 7.13 11.35 7 12 7zM2 4.27l2.28 2.28.46.46C3.08 8.3 1.78 10.02 1 12c1.73 4.39 6 7.5 11 7.5 1.55 0 3.03-.3 4.38-.84l.42.42L19.73 22 21 20.73 3.27 3 2 4.27zM7.53 9.8l1.55 1.55c-.05.21-.08.43-.08.65 0 1.66 1.34 3 3 3 .22 0 .44-.03.65-.08l1.55 1.55c-.67.33-1.41.53-2.2.53-2.76 0-5-2.24-5-5 0-.79.2-1.53.53-2.2z"/></svg></button>
                    </div>
                    <p class="set-error" role="alert"></p>
                </div>
                <div class="modal-actions">
                    <button type="button" class="modal-btn is-cancel" id="pwCancel">Cancel</button>
                    <button type="submit" class="modal-btn btn-blue" id="pwSubmit">Update Password</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Pass PHP Variables to JavaScript -->
    <script>
        // same badges as every admin page: they animate when HR did something new, and clear once the Dashboard is opened
        window.ADMIN_NOTIFY = <?php echo json_encode(static_admin_notify(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
        window.ADMIN_ACCOUNT = <?php echo json_encode(['name' => $account_name, 'email' => $account_email], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    </script>
    <!-- Shared admin behaviour first, then this page's script -->
    <script src="../assets/js/admin.js?v=<?php echo @filemtime(__DIR__ . '/../assets/js/admin.js'); ?>"></script>
</body>
</html>