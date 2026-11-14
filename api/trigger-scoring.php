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
    require_once __DIR__ . '/../src/Helpers/security.php';
    secure_session(1800);
    session_start();
    send_security_headers(false);
    header('Content-Type: application/json; charset=UTF-8');
}

function respond_json_ts(array $data, int $code = 200): void {
    if (!defined('TRIGGER_SCORING_INTERNAL')) {
        http_response_code($code);
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
        if (ob_get_level() > 0) {
            ob_end_flush();
        }
        flush();
        exit;
    }
}

// Auth guard when called as a real HTTP endpoint
if (!defined('TRIGGER_SCORING_INTERNAL')) {
    $sessionOk = !empty($_SESSION['otp_verified']) && !empty($_SESSION['applicant_id']);
    $hrOk      = in_array($_SESSION['user_role'] ?? '', ['hr', 'admin'], true);
    if (!$sessionOk && !$hrOk) {
        respond_json_ts(['status' => 'error', 'message' => 'Unauthorised.'], 403);
    }
}

// Internal server-side callers (submit-response.php) inject the applicant id via $_POST
// or the active session; reading php://input again there would re-consume the original
// JSON request body and lose the applicant_id. Prefer those sources when internal.
if (defined('TRIGGER_SCORING_INTERNAL')) {
    $json  = null;
    $input = is_array($_POST) ? $_POST : [];
    if (!isset($input['applicant_id']) && isset($_SESSION['applicant_id'])) {
        $input['applicant_id'] = $_SESSION['applicant_id'];
    }
} else {
    $json  = json_decode((string) file_get_contents('php://input'), true);
    $input = is_array($json) ? $json : $_POST;
}

// When called from submit-response.php the applicant_id is injected directly.
if (!isset($input['applicant_id']) && !defined('TRIGGER_SCORING_INTERNAL')) {
    $input['applicant_id'] = $_SESSION['applicant_id'] ?? 0;
}
$applicantId = (int) ($input['applicant_id'] ?? 0);
if ($applicantId <= 0) {
    respond_json_ts(['status' => 'error', 'message' => 'applicant_id is required.'], 422);
    return;
}

require_once __DIR__ . '/../src/Helpers/db.php';
require_once __DIR__ . '/../src/Services/RiskScoringService.php';
require_once __DIR__ . '/../src/Services/GeminiRiskService.php';
require_once __DIR__ . '/../src/Services/GeminiAnswerConsistencyService.php';
require_once __DIR__ . '/../src/Repositories/ApplicantRepository.php';

try {
    $pdo     = db();
    $scorer  = new RiskScoringService($pdo);
    $result  = $scorer->score($applicantId);

    // Gemini scores free-text relevance and logistics contradictions separately.
    // On failure, keep the rule-based logistics score already calculated above.
    $consistency = [
        'points' => 0,
        'reason' => 'Consistency evaluation failed; not scored.',
        'issues' => [],
        'logistics_points' => null,
        'logistics_reason' => 'Logistics consistency was not evaluated.',
        'logistics_issues' => [],
    ];
    try {
        require_once __DIR__ . '/../applicant/applicant-helpers.php';
        $questions = static_questionnaire();
        if (empty($questions)) {
            require_once __DIR__ . '/../src/Repositories/AdminRepository.php';
            $questions = db_questions_for_applicant($pdo);
        }

        $stAns = $pdo->prepare("SELECT answers_json FROM response WHERE applicant_id = :id LIMIT 1");
        $stAns->execute([':id' => $applicantId]);
        $ansRow = $stAns->fetch();
        $answers = json_decode((string) ($ansRow['answers_json'] ?? '{}'), true);
        $answers = is_array($answers) ? $answers : [];

        if (!empty($questions) && !empty($answers)) {
            $consService = new GeminiAnswerConsistencyService();
            $consistency = $consService->evaluate($questions, $answers);
        }
    } catch (Throwable $e) {
        error_log('trigger-scoring consistency error: ' . $e->getMessage());
    }
    $result = GeminiAnswerConsistencyService::applyToRiskResult($result, $consistency);

    // Persist score + mirror outcome on applicant row FIRST, so a slow/failed
    // Gemini call can never leave the applicant stuck in "under review".
    $result['narrative'] = [];
    $scorer->saveScore($applicantId, $result);

    // Gemini narrative (best-effort; update the stored score row afterwards).
    $applicant = repo_find_applicant($applicantId, $pdo);
    $narrative = [];
    try {
        $gemini    = new GeminiRiskService();
        $narrative = $gemini->narrative($applicant ?? [], $result);
        if (is_array($narrative) && $narrative) {
            $result['narrative'] = $narrative;
            // update narrative_json on the score row
            $st = $pdo->prepare(
                "UPDATE score SET narrative_json = :n::jsonb
                 WHERE response_id = (SELECT response_id FROM response WHERE applicant_id = :id LIMIT 1)"
            );
            $st->execute([':n' => json_encode($narrative, JSON_UNESCAPED_UNICODE), ':id' => $applicantId]);
        }
    } catch (Throwable $e) {
        error_log('trigger-scoring Gemini error: ' . $e->getMessage());
    }

    respond_json_ts([
        'status'  => 'success',
        'outcome' => $result['outcome'],
        'band'    => $result['band'],
        'points'  => $result['points'],
    ]);

} catch (Throwable $e) {
    error_log('trigger-scoring error (applicant ' . $applicantId . '): ' . $e->getMessage());
    respond_json_ts(['status' => 'error', 'message' => 'Scoring failed: ' . $e->getMessage()], 500);
}
