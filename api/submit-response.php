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

        // ---------------------------------------------------------------------------
        // Sync the static store so the applicant-side evaluation (static-evaluation.php)
        // reads this just-sent submission instead of stale earlier ones. The store is
        // only written here because the applicant pages read submissions from it
        // (static_run_evaluation() in static-evaluation.php), while the database is
        // authoritative for HR (details.php reads the response table directly).
        // ---------------------------------------------------------------------------
        $storePdo = db();
        $appStmt  = $storePdo->prepare(
            "SELECT id, name, email FROM applicant WHERE applicant_id = :id AND deleted_at IS NULL LIMIT 1"
        );
        $appStmt->execute([':id' => $applicantId]);
        $app = $appStmt->fetch();

        $timing = [];
        foreach ((array) ($input['timing'] ?? []) as $key => $ms) {
            if (is_string($key) && preg_match('/^q\d+$/', $key) && is_numeric($ms)) {
                $timing[$key] = (int) min(max(0, $ms), 3600000);
            }
        }
        $videoDevice = (string) ($input['device'] ?? '');
        $device      = substr(preg_replace('/[^A-Za-z0-9\\-]/', '', $videoDevice), 0, 64);
        $hash        = substr(preg_replace('/[^a-f0-9]/', '', strtolower((string) ($video['hash'] ?? ''))), 0, 64);
        $otp = [
            'failed'   => (int) ($_SESSION['otp_failed_total'] ?? 0),
            'requests' => array_values(array_map('intval', (array) ($_SESSION['otp_request_times'] ?? []))),
            'locked'   => !empty($_SESSION['otp_was_locked'])
        ];
        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');

        require_once __DIR__ . '/../hr/static-data.php';
        static_store_update(function (array &$store) use ($applicantId, $app, $answers, $video, $timing, $device, $hash, $otp, $ip) {
            $store['submissions'][] = [
                'reference'    => 'SCR-' . date('Y') . '-' . str_pad((string) $applicantId, 5, '0', STR_PAD_LEFT),
                'applicant_id' => $app['id'] ?? null,
                'name'         => $app['name'] ?? '',
                'email'        => $app['email'] ?? '',
                'answers'      => $answers,
                'timing'       => $timing,
                'device'       => $device,
                'ip'           => $ip,
                'otp'          => $otp,
                'video'        => [
                    'name'    => (string) ($video['name'] ?? ''),
                    'size'    => (int) ($video['size'] ?? 0),
                    'seconds' => isset($video['seconds']) ? round((float) $video['seconds'], 1) : null,
                    'source'  => (string) ($video['source'] ?? ''),
                    'hash'    => $hash
                ],
                'submitted_at' => static_now()->format('Y-m-d H:i')
            ];
        });

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

