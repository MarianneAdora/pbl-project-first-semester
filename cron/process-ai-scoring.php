<?php
/**
 * cron/process-ai-scoring.php
 *
 * Re-scores submitted applicants that have no score row yet.
 * Run on a schedule (e.g. every 5 minutes):
 *   php /path/to/cron/process-ai-scoring.php
 *
 * Safe to run repeatedly — already-scored applicants are skipped.
 */

define('TRIGGER_SCORING_INTERNAL', true);

require_once __DIR__ . '/../src/Helpers/db.php';
require_once __DIR__ . '/../src/Services/RiskScoringService.php';
require_once __DIR__ . '/../src/Services/GeminiRiskService.php';
require_once __DIR__ . '/../src/Repositories/ApplicantRepository.php';

$pdo = db();

$st = $pdo->query(
    "SELECT a.applicant_id
     FROM applicant a
     JOIN response r ON r.applicant_id = a.applicant_id
     LEFT JOIN score s ON s.response_id = r.response_id
     WHERE a.status = 'submitted'
       AND a.deleted_at IS NULL
       AND r.submitted_at IS NOT NULL
       AND s.score_id IS NULL
     ORDER BY r.submitted_at ASC
     LIMIT 50"
);
$pending = $st->fetchAll(PDO::FETCH_COLUMN);

if (!$pending) {
    echo "No pending applicants to score.\n";
    exit(0);
}

$scorer = new RiskScoringService($pdo);
$gemini = new GeminiRiskService();

foreach ($pending as $applicantId) {
    $applicantId = (int) $applicantId;
    try {
        $result    = $scorer->score($applicantId);
        $applicant = repo_find_applicant($applicantId, $pdo);
        try {
            $result['narrative'] = $gemini->narrative($applicant ?? [], $result);
        } catch (Throwable $e) {
            error_log("cron Gemini error for applicant $applicantId: " . $e->getMessage());
            $result['narrative'] = [];
        }
        $scorer->saveScore($applicantId, $result);
        echo "Scored applicant $applicantId: {$result['outcome']} / {$result['band']} ({$result['points']} pts)\n";
    } catch (Throwable $e) {
        error_log("cron scoring error for applicant $applicantId: " . $e->getMessage());
        echo "ERROR applicant $applicantId: " . $e->getMessage() . "\n";
    }
}
