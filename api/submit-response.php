<?php
// api/submit-response.php — DB endpoint for the applicant questionnaire + video pages.
// Body: JSON { action: 'start' } or { action: 'submit', answers, video, device }.
// The applicant is identified by $_SESSION['applicant_id'] (set by opening.php?token=...).
// Answers land in response.answers_json, video URL in response.video_file_url,
// applicant.status -> submitted (Completed in HR UI).

session_start();
header('Content-Type: application/json; charset=UTF-8');

$respond = function (array $data, int $code = 200): void {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
};

if (empty($_SESSION['otp_verified']) || empty($_SESSION['applicant_id'])) {
    $respond(['status' => 'error', 'message' => 'Your session has ended. Please open your invite link again.'], 403);
}
$applicantId = (int) $_SESSION['applicant_id'];

$json = json_decode((string) file_get_contents('php://input'), true);
$input = is_array($json) ? $json : $_POST;
$action = (string) ($input['action'] ?? '');

require_once __DIR__ . '/../src/Repositories/ResponseRepository.php';

try {
    if ($action === 'start') {
        repo_response_start($applicantId);
        $respond(['status' => 'success']);
    }

    if ($action === 'submit') {
        $answers = is_array($input['answers'] ?? null) ? $input['answers'] : [];
        $video = is_array($input['video'] ?? null) ? $input['video'] : [];
        $video['device'] = (string) ($input['device'] ?? '');
        repo_response_submit($applicantId, $answers, $video);
        $_SESSION['screening_submitted'] = true;
        $respond(['status' => 'success']);
    }

    $respond(['status' => 'error', 'message' => 'Unknown action.'], 400);
} catch (InvalidArgumentException $e) {
    $respond(['status' => 'error', 'message' => $e->getMessage()], 422);
} catch (Throwable $e) {
    error_log('submit-response DB error: ' . $e->getMessage());
    $respond(['status' => 'error', 'message' => 'Something went wrong while saving. Please try again.'], 500);
}

