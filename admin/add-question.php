<?php
require_once __DIR__ . '/../src/Helpers/security.php';
start_secure_session(1800);
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
require_once __DIR__ . '/../src/Helpers/db.php';
require_once __DIR__ . '/question-admin.php';

$question_types = [
    'yesno'        => 'Yes / No',
    'yesno_select' => 'Yes / No + a list',
    'scale'        => 'Rating scale',
    'date'         => 'Date',
    'text'         => 'Written answer',
    'consent'      => 'Statement + checkbox'
];

// add-question.php          -> Create Questionnaire
// add-question.php?id=q5    -> Edit Questionnaire (the pencil in the Questionnaires list)
$h       = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$editId  = trim((string) ($_GET['id'] ?? ''));
$current = repo_admin_questions_list();           // DB-backed list
$editing = null;
if ($editId !== '') {
    foreach ($current as $q) {
        if ((string) $q['id'] === $editId) { $editing = $q; break; }
    }
    if ($editing === null) {                   // deleted meanwhile, or a wrong id
        header('Location: question-list.php');
        exit();
    }
}
$isEdit = $editing !== null;

// The sections that exist (one entry per section number, with its title), for the Section list
$sections = [];
foreach ($current as $q) {
    $no = (int) $q['section_no'];
    if ($no > 0 && trim((string) $q['section']) !== '' && !isset($sections[$no])) {
        $sections[$no] = ['no' => $no, 'name' => (string) $q['section']];
    }
}
ksort($sections);
$sections = array_values($sections);

// A new section automatically becomes the next number (counting deleted questions too, like the server does)
$maxNo = (int) db()->query("SELECT COALESCE(MAX(section_no),0) FROM question_config")->fetchColumn();
$next_no = $maxNo + 1;

// One colour per section (the same ones as the Questionnaires Statistics on the Dashboard)
$palette = ['#8f8aea', '#74cfb9', '#f8a09a', '#f6c453', '#7ab8f0', '#c58ae0'];
$section_options = [];
foreach ($sections as $s) {
    $section_options[] = [(string) $s['no'], 'Sec ' . $s['no'] . ' · ' . $s['name'], $palette[($s['no'] - 1 + count($palette)) % count($palette)]];
}

// Derived from answer_config JSONB; see static_question_types()
$type_colors  = ['yesno' => '#159a43', 'yesno_select' => '#1c8adb', 'scale' => '#f6c453', 'date' => '#8f8aea', 'text' => '#f8a09a', 'consent' => '#74cfb9'];
$type_options = [];
foreach ($question_types as $key => $label) $type_options[] = [$key, $label, $type_colors[$key] ?? '#8a8f96'];

