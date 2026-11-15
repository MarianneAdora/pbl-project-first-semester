<?php
// api/submit-response.php — DB endpoint for the applicant questionnaire + video pages.
// Body: JSON { action: 'start' } or { action: 'submit', answers, video, device }.
// The applicant is identified by $_SESSION['applicant_id'] (set by opening.php?token=...).
// Answers land in response.answers_json, video URL in response.video_file_url,
// applicant.status -> submitted (Completed in HR UI).

require_once __DIR__ . '/../src/Helpers/security.php';
secure_session(1800);
session_start();
send_security_headers(false);

header('Content-Type: application/json; charset=UTF-8');

function respond_json(array $data, int $code = 200): void {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    if (ob_get_level() > 0) {
        ob_end_flush();
    }
    flush();
    exit;
}

if (empty($_SESSION['otp_verified']) || empty($_SESSION['applicant_id'])) {
    respond_json(['status' => 'error', 'message' => 'Your session has ended. Please open your invite link again.'], 403);
}
$applicantId = (int) $_SESSION['applicant_id'];

$json = json_decode((string) file_get_contents('php://input'), true);
$input = is_array($json) ? $json : $_POST;
$action = (string) ($input['action'] ?? '');

// CSRF protection for state-changing submit action
if ($action === 'submit') {
    $csrfIn = (string) ($input['csrf_token'] ?? '');
    if (!csrf_verify($csrfIn)) {
        respond_json(['status' => 'error', 'message' => 'Invalid CSRF token. Please refresh and try again.'], 403);
    }
}

require_once __DIR__ . '/../src/Repositories/ResponseRepository.php';

try {
    if ($action === 'start') {
        repo_response_start($applicantId);
        respond_json(['status' => 'success']);
    }

    if ($action === 'submit') {
        $answers = is_array($input['answers'] ?? null) ? $input['answers'] : [];
        $video = is_array($input['video'] ?? null) ? $input['video'] : [];
        $video['device'] = (string) ($input['device'] ?? '');
        $video['timing'] = is_array($input['timing'] ?? null) ? $input['timing'] : [];
        try {
            repo_response_submit($applicantId, $answers, $video);
        } catch (Throwable $e) {
            error_log('submit-response repo error: ' . $e->getMessage());
            throw $e;
        }

        // Mark as submitted IMMEDIATELY after the DB save succeeds, before the
        // application-state sync (which can throw). This ensures the applicant is
        // redirected to application-status.php even if the sync fails.
        $_SESSION['screening_submitted'] = true;

// Submission is DB-authoritative; application-state sync removed.

        // Trigger risk scoring asynchronously (best-effort; submission already saved).
        try {
            define('TRIGGER_SCORING_INTERNAL', true);
            $_POST['applicant_id'] = $applicantId;
            ob_start();
            require __DIR__ . '/trigger-scoring.php';
            ob_end_clean();
        } catch (Throwable $e) {
            error_log('submit-response scoring trigger error: ' . $e->getMessage());
        }

        error_log('SUBMIT ob_level before respond=' . ob_get_level());
        error_log('SUBMIT about to respond success');
        respond_json(['status' => 'success']);
    }

    respond_json(['status' => 'error', 'message' => 'Unknown action.'], 400);
} catch (InvalidArgumentException $e) {
    respond_json(['status' => 'error', 'message' => $e->getMessage()], 422);
} catch (Throwable $e) {
    error_log('submit-response DB error: ' . $e->getMessage());
    if (!empty($_SESSION['screening_submitted'])) {
        respond_json(['status' => 'success']);
    }
    respond_json(['status' => 'error', 'message' => 'Something went wrong while saving. Please try again.'], 500);
}

