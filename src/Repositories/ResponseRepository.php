<?php
// src/Repositories/ResponseRepository.php — DB reads/writes for applicant screening submissions.
// response table: one row per applicant (applicant_id UNIQUE), answers_json JSONB,
// video_file_url + video_file_hash, submitted_at marks completion.

require_once __DIR__ . '/../Helpers/db.php';

/**
 * Fetch the applicant's screening submission, if any.
 * Returns the raw response row (answers_json decoded when possible) or null.
 */
function repo_response_find(int $applicantId, PDO $pdo = null): ?array
{
    $pdo = $pdo ?: db();
    $st = $pdo->prepare(
        "SELECT response_id, applicant_id, answers_json, video_file_url, video_file_hash," .
        " ip_address, device_fingerprint, data_protection_agreed_at, started_at, submitted_at" .
        " FROM response WHERE applicant_id = :id LIMIT 1"
    );
    $st->execute([':id' => $applicantId]);
    $row = $st->fetch();
    if (!$row) return null;
    $answers = $row['answers_json'];
    if (is_string($answers)) {
        $decoded = json_decode($answers, true);
        if (is_array($decoded)) $answers = $decoded;
    }
    $row['answers'] = is_array($answers) ? $answers : [];
    return $row;
}

/**
 * Mark screening started (questionnaire opened): applicant pending -> in_progress,
 * response row created with started_at. Safe to call repeatedly.
 */
function repo_response_start(int $applicantId, PDO $pdo = null): void
{
    $pdo = $pdo ?: db();
    $pdo->prepare(
        "UPDATE applicant SET status = 'in_progress'" .
        " WHERE applicant_id = :id AND status = 'pending' AND deleted_at IS NULL"
    )->execute([':id' => $applicantId]);
    $pdo->prepare(
        "INSERT INTO response (applicant_id, answers_json, started_at)" .
        " VALUES (:id, '{}'::jsonb, NOW())" .
        " ON CONFLICT (applicant_id) DO UPDATE SET" .
        " started_at = COALESCE(response.started_at, NOW())"
    )->execute([':id' => $applicantId]);
}

/**
 * Save the final screening submission: answers + video into response,
 * applicant -> submitted (Completed in HR UI). Throws on validation problems.
 * $answers: [question_id(int) => answer] — Yes/No strings, scale ints, date/text strings.
 * $video: ['url' => Cloudinary secure_url, 'hash' => sha256 hex (optional)].
 */
