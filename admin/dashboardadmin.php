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

require_once __DIR__ . '/static-admin.php';

// Every number on this page comes from static-admin.php (the one place for the admin sample data).
// BACKEND: when the database is ready, only static-admin.php changes.
$overview = static_admin_overview();             // the three summary boxes
$stats    = static_admin_question_stats();       // Questionnaires Statistics: every question + the answer most applicants chose
$all_log  = static_activity();                   // everything HR and the Admin did, newest first
$recent   = array_slice($all_log, 0, 10);        // Recent Activities shows the latest 10 only

// Share of something, used by the little bar on each summary box
function share(int $part, int $total): int
{
    return $total > 0 ? (int) round($part / $total * 100) : 0;
}

$hr_pct  = share((int) $overview['hr_active'], (int) $overview['hr_total']);
$log_pct = share((int) $overview['activities_week'], count($all_log));

$hr_note  = 'out of ' . (int) $overview['hr_total'] . ' HR account' . ($overview['hr_total'] === 1 ? '' : 's');
$q_note   = 'in ' . (int) $overview['sections'] . ' section' . ($overview['sections'] === 1 ? '' : 's');
$log_note = 'in the last 7 days';

$q_count   = count($stats['questions']);
$stats_sub = $q_count . ' question' . ($q_count === 1 ? '' : 's') . ' · ' . (
    $stats['submissions'] > 0
        ? 'from ' . (int) $stats['submissions'] . ' submitted questionnaire' . ($stats['submissions'] === 1 ? '' : 's')
        : 'no applicant has submitted the questionnaire yet'
);
$log_total = count($all_log);
$log_count = $log_total > 10 ? 'Latest 10 of ' . $log_total : $log_total . ' in total';

$display_name = trim($_SESSION['user_name'] ?? '') !== '' ? $_SESSION['user_name'] : 'Admin';
$greeting     = 'Hello, ' . $display_name . '!';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
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
        }
    </script>

    <!-- Plus Jakarta Sans Google Font -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">

    <!-- Shared admin styles first (shell, buttons, pills, toast, dark theme), then this page's styles -->
    <link rel="stylesheet" href="../assets/css/admin-common.css?v=<?php echo @filemtime(__DIR__ . '/../assets/css/admin-common.css'); ?>">
    <!-- This page's styles are inside the page itself (no separate css file to lose). Same rules as assets/css/dashboardadmin.css -->
    <style id="dashboard-admin-styles">
/* ==========================================================================
   Dashboard (Admin) page styles. Shared parts (sidebar, buttons, pills, table, toast) are in admin-common.css.
   Order on the page: three summary boxes, Questionnaires Statistics (bar chart), Recent Activities.
   ========================================================================== */

.admin-dashboard {
    display: flex;
    flex-direction: column;
    gap: 22px;
}

/* ---------- Summary boxes ---------- */
.admin-metrics {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 20px;
}

.stat-card {
    --tone: #a3081e;                       /* set per box below */
    --tone-soft: #fbe6e9;
    position: relative;
    display: flex;
    flex-direction: column;
    justify-content: space-between;
    gap: 6px;
    padding: 14px 22px;
    background: var(--surface);
    border-radius: 14px;
    box-shadow: 0 4px 15px rgba(0, 0, 0, 0.04);
    overflow: hidden;
    color: inherit;
    text-decoration: none;
    cursor: pointer;
    transition: transform 0.35s var(--ease-pop), box-shadow 0.3s var(--ease-out), background-color 0.3s ease;
}

/* thin colour edge on the left */
.stat-card::before {
    content: '';
    position: absolute;
    left: 0;
    top: 16px;
    bottom: 16px;
    width: 4px;
    border-radius: 0 4px 4px 0;
    background: var(--tone);
}

