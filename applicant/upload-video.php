<?php
session_start();

// ---- Settings ---------------------------------------------------------------
// Only an applicant who passed the email check may be here. Set this to false to design or test
// this page on its own, without going through opening.php and verify-otp.php first.
$requireVerification = true;      // TODO: keep true in production

$nextPage = 'application-status.php';
$backPage = 'questionnaire.php';

// Real upload: api/upload-file.php (Cloudinary, folder shortlist_applicant_videos).
$simulateUpload = false;
$uploadUrl      = '../api/upload-file.php';

// Stepper (same as the other applicant pages): 0 = Verification, 1 = Instructions, 2 = Questionnaires, 3 = Video, 4 = Submit
$currentStep = 3;
$stepLabels  = ['Verification', 'Instructions', 'Questionnaires', 'Video', 'Submit'];

// No caching, so the Back button never shows an old copy of the page
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');

if ($requireVerification) {
    if (empty($_SESSION['otp_verified']))         { header('Location: opening.php');            exit; }
    if (!empty($_SESSION['screening_submitted'])) { header('Location: application-status.php'); exit; }   // already sent: no second try
}

// ---- Video rules + questions (DB first, static fallback) -----
require_once __DIR__ . '/static-applicant.php';
$video = static_video_rules();
try {
    require_once __DIR__ . '/../src/Repositories/AdminRepository.php';
    $questions = db_questions_for_applicant();
    if (!$questions) $questions = static_questionnaire();
} catch (Throwable $e) {
    error_log('upload-video DB error: ' . $e->getMessage());
    $questions = static_questionnaire();
}
$formatsText = strtoupper(implode(', ', $video['formats']));

$screening = [
    'questions'      => $questions,
    'video'          => $video,
    'endpoint'       => '../api/submit-response.php',
    'session'        => substr(sha1(session_id()), 0, 16),
    'live'           => $requireVerification,
    'nextPage'       => $nextPage,
    'backPage'       => $backPage,
    'uploadUrl'      => $uploadUrl,
    'simulateUpload' => $simulateUpload
];
?>
<!DOCTYPE html>
<html lang="en" data-page="upload-video">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Video Response | Expertise</title>

    <!-- Runs before first paint: marks JS as on (the entrance animation needs it) -->
    <script>
        document.documentElement.classList.add('js');
        // safety: if the scripts fail to load, never leave the page invisible
        setTimeout(function () { document.documentElement.classList.add('is-ready'); }, 3000);
    </script>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@500;600&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">

    <!-- applicant.css = shared applicant styles + every applicant page (this page is picked by data-page="upload-video" on <html>) -->
    <link rel="stylesheet" href="../assets/css/applicant.css?v=<?= @filemtime(__DIR__ . '/../assets/css/applicant.css') ?>">
</head>
<body>