function repo_response_submit(int $applicantId, array $answers, array $video = [], PDO $pdo = null): void
{
    $pdo = $pdo ?: db();

    // Applicant must exist, be reachable, and not already submitted.
    $st = $pdo->prepare(
        "SELECT applicant_id, status FROM applicant" .
        " WHERE applicant_id = :id AND deleted_at IS NULL LIMIT 1"
    );
    $st->execute([':id' => $applicantId]);
    $app = $st->fetch();
    if (!$app) throw new InvalidArgumentException('Applicant record not found.');
    if (in_array($app['status'], ['submitted', 'reviewed', 'accepted', 'rejected'], true)) {
        throw new InvalidArgumentException('This screening was already submitted.');
    }

    // Validate every active question is answered (mirrors static_validate_answer).
    require_once __DIR__ . '/AdminRepository.php';
    $questions = repo_admin_questions($pdo);
    $active = array_values(array_filter($questions, fn($q) => !empty($q['is_active'])));
    if (!$active) throw new RuntimeException('No active screening questions found.');

    $clean = [];
    $consentAt = null;
    foreach ($active as $q) {
        $qid = (string) $q['question_id'];
        // Derive the page type from the answer_config JSONB content.
        // passing_answer may also hold max/labels for scale questions
        // seeded directly in the DB (see seeds.sql: Question 5 stores
        // max/labels in passing_answer, not answer_config).
        $cfg = $q['answer_config'];
        if (is_string($cfg)) { $cfg = json_decode($cfg, true); }
        if (!is_array($cfg)) $cfg = [];

        $passCfg = $q['passing_answer'] ?? null;
        if (is_string($passCfg)) { $passCfg = json_decode($passCfg, true); }
        if (!is_array($passCfg)) $passCfg = [];

        $type = (string) ($q['question_type'] ?? '');
        if ($type === '') {
            if (!empty($cfg['statement']) && !empty($cfg['checkbox_label'])) {
                $type = 'consent';
            } elseif (!empty($cfg['choices'])) {
                $type = 'yesno_select';
            } elseif (!empty($cfg['max']) && !empty($cfg['labels'])) {
                $type = 'scale';
            } elseif (!empty($passCfg['max']) && !empty($passCfg['labels'])) {
                $type = 'scale';
            } elseif (!empty($cfg['hint'])) {
                $type = 'date';
            } else {
                $type = 'text';
            }
        }
        // Frontend sends page keys ("q7", "q7:follow"); accept those AND bare DB ids ("7").
        $raw = $answers[$qid] ?? $answers[(int) $qid] ?? $answers['q' . $qid] ?? null;
        if (is_string($raw)) $raw = trim($raw);

        switch ($type) {
            case 'yesno':
                if ($raw !== 'Yes' && $raw !== 'No') {
                    throw new InvalidArgumentException('Question ' . $qid . ': Please choose Yes or No.');
                }
                $clean[$qid] = $raw;
                break;
            case 'yesno_select':
                if ($raw !== 'Yes' && $raw !== 'No') {
                    throw new InvalidArgumentException('Question ' . $qid . ': Please choose Yes or No.');
                }
                $clean[$qid] = $raw;
                $follow = trim((string) (
                    $answers[$qid . ':follow'] ?? $answers[$qid . '_follow'] ??
                    $answers['q' . $qid . ':follow'] ?? $answers['q' . $qid . '_follow'] ?? ''
                ));
                if ($follow !== '') $clean[$qid . ':follow'] = mb_substr($follow, 0, 500);
                break;
            case 'scale':
                if (!is_numeric($raw) || (int) $raw < 1 || (int) $raw > 10) {
                    throw new InvalidArgumentException('Question ' . $qid . ': Please choose a rating.');
                }
                $clean[$qid] = (int) $raw;
                break;
            case 'date':
                if (!is_string($raw) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) {
                    throw new InvalidArgumentException('Question ' . $qid . ': Please choose a date.');
                }
                if ($raw < date('Y-m-d')) {
                    throw new InvalidArgumentException('Question ' . $qid . ': Please choose today or a later date.');
                }
                $clean[$qid] = $raw;
                break;
            case 'consent':
                if (!is_string($raw) || !in_array($raw, ['Yes', 'No'], true)) {
                    throw new InvalidArgumentException('Question ' . $qid . ': Please answer Yes or No.');
                }
                $clean[$qid] = $raw;
                break;
            default:
                if (!is_string($raw) || trim($raw) === '') {
                    throw new InvalidArgumentException('Question ' . $qid . ': Please write your answer.');
                }
                $clean[$qid] = mb_substr(trim($raw), 0, 2000);
        }

        if (!empty($q['is_data_protection']) && ($clean[$qid] ?? '') === 'Yes' && $consentAt === null) {
            $consentAt = date('Y-m-d H:i:s');
        }
    }

    $videoUrl = trim((string) ($video['url'] ?? ''));
    if ($videoUrl === '' || !filter_var($videoUrl, FILTER_VALIDATE_URL)) {
        throw new InvalidArgumentException('Please add your video first.');
    }
    $videoHash = strtolower(trim((string) ($video['hash'] ?? '')));
    if ($videoHash !== '' && !preg_match('/^[a-f0-9]{64}$/', $videoHash)) $videoHash = '';

    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    $device = substr((string) preg_replace('/[^A-Za-z0-9\-]/', '', (string) ($video['device'] ?? '')), 0, 64);

    // timing_json: per-question ms timings forwarded from the questionnaire JS
    $timingRaw = is_array($video['timing'] ?? null) ? $video['timing'] : [];
    $timing = [];
    foreach ($timingRaw as $k => $ms) {
        if (is_numeric($ms)) $timing[(string) $k] = (int) $ms;
    }

    $pdo->prepare(
        "INSERT INTO response (applicant_id, answers_json, video_file_url, video_file_hash," .
        " ip_address, device_fingerprint, data_protection_agreed_at, timing_json, started_at, submitted_at)" .
        " VALUES (:id, :answers::jsonb, :url, :hash, :ip, :device, :consent, :timing::jsonb, NOW(), NOW())" .
        " ON CONFLICT (applicant_id) DO UPDATE SET" .
        " answers_json = EXCLUDED.answers_json," .
        " video_file_url = EXCLUDED.video_file_url," .
        " video_file_hash = EXCLUDED.video_file_hash," .
        " ip_address = EXCLUDED.ip_address," .
        " device_fingerprint = EXCLUDED.device_fingerprint," .
        " data_protection_agreed_at = COALESCE(response.data_protection_agreed_at, EXCLUDED.data_protection_agreed_at)," .
        " timing_json = EXCLUDED.timing_json," .
        " started_at = COALESCE(response.started_at, NOW())," .
        " submitted_at = NOW()" .
        " WHERE response.submitted_at IS NULL"
    )->execute([
        ':id' => $applicantId,
        ':answers' => json_encode($clean, JSON_UNESCAPED_UNICODE),
        ':url' => $videoUrl,
        ':hash' => $videoHash !== '' ? $videoHash : null,
        ':ip' => $ip !== '' ? $ip : null,
        ':device' => $device !== '' ? $device : null,
        ':consent' => $consentAt,
        ':timing' => json_encode($timing, JSON_UNESCAPED_UNICODE),
    ]);

    $pdo->prepare(
        "UPDATE applicant SET status = 'submitted'" .
        " WHERE applicant_id = :id AND deleted_at IS NULL"
    )->execute([':id' => $applicantId]);
}