.stat-card.tone-red   { --tone: #a3081e; --tone-soft: #fbe6e9; }
.stat-card.tone-blue  { --tone: #1c8adb; --tone-soft: #e1f0fc; }
.stat-card.tone-green { --tone: #159a43; --tone-soft: #dff3e6; }

html[data-theme="dark"] .stat-card.tone-red { --tone: #ff6b80; }
html[data-theme="dark"] .stat-card.tone-blue { --tone: #4aa8f0; }
html[data-theme="dark"] .stat-card.tone-green { --tone: #4cc27a; }

.stat-card:hover { transform: translateY(-6px) scale(1.03); box-shadow: var(--shadow-lift); z-index: 2; }
.stat-card:active { transform: translateY(-2px) scale(0.99); }

.stat-top { display: flex; align-items: center; gap: 10px; }

.stat-icon {
    width: 34px;
    height: 34px;
    flex-shrink: 0;
    display: grid;
    place-items: center;
    border-radius: 10px;
    background: var(--tone-soft);
    transition: transform 0.35s var(--ease-pop);
}

.stat-icon svg { width: 20px; height: 20px; fill: var(--tone); }
html[data-theme="dark"] .stat-icon { background: color-mix(in srgb, var(--tone) 18%, transparent); }
.stat-card:hover .stat-icon { transform: rotate(-6deg) scale(1.1); }

.card-title { font-size: 13px; font-weight: 700; color: var(--ink); white-space: nowrap; }

/* arrow that slides in on hover: tells you the box is a link */
.stat-go {
    width: 22px;
    height: 22px;
    margin-left: auto;
    fill: var(--ink-soft);
    opacity: 0;
    transform: translateX(-6px);
    transition: opacity 0.2s ease, transform 0.3s var(--ease-out);
}

.stat-card:hover .stat-go { opacity: 1; transform: translateX(0); }

/* "3 new" chip on Recent Activities (HR did something since the Admin last looked) */
.new-chip {
    margin-left: auto;
    padding: 3px 9px;
    border-radius: 10px;
    background: var(--red-bright);
    color: #ffffff;
    font-size: 10.5px;
    font-weight: 800;
    white-space: nowrap;
    animation: popIn 0.45s var(--ease-pop) 0.6s backwards;
}

.new-chip + .stat-go { margin-left: 0; }

.card-value {
    font-size: 36px;
    font-weight: 800;
    line-height: 1;
    letter-spacing: -1px;
    color: var(--ink);
    font-variant-numeric: tabular-nums;
}

.stat-foot { display: flex; flex-direction: column; gap: 7px; }
.stat-bar { height: 6px; border-radius: 6px; background: var(--surface-3); overflow: hidden; }

.stat-bar span {
    display: block;
    height: 100%;
    width: var(--w, 0%);
    border-radius: 6px;
    background: var(--tone);
    transform-origin: left center;
    animation: barGrow 0.9s var(--ease-out) 0.5s backwards;
}

@keyframes barGrow { from { transform: scaleX(0); } to { transform: scaleX(1); } }
.stat-note { font-size: 11.5px; font-weight: 600; color: var(--ink-soft); }

/* ---------- Cards under the boxes ---------- */
.dash-card {
    position: relative;
    padding: 18px 20px 20px;
    background: var(--surface);
    border-radius: 14px;
    box-shadow: 0 4px 15px rgba(0, 0, 0, 0.04);
    transition: transform 0.35s var(--ease-pop), box-shadow 0.3s var(--ease-out), background-color 0.3s ease;
}

.dash-card:hover {
    transform: translateY(-4px) scale(1.012);
    box-shadow: 0 12px 28px rgba(0, 0, 0, 0.1);
    z-index: 2;
}

.card-top {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 16px;
    margin-bottom: 10px;
}

.card-top h3 { font-size: 15px; font-weight: 800; color: var(--ink); }
.chart-sub { display: block; margin-top: 3px; font-size: 11.5px; font-weight: 600; color: var(--ink-soft); }

.stats-unit {
    flex-shrink: 0;
    padding: 5px 12px;
    border-radius: 10px;
    background: var(--surface-2);
    color: var(--ink-soft);
    font-size: 11px;
    font-weight: 700;
}

/* Download PDF button (top right of the Questionnaires Statistics card) */
.stats-tools { display: flex; flex-wrap: wrap; align-items: center; justify-content: flex-end; gap: 10px; }

.stats-download {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    height: 32px;
    padding: 0 15px 0 12px;
    border: none;
    border-radius: 16px;
    background: var(--brand);
    color: #ffffff;
    font-size: 11.5px;
    font-weight: 700;
    cursor: pointer;
    box-shadow: 0 4px 12px rgba(163, 8, 30, 0.22);
    transition: transform 0.3s var(--ease-pop), box-shadow 0.3s var(--ease-out), background-color 0.2s ease;
}

.stats-download svg { width: 16px; height: 16px; fill: #ffffff; transition: transform 0.3s var(--ease-out); }
.stats-download:hover { background: var(--brand-dark); transform: translateY(-2px) scale(1.04); box-shadow: 0 8px 18px rgba(163, 8, 30, 0.3); }
.stats-download:hover svg { transform: translateY(2px); }
.stats-download:active { transform: translateY(0) scale(0.97); }
.stats-download:disabled { opacity: 0.7; cursor: progress; }
.stats-download.is-busy svg { animation: pdfBob 0.7s ease-in-out infinite; }
@keyframes pdfBob { 50% { transform: translateY(3px); } }

/* ---------- Questionnaires Statistics: sideways bars (left to right), scrollable ---------- */
.stats-scroll {
    max-height: 380px;                      /* many questions: scrolls up/down inside the card */
    overflow: auto;                         /* narrow window: scrolls sideways too */
    overscroll-behavior: auto;              /* at the end of the chart the page itself keeps scrolling */
    scrollbar-width: thin;
    border: 1px solid var(--surface-3);
    border-radius: 12px;
    background: var(--surface);
}

.stats-scroll:focus-visible { outline-offset: 0; }
.stats-inner { min-width: 700px; padding: 0 14px 6px; }

/* the 0 ... 100 scale (stays on top while the list scrolls), lined up with the bar area of every row */
.chart-axis,
.q-row {
    display: grid;
    grid-template-columns: minmax(230px, 36%) minmax(320px, 1fr);
    align-items: center;
    column-gap: 22px;
}

.chart-axis {
    position: sticky;
    top: 0;
    z-index: 3;
    padding: 10px 8px 8px;
    background: var(--surface);
    border-bottom: 1px solid var(--surface-2);
}

.axis-scale {
    display: flex;
    justify-content: space-between;
    font-size: 10px;
    font-weight: 700;
    color: var(--ink-faint);
    font-variant-numeric: tabular-nums;
}

.axis-scale i { font-style: normal; width: 0; display: flex; justify-content: center; }
.axis-scale i:first-child { justify-content: flex-start; }
.axis-scale i:last-child { justify-content: flex-end; }

.stats-list { border-top: none; }

.q-item { border-bottom: 1px solid var(--surface-2); animation: qIn 0.45s var(--ease-out) both; animation-delay: calc(var(--i, 0) * 45ms + 250ms); }
.q-item:last-child { border-bottom: none; }
@keyframes qIn { from { opacity: 0; transform: translateY(8px); } to { opacity: 1; transform: none; } }

.q-row {
    padding: 12px 8px;
    border-radius: 10px;
    cursor: pointer;
    transition: background-color 0.2s ease;
}

.q-row:hover, .q-row:focus-visible { background: var(--surface-2); outline: none; }

/* left side: id tag + question + answer chip */
.q-main { display: flex; align-items: flex-start; gap: 12px; min-width: 0; }

.q-badge {
    --c: #8f8aea;
    flex-shrink: 0;
    display: grid;
    place-items: center;
    min-width: 40px;
    height: 26px;
    padding: 0 8px;
    border-radius: 8px;
    background: color-mix(in srgb, var(--c) 28%, transparent);
    color: var(--ink);
    font-size: 11px;
    font-weight: 800;
}

.q-text {
    min-width: 0;
    font-size: 12.5px;
    font-weight: 700;
    line-height: 1.4;
    color: var(--ink);
}

.q-text span.q-title {
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
}

.q-meta { display: flex; flex-wrap: wrap; align-items: center; gap: 6px 8px; margin-top: 5px; }
.q-meta small { font-size: 10.5px; font-weight: 600; color: var(--ink-soft); }

.q-tag { padding: 1px 7px; border-radius: 8px; background: var(--surface-3); font-size: 10px; font-style: normal; font-weight: 800; color: var(--ink-soft); }
.q-item.is-off .q-text, .q-item.is-off .q-badge { opacity: 0.55; }

.q-answer {
    max-width: 100%;
    padding: 2px 10px;
    overflow: hidden;
    border-radius: 9px;
    background: var(--info-bg);
    color: var(--info-ink);
    font-size: 11px;
    font-weight: 800;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.q-answer.is-good { background: var(--ok-bg); color: var(--ok-ink); }
.q-answer.is-warn { background: var(--warn-bg); color: var(--warn-ink); }
.q-answer.is-muted { background: var(--surface-2); color: var(--ink-soft); }
html[data-theme="dark"] .q-answer.is-muted { background: var(--off-bg); }

/* right side: the bar grows from the left. Faint gridlines every 20 so the length can be read at a glance */
.q-plot {
    position: relative;
    height: 26px;
    background-image: linear-gradient(to right, var(--edge) 1px, transparent 1px);
    background-size: 20% 100%;
    box-shadow: inset -1px 0 0 var(--edge);
}

.q-bar {
    position: absolute;
    inset: 0 auto 0 0;
    width: 0;
    min-width: 0;
    border-radius: 0 6px 6px 0;
    background: var(--c);
    transition: width 1s var(--ease-out);
    transition-delay: calc(var(--i, 0) * 45ms + 200ms);
}

.stats-list.is-grown .q-bar { width: var(--w); }

/* the number sits at the end of the bar (inside it), or just after a very short bar */
.q-val {
    position: absolute;
    top: 50%;
    left: calc(var(--w) + 8px);
    transform: translateY(-50%);
    font-size: 11.5px;
    font-weight: 800;
    color: var(--ink);
    white-space: nowrap;
    opacity: 0;
    transition: opacity 0.4s ease 0.9s;
}

.stats-list.is-grown .q-val { opacity: 1; }

.q-val.is-inside { left: auto; right: calc(100% - var(--w) + 8px); color: #1f1f23; }   /* dark on the light bar, in both themes */

.q-val.is-none { left: 10px; color: var(--ink-faint); font-weight: 700; }

/* click a row: every answer with its share */
.q-detail {
    display: grid;
    grid-template-rows: 0fr;
    padding: 0 8px 0 60px;
    opacity: 0;
    transition: grid-template-rows 0.3s ease, opacity 0.25s ease, padding 0.3s ease;
}

.q-detail > div { overflow: hidden; min-height: 0; }
.q-item.is-open .q-detail { grid-template-rows: 1fr; padding-bottom: 14px; opacity: 1; }

.q-opt {
    display: grid;
    grid-template-columns: minmax(90px, 170px) 1fr 110px;
    align-items: center;
    gap: 12px;
    padding: 4px 0;
    font-size: 11.5px;
    font-weight: 600;
    color: var(--ink-soft);
}

.q-opt-name { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; color: var(--ink); }
.q-opt-bar { height: 8px; border-radius: 4px; background: var(--surface-3); overflow: hidden; }
.q-opt-bar i { display: block; height: 100%; width: 0; border-radius: inherit; background: var(--c); transition: width 0.7s var(--ease-out); }
.q-item.is-open .q-opt-bar i { width: var(--w); }
.q-opt.is-top .q-opt-name { font-weight: 800; }
.q-opt-val { text-align: right; font-variant-numeric: tabular-nums; }
.q-note { margin-top: 4px; font-size: 11px; font-weight: 600; color: var(--ink-soft); }

/* legend under the chart (Section 1 / Section 2 / ...), also the section filter */
.stats-filter { display: flex; flex-wrap: wrap; justify-content: center; gap: 8px; margin: 14px 0 0; }

.stats-chip {
    --c: var(--red);
    display: inline-flex;
    align-items: center;
    gap: 7px;
    height: 30px;
    padding: 0 12px;
    border: 1.5px solid transparent;
    border-radius: 15px;
    background: transparent;
    color: var(--ink-soft);
    font-size: 11.5px;
    font-weight: 700;
    cursor: pointer;
    transition: background-color 0.2s ease, border-color 0.2s ease, color 0.2s ease, transform 0.3s var(--ease-pop);
}

.stats-chip i { width: 10px; height: 10px; border-radius: 2px; background: var(--c); }
.stats-chip b { font-size: 10.5px; opacity: 0.7; }
.stats-chip:hover { transform: translateY(-2px); color: var(--ink); }
.stats-chip.is-on { border-color: var(--c); background: color-mix(in srgb, var(--c) 18%, transparent); color: var(--ink); }

.chart-empty { padding: 38px 0; text-align: center; font-size: 12.5px; font-weight: 600; color: var(--ink-soft); }

/* ---------- Recent Activities (latest 10) ---------- */
.activity-count { font-size: 12px; font-weight: 700; color: var(--ink-soft); }

.activity-card { scroll-margin-top: 20px; overflow: hidden; }

/* scrollable list: fills the card edge to edge, the header stays put, the rows scroll (latest 10) */
.activity-card .table-responsive {
    max-height: 340px;
    margin: 0 -20px 0;
    overflow-x: hidden;
    overflow-y: auto;
    scrollbar-width: thin;
    scrollbar-color: var(--edge) transparent;
}

.activity-card .table-responsive.has-more {
    -webkit-mask-image: linear-gradient(to bottom, #000 calc(100% - 34px), transparent);
            mask-image: linear-gradient(to bottom, #000 calc(100% - 34px), transparent);
}

.activity-card .table-responsive::-webkit-scrollbar { width: 6px; }
.activity-card .table-responsive::-webkit-scrollbar-thumb { background: var(--edge); border-radius: 6px; }

.activity-card .data-table td { white-space: nowrap; text-overflow: ellipsis; padding-top: 9px; padding-bottom: 9px; }
.activity-card .data-table th { position: sticky; top: 0; z-index: 2; }
.activity-card .data-table tbody tr { cursor: default; }
.activity-card .data-table td:first-child { padding-left: 20px; }
.activity-card .data-table th:last-child, .activity-card .data-table td:last-child { padding-right: 20px; }

/* an activity HR did since the Admin last opened the Dashboard */
.activity-card .data-table tbody tr.is-new { background-color: color-mix(in srgb, var(--red-bright) 6%, transparent); }
.activity-card .data-table tbody tr.is-new td strong { font-weight: 800; }
.activity-card .data-table tbody tr.is-new:hover { background-color: color-mix(in srgb, var(--red-bright) 11%, transparent); }

.empty-row { padding: 34px 10px !important; text-align: center; color: var(--ink-soft); font-weight: 600; }

/* the box that was jumped to (the Recent Activities summary box) glows once */
.activity-card.flash { animation: activityFlash 1.2s ease; }

@keyframes activityFlash {
    0%, 100% { box-shadow: var(--shadow-card); }
    30%      { box-shadow: 0 0 0 4px rgba(209, 18, 42, 0.28), var(--shadow-lift); }
}

@media (prefers-reduced-motion: reduce) {
    .q-item, .stat-bar span, .new-chip { animation: none; }
    .q-bar, .q-opt-bar i { transition: none; }
}

/* ---------- Smaller windows ---------- */
@media (max-width: 1100px) {
    .admin-metrics { grid-template-columns: 1fr; }
    .stat-card { min-height: 130px; }
}

@media (max-width: 860px) {
    .card-top { flex-direction: column; gap: 8px; }
    .q-detail { padding-left: 8px; }
    .q-opt { grid-template-columns: 100px 1fr 90px; }
}

    </style>
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
                <img src="../pic/expertise2.png" alt="Expertise Logo" class="brand-logo">
            </div>
            <button type="button" class="toggle-btn" id="sidebarToggle" title="Toggle Sidebar" aria-label="Toggle sidebar">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M15.41 7.41L14 6l-6 6 6 6 1.41-1.41L10.83 12z"/></svg>
            </button>
        </div>

        <nav class="sidebar-nav" aria-label="Admin">
            <a href="dashboardadmin.php" class="nav-item reveal active" id="dashboardLink" style="--i:1" title="Dashboard" aria-current="page">
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
            <a href="settingsadmin.php" class="nav-item reveal" style="--i:5" title="Settings">
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
        <header class="content-header reveal" style="--i:1">
            <h1 id="greeting" title="<?php echo htmlspecialchars($greeting, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($greeting, ENT_QUOTES, 'UTF-8'); ?></h1>
            <p>Overview of the system activities and key information</p>
        </header>

        <div class="admin-dashboard">

            <!-- Summary boxes (on top, like the HR dashboard) -->
            <div class="admin-metrics">
                <a class="stat-card tone-blue reveal" style="--i:2" href="hrlist.php" title="Open HR Accounts">
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

                <a class="stat-card tone-green reveal" style="--i:3" href="questionlist.php" title="Open Questions">
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
                        <button type="button" class="stats-download" id="statsPdfBtn" title="Download the results as a PDF file">
                            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M19 9h-4V3H9v6H5l7 7 7-7zM5 18v2h14v-2H5z"/></svg>
                            Download PDF
                        </button>
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

            <!-- Recent Activities: the latest 10 things HR and the Admin did in the system -->
            <section class="dash-card activity-card reveal" style="--i:6" id="recentActivities" aria-labelledby="activityTitle">
                <div class="card-top">
                    <h3 id="activityTitle">Recent Activities</h3>
                    <span class="activity-count"><?php echo htmlspecialchars($log_count, ENT_QUOTES, 'UTF-8'); ?></span>
                </div>
                <div class="table-responsive">
                    <table class="data-table activity-table">
                        <colgroup>
                            <col style="width: 24%">
                            <col style="width: 26%">
                            <col style="width: 34%">
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
        window.ADMIN_NOTIFY = <?php echo json_encode(static_admin_notify(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
        window.ADMIN_DASH = <?php echo json_encode(['stats' => $stats, 'activity' => $recent], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE); ?>;
    </script>
    <!-- Shared admin behaviour first, then this page's script -->
    <script src="../assets/js/admin-common.js?v=<?php echo @filemtime(__DIR__ . '/../assets/js/admin-common.js'); ?>"></script>
    <script src="../assets/js/stats-pdf.js?v=<?php echo @filemtime(__DIR__ . '/../assets/js/stats-pdf.js'); ?>"></script>
    <script src="../assets/js/dashboardadmin.js?v=<?php echo @filemtime(__DIR__ . '/../assets/js/dashboardadmin.js'); ?>"></script>
</body>
</html>