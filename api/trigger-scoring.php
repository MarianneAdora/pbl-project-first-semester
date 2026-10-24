<?php
/**
 * api/trigger-scoring.php
 *
 * Called internally (by api/submit-response.php) after a successful submission.
 * Computes the risk score via RiskScoringService, generates the Gemini narrative
 * via GeminiRiskService, and persists both to the score table.
 *
 * Also used by cron/process-ai-scoring.php to re-score any submitted applicants
 * whose score row is still missing.
 *
 * Request (POST, JSON or form):
 *   { applicant_id: int }
 *
 * Response:
 *   { status: 'success', outcome, band, points }
 *   { status: 'error',   message }
 *
 * Auth: requires a valid applicant session OR an internal server-side call
 * (TRIGGER_SCORING_INTERNAL constant set before including this file).
 */

if (!defined('TRIGGER_SCORING_INTERNAL')) {
    session_start();
    header('Content-Type: application/json; charset=UTF-8');
}

$respond = function (array $data, int $code = 200): void {
    if (!defined('TRIGGER_SCORING_INTERNAL')) {
        http_response_code($code);
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
        exit;
    }
};

// Auth guard when called as a real HTTP endpoint
if (!defined('TRIGGER_SCORING_INTERNAL')) {
    $sessionOk = !empty($_SESSION['otp_verified']) && !empty($_SESSION['applicant_id']);
    $hrOk      = in_array($_SESSION['user_role'] ?? '', ['hr', 'admin'], true);
    if (!$sessionOk && !$hrOk) {
        $respond(['status' => 'error', 'message' => 'Unauthorised.'], 403);
    }
}

$json  = json_decode((string) file_get_contents('php://input'), true);
$input = is_array($json) ? $json : $_POST;

// When called from submit-response.php the applicant_id is injected directly.
if (!isset($input['applicant_id']) && !defined('TRIGGER_SCORING_INTERNAL')) {
    $input['applicant_id'] = $_SESSION['applicant_id'] ?? 0;
}
$applicantId = (int) ($input['applicant_id'] ?? 0);
if ($applicantId <= 0) {
    $respond(['status' => 'error', 'message' => 'applicant_id is required.'], 422);
    return;
}

require_once __DIR__ . '/../src/Helpers/db.php';
require_once __DIR__ . '/../src/Services/RiskScoringService.php';
require_once __DIR__ . '/../src/Services/GeminiRiskService.php';
require_once __DIR__ . '/../src/Repositories/ApplicantRepository.php';

try {
    $pdo     = db();
    $scorer  = new RiskScoringService($pdo);
    $result  = $scorer->score($applicantId);

    // Gemini narrative (best-effort; scoring is already done above)
    $applicant = repo_find_applicant($applicantId, $pdo);
    $narrative = [];
    try {
        $gemini    = new GeminiRiskService();
        $narrative = $gemini->narrative($applicant ?? [], $result);
    } catch (Throwable $e) {
        error_log('trigger-scoring Gemini error: ' . $e->getMessage());
    }

    // Merge narrative into the flags JSON before saving
    $result['narrative'] = $narrative;
    $scorer->saveScore($applicantId, $result);

    $respond([
        'status'  => 'success',
        'outcome' => $result['outcome'],
        'band'    => $result['band'],
        'points'  => $result['points'],
    ]);

} catch (Throwable $e) {
    error_log('trigger-scoring error (applicant ' . $applicantId . '): ' . $e->getMessage());
    $respond(['status' => 'error', 'message' => 'Scoring failed: ' . $e->getMessage()], 500);
}