// One custom dropdown (the same markup as the Role / Status dropdowns on Create HR Account).
// $options = [[value, label, dot colour], ...]; $placeholder adds an empty first choice ("Select Section").
function q_dropdown(string $id, string $labelId, array $options, string $selected, string $placeholder = ''): void
{
    $e = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
    $sectionMenu = $id === 'sectionDropdown';
    if ($placeholder !== '') array_unshift($options, ['', $placeholder, '#c5c9ce']);
    $now = $options[0];
    foreach ($options as $o) {
        if ((string) $o[0] === $selected) { $now = $o; break; }
    }
    ?>
                        <div class="dropdown<?php echo (string) $now[0] === '' ? ' is-placeholder' : ''; ?>" id="<?php echo $e($id); ?>">
                            <button type="button" class="dropdown-btn" aria-haspopup="<?php echo $sectionMenu ? 'menu' : 'listbox'; ?>" aria-expanded="false" aria-labelledby="<?php echo $e($labelId . ' ' . $id . 'Value'); ?>">
                                <span class="dot"></span>
                                <span class="dropdown-value" id="<?php echo $e($id); ?>Value"><?php echo $e($now[1]); ?></span>
                                <svg class="dropdown-chevron" viewBox="0 0 24 24" aria-hidden="true"><path d="M7.41 8.59 12 13.17l4.59-4.58L18 10l-6 6-6-6z"/></svg>
                            </button>
                            <ul class="dropdown-menu<?php echo $sectionMenu ? ' section-options' : ''; ?>" role="<?php echo $sectionMenu ? 'menu' : 'listbox'; ?>" aria-labelledby="<?php echo $e($labelId); ?>" tabindex="-1">
                                <?php foreach ($options as $k => $o): ?>
                                <?php if ($sectionMenu): ?>
                                <li class="section-option-row" role="none" style="--k:<?php echo (int) $k; ?>">
                                    <button type="button" class="dropdown-option" role="menuitemradio" data-value="<?php echo $e($o[0]); ?>" data-dot="<?php echo $e($o[2]); ?>" aria-checked="<?php echo (string) $o[0] === (string) $now[0] ? 'true' : 'false'; ?>" tabindex="-1">
                                        <span class="dot"></span><span class="opt-label"><?php echo $e($o[1]); ?></span>
                                        <svg class="opt-check" viewBox="0 0 24 24" aria-hidden="true"><path d="M9 16.2 4.8 12l-1.4 1.4L9 19 21 7l-1.4-1.4z"/></svg>
                                    </button>
                                    <?php if ((string) $o[0] !== ''): ?>
                                    <button type="button" class="section-option-delete" role="menuitem" aria-label="<?php echo $e('Delete ' . $o[1]); ?>" title="<?php echo $e('Delete ' . $o[1]); ?>" tabindex="-1">
                                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M18 6 6 18M6 6l12 12"/></svg>
                                    </button>
                                    <?php endif; ?>
                                </li>
                                <?php else: ?>
                                <li class="dropdown-option" role="option" data-value="<?php echo $e($o[0]); ?>" data-dot="<?php echo $e($o[2]); ?>" aria-selected="<?php echo (string) $o[0] === (string) $now[0] ? 'true' : 'false'; ?>" style="--k:<?php echo (int) $k; ?>">
                                    <span class="dot"></span><span class="opt-label"><?php echo $e($o[1]); ?></span>
                                    <svg class="opt-check" viewBox="0 0 24 24" aria-hidden="true"><path d="M9 16.2 4.8 12l-1.4 1.4L9 19 21 7l-1.4-1.4z"/></svg>
                                </li>
                                <?php endif; ?>
                                <?php endforeach; ?>
                            </ul>
                        </div>
    <?php
}
?>
<!DOCTYPE html>
<html lang="en" data-page="addquestion">
<head>
    <meta charset="UTF-8">
    <meta name="csrf-token" content="<?= attr($csrf) ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $isEdit ? 'Edit Questionnaire' : 'Create Questionnaire'; ?> - Expertise Admin</title>

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

    <!-- admin.css = shared admin styles + every admin page (this page is picked by data-page="addquestion" on <html>) -->
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

            <a href="question-list.php" class="nav-item active reveal" style="--i:3" title="Questions" aria-current="page">
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
        <div class="form-head reveal" style="--i:1">
            <header class="content-header">
                <h1><?php echo $isEdit ? 'Edit Questionnaire' : 'Create Questionnaire'; ?></h1>
            </header>
            <a href="question-list.php" class="back-link" id="backLink">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20 11H7.83l5.59-5.59L12 4l-8 8 8 8 1.41-1.41L7.83 13H20v-2z"/></svg>
                Back
            </a>
        </div>

        <!-- ================= Form ================= -->
        <section class="form-card reveal" style="--i:2" id="formCard">
            <div class="form-banner" id="formBanner" role="alert"></div>

            <form id="qForm" novalidate autocomplete="off">
                <div class="form-grid">

                    <!-- Question -->
                    <div class="field field-wide" data-field="text">
                        <label for="text">Question <span class="req">*</span></label>
                        <textarea class="textarea" id="text" name="text" rows="4" maxlength="400" placeholder="Enter questionnaire"><?php echo $isEdit ? $h($editing['text']) : ''; ?></textarea>
                        <p class="field-error" role="alert"></p>
                        <p class="field-hint"><span id="textCount">0</span>/400. Write <code>{link}</code> where a clickable link should appear inside the question.</p>
                    </div>

                    <!-- Section: pick an existing one (it can be renamed) ... -->
                    <div class="field" data-field="section">
                        <label id="sectionLabel">Section <span class="req">*</span></label>
                        <div class="section-select-row">
                            <?php q_dropdown('sectionDropdown', 'sectionLabel', $section_options, $isEdit ? (string) (int) $editing['section_no'] : '', 'Select Section'); ?>
                        </div>
                        <p class="field-error" role="alert"></p>
                    </div>

                    <!-- ... or type the title of a NEW one (it automatically becomes the next section number) -->
                    <div class="field" data-field="section_title">
                        <label for="sectionTitle" id="sectionTitleLabel">New Section</label>
                        <div class="input-box">
                            <svg class="field-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M17 3H7c-1.1 0-1.99.9-1.99 2L5 21l7-3 7 3V5c0-1.1-.9-2-2-2z"/></svg>
                            <input type="text" id="sectionTitle" name="section_title" placeholder="Type New Section" maxlength="60">
                        </div>
                        <p class="field-error" role="alert"></p>
                        <p class="field-hint" id="sectionHint"></p>
                    </div>

                    <!-- Type -->
                    <div class="field" data-field="type">
                        <label id="typeLabel">Type <span class="req">*</span></label>
                        <?php q_dropdown('typeDropdown', 'typeLabel', $type_options, $isEdit ? (string) $editing['type'] : '', 'Select Type'); ?>
                        <p class="field-error" role="alert"></p>
                        <p class="field-hint">"Statement + checkbox" automatically marks this as a consent question.</p>
                    </div>

                    <!-- Status -->
                    <div class="field" data-field="status">
                        <label id="statusLabel">Status <span class="req">*</span></label>
                        <?php q_dropdown('statusDropdown', 'statusLabel', [['Active', 'Active', '#159a43'], ['Inactive', 'Inactive', '#8a8f96']], $isEdit ? (string) ($editing['status'] ?? 'Active') : 'Active'); ?>
                        <p class="field-error" role="alert"></p>
                    </div>

                    <div class="field field-wide" data-field="is_data_protection" id="dataProtectionField" hidden>
                        <label class="check-row">
                            <input type="checkbox" id="isDataProtection" name="is_data_protection" aria-describedby="dataProtectionHint">
                            <span class="box"><svg viewBox="0 0 24 24"><path d="M5 12.5l4.5 4.5L19 7.5"/></svg></span>
                            Track this as a data-protection agreement
                        </label>
                        <p class="field-hint" id="dataProtectionHint">When the applicant answers Yes or agrees, save the agreement time with their response.</p>
                    </div>

                    <!-- ===== Extra fields of the chosen type (only the matching box is shown) ===== -->
                    <div class="sub-fields" data-for="yesno" hidden>
                        <p class="sub-title">Yes / No settings</p>
                        <div class="field-row">
                            <div class="field" data-field="no_note">
                                <label for="no_note">Reminder when the answer is No <span class="optional">(optional)</span></label>
                                <div class="input-box no-icon"><input type="text" id="no_note" name="no_note" maxlength="200" placeholder="Example: Please note this may affect your application."></div>
                                <p class="field-error" role="alert"></p>
                            </div>
                            <div class="field" data-field="favorable">
                                <label id="favorableLabel">Counts as the good answer</label>
                                <?php q_dropdown('favorableDropdown', 'favorableLabel', [['Yes', 'Yes', '#159a43'], ['No', 'No', '#c0392b']], 'Yes'); ?>
                                <p class="field-hint">Used by the Questionnaires Statistics on the Dashboard.</p>
                            </div>
                        </div>
                    </div>

                    <div class="sub-fields" data-for="yesno_select" hidden>
                        <p class="sub-title">Yes / No + a list</p>
                        <div class="field-row">
                            <div class="field" data-field="followup_label">
                                <label for="followup_label">Label above the list <span class="req">*</span></label>
                                <div class="input-box no-icon"><input type="text" id="followup_label" name="followup_label" maxlength="80" placeholder="Example: Your notice period"></div>
                                <p class="field-error" role="alert"></p>
                            </div>
                            <div class="field" data-field="followup_when">
                                <label id="followupWhenLabel">Show the list when the answer is</label>
                                <?php q_dropdown('followupWhenDropdown', 'followupWhenLabel', [['Yes', 'Yes', '#159a43'], ['No', 'No', '#c0392b']], 'Yes'); ?>
                            </div>
                        </div>
                        <div class="field" data-field="followup_options">
                            <label for="followup_options">Choices in the list <span class="req">*</span></label>
                            <textarea class="textarea" id="followup_options" name="followup_options" rows="4" maxlength="2000" placeholder="One choice per line"></textarea>
                            <p class="field-error" role="alert"></p>
                            <p class="field-hint">One choice per line, from 2 up to 12 choices.</p>
                        </div>
                    </div>

                    <div class="sub-fields" data-for="scale" hidden>
                        <p class="sub-title">Rating scale</p>
                        <div class="field-row">
                            <div class="field" data-field="max">
                                <label for="max">Highest rating <span class="req">*</span></label>
                                <div class="input-box no-icon"><input type="text" id="max" name="max" data-input-rule="digits" inputmode="numeric" maxlength="2" placeholder="5"></div>
                                <p class="field-error" role="alert"></p>
                                <p class="field-hint">The scale goes from 1 up to this number (3 to 10).</p>
                            </div>
                            <div class="field"></div>
                            <div class="field" data-field="label_left">
                                <label for="label_left">Left label <span class="optional">(optional)</span></label>
                                <div class="input-box no-icon"><input type="text" id="label_left" name="label_left" maxlength="40" placeholder="Example: Not satisfied"></div>
                                <p class="field-error" role="alert"></p>
                            </div>
                            <div class="field" data-field="label_right">
                                <label for="label_right">Right label <span class="optional">(optional)</span></label>
                                <div class="input-box no-icon"><input type="text" id="label_right" name="label_right" maxlength="40" placeholder="Example: Very satisfied"></div>
                                <p class="field-error" role="alert"></p>
                            </div>
                        </div>
                    </div>

                    <div class="sub-fields" data-for="date" hidden>
                        <p class="sub-title">Date</p>
                        <div class="field" data-field="date_hint">
                            <label for="date_hint">Hint under the date <span class="optional">(optional)</span></label>
                            <div class="input-box no-icon"><input type="text" id="date_hint" name="date_hint" maxlength="200" placeholder="Example: Choose the earliest date you can start"></div>
                            <p class="field-error" role="alert"></p>
                        </div>
                    </div>

                    <div class="sub-fields" data-for="text" hidden>
                        <p class="sub-title">Written answer</p>
                        <div class="field-row">
                            <div class="field" data-field="text_hint">
                                <label for="text_hint">Hint under the box <span class="optional">(optional)</span></label>
                                <div class="input-box no-icon"><input type="text" id="text_hint" name="text_hint" maxlength="200" placeholder="Example: Tell us in a few sentences"></div>
                                <p class="field-error" role="alert"></p>
                            </div>
                            <div class="field" data-field="placeholder">
                                <label for="placeholder">Text shown inside the empty box <span class="optional">(optional)</span></label>
                                <div class="input-box no-icon"><input type="text" id="placeholder" name="placeholder" maxlength="200" placeholder="Type your answer here..."></div>
                                <p class="field-error" role="alert"></p>
                            </div>
                            <div class="field" data-field="min_chars">
                                <label for="min_chars">Shortest answer (characters)</label>
                                <div class="input-box no-icon"><input type="text" id="min_chars" name="min_chars" data-input-rule="digits" inputmode="numeric" maxlength="4" placeholder="0"></div>
                                <p class="field-error" role="alert"></p>
                            </div>
                            <div class="field" data-field="max_chars">
                                <label for="max_chars">Longest answer (characters) <span class="req">*</span></label>
                                <div class="input-box no-icon"><input type="text" id="max_chars" name="max_chars" data-input-rule="digits" inputmode="numeric" maxlength="4" placeholder="500"></div>
                                <p class="field-error" role="alert"></p>
                            </div>
                        </div>
                    </div>

                    <div class="sub-fields" data-for="consent" hidden>
                        <p class="sub-title">Statement + checkbox</p>
                        <div class="field" data-field="body">
                            <label for="body">Statement the applicant reads <span class="req">*</span></label>
                            <textarea class="textarea" id="body" name="body" rows="5" maxlength="10000" placeholder="Write the statement here"></textarea>
                            <p class="field-error" role="alert"></p>
                            <p class="field-hint">Leave an empty line between paragraphs.</p>
                        </div>
                        <div class="field" data-field="checkbox">
                            <label for="checkbox">Text next to the checkbox <span class="req">*</span></label>
                            <div class="input-box no-icon"><input type="text" id="checkbox" name="checkbox" maxlength="200" placeholder="Example: I agree"></div>
                            <p class="field-error" role="alert"></p>
                        </div>
                    </div>

                    <!-- ===== Screening rubric: knock-out question + the passing answer (Yes/No, Yes/No + list and rating questions) ===== -->
                    <div class="sub-fields" data-for="knockout" hidden>
                        <p class="sub-title">Screening rubric</p>
                        <label class="check-row">
                            <input type="checkbox" id="knockout" name="knockout">
                            <span class="box"><svg viewBox="0 0 24 24"><path d="M5 12.5l4.5 4.5L19 7.5"/></svg></span>
                            This is a knock-out question
                        </label>
                        <p class="field-hint ko-hint">An applicant whose answer does not match the passing answer fails the screening. HR sees which question it was and why in the applicant's Questionnaires tab.</p>

                        <div class="ko-fields" id="koFields" hidden>
                            <!-- Yes / No (and Yes / No + a list) -->
                            <div class="field" data-field="pass_answer" id="passAnswerField">
                                <label id="passLabel">Passing answer <span class="req">*</span></label>
                                <?php q_dropdown('passDropdown', 'passLabel', [['Yes', 'Yes', '#159a43'], ['No', 'No', '#c0392b']], 'Yes'); ?>
                                <p class="field-error" role="alert"></p>
                                <p class="field-hint">Any other answer fails the screening.</p>
                            </div>

                            <!-- Yes / No + a list: which choices still pass (shown while the passing answer is the one that opens the list) -->
                            <div class="field" data-field="pass_follow" id="passFollowField" hidden>
                                <label id="passFollowLabel">Choices that still pass <span class="optional">(optional)</span></label>
                                <div class="ko-choices" id="passFollowList"></div>
                                <p class="field-hint">Tick the choices that pass. Leave all unticked to accept every choice.</p>
                            </div>

                            <!-- Rating scale -->
                            <div class="field" data-field="pass_min" id="passMinField" hidden>
                                <label for="pass_min">Lowest passing rating <span class="req">*</span></label>
                                <div class="input-box no-icon"><input type="text" id="pass_min" name="pass_min" data-input-rule="digits" inputmode="numeric" maxlength="2" placeholder="3"></div>
                                <p class="field-error" role="alert"></p>
                                <p class="field-hint" id="passMinHint">A rating lower than this fails the screening.</p>
                            </div>
                        </div>
                    </div>

                    <!-- shown while the question contains {link} -->
                    <div class="sub-fields" data-for="link" hidden>
                        <p class="sub-title">The link inside the question</p>
                        <div class="field-row">
                            <div class="field" data-field="link_text">
                                <label for="link_text">Clickable words <span class="req">*</span></label>
                                <div class="input-box no-icon"><input type="text" id="link_text" name="link_text" maxlength="60" placeholder="Example: Privacy Policy"></div>
                                <p class="field-error" role="alert"></p>
                            </div>
                            <div class="field" data-field="link_url">
                                <label for="link_url">Web address <span class="req">*</span></label>
                                <div class="input-box no-icon"><input type="text" id="link_url" name="link_url" maxlength="500" placeholder="https://"></div>
                                <p class="field-error" role="alert"></p>
                            </div>
                        </div>
                    </div>
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
    </main>

    <!-- Discard Confirmation Modal -->
    <div class="modal-backdrop" id="discardModal" role="dialog" aria-modal="true" aria-labelledby="discardTitle">
        <div class="modal-card">
            <h3 id="discardTitle"><?php echo $isEdit ? 'Discard your changes?' : 'Discard this question?'; ?></h3>
            <p><?php echo $isEdit ? 'The changes you made will be lost.' : 'What you wrote will be lost.'; ?></p>
            <div class="modal-actions">
                <button type="button" class="modal-btn is-cancel" id="keepEditing">Keep Editing</button>
                <button type="button" class="modal-btn is-confirm" id="confirmDiscard">Discard</button>
            </div>
        </div>
    </div>

    <!-- Pass PHP variables to JavaScript -->
    <script>
        window.ADMIN_NOTIFY = <?php
            try { echo json_encode(repo_admin_notify(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); }
            catch (Throwable $e) {
                error_log('add-question.php DB notification error: ' . $e->getMessage());
                echo json_encode(['activity' => [], 'recent' => [], 'expiring' => 0, 'days' => 7]);
            }
        ?>;
        window.ADMIN_QFORM = <?php echo json_encode([
            'mode'     => $isEdit ? 'edit' : 'create',
            'question' => $isEdit ? $editing : null,
            'sections' => $sections,
            'nextNo'   => $next_no
        ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE); ?>;
    </script>
    <!-- Shared admin behaviour first, then this page's script -->
    <script src="../assets/js/input-rules.js?v=<?php echo @filemtime(__DIR__ . '/../assets/js/input-rules.js'); ?>"></script>
    <script src="../assets/js/admin.js?v=<?php echo @filemtime(__DIR__ . '/../assets/js/admin.js'); ?>"></script>
</body>
</html>