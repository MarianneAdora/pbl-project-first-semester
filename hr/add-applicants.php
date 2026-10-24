<?php
session_start();

// No caching, so the Back button after logout never shows this page
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');

// Protection: Ensure only HR users can access this page
if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'hr') {
    header('Location: ../index.php');
    exit();
}

// Add Applicant writes straight to the database (PostgreSQL via PDO).
// This page is also its own POST endpoint (replaces the old static-data.php call).
// CV upload goes to Cloudinary under the ebsmi_cvs folder and cv_file_url stores the secure URL.
// After saving, a shortlist invite email is sent to the applicant's email address.
require_once __DIR__ . '/../src/Repositories/ApplicantRepository.php';
require_once __DIR__ . '/../src/Services/CloudinaryService.php';
require_once __DIR__ . '/../src/Services/EmailService.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $respond = function (array $data, int $code = 200): void {
        http_response_code($code);
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
        exit;
    };

    if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'hr') {
        $respond(['status' => 'error', 'message' => 'Your session has ended. Please log in again.'], 403);
    }

    $input = $_POST;
    $ct = (string) ($_SERVER['CONTENT_TYPE'] ?? '');
    if (stripos($ct, 'application/json') !== false) {
        $json = json_decode((string) file_get_contents('php://input'), true);
        if (is_array($json)) $input = $json;
    }

    // ---- CV autofill: POST add-applicants.php with action=parse_cv + cv file ----
    // Returns { status, parsed: {name, email, contact} } WITHOUT saving anything.
    if (($input['action'] ?? '') === 'parse_cv') {
        $cv = isset($_FILES['cv']) && is_array($_FILES['cv']) ? $_FILES['cv'] : null;
        if (!$cv || ($cv['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $respond(['status' => 'error', 'message' => 'Choose a CV file first.'], 422);
        }
        $ext = strtolower(pathinfo((string) ($cv['name'] ?? ''), PATHINFO_EXTENSION));
        if (!in_array($ext, ['pdf', 'doc', 'docx', 'txt'], true)) {
            $respond(['status' => 'error', 'message' => 'Upload a PDF, DOC or DOCX file.'], 422);
        }
        if (($cv['size'] ?? 0) > 5 * 1024 * 1024) {
            $respond(['status' => 'error', 'message' => 'The CV must be 5 MB or smaller.'], 422);
        }
        try {
            require_once __DIR__ . '/../src/Services/CvParserService.php';
            $parser = new CvParserService();
            $parsed = $parser->parseFile($cv['tmp_name'], (string) ($cv['name'] ?? ''), (string) ($cv['type'] ?? ''));
            $respond(['status' => 'success', 'parsed' => [
                'name' => (string) ($parsed['name'] ?? ''),
                'email' => (string) ($parsed['email'] ?? ''),
                'contact' => (string) ($parsed['contact'] ?? ''),
            ]]);
        } catch (Throwable $e) {
            error_log('addapplicants parse_cv error: ' . $e->getMessage());
            $respond(['status' => 'error', 'message' => 'Could not read that CV: ' . $e->getMessage()], 500);
        }
    }

    // ---- CV validation (optional file, PDF/DOC/DOCX, 5 MB max) ----
    $cvUpload = isset($_FILES['cv']) && is_array($_FILES['cv']) ? $_FILES['cv'] : null;
    if ($cvUpload && ($cvUpload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        if (($cvUpload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $respond(['status' => 'error', 'message' => 'The CV upload failed. Please try again.'], 422);
        }
        $cvExt = strtolower(pathinfo((string) ($cvUpload['name'] ?? ''), PATHINFO_EXTENSION));
        if (!in_array($cvExt, ['pdf', 'doc', 'docx'], true)) {
            $respond(['status' => 'error', 'message' => 'Upload a PDF, DOC or DOCX file.'], 422);
        }
        if (($cvUpload['size'] ?? 0) > 5 * 1024 * 1024) {
            $respond(['status' => 'error', 'message' => 'The CV must be 5 MB or smaller.'], 422);
        }
        if (($cvUpload['size'] ?? 0) === 0) {
            $respond(['status' => 'error', 'message' => 'This file is empty. Choose a different CV.'], 422);
        }
    } else {
        $cvUpload = null; // no file chosen: CV stays optional
    }

    try {
        // 1. Insert first so the Cloudinary subfolder uses the real applicant id: ebsmi_cvs/{id}
        $applicant = repo_add_applicant(
            (string) ($input['name'] ?? ''),
            (string) ($input['email'] ?? ''),
            (string) ($input['contact'] ?? '')
        );

        // 2. Upload the CV (if any) and save its URL on the row.
        // Flat layout: ebsmi_cvs/cv-{id}-{filename} (no subfolders), PDF = previewable image type.
        $cvUrl = null;
        $cvName = null;
        if ($cvUpload) {
            try {
                $cloud = new CloudinaryService();
                $uploaded = $cloud->uploadCV(
                    $cvUpload['tmp_name'],
                    $applicant['id'],
                    'ebsmi_cvs',
                    (string) ($cvUpload['name'] ?? ''),
                    (string) ($cvUpload['type'] ?? '')
                );
                $cvUrl = is_array($uploaded) ? ($uploaded['url'] ?? '') : (string) $uploaded;
                $cvName = is_array($uploaded) ? ($uploaded['name'] ?? '') : (string) ($cvUpload['name'] ?? '');
                if ($cvUrl === '') throw new Exception('Cloudinary returned an empty URL.');
                $cvName = (string) ($cvUpload['name'] ?? '');
            } catch (Throwable $e) {
                error_log('addapplicants Cloudinary error: ' . $e->getMessage());
                $respond(['status' => 'error', 'message' => 'Applicant saved, but the CV upload failed: ' . $e->getMessage() . ' (cv_file_url left empty)'], 502);
            }
            try {
                $pdo = db();
                $st = $pdo->prepare("UPDATE applicant SET cv_file_url = :url WHERE applicant_id = :id");
                $st->execute([':url' => $cvUrl, ':id' => $applicant['id']]);
            } catch (Throwable $e) {
                error_log('addapplicants cv_file_url DB error: ' . $e->getMessage());
            }
            $applicant['cv_file_url'] = $cvUrl;
            $applicant['cv_name'] = $cvName;
        }

        // 3. Send shortlist invite email to the applicant's email (extracted from CV or typed in).
        $emailSent = false;
        try {
            $emailService = new EmailService();
            $emailSent = $emailService->sendInviteEmail(
                (string) ($applicant['email'] ?? ''),
                (string) ($applicant['name'] ?? ''),
                (string) ($applicant['secure_token'] ?? '')
            );
        } catch (Throwable $e) {
            error_log('addapplicants invite email error: ' . $e->getMessage());
            $emailSent = false;
        }

        // Fresh numbers so the page can update badges (same shape the old endpoint sent).
        $respond([
            'status' => 'success',
            'applicant' => $applicant,
            'stats' => repo_dashboard_stats(),
            'completed_ids' => [],
            'archived' => count(repo_applicants_archived()),
            'email_sent' => $emailSent,
            'cv_uploaded' => $cvUrl !== null,
            'cv_url' => $cvUrl,
        ]);
    } catch (InvalidArgumentException $e) {
        $respond(['status' => 'error', 'message' => $e->getMessage()], 422);
    } catch (Throwable $e) {
        error_log('addapplicants DB error: ' . $e->getMessage());
        $respond(['status' => 'error', 'message' => 'Something went wrong while saving. Please try again.'], 500);
    }
}

try {
    $archive_sync = ['ids' => [], 'at' => []];
    foreach (repo_applicants_archived() as $a) {
        $archive_sync['ids'][] = (int) $a['id'];
        if (!empty($a['archived_at'])) $archive_sync['at'][(int) $a['id']] = (int) (strtotime((string) $a['archived_at']) * 1000);
    }
} catch (Throwable $e) {
    error_log('addapplicants archive DB error: ' . $e->getMessage());
    $archive_sync = ['ids' => [], 'at' => []];
}
?>
<!DOCTYPE html>
<html lang="en" data-page="addapplicants">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Applicant - Expertise Portal</title>

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

    <!-- hr.css = shared hr styles + every hr page (this page is picked by data-page="addapplicants" on <html>) -->
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

    <!-- Discard Confirmation Modal -->
    <div class="modal-backdrop" id="discardModal">
        <div class="modal-card">
            <h3>Discard this applicant?</h3>
            <p>The details you entered will be lost.</p>
            <div class="modal-actions">
                <button class="btn-cancel" id="keepEditing" type="button">Keep Editing</button>
                <button class="btn-confirm" id="confirmDiscard" type="button">Discard</button>
            </div>
        </div>
    </div>

    <!-- Invitation Sent Modal -->
    <div class="modal-backdrop" id="sentModal" role="dialog" aria-modal="true" aria-labelledby="sentTitle">
        <div class="modal-card invite-modal">
            <button class="modal-x" id="sentClose" type="button" aria-label="Close">
                <svg viewBox="0 0 24 24"><path d="M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"/></svg>
            </button>
            <svg class="big-check" viewBox="0 0 80 80" aria-hidden="true">
                <circle class="bc-circle" cx="40" cy="40" r="36"/>
                <path class="bc-tick" d="M24 41l11 11 21-23"/>
            </svg>
            <h3 id="sentTitle">Invitation Sent Successfully</h3>
            <p class="invite-lead">The screening invitation has been sent to:</p>
            <div class="email-chip">
                <svg viewBox="0 0 24 24"><path d="M20 4H4c-1.1 0-2 .9-2 2v12c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2zm0 4l-8 5-8-5V6l8 5 8-5v2z"/></svg>
                <span id="sentEmail">applicant@email.com</span>
            </div>
            <p class="invite-note">The applicant can access the screening through the link provided in the email.</p>
            <button class="btn-primary btn-done" id="sentDone" type="button">DONE</button>
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

            <!-- Applicants section: this page belongs to it, so it is highlighted and open -->
            <div class="nav-group current active reveal" id="applicantsGroup" style="--i:2">
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
                    <a href="screening-list.php" class="sub-item"><span class="sub-text">Screening List</span></a>
                    <a href="add-applicants.php" class="sub-item active" aria-current="page"><span class="sub-text">Add Applicant</span></a>
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
                <svg class="nav-icon" viewBox="0 0 24 24"><path d="M19.14 12.94c.04-.3.06-.61.06-.94 0-.32-.02-.64-.07-.94l2.03-1.58c.18-.14.23-.41.12-.61l-1.92-3.32c-.12-.22-.37-.29-.59-.22l-2.39.96c-.5-.38-1.03-.7-1.62-.94l-.36-2.54c-.04-.24-.24-.41-.48-.41h-3.84c-.24 0-.43.17-.47.41l-.36 2.54c-.59.24-1.13.57-1.62.94l-2.39-.96c-.22-.08-.47 0-.59.22L2.74 8.87c-.12.21-.08.47.12.61l2.03 1.58c-.05.3-.09.63-.09.94s.02.64.07.94l-2.03 1.58c-.18.14-.23.41-.12.61l1.92 3.32c.12.22.37.29.59.22l2.39-.96c.5.38 1.03.7 1.62.94l.36 2.54c.05.24.24.41.48.41h3.84c.24 0 .44-.17.47-.41l.36-2.54c.59-.24 1.13-.56 1.62-.94l2.39.96c.22.08.47 0 .59-.22l1.92-3.32c.12-.22.07-.47-.12-.61l-2.01-1.58zM12 15.6c-1.98 0-3.6-1.62-3.6-3.6s1.62-3.6 3.6-3.6 3.6 1.62 3.6 3.6-1.62 3.6-3.6 3.6z"/></svg>
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
            <h1>Add Shortlisted Applicant</h1>
            <p>Enter the applicant details and send a screening invitation.</p>
        </header>

        <!-- ================= Form ================= -->
        <section class="form-card reveal" style="--i:2" id="formCard">
            <form id="applicantForm" novalidate autocomplete="off">

                <div class="form-grid">
                    <div class="field" data-field="name">
                        <label for="name">Full Name <span class="req">*</span></label>
                        <div class="input-box">
                            <svg class="field-icon" viewBox="0 0 24 24"><path d="M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z"/></svg>
                            <input type="text" id="name" name="name" placeholder="Enter full name" maxlength="100" required>
                        </div>
                        <p class="field-error" role="alert"></p>
                    </div>

                    <div class="field" data-field="email">
                        <label for="email">Email Address <span class="req">*</span></label>
                        <div class="input-box">
                            <svg class="field-icon" viewBox="0 0 24 24"><path d="M20 4H4c-1.1 0-2 .9-2 2v12c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2zm0 4l-8 5-8-5V6l8 5 8-5v2z"/></svg>
                            <input type="email" id="email" name="email" placeholder="Enter email address" maxlength="150" required>
                        </div>
                        <p class="field-error" role="alert"></p>
                    </div>

                    <div class="field" data-field="contact">
                        <label for="contact">Contact Information <span class="req">*</span></label>
                        <div class="input-box">
                            <svg class="field-icon" viewBox="0 0 24 24"><path d="M6.62 10.79c1.44 2.83 3.76 5.14 6.59 6.59l2.2-2.2c.27-.27.67-.36 1.02-.24 1.12.37 2.33.57 3.57.57.55 0 1 .45 1 1V20c0 .55-.45 1-1 1-9.39 0-17-7.61-17-17 0-.55.45-1 1-1h3.5c.55 0 1 .45 1 1 0 1.25.2 2.45.57 3.57.11.35.03.74-.25 1.02l-2.2 2.2z"/></svg>
                            <input type="tel" id="contact" name="contact" placeholder="Enter contact number" maxlength="20" inputmode="tel" required>
                        </div>
                        <p class="field-error" role="alert"></p>
                    </div>

                    <div class="field" data-field="cv">
                        <label for="cv">CV Upload</label>
                        <div class="input-box file-box" id="fileBox">
                            <input type="file" id="cv" name="cv" accept=".pdf,.doc,.docx,application/pdf,application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document">
                            <label for="cv" class="choose-btn">
                                <svg viewBox="0 0 24 24"><path d="M9 16h6v-6h4l-7-7-7 7h4zm-4 2h14v2H5z"/></svg>
                                Choose File
                            </label>
                            <span class="file-name" id="fileName">PDF, DOC or DOCX, up to 5 MB</span>
                            <button type="button" class="file-remove" id="fileRemove" aria-label="Remove file" hidden>
                                <svg viewBox="0 0 24 24"><path d="M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"/></svg>
                            </button>
                        </div>
                        <p class="cv-autofill" id="cvAutofill" role="status" aria-live="polite"></p>
                        <p class="field-error" role="alert"></p>
                    </div>

                    <div class="field field-wide" data-field="note">
                        <label for="note">Note <span class="opt">(Optional)</span></label>
                        <div class="input-box textarea-box">
                            <svg class="field-icon" viewBox="0 0 24 24"><path d="M14 2H6c-1.1 0-2 .9-2 2v16c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V8l-6-6zm2 16H8v-2h8v2zm0-4H8v-2h8v2zm-3-5V3.5L18.5 9H13z"/></svg>
                            <textarea id="note" name="note" rows="3" maxlength="500" placeholder="Add any additional notes (only HR can see this)"></textarea>
                            <span class="note-count" id="noteCount">0/500</span>
                        </div>
                        <p class="field-error" role="alert"></p>
                    </div>
                </div>

                <div class="info-box">
                    <svg viewBox="0 0 24 24"><path d="M20 4H4c-1.1 0-2 .9-2 2v12c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2zm0 4l-8 5-8-5V6l8 5 8-5v2z"/></svg>
                    <div>
                        <h4>Screening Invitation</h4>
                        <p>The applicant is saved to the database with their CV (uploaded to Cloudinary) and will appear in the screening list. Email invites are disabled for now.</p>
                    </div>
                </div>

                <div class="form-actions">
                    <button type="button" class="btn-secondary" id="cancelBtn">CANCEL</button>
                    <button type="submit" class="btn-primary" id="sendBtn">
                        <svg class="send-icon" viewBox="0 0 24 24"><path d="M2.01 21L23 12 2.01 3 2 10l15 2-15 2z"/></svg>
                        <span class="spinner" aria-hidden="true"></span>
                        <span class="btn-label">SEND</span>
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
            <h2>Applicant Added Successfully</h2>
            <p class="success-lead">The applicant has been added to the system and the invitation has been sent via email.</p>

            <div class="details-box">
                <h4>Applicant Details</h4>
                <dl>
                    <dt>Name:</dt><dd id="detName">-</dd>
                    <dt>Email:</dt><dd id="detEmail">-</dd>
                    <dt>Contact Number:</dt><dd id="detContact">-</dd>
                </dl>
            </div>

            <div class="success-actions">
                <a href="screening-list.php" class="btn-primary">View Application List</a>
                <button type="button" class="btn-outline" id="addAnother">Add Another Applicant</button>
            </div>
        </section>
    </main>

    <!-- Shared HR behaviour first, then this page's script -->
    <script>window.HR_SERVER_ARCHIVE = <?php echo json_encode($archive_sync, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;</script>
    <script src="../assets/js/hr.js?v=<?php echo @filemtime(__DIR__ . '/../assets/js/hr.js'); ?>"></script>
</body>
</html>