<main class="page stage">

    <div class="wrap">

        <!-- Progress stepper: the dots pop in one by one and the lines draw between them -->
        <ol class="stepper" aria-label="Screening progress">
            <?php foreach ($stepLabels as $i => $label):
                $state = $i < $currentStep ? 'is-done' : ($i === $currentStep ? 'is-current' : 'is-upcoming');
            ?>
                <li class="step reveal reveal-fade <?= $state ?>" style="--i:<?= 1 + $i * 1.2 ?>" <?= $i === $currentStep ? 'aria-current="step"' : '' ?>>
                    <span class="step__dot">
                        <?php if ($state === 'is-done'): ?>
                            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12.5l4.5 4.5L19 7.5"/></svg>
                        <?php else: ?>
                            <span class="step__pip"></span>
                        <?php endif; ?>
                    </span>
                    <span class="step__label"><?= htmlspecialchars($label) ?></span>
                </li>
            <?php endforeach; ?>
        </ol>

        <div class="main glass reveal" style="--i:5">

            <section class="intro">
                <h1 class="intro__title">Video Response</h1>
                <p class="intro__text">
                    Upload your completed video response, or record it right here with your camera. You can add one video only.
                </p>
                <p class="tenbadge">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="13" r="8"/><path d="M12 9v4.5l3 1.8M9.5 3h5"/></svg>
                    Only <?= (int) $video['max_seconds'] ?> seconds needed. No longer video is required.
                </p>
            </section>

            <!-- Drop zone: drag a video in, browse for one, or record it with the camera -->
            <section class="dropzone" id="dropzone" aria-label="Add your video">

                <!-- state 1: pick or drop a file -->
                <div class="dropzone__pick" id="pickPanel">
                    <svg class="dropzone__cloud" viewBox="0 0 64 64" aria-hidden="true">
                        <path d="M20 46a12 12 0 0 1-1.6-23.9A15 15 0 0 1 47.3 20 11.5 11.5 0 0 1 46 46"/>
                        <path d="M32 52V30M24 37l8-8 8 8"/>
                    </svg>
                    <p class="dropzone__title" id="dropTitle">Drag and drop your video here</p>
                    <p class="dropzone__or">or</p>
                    <div class="dropzone__buttons">
                        <label class="btn btn--primary" for="videoInput" id="browseBtn" tabindex="0" role="button">Browse Files</label>
                        <button type="button" class="btn btn--outline" id="recordBtn" hidden>
                            <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="7"/></svg>
                            Record Video
                        </button>
                    </div>
                    <input type="file" id="videoInput" accept=".mp4,.mov,video/mp4,video/quicktime" hidden>
                </div>

                <!-- state 2: the camera (record up to the limit, then review) -->
                <div class="recorder" id="recorder" hidden>
                    <div class="recorder__view" id="recView">
                        <video id="camVideo" playsinline muted></video>
                        <div class="recorder__count" id="camCount" aria-hidden="true"></div>
                        <div class="recorder__rec" id="camRec" hidden><span class="rec-dot"></span><span id="camTime">0:00</span></div>
                        <div class="recorder__bar"><span id="camBar"></span></div>
                    </div>
                    <div class="recorder__side">
                        <p class="recorder__title" id="recTitle">Check your camera</p>
                        <p class="recorder__text" id="recText">Face the camera in good light. Your video is <?= (int) $video['max_seconds'] ?> seconds long.</p>
                        <div class="recorder__actions" id="recActions">
                            <button type="button" class="btn btn--primary" id="recStart">Start Recording</button>
                            <button type="button" class="btn btn--dark" id="recStop" hidden>Stop</button>
                            <button type="button" class="btn btn--primary" id="recUse" hidden>Use This Video</button>
                            <button type="button" class="btn btn--ghost" id="recRetake" hidden>Retake</button>
                            <button type="button" class="btn btn--ghost" id="recCancel">Cancel</button>
                        </div>
                    </div>
                </div>
            </section>

            <p class="rules">
                <span>Supported formats: <?= htmlspecialchars($formatsText) ?></span>
                <span>Max file size: <?= (int) $video['max_mb'] ?>MB</span>
                <span>1 video only</span>
                <span>Video length: <?= (int) $video['max_seconds'] ?> seconds</span>
            </p>

            <!-- One message at a time -->
            <div class="vmsg" id="vmsg">
                <div class="vmsg__inner">
                    <div class="banner" id="vError" role="alert">
                        <svg class="banner__icon" viewBox="0 0 24 24" aria-hidden="true">
                            <circle cx="12" cy="12" r="10" fill="currentColor"/>
                            <path d="M12 7v6M12 16.5v.5" stroke="#fff" stroke-width="2.2" stroke-linecap="round"/>
                        </svg>
                        <span id="vErrorText"></span>
                    </div>
                </div>
            </div>

            <!-- The chosen video (appears after a file is picked or recorded) -->
            <section class="filecard" id="fileCard" hidden aria-live="polite">
                <button type="button" class="filecard__thumb" id="thumbBtn" aria-label="Play your video">
                    <img id="thumbImg" alt="" hidden>
                    <svg class="filecard__play" viewBox="0 0 24 24" aria-hidden="true"><path d="M8 5.5v13l11-6.5z"/></svg>
                </button>
                <div class="filecard__body">
                    <div class="filecard__top">
                        <span class="filecard__name" id="fileName">video_response.mp4</span>
                        <span class="filecard__size" id="fileSize">[0 MB]</span>
                    </div>
                    <div class="filecard__progress">
                        <div class="bar" id="uploadBar" role="progressbar" aria-label="Upload progress" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0"><span></span></div>
                        <span class="filecard__pct" id="filePct">0%</span>
                    </div>
                    <div class="filecard__actions">
                        <button type="button" class="link link--plain" id="removeBtn">Remove</button>
                        <span class="filecard__state" id="fileState"></span>
                    </div>
                </div>
            </section>

            <button type="button" class="btn btn--primary submit" id="submitBtn" disabled>
                <span class="submit__label">Submit Video</span>
                <span class="submit__arrow" aria-hidden="true">&rarr;</span>
            </button>

        </div>
    </div>
</main>

<!-- Preview of the chosen video -->
<div class="vmodal" id="previewModal" role="dialog" aria-modal="true" aria-label="Video preview" hidden>
    <div class="vmodal__card">
        <video id="previewVideo" controls playsinline></video>
        <button type="button" class="vmodal__close" id="previewClose" aria-label="Close preview">&times;</button>
    </div>
</div>

<script>
    window.SCREENING = <?= json_encode($screening, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>;
</script>
<script src="../assets/js/applicant.js?v=<?= @filemtime(__DIR__ . '/../assets/js/applicant.js') ?>"></script>
</body>
</html>