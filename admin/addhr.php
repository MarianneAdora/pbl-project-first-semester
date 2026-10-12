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

// The HR accounts + the sidebar badges (static-admin.php is the endpoint of this page too)
require_once __DIR__ . '/static-admin.php';

// addhr.php          -> Create HR Account
// addhr.php?id=2     -> Edit HR Account (the pencil in the HR Accounts list)
$editId  = (int) ($_GET['id'] ?? 0);
$editing = null;
if ($editId > 0) {
    foreach (static_admin_accounts() as $a) {
        if ((int) $a['id'] === $editId) { $editing = $a; break; }
    }
    if ($editing === null) {                       // deleted meanwhile, or a wrong id
        header('Location: hrlist.php');
        exit();
    }
}
$isEdit = $editing !== null;
$h = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $isEdit ? 'Edit HR Account' : 'Create HR Account'; ?> - Expertise Admin</title>

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
    <!-- This page's styles (Create / Edit HR Account), kept here so no separate file is needed -->
    <style>
/* ==========================================================================
   Create / Edit HR Account (admin): page styles.
   Looks and moves exactly like the HR Add Applicant page (assets/css/addapplicants.css):
   same input boxes with icons, same red "!" error messages + shake, same blue buttons with a
   spinner, same animated check mark, same success panel and the same custom dropdown.
   Shell, sidebar, buttons, modals, toast and the dark theme live in admin-common.css, the dropdown
   below (link both BEFORE this file). Colours come from the tokens, so the dark
   theme works by itself.
   ========================================================================== */

/* ---------- Custom dropdown (Role and Status): the same one the HR Screening List uses ---------- */
:root {
    --input-line: #cfd3d8;            /* border of the search box and the dropdown button */
    --input-line-hover: #a3a8af;
    --soft-red: #fdf1f3;              /* hover / selected tint (the HR pink) */
    --soft-red-2: #fadadf;
}

html[data-theme="dark"] {
    --input-line: #3d434c;
    --input-line-hover: #5a616c;
    --soft-red: rgba(255, 107, 128, 0.10);
    --soft-red-2: rgba(255, 107, 128, 0.22);
}

/* ---- Custom dropdown (the same one the HR Screening List uses) ---- */
.dropdown { position: relative; }

.dropdown-btn {
    width: 100%;
    height: 46px;
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 0 14px;
    background: var(--surface);
    border: 1.5px solid var(--input-line);
    border-radius: 12px;
    font-size: 14px;
    font-weight: 700;
    color: var(--ink);
    text-align: left;
    cursor: pointer;
    transition: border-color 0.2s ease, box-shadow 0.2s ease, background-color 0.2s ease, transform 0.3s var(--ease-pop);
}

.dropdown-btn:hover {
    border-color: var(--input-line-hover);
    background: var(--surface-2);
    transform: translateY(-1px);
}

.dropdown-btn:focus-visible,
.dropdown.open .dropdown-btn {
    outline: none;
    border-color: var(--brand);
    box-shadow: 0 0 0 3px color-mix(in srgb, var(--brand) 14%, transparent);
}

/* a filter that is switched on shows it in red, like the HR filter cards */
.dropdown.is-active .dropdown-btn {
    border-color: color-mix(in srgb, var(--brand) 55%, transparent);
    color: var(--red);
}

.dropdown.is-active .dropdown-chevron { fill: var(--red); }

