<?php
/**
 * src/Services/RiskScoringService.php
 *
 * Computes the risk score for a submitted applicant using the RISK SCORING RUBRIC.
 * All thresholds come from config/risk_config.php.
 *
 * Input  : applicant_id  (the DB row must already be submitted)
 * Output : array with keys:
 *   points      int
 *   band        'Low' | 'Medium' | 'High'
 *   auto_high   string[]   reasons for automatic High (empty = none)
 *   factors     array[]    [ label, points, max, reason ]
 *   outcome     'passed' | 'failed' | 'review'
 *   knockouts   array[]    every knock-out question with met / not met
 */

require_once __DIR__ . '/../Helpers/db.php';
require_once __DIR__ . '/../Repositories/ApplicantRepository.php';   // repo_normalize_email()

class RiskScoringService
{
    private PDO $pdo;
    private array $cfg;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? db();
        $this->cfg = require __DIR__ . '/../../config/risk_config.php';
    }

    // -------------------------------------------------------------------------
    // Public entry point
    // -------------------------------------------------------------------------

    /**
     * Score the applicant and return the full result array.
     * Throws RuntimeException when the applicant or response is not found.
     */
    public function score(int $applicantId): array
    {
        $applicant = $this->fetchApplicant($applicantId);
        $response  = $this->fetchResponse($applicantId);
        $otp       = $this->fetchOtp($applicantId);
        $otpLog    = $this->fetchOtpLog($applicantId);

        $factors  = [];
        $autoWhy  = [];

        $add = function (string $label, int $points, int $max, string $reason) use (&$factors): void {
            $factors[] = compact('label', 'points', 'max', 'reason');
        };

        // ---- OTP: failed attempts (max 2) -----------------------------------
        $failed = (int) ($otp['attempt_count'] ?? 0);
        $locked = !empty($otp['locked_until']) && strtotime((string) $otp['locked_until']) > time();
        if ($locked || $failed >= $this->cfg['otp_failed']['high']) {
            $add('Failed OTP attempts', 2, 2,
                $locked ? 'The code page was locked after too many wrong codes.'
                        : $failed . ' wrong codes before success.');
        } elseif ($failed >= $this->cfg['otp_failed']['mid']) {
            $add('Failed OTP attempts', 1, 2, $failed . ' wrong codes before success.');
        } else {
            $add('Failed OTP attempts', 0, 2, $failed . ' wrong code(s). Typos are normal.');
        }

        // ---- OTP: requests within rolling window (max 2) --------------------
        $requestTimes = $this->parseOtpRequestTimes($otpLog);
        $windowSec    = $this->cfg['otp_requests']['window_minutes'] * 60;
        $most = 0;
        foreach ($requestTimes as $start) {
            $n = count(array_filter($requestTimes, fn($t) => $t >= $start && $t < $start + $windowSec));
            $most = max($most, $n);
        }
        if ($most >= $this->cfg['otp_requests']['high']) {
            $add('OTP requests in ' . $this->cfg['otp_requests']['window_minutes'] . ' minutes', 2, 2,
                $most . ' codes were requested.');
        } elseif ($most >= $this->cfg['otp_requests']['mid']) {
            $add('OTP requests in ' . $this->cfg['otp_requests']['window_minutes'] . ' minutes', 1, 2,
                $most . ' codes were requested.');
        } else {
            $add('OTP requests in ' . $this->cfg['otp_requests']['window_minutes'] . ' minutes', 0, 2,
                $most . ' code request(s). Email delays make this normal.');
        }

        // ---- Invite link click time (record only, 0 pts) --------------------
        $add('Time between invite and link click', 0, 0,
            'Recorded only — email scanners open links instantly, so this is not scored.');

        // ---- Questionnaire speed (max 3) ------------------------------------
        $timingJson = (string) ($response['timing_json'] ?? '{}');
        $timing     = json_decode($timingJson, true);
        $timing     = is_array($timing) ? array_values(array_filter($timing, 'is_numeric')) : [];

        if (count($timing) > 0) {
            $total = array_sum($timing) / 1000;
            $fast  = count(array_filter($timing, fn($ms) => $ms < $this->cfg['speed']['fast_item_ms']));
            $share = $fast / count($timing);
            [$t1, $t2, $t3] = $this->cfg['speed']['total_seconds'];
            [$s1, $s2, $s3] = $this->cfg['speed']['fast_share'];
            $byTotal = $total < $t3 ? 3 : ($total < $t2 ? 2 : ($total < $t1 ? 1 : 0));
            $byShare = $share >= $s3 ? 3 : ($share >= $s2 ? 2 : ($share >= $s1 ? 1 : 0));
            $pts     = max($byTotal, $byShare);
            $add('Questionnaire speed', $pts, 3,
                'Finished in ' . round($total) . ' s; ' . round($share * 100) . '% of items answered in under '
                . ($this->cfg['speed']['fast_item_ms'] / 1000) . ' s.');
        } else {
            $add('Questionnaire speed', 0, 3, 'No timing data was recorded.');
        }

        // ---- Same email or phone as another record (max 3) ------------------
        $email   = strtolower(trim((string) $applicant['email']));
        $phone   = preg_replace('/\D/', '', (string) ($applicant['phone_number'] ?? ''));
        $myName  = strtolower(trim((string) $applicant['name']));

        $emailClash  = false;
        $phoneNames  = [];
        $others = $this->fetchOtherApplicants($applicantId);
        foreach ($others as $o) {
            $oName  = strtolower(trim((string) $o['name']));
            $oEmail = strtolower(trim((string) $o['email']));
            $oPhone = preg_replace('/\D/', '', (string) ($o['phone_number'] ?? ''));
            if ($oEmail === $email && $oName !== $myName) {
                $emailClash = true;
            }
            if ($phone !== '' && $oPhone === $phone && $oName !== $myName) {
                $phoneNames[$oName] = true;
            }
        }
        if ($emailClash) {
            $add('Same email or phone as another record', 3, 3,
                'The same email was used under a different name.');
            $autoWhy[] = 'Same email under a different name';
        } elseif (count($phoneNames) >= 2) {
            $add('Same email or phone as another record', 2, 3,
                'The phone number appears on ' . count($phoneNames) . ' other records with different names.');
        } elseif (count($phoneNames) === 1) {
            $add('Same email or phone as another record', 1, 3,
                'The phone number is on one other record with a different name (families often share numbers).');
        } else {
            $add('Same email or phone as another record', 0, 3,
                'No other record shares the email or phone number.');
        }

        // ---- Re-applying after a REJECT (max 2) -----------------------------
        $daysAgo = $this->daysSinceLastReject($applicantId, $email);
        if ($daysAgo !== null && $daysAgo <= $this->cfg['reapply_days']['high']) {
            $add('Re-applying after a failed screening', 2, 2,
                'The last screening was rejected ' . $daysAgo . ' day(s) ago.');
        } elseif ($daysAgo !== null && $daysAgo <= $this->cfg['reapply_days']['mid']) {
            $add('Re-applying after a failed screening', 1, 2,
                'The last screening was rejected ' . $daysAgo . ' days ago.');
        } else {
            $add('Re-applying after a failed screening', 0, 2, 'No recent rejection on record.');
        }

        // ---- Same device or IP as other applicants (max 3) ------------------
        $device = (string) ($response['device_fingerprint'] ?? '');
        $ip     = (string) ($response['ip_address'] ?? '');

        $sameDevice = [];
        $sameIp     = [];
        $windowDeviceSec = $this->cfg['device']['window_days'] * 86400;
        $windowIpSec     = $this->cfg['ip']['window_hours'] * 3600;
        $now = time();

        foreach ($this->fetchOtherResponses($applicantId) as $r) {
            $theirEmail = strtolower(trim((string) ($r['email'] ?? '')));
            if ($theirEmail === $email) continue;
            $submittedTs = $r['submitted_at'] ? strtotime((string) $r['submitted_at']) : 0;
            if ($device !== '' && ($r['device_fingerprint'] ?? '') === $device
                && ($now - $submittedTs) <= $windowDeviceSec) {
                $sameDevice[$theirEmail] = true;
            }
            if ($ip !== '' && ($r['ip_address'] ?? '') === $ip
                && ($now - $submittedTs) <= $windowIpSec) {
                $sameIp[$theirEmail] = true;
            }
        }

        if (count($sameDevice) >= 2) {
            $add('Same device or IP as other applicants', 3, 3,
                'The same device was used by ' . count($sameDevice) . ' other applicants this week.');
            $autoWhy[] = 'Same device as 2 or more other applicants';
        } elseif (count($sameDevice) === 1) {
            $add('Same device or IP as other applicants', 2, 3,
                'The same device was used by another applicant this week.');
        } elseif (count($sameIp) >= $this->cfg['ip']['min_others']) {
            $add('Same device or IP as other applicants', 1, 3,
                count($sameIp) . ' other applicants used the same IP within '
                . $this->cfg['ip']['window_hours'] . ' hours.');
        } else {
            $add('Same device or IP as other applicants', 0, 3,
                'No suspicious device or IP sharing detected.');
        }

        // ---- Contradictory logistics answers (max 3) ------------------------
        $answers  = json_decode((string) ($response['answers_json'] ?? '{}'), true);
        $answers  = is_array($answers) ? $answers : [];
        $employed = (string) ($answers['7'] ?? $answers['q7'] ?? '');
        $follow   = (string) ($answers['7:follow'] ?? $answers['q7:follow'] ?? '');
        $startRaw = (string) ($answers['6'] ?? $answers['q6'] ?? '');

        $direct = 0;
        $minor  = 0;
        $notes  = [];

        if ($employed === 'No' && $follow !== '') {
            $direct++;
            $notes[] = 'Not employed, but a notice period was given.';
        }
        if ($employed === 'Yes' && $follow === '') {
            $direct++;
            $notes[] = 'Employed, but no notice period was given.';
        }
        if ($employed === 'Yes' && $startRaw !== '' && isset($this->cfg['notice_days'][$follow])) {
            $startTs = strtotime($startRaw);
            if ($startTs) {
                $days = (int) floor(($startTs - strtotime('today')) / 86400);
                if ($days < $this->cfg['notice_days'][$follow]) {
                    $minor++;
                    $notes[] = 'The earliest start date is sooner than the notice period.';
                }
            }
        }

        if ($direct >= 2) {
            $add('Contradictory logistics answers', 3, 3, implode(' ', $notes));
        } elseif ($direct === 1) {
            $add('Contradictory logistics answers', 2, 3, implode(' ', $notes));
        } elseif ($minor >= 1) {
            $add('Contradictory logistics answers', 1, 3, implode(' ', $notes));
        } else {
            $add('Contradictory logistics answers', 0, 3, 'The logistics answers are consistent.');
        }

        // ---- Video: time on page (skipped — upload allowed) -----------------
        $add('Time on the video page', 0, 2,
            'Not scored — applicants may upload a file instead of recording live.');

        // ---- Video: hash match with another applicant (max 3) ---------------
        $hash  = (string) ($response['video_file_hash'] ?? '');
        $match = false;
        if ($hash !== '') {
            $st = $this->pdo->prepare(
                "SELECT r.applicant_id FROM response r
                 JOIN applicant a ON a.applicant_id = r.applicant_id
                 WHERE r.video_file_hash = :h
                   AND r.applicant_id <> :id
                   AND a.deleted_at IS NULL
                 LIMIT 1"
            );
            $st->execute([':h' => $hash, ':id' => $applicantId]);
            $match = (bool) $st->fetchColumn();
        }
        if ($match) {
            $add('Video matches another applicant', 3, 3,
                'The video file is identical to another applicant\'s video.');
            $autoWhy[] = 'Video matches another applicant';
        } else {
            $add('Video matches another applicant', 0, 3,
                $hash === '' ? 'No file fingerprint was recorded.' : 'No other applicant sent this video.');
        }

        // ---- Total, band, knock-outs ----------------------------------------
        $points = array_sum(array_column($factors, 'points'));
        $band   = $points <= $this->cfg['bands']['low_max'] ? 'Low'
                : ($points <= $this->cfg['bands']['medium_max'] ? 'Medium' : 'High');
        if ($autoWhy) $band = 'High';

        $knockouts = $this->checkKnockouts($answers);
        $failed_ko = array_filter($knockouts, fn($k) => $k['met'] === false);
        // Applicant-facing flow is pass/fail only. A high risk band is NOT a third
        // outcome: HR reviews high-risk records internally via the AI evaluation,
        // but the applicant is passed as long as every knock-out question is met.
        $outcome   = count($failed_ko) > 0 ? 'failed' : 'passed';

        return compact('points', 'band', 'autoWhy', 'factors', 'outcome', 'knockouts');
    }

    // -------------------------------------------------------------------------
    // DB helpers
    // -------------------------------------------------------------------------

    private function fetchApplicant(int $id): array
    {
        $st = $this->pdo->prepare(
            "SELECT applicant_id, name, email, phone_number, status, result
             FROM applicant WHERE applicant_id = :id AND deleted_at IS NULL LIMIT 1"
        );
        $st->execute([':id' => $id]);
        $row = $st->fetch();
        if (!$row) throw new RuntimeException("Applicant $id not found.");
        return $row;
    }

    private function fetchResponse(int $applicantId): array
    {
        $st = $this->pdo->prepare(
            "SELECT response_id, answers_json, video_file_hash, ip_address, device_fingerprint,
                    timing_json, started_at, submitted_at
             FROM response WHERE applicant_id = :id LIMIT 1"
        );
        $st->execute([':id' => $applicantId]);
        $row = $st->fetch();
        if (!$row) throw new RuntimeException("Response for applicant $applicantId not found.");
        return $row;
    }

    private function fetchOtp(int $applicantId): array
    {
        $st = $this->pdo->prepare(
            "SELECT attempt_count, request_count, locked_until
             FROM otp WHERE applicant_id = :id LIMIT 1"
        );
        $st->execute([':id' => $applicantId]);
        return $st->fetch() ?: [];
    }

    private function fetchOtpLog(int $applicantId): array
    {
        try {
            $st = $this->pdo->prepare(
                "SELECT requested_at FROM otp_request_log
                 WHERE applicant_id = :id ORDER BY requested_at ASC"
            );
            $st->execute([':id' => $applicantId]);
            return $st->fetchAll();
        } catch (Throwable $e) {
            // Table may not exist on older installs; fall back to empty.
            return [];
        }
    }

    private function fetchOtherApplicants(int $excludeId): array
    {
        $st = $this->pdo->prepare(
            "SELECT applicant_id, name, email, phone_number
             FROM applicant WHERE applicant_id <> :id AND deleted_at IS NULL AND verified_at IS NOT NULL"
        );
        $st->execute([':id' => $excludeId]);
        return $st->fetchAll();
    }

    private function fetchOtherResponses(int $excludeApplicantId): array
    {
        $st = $this->pdo->prepare(
            "SELECT r.device_fingerprint, r.ip_address, r.submitted_at, a.email
             FROM response r
             JOIN applicant a ON a.applicant_id = r.applicant_id
             WHERE r.applicant_id <> :id
               AND r.submitted_at IS NOT NULL
               AND a.deleted_at IS NULL"
        );
        $st->execute([':id' => $excludeApplicantId]);
        return $st->fetchAll();
    }

    private function daysSinceLastReject(int $excludeId, string $email): ?int
    {
        // Look for a previous applicant row with the same email that was rejected.
        $st = $this->pdo->prepare(
            "SELECT MAX(created_at) AS last_at
             FROM applicant
             WHERE email_normalized = :email
               AND applicant_id <> :id
               AND LOWER(result) = 'rejected'
               AND deleted_at IS NULL"
        );
        $st->execute([':email' => repo_normalize_email($email), ':id' => $excludeId]);
        $ts = $st->fetchColumn();
        if (!$ts) return null;
        return (int) floor((time() - strtotime((string) $ts)) / 86400);
    }

    private function parseOtpRequestTimes(array $rows): array
    {
        $times = [];
        foreach ($rows as $r) {
            $ts = strtotime((string) ($r['requested_at'] ?? ''));
            if ($ts) $times[] = $ts;
        }
        sort($times);
        return $times;
    }

    private function checkKnockouts(array $answers): array
    {
        $st = $this->pdo->query(
            "SELECT question_id, question_text, question_type, answer_config, passing_answer, is_knockout
             FROM question_config WHERE is_active = TRUE AND deleted_at IS NULL ORDER BY section_no, display_order, question_id"
        );
        $questions = $st->fetchAll();
        $out = [];
        foreach ($questions as $q) {
            if (empty($q['is_knockout'])) continue;
            $qid     = (string) $q['question_id'];
            $given   = $answers[$qid] ?? $answers['q' . $qid] ?? null;
            if ($given === null) continue;
            $passCfg = $q['passing_answer'] ?? null;
            if (is_string($passCfg)) $passCfg = json_decode($passCfg, true);
            if (!is_array($passCfg)) $passCfg = [];

            $cfg = $q['answer_config'] ?? null;
            if (is_string($cfg)) $cfg = json_decode($cfg, true);
            if (!is_array($cfg)) $cfg = [];

            $type = (string) ($q['question_type'] ?? '');
            if ($type === 'scale' || isset($passCfg['min'])) {
                $min = (int) ($passCfg['min'] ?? 1);
                if ($min < 1) $min = 1;
                $givenNum = is_numeric($given) ? (int) $given : 0;
                if (is_string($given)) {
                    $decodedGiven = json_decode($given, true);
                    if (is_array($decodedGiven) && isset($decodedGiven['answer']) && is_numeric($decodedGiven['answer'])) {
                        $givenNum = (int) $decodedGiven['answer'];
                    }
                }
                $maxRating = (int) ($cfg['max'] ?? $passCfg['max'] ?? 5);
                if ($maxRating < 1) $maxRating = 5;
                $met = $givenNum >= $min;
                $out[] = [
                    'id'       => $qid,
                    'question' => (string) $q['question_text'],
                    'given'    => (string) $given,
                    'expected' => 'At least ' . $min . ' of ' . $maxRating,
                    'met'      => $met,
                    'reason'   => $met ? '' : 'Rated ' . $givenNum . '; the lowest passing rating is ' . $min . '.',
                ];
                continue;
            }

            $passing = isset($passCfg['answer']) ? (string) $passCfg['answer'] : trim((string) ($q['passing_answer'] ?? ''));
            if ($passing === '' && is_string($q['passing_answer'] ?? null)) {
                $decodedPassing = json_decode((string) $q['passing_answer'], true);
                if (is_array($decodedPassing) && isset($decodedPassing['answer'])) {
                    $passing = (string) $decodedPassing['answer'];
                }
            }
            // passing_answer may be stored as JSON like {"answer":"Yes"}; normalise to the scalar answer.
            if ($given === null) continue;
            if ($passing === '') continue;

            $givenNorm = $given;
            if (is_string($given)) {
                $decodedGiven = json_decode($given, true);
                if (is_array($decodedGiven) && isset($decodedGiven['answer'])) {
                    $givenNorm = $decodedGiven['answer'];
                }
            }

            $met = strcasecmp(trim((string) $givenNorm), trim($passing)) === 0;

            $passFollow = isset($passCfg['pass_follow']) && is_array($passCfg['pass_follow']) ? $passCfg['pass_follow'] : [];
            $when = (string) ($cfg['followup_when'] ?? 'Yes');
            if ($met && $passFollow && strcasecmp($passing, $when) === 0 && strcasecmp(trim((string) $givenNorm), $when) === 0) {
                $followGiven = $answers[$qid . ':follow'] ?? $answers['q' . $qid . ':follow']
                    ?? $answers[$qid . '_follow'] ?? $answers['q' . $qid . '_follow'] ?? null;
                if (!in_array(trim((string) $followGiven), array_map('strval', $passFollow), true)) {
                    $met = false;
                }
            }
            $out[] = [
                'id'       => $qid,
                'question' => (string) $q['question_text'],
                'given'    => (string) $given,
                'expected' => $passing,
                'met'      => $met,
                'reason'   => $met ? '' : 'The applicant answered "' . $given . '"; the passing answer is "' . $passing . '".',
            ];
        }
        return $out;
    }

    // -------------------------------------------------------------------------
    // Persist the result to the score table
    // -------------------------------------------------------------------------

    /**
     * Save (upsert) the computed score into the score table.
     * $result = return value of score().
     */
    public function saveScore(int $applicantId, array $result): void
    {
        $st = $this->pdo->prepare(
            "SELECT response_id FROM response WHERE applicant_id = :id LIMIT 1"
        );
        $st->execute([':id' => $applicantId]);
        $responseId = (int) $st->fetchColumn();
        if (!$responseId) throw new RuntimeException("No response row for applicant $applicantId.");

        $band = $result['band'];
        $riskLevel = $band === 'High' ? 'manual_review' : ($result['outcome'] === 'failed' ? 'rejected' : 'pending');

        $this->pdo->prepare(
            "INSERT INTO score (response_id, risk_score, risk_flags_json, narrative_json, final_decision)
             VALUES (:rid, :pts, :flags::jsonb, :narr::jsonb, :dec)
             ON CONFLICT (response_id) DO UPDATE SET
               risk_score       = EXCLUDED.risk_score,
               risk_flags_json  = EXCLUDED.risk_flags_json,
               narrative_json   = EXCLUDED.narrative_json,
               final_decision   = EXCLUDED.final_decision"
        )->execute([
            ':rid'   => $responseId,
            ':pts'   => $result['points'],
            ':flags' => json_encode([
                'band'      => $band,
                'auto_high' => $result['autoWhy'],
                'factors'   => $result['factors'],
                'knockouts' => $result['knockouts'],
                'outcome'   => $result['outcome'],
                'consistency' => $result['consistency'] ?? [],
            ], JSON_UNESCAPED_UNICODE),
            ':narr'  => json_encode($result['narrative'] ?? [], JSON_UNESCAPED_UNICODE),
            ':dec'   => $riskLevel,
        ]);

        // Mirror the outcome on the applicant row so the HR list shows the right result.
        if ($result['outcome'] === 'passed') {
            $this->pdo->prepare(
                "UPDATE applicant SET result = 'accepted' WHERE applicant_id = :id AND deleted_at IS NULL"
            )->execute([':id' => $applicantId]);
        } elseif ($result['outcome'] === 'failed') {
            $this->pdo->prepare(
                "UPDATE applicant SET result = 'rejected' WHERE applicant_id = :id AND deleted_at IS NULL"
            )->execute([':id' => $applicantId]);
        }
        // 'review' outcome: result stays NULL — HR must decide.
    }
}