.dropdown-value {
    flex: 1;
    min-width: 0;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.dropdown-chevron {
    width: 22px;
    height: 22px;
    flex-shrink: 0;
    fill: var(--ink);
    transition: transform 0.35s var(--ease-pop), fill 0.2s ease;
}

.dropdown.open .dropdown-chevron { transform: rotate(180deg); }

.dot {
    width: 10px;
    height: 10px;
    flex-shrink: 0;
    border-radius: 50%;
    background: var(--dot, #c5c9ce);
    box-shadow: 0 0 0 3px color-mix(in srgb, var(--dot, #c5c9ce) 20%, transparent);
    transition: background-color 0.25s ease, transform 0.3s var(--ease-pop);
}

.dropdown-btn:hover .dot { transform: scale(1.2); }

.dropdown-menu {
    position: absolute;
    top: calc(100% + 8px);
    left: 0;
    right: 0;
    margin: 0;
    padding: 6px;
    list-style: none;
    background: var(--surface);
    border: 1px solid var(--edge);
    border-radius: 14px;
    box-shadow: 0 18px 40px rgba(0, 0, 0, 0.16), 0 4px 12px rgba(0, 0, 0, 0.06);
    z-index: 50;
    opacity: 0;
    visibility: hidden;
    transform-origin: top center;
    transform: translateY(-8px) scale(0.97);
    transition: opacity 0.2s ease, transform 0.3s var(--ease-pop), visibility 0s linear 0.3s;
}

.dropdown.open .dropdown-menu {
    opacity: 1;
    visibility: visible;
    transform: none;
    transition: opacity 0.2s ease, transform 0.3s var(--ease-pop), visibility 0s;
}

.dropdown-option {
    display: flex;
    align-items: center;
    gap: 10px;
    height: 42px;
    padding: 0 12px;
    border-radius: 9px;
    font-size: 14px;
    font-weight: 600;
    color: var(--ink);
    cursor: pointer;
    user-select: none;
    transition: background-color 0.15s ease, color 0.15s ease, transform 0.25s var(--ease-pop);
}

.dropdown.open .dropdown-option {
    animation: optionIn 0.35s var(--ease-out) backwards;
    animation-delay: calc(var(--k, 0) * 35ms + 40ms);
}

.dropdown-option:hover,
.dropdown-option.is-focused {
    background: var(--soft-red);
    transform: translateX(4px);
}

.dropdown-option[aria-selected="true"] {
    color: var(--red);
    font-weight: 800;
}

.opt-label { flex: 1; }

.opt-count {
    min-width: 26px;
    padding: 2px 8px;
    border-radius: 20px;
    background: var(--surface-3);
    color: var(--ink-soft);
    font-size: 11.5px;
    font-weight: 800;
    text-align: center;
}

.dropdown-option[aria-selected="true"] .opt-count {
    background: var(--soft-red-2);
    color: var(--red);
}

.opt-check {
    width: 18px;
    height: 18px;
    fill: var(--red);
    opacity: 0;
    transform: scale(0.4);
    transition: opacity 0.2s ease, transform 0.3s var(--ease-pop);
}

.dropdown-option[aria-selected="true"] .opt-check { opacity: 1; transform: none; }

@keyframes optionIn {
    from { opacity: 0; transform: translateY(-6px); }
    to   { opacity: 1; transform: translateY(0); }
}


/* ---------- Title + Back ---------- */
.form-head { margin-bottom: 0; }
.form-head .content-header { margin-bottom: 22px; }

/* ---------- Card ---------- */
.form-card {
    padding: 30px 34px 28px;
}

.form-card .form-grid {
    gap: 20px 28px;
}

.form-card .field {
    margin-bottom: 0;
    position: relative;
}

/* the field whose dropdown is open sits above the fields below it */
.form-card .field.is-open { z-index: 20; }

.form-card .field > label {
    display: block;
    font-size: 13.5px;
    font-weight: 700;
    color: var(--ink);
    margin-bottom: 8px;
}

.form-card .field-wide { grid-column: 1 / -1; }

/* ---------- Inputs (box with a leading icon) ---------- */
.input-box {
    position: relative;
    display: flex;
    align-items: center;
    min-height: 46px;
    background: var(--field);
    border: 1px solid var(--edge);
    border-radius: 10px;
    transition: border-color 0.2s ease, box-shadow 0.2s ease, transform 0.3s var(--ease-pop), background-color 0.2s ease;
}

.input-box:hover { border-color: color-mix(in srgb, var(--ink) 25%, var(--edge)); }

.input-box:focus-within {
    border-color: var(--brand);
    box-shadow: 0 0 0 3px color-mix(in srgb, var(--brand) 14%, transparent);
}

.field-icon {
    position: absolute;
    left: 14px;
    width: 19px;
    height: 19px;
    fill: var(--ink-faint);
    pointer-events: none;
    transition: fill 0.2s ease, transform 0.3s var(--ease-pop);
}

.input-box:focus-within .field-icon { fill: var(--red); transform: scale(1.1); }

.input-box input[type="text"],
.input-box input[type="email"],
.input-box input[type="password"] {
    width: 100%;
    border: none;
    outline: none;
    background: transparent;
    font-size: 13.5px;
    font-weight: 500;
    color: var(--ink);
    padding: 0 14px 0 44px;
    height: 44px;
}

.input-box.has-eye input { padding-right: 50px; }

.input-box ::placeholder { color: var(--ink-faint); font-weight: 500; }

/* the eye that shows / hides the password */
.input-box .eye-toggle { position: absolute; right: 8px; }
.input-box .eye-toggle svg { fill: var(--ink-faint); transition: fill 0.2s ease; }
.input-box .eye-toggle:hover svg, .input-box:focus-within .eye-toggle svg { fill: var(--ink-soft); }

/* ---------- Validation (red "!" message + shake, like the HR form) ---------- */
.form-card .field.has-error .input-box,
.form-card .field.has-error .dropdown-btn {
    border-color: var(--red-bright);
    background: color-mix(in srgb, var(--red-bright) 6%, var(--field));
}

.form-card .field.has-error .input-box:focus-within {
    box-shadow: 0 0 0 3px color-mix(in srgb, var(--red-bright) 16%, transparent);
}

.form-card .field.shake .input-box,
.form-card .field.shake .dropdown-btn { animation: shake 0.4s ease; }

.form-card .field.shake { animation: none; }

.form-card .field-error {
    display: none;
    align-items: flex-start;
    gap: 6px;
    max-height: none;
    margin: 7px 0 0;
    font-size: 12px;
    font-weight: 600;
    line-height: 1.35;
    color: var(--red-bright);
    opacity: 1;
    transition: none;
}

/* small "!" badge in front of every message */
.form-card .field-error::before {
    content: '!';
    flex-shrink: 0;
    width: 15px;
    height: 15px;
    margin-top: 0.5px;
    border-radius: 50%;
    background: var(--red-bright);
    color: #ffffff;
    font-size: 10px;
    font-weight: 800;
    line-height: 15px;
    text-align: center;
}

.form-card .field.has-error .field-error {
    display: flex;
    max-height: none;
    margin-top: 7px;
    animation: errorIn 0.25s var(--ease-out);
}

@keyframes errorIn {
    from { opacity: 0; transform: translateY(-4px); }
    to   { opacity: 1; transform: none; }
}

.form-card .field.has-error .field-icon { fill: var(--red-bright); }

.form-card .field-hint { margin-top: 7px; }

/* a message from the server at the top of the form (the save was refused) */
.form-card .form-banner { margin-bottom: 22px; }

/* ---------- Dropdowns inside the form (Role, Status) ---------- */
.form-card .dropdown-btn { height: 46px; border-radius: 10px; font-weight: 600; }
.form-card .dropdown-menu { z-index: 60; }

/* ---------- Send credentials checkbox ---------- */
.send-row { margin-top: 22px; }
.send-row .check-row { margin: 0; }

/* ---------- Buttons ---------- */
.form-actions {
    display: flex;
    justify-content: flex-end;
    gap: 14px;
    margin-top: 24px;
}

.btn-primary,
.btn-secondary,
.btn-outline {
    height: 44px;
    padding: 0 30px;
    border-radius: 10px;
    font-family: inherit;
    font-size: 13px;
    font-weight: 800;
    letter-spacing: 0.5px;
    cursor: pointer;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    transition: background-color 0.2s ease, transform 0.3s var(--ease-pop), box-shadow 0.2s ease, opacity 0.2s ease;
}

.btn-primary { min-width: 140px; border: none; }
.btn-primary:disabled { opacity: 0.8; cursor: wait; }

.btn-secondary {
    background: var(--surface-2);
    color: var(--ink);
    border: 1px solid var(--edge);
    min-width: 130px;
}

.btn-secondary:hover:not(:disabled) {
    background: var(--surface-3);
    transform: translateY(-2px) scale(1.03);
}

.btn-secondary:active:not(:disabled) { transform: scale(0.98); }
.btn-secondary:disabled { opacity: 0.55; cursor: not-allowed; }

.btn-outline { background: var(--surface); border: 1.5px solid var(--blue); }

.btn-outline:hover {
    background: var(--info-bg);
    transform: translateY(-3px) scale(1.04);
    box-shadow: 0 8px 18px rgba(0, 162, 255, 0.2);
}

/* saving state: spinner appears and the label changes */
.spinner {
    display: none;
    width: 16px;
    height: 16px;
    border-radius: 50%;
    border: 2.5px solid rgba(255, 255, 255, 0.4);
    border-top-color: #ffffff;
    animation: spin 0.7s linear infinite;
}

.btn-primary.saving .spinner { display: inline-block; }

/* ---------- Animated check mark (modal + success panel) ---------- */
.big-check {
    width: 92px;
    height: 92px;
    display: block;
    margin: 0 auto 18px;
}

.big-check.small {
    width: 78px;
    height: 78px;
    margin: 0 0 14px;
}

.bc-circle { fill: #1a6e3e; transform-origin: center; }

.bc-tick {
    fill: none;
    stroke: #ffffff;
    stroke-width: 6;
    stroke-linecap: round;
    stroke-linejoin: round;
    stroke-dasharray: 60;
    stroke-dashoffset: 60;
}

.modal-backdrop.active .bc-circle,
.success-card:not([hidden]) .bc-circle {
    animation: checkPop 0.5s var(--ease-pop) 0.1s backwards;
}

.modal-backdrop.active .bc-tick,
.success-card:not([hidden]) .bc-tick {
    animation: checkDraw 0.45s ease-out 0.4s forwards;
}

@keyframes checkPop {
    from { transform: scale(0.3); opacity: 0; }
    to   { transform: scale(1); opacity: 1; }
}

@keyframes checkDraw { to { stroke-dashoffset: 0; } }

/* ---------- "Created" modal ---------- */
.invite-modal {
    position: relative;
    max-width: 440px;
    padding: 34px 32px 30px;
}

.invite-modal h3 {
    font-size: 21px;
    color: var(--ink);
    margin-bottom: 8px;
}

html[data-theme="dark"] .invite-modal h3 { color: var(--ink); }

.invite-lead {
    font-size: 13.5px;
    font-weight: 600;
    color: var(--ink-soft);
    margin-bottom: 16px !important;
}

.email-chip {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 10px;
    padding: 12px 14px;
    background: var(--info-bg);
    border: 1px solid var(--info-line);
    border-radius: 10px;
    color: var(--info-ink);
    font-size: 14px;
    font-weight: 700;
    margin-bottom: 14px;
    word-break: break-all;
}

.email-chip svg { width: 22px; height: 22px; fill: currentColor; flex-shrink: 0; }

.invite-note {
    font-size: 12.5px !important;
    line-height: 1.55;
    margin-bottom: 22px !important;
}

.btn-done { width: 100%; }

.modal-x {
    position: absolute;
    top: 14px;
    right: 14px;
    width: 32px;
    height: 32px;
    border: none;
    border-radius: 50%;
    background: transparent;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: background-color 0.2s ease, transform 0.3s var(--ease-pop);
}

.modal-x svg { width: 18px; height: 18px; fill: var(--ink-soft); }
.modal-x:hover { background: var(--surface-3); transform: rotate(90deg); }

/* ---------- Success panel ---------- */
.success-card { animation: panelIn 0.5s var(--ease-out) backwards; }
.success-card[hidden] { display: none; }

@keyframes panelIn {
    from { opacity: 0; transform: translateY(16px); }
    to   { opacity: 1; transform: none; }
}

.success-card h2 {
    font-size: 24px;
    font-weight: 800;
    color: var(--ink);
    margin-bottom: 6px;
}

.success-lead {
    font-size: 14px;
    font-weight: 500;
    color: var(--ink-soft);
    line-height: 1.55;
    margin-bottom: 24px;
}

.details-box {
    border: 1px solid var(--edge);
    border-radius: 12px;
    padding: 20px 24px 22px;
    margin-bottom: 26px;
    background: var(--surface-2);
}

.details-box h4 {
    font-size: 15px;
    font-weight: 800;
    color: var(--ink);
    margin-bottom: 14px;
}

.details-box dl {
    display: grid;
    grid-template-columns: 170px 1fr;
    gap: 12px 12px;
    font-size: 13.5px;
}

.details-box dt { font-weight: 600; color: var(--ink-soft); }

.details-box dd {
    font-weight: 700;
    color: var(--ink);
    min-width: 0;
    overflow-wrap: anywhere;
}

.success-actions {
    display: flex;
    flex-wrap: wrap;
    gap: 14px;
}

@media (max-width: 860px) {
    .details-box dl { grid-template-columns: 1fr; gap: 2px; }
    .details-box dd { margin-bottom: 8px; }
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

    <!-- Discard Confirmation Modal -->
    <div class="modal-backdrop" id="discardModal" role="dialog" aria-modal="true" aria-labelledby="discardTitle">
        <div class="modal-card">
            <h3 id="discardTitle"><?php echo $isEdit ? 'Discard your changes?' : 'Discard this account?'; ?></h3>
            <p><?php echo $isEdit ? 'The changes you made will be lost.' : 'The details you entered will be lost.'; ?></p>
            <div class="modal-actions">
                <button type="button" class="modal-btn is-cancel" id="keepEditing">Keep Editing</button>
                <button type="button" class="modal-btn is-confirm" id="confirmDiscard">Discard</button>
            </div>
        </div>
    </div>

    <!-- Account Created Modal -->
    <div class="modal-backdrop" id="createdModal" role="dialog" aria-modal="true" aria-labelledby="createdTitle">
        <div class="modal-card invite-modal">
            <button class="modal-x" id="createdClose" type="button" aria-label="Close">
                <svg viewBox="0 0 24 24"><path d="M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"/></svg>
            </button>
            <svg class="big-check" viewBox="0 0 80 80" aria-hidden="true">
                <circle class="bc-circle" cx="40" cy="40" r="36"/>
                <path class="bc-tick" d="M24 41l11 11 21-23"/>
            </svg>
            <h3 id="createdTitle">New HR Account created Successfully!</h3>
            <p class="invite-lead" id="createdLead">The login credentials will be sent to:</p>
            <div class="email-chip" id="createdChip">
                <svg viewBox="0 0 24 24"><path d="M20 4H4c-1.1 0-2 .9-2 2v12c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2zm0 4l-8 5-8-5V6l8 5 8-5v2z"/></svg>
                <span id="createdEmail">hr@email.com</span>
            </div>
            <button class="btn btn-primary btn-done" id="createdDone" type="button">DONE</button>
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
            <a href="dashboardadmin.php" class="nav-item reveal" id="dashboardLink" style="--i:1" title="Dashboard">
                <svg class="nav-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M10 20v-6h4v6h5v-8h3L12 3 2 12h3v8z"/></svg>
                <span class="nav-text">Dashboard</span>
                <span class="nav-badge alert" id="dashboardBadge" hidden></span>
            </a>

            <a href="hrlist.php" class="nav-item active reveal" style="--i:2" title="HR Accounts" aria-current="page">
                <svg class="nav-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M16 11c1.66 0 2.99-1.34 2.99-3S17.66 5 16 5c-1.66 0-3 1.34-3 3s1.34 3 3 3zm-8 0c1.66 0 2.99-1.34 2.99-3S9.66 5 8 5C6.34 5 5 6.34 5 8s1.34 3 3 3zm0 2c-2.33 0-7 1.17-7 3.5V19h14v-2.5c0-2.33-4.67-3.5-7-3.5zm8 0c-.29 0-.62.02-.97.05 1.16.84 1.97 1.97 1.97 3.45V19h6v-2.5c0-2.33-4.67-3.5-7-3.5z"/></svg>
                <span class="nav-text">HR Accounts</span>
                <span class="nav-badge alert" id="hrBadge" hidden></span>
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
        <div class="form-head reveal" style="--i:1">
            <header class="content-header">
                <h1><?php echo $isEdit ? 'Edit HR Account' : 'Create HR Account'; ?></h1>
                <p><?php echo $isEdit ? 'Edit existing account for HR screening staff' : 'Create an account for HR screening staff'; ?></p>
            </header>
            <a href="hrlist.php" class="back-link" id="backLink">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20 11H7.83l5.59-5.59L12 4l-8 8 8 8 1.41-1.41L7.83 13H20v-2z"/></svg>
                Back
            </a>
        </div>

        <!-- ================= Form ================= -->
        <section class="form-card reveal" style="--i:2" id="formCard">
            <div class="form-banner" id="formBanner" role="alert"></div>

            <form id="hrForm" novalidate autocomplete="off">
                <div class="form-grid">
                    <div class="field" data-field="name">
                        <label for="name">Full Name <span class="req">*</span></label>
                        <div class="input-box">
                            <svg class="field-icon" viewBox="0 0 24 24"><path d="M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z"/></svg>
                            <input type="text" id="name" name="name" placeholder="Enter full name" maxlength="100" value="<?php echo $isEdit ? $h($editing['name']) : ''; ?>" required>
                        </div>
                        <p class="field-error" role="alert"></p>
                    </div>

                    <div class="field" data-field="email">
                        <label for="email">Email Address <span class="req">*</span></label>
                        <div class="input-box">
                            <svg class="field-icon" viewBox="0 0 24 24"><path d="M20 4H4c-1.1 0-2 .9-2 2v12c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2zm0 4l-8 5-8-5V6l8 5 8-5v2z"/></svg>
                            <input type="email" id="email" name="email" placeholder="Enter email address" maxlength="150" value="<?php echo $isEdit ? $h($editing['email']) : ''; ?>" required>
                        </div>
                        <p class="field-error" role="alert"></p>
                    </div>

                    <div class="field" data-field="password">
                        <label for="password">Password <?php if (!$isEdit): ?><span class="req">*</span><?php else: ?><span class="optional">(leave empty to keep the current one)</span><?php endif; ?></label>
                        <div class="input-box has-eye">
                            <svg class="field-icon" viewBox="0 0 24 24"><path d="M18 8h-1V6c0-2.76-2.24-5-5-5S7 3.24 7 6v2H6c-1.1 0-2 .9-2 2v10c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V10c0-1.1-.9-2-2-2zm-6 9c-1.1 0-2-.9-2-2s.9-2 2-2 2 .9 2 2-.9 2-2 2zm3.1-9H8.9V6c0-1.71 1.39-3.1 3.1-3.1 1.71 0 3.1 1.39 3.1 3.1v2z"/></svg>
                            <input type="password" id="password" name="password" placeholder="Enter Password" maxlength="100" autocomplete="new-password">
                            <button type="button" class="eye-toggle" aria-label="Show password" aria-pressed="false" data-target="password">
                                <svg class="eye-on" viewBox="0 0 24 24"><path d="M12 4.5C7 4.5 2.73 7.61 1 12c1.73 4.39 6 7.5 11 7.5s9.27-3.11 11-7.5c-1.73-4.39-6-7.5-11-7.5zM12 17c-2.76 0-5-2.24-5-5s2.24-5 5-5 5 2.24 5 5-2.24 5-5 5zm0-8c-1.66 0-3 1.34-3 3s1.34 3 3 3 3-1.34 3-3-1.34-3-3-3z"/></svg>
                                <svg class="eye-off" viewBox="0 0 24 24"><path d="M12 7c2.76 0 5 2.24 5 5 0 .65-.13 1.26-.36 1.83l2.92 2.92c1.51-1.26 2.7-2.89 3.43-4.75-1.73-4.39-6-7.5-11-7.5-1.4 0-2.74.25-3.98.7l2.16 2.16C10.74 7.13 11.35 7 12 7zM2 4.27l2.28 2.28.46.46C3.08 8.3 1.78 10.02 1 12c1.73 4.39 6 7.5 11 7.5 1.55 0 3.03-.3 4.38-.84l.42.42L19.73 22 21 20.73 3.27 3 2 4.27zM7.53 9.8l1.55 1.55c-.05.21-.08.43-.08.65 0 1.66 1.34 3 3 3 .22 0 .44-.03.65-.08l1.55 1.55c-.67.33-1.41.53-2.2.53-2.76 0-5-2.24-5-5 0-.79.2-1.53.53-2.2zm4.31-.78l3.15 3.15.02-.16c0-1.66-1.34-3-3-3l-.17.01z"/></svg>
                            </button>
                        </div>
                        <p class="field-error" role="alert"></p>
                        <p class="field-hint">At least 8 characters, with letters and a number.</p>
                    </div>

                    <div class="field" data-field="password_confirm">
                        <label for="password_confirm">Confirm Password <?php if (!$isEdit): ?><span class="req">*</span><?php endif; ?></label>
                        <div class="input-box has-eye">
                            <svg class="field-icon" viewBox="0 0 24 24"><path d="M18 8h-1V6c0-2.76-2.24-5-5-5S7 3.24 7 6v2H6c-1.1 0-2 .9-2 2v10c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V10c0-1.1-.9-2-2-2zm-6 9c-1.1 0-2-.9-2-2s.9-2 2-2 2 .9 2 2-.9 2-2 2zm3.1-9H8.9V6c0-1.71 1.39-3.1 3.1-3.1 1.71 0 3.1 1.39 3.1 3.1v2z"/></svg>
                            <input type="password" id="password_confirm" name="password_confirm" placeholder="Confirm Password" maxlength="100" autocomplete="new-password">
                            <button type="button" class="eye-toggle" aria-label="Show password" aria-pressed="false" data-target="password_confirm">
                                <svg class="eye-on" viewBox="0 0 24 24"><path d="M12 4.5C7 4.5 2.73 7.61 1 12c1.73 4.39 6 7.5 11 7.5s9.27-3.11 11-7.5c-1.73-4.39-6-7.5-11-7.5zM12 17c-2.76 0-5-2.24-5-5s2.24-5 5-5 5 2.24 5 5-2.24 5-5 5zm0-8c-1.66 0-3 1.34-3 3s1.34 3 3 3 3-1.34 3-3-1.34-3-3-3z"/></svg>
                                <svg class="eye-off" viewBox="0 0 24 24"><path d="M12 7c2.76 0 5 2.24 5 5 0 .65-.13 1.26-.36 1.83l2.92 2.92c1.51-1.26 2.7-2.89 3.43-4.75-1.73-4.39-6-7.5-11-7.5-1.4 0-2.74.25-3.98.7l2.16 2.16C10.74 7.13 11.35 7 12 7zM2 4.27l2.28 2.28.46.46C3.08 8.3 1.78 10.02 1 12c1.73 4.39 6 7.5 11 7.5 1.55 0 3.03-.3 4.38-.84l.42.42L19.73 22 21 20.73 3.27 3 2 4.27zM7.53 9.8l1.55 1.55c-.05.21-.08.43-.08.65 0 1.66 1.34 3 3 3 .22 0 .44-.03.65-.08l1.55 1.55c-.67.33-1.41.53-2.2.53-2.76 0-5-2.24-5-5 0-.79.2-1.53.53-2.2zm4.31-.78l3.15 3.15.02-.16c0-1.66-1.34-3-3-3l-.17.01z"/></svg>
                            </button>
                        </div>
                        <p class="field-error" role="alert"></p>
                    </div>

                    <!-- Role (custom dropdown, like the HR filters) -->
                    <div class="field" data-field="role">
                        <label id="roleLabel">Role <span class="req">*</span></label>
                        <div class="dropdown" id="roleDropdown">
                            <button type="button" class="dropdown-btn" aria-haspopup="listbox" aria-expanded="false" aria-labelledby="roleLabel roleValue">
                                <span class="dot"></span>
                                <span class="dropdown-value" id="roleValue">HR Staff</span>
                                <svg class="dropdown-chevron" viewBox="0 0 24 24" aria-hidden="true"><path d="M7.41 8.59 12 13.17l4.59-4.58L18 10l-6 6-6-6z"/></svg>
                            </button>
                            <ul class="dropdown-menu" role="listbox" aria-labelledby="roleLabel" tabindex="-1">
                                <li class="dropdown-option" role="option" data-value="HR Staff" data-dot="#1c8adb" aria-selected="true" style="--k:0">
                                    <span class="dot"></span><span class="opt-label">HR Staff</span>
                                    <svg class="opt-check" viewBox="0 0 24 24" aria-hidden="true"><path d="M9 16.2 4.8 12l-1.4 1.4L9 19 21 7l-1.4-1.4z"/></svg>
                                </li>
                            </ul>
                        </div>
                        <p class="field-error" role="alert"></p>
                    </div>

                    <!-- Status (custom dropdown) -->
                    <div class="field" data-field="status">
                        <label id="statusLabel">Status <span class="req">*</span></label>
                        <div class="dropdown" id="statusDropdown">
                            <button type="button" class="dropdown-btn" aria-haspopup="listbox" aria-expanded="false" aria-labelledby="statusLabel statusValue">
                                <span class="dot"></span>
                                <span class="dropdown-value" id="statusValue">Active</span>
                                <svg class="dropdown-chevron" viewBox="0 0 24 24" aria-hidden="true"><path d="M7.41 8.59 12 13.17l4.59-4.58L18 10l-6 6-6-6z"/></svg>
                            </button>
                            <ul class="dropdown-menu" role="listbox" aria-labelledby="statusLabel" tabindex="-1">
                                <li class="dropdown-option" role="option" data-value="Active" data-dot="#159a43" aria-selected="true" style="--k:0">
                                    <span class="dot"></span><span class="opt-label">Active</span>
                                    <svg class="opt-check" viewBox="0 0 24 24" aria-hidden="true"><path d="M9 16.2 4.8 12l-1.4 1.4L9 19 21 7l-1.4-1.4z"/></svg>
                                </li>
                                <li class="dropdown-option" role="option" data-value="Inactive" data-dot="#8a8f96" aria-selected="false" style="--k:1">
                                    <span class="dot"></span><span class="opt-label">Inactive</span>
                                    <svg class="opt-check" viewBox="0 0 24 24" aria-hidden="true"><path d="M9 16.2 4.8 12l-1.4 1.4L9 19 21 7l-1.4-1.4z"/></svg>
                                </li>
                            </ul>
                        </div>
                        <p class="field-error" role="alert"></p>
                    </div>
                </div>

                <div class="send-row">
                    <label class="check-row">
                        <input type="checkbox" id="sendEmail" name="send_email" checked>
                        <span class="box"><svg viewBox="0 0 24 24"><path d="M5 12.5l4.5 4.5L19 7.5"/></svg></span>
                        Send Login credentials via email
                    </label>
                </div>

                <div class="form-actions">
                    <button type="button" class="btn btn-secondary" id="cancelBtn">CANCEL</button>
                    <button type="submit" class="btn btn-primary" id="saveBtn">
                        <span class="spinner" aria-hidden="true"></span>
                        <span class="btn-label"><?php echo $isEdit ? 'SAVE CHANGES' : 'SAVE'; ?></span>
                    </button>
                </div>
            </form>
        </section>

        <!-- ================= Success panel (shown after DONE) ================= -->
        <section class="form-card success-card" id="successCard" hidden>
            <svg class="big-check small" viewBox="0 0 80 80" aria-hidden="true">
                <circle class="bc-circle" cx="40" cy="40" r="36"/>
                <path class="bc-tick" d="M24 41l11 11 21-23"/>
            </svg>
            <h2>HR Created Successfully</h2>
            <p class="success-lead" id="successLead">The new HR account has been added to the system and the login credentials have been sent via email.</p>

            <div class="details-box">
                <h4>HR Details</h4>
                <dl>
                    <dt>Name:</dt><dd id="detName">-</dd>
                    <dt>Email:</dt><dd id="detEmail">-</dd>
                    <dt>Role:</dt><dd id="detRole">-</dd>
                </dl>
            </div>

            <div class="success-actions">
                <a href="hrlist.php" class="btn btn-primary">View HR List</a>
                <button type="button" class="btn btn-outline" id="addAnother">Add Another HR</button>
            </div>
        </section>
    </main>

    <!-- Pass PHP variables to JavaScript -->
    <script>
        window.ADMIN_NOTIFY = <?php echo json_encode(static_admin_notify(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
        window.ADMIN_HR_FORM = <?php echo json_encode([
            'mode'    => $isEdit ? 'edit' : 'create',
            'account' => $isEdit ? ['id' => (int) $editing['id'], 'name' => $editing['name'], 'email' => $editing['email'], 'role' => $editing['role'], 'status' => $editing['status']] : null
        ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE); ?>;
    </script>
    <!-- Shared admin behaviour first, then this page's script -->
    <script src="../assets/js/admin-common.js?v=<?php echo @filemtime(__DIR__ . '/../assets/js/admin-common.js'); ?>"></script>
    <script src="../assets/js/addhr.js?v=<?php echo @filemtime(__DIR__ . '/../assets/js/addhr.js'); ?>"></script>
</body>
</html>