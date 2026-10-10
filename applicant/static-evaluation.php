<?php
/* ==========================================================================
   STATIC AI EVALUATION (front-end only, no real AI).
   Location: applicant/static-evaluation.php  (next to the applicant pages)
   Temporary, like static-applicant.php. It is deleted when the real AI evaluation / database exists.

   It follows the RISK SCORING RUBRIC: every factor adds points, the total gives a band
       0 to 3   Low      no flag
       4 to 6   Medium   HR sees a highlighted note and the reasons
       7 or more High    the applicant is held for HR review before the result goes out
   and three automatic High cases (same video as another applicant, same email under a different name,
   same device ID as 2 or more other applicants).

   The system NEVER fails an applicant on risk alone. The result the applicant sees is:
       failed    an answer to a "must have" question is No (see 'knockout' below)
       review    the risk band is High (or an automatic High): HR checks first, no result yet
       passed    everything else -> the applicant schedules the interview

   All thresholds are in static_risk_config() below, in ONE place. Change them there, not in the logic.

   Used by:  applicant/application-status.php   static_run_evaluation()
   BACKEND (later): the same factors, calculated in the API from the database; keep the reasons so HR can
   see the points and the reason for every factor (stored in "evaluations" now).
   ========================================================================== */

require_once __DIR__ . '/static-applicant.php';

// ---------------------------------------------------------------------------
// ALL thresholds in one place
// ---------------------------------------------------------------------------
function static_risk_config(): array
{
    return [
        // result bands: 0..low_max = Low, up to medium_max = Medium, above = High
        'bands' => ['low_max' => 3, 'medium_max' => 6],

        // wrong codes before success: below "mid" = 0 points, "mid" to "high"-1 = 1 point, "high" or locked out = 2 points
        'otp_failed' => ['mid' => 3, 'high' => 5],

        // code requests inside one window: 1-2 = 0 points, "mid" = 1 point, "high" = 2 points
        'otp_requests' => ['window_minutes' => 10, 'mid' => 3, 'high' => 5],

        // questionnaire speed (the higher of the two scores counts, they are not added)
        'speed' => [
            'fast_item_ms'  => 1500,              // an item answered in under 1.5 seconds is "fast"
            'total_seconds' => [60, 40, 25],      // total time:      >=60 -> 0, 40-59 -> 1, 25-39 -> 2, <25 -> 3
            'fast_share'    => [0.50, 0.70, 0.90] // share of fast items: <50% -> 0, 50% -> 1, 70% -> 2, 90% -> 3
        ],

        // re-applying after a failed screening: within "high" days = 2 points, within "mid" days = 1 point
        'reapply_days' => ['high' => 7, 'mid' => 30],

        // same IP: this many different applicants inside the window = 1 point (an IP alone is never scored)
        'ip' => ['window_hours' => 24, 'min_others' => 3],

        // same device ID as other applicants inside the window: 1 other = 2 points, 2 or more = 3 points (automatic High)
        'device' => ['window_days' => 7],

        // earliest start date vs. notice period: the shortest number of days each notice period means
        'notice_days' => [
            'Less than 1 week'       => 0,
            '1 to 2 weeks'           => 7,
            '3 to 4 weeks (30 days)' => 21,
            '1 to 2 months'          => 30,
            'More than 2 months'     => 60
        ],

        // "must have" questions: an answer of No means the applicant does not meet the requirements
        'knockout' => ['q2', 'q3', 'q4', 'q8', 'q9', 'q10', 'q11'],

        // how long the "your screening is being evaluated" page shows before the result (milliseconds)
        'loading_ms' => 8000
    ];
}

// "2026-10-10 14:30" (Manila time, like the store) -> timestamp, or null
function static_eval_ts(string $when): ?int
{
    $d = DateTimeImmutable::createFromFormat('Y-m-d H:i', $when, new DateTimeZone('Asia/Manila'));
    return $d ? $d->getTimestamp() : null;
}

function static_eval_digits(string $value): string
{
    return preg_replace('/\D/', '', $value) ?? '';
}

// ---------------------------------------------------------------------------
// The scoring. Pure function: everything it needs is in $c, nothing is read from the session.
//   $c['answers']      the questionnaire answers
//   $c['questions']    static_questionnaire()
//   $c['timing']       [ 'q1' => milliseconds spent on that question, ... ]
//   $c['otp']          ['failed' => int, 'requests' => [timestamps], 'locked' => bool]
//   $c['me']           the applicant record (or null)   $c['email']  the applicant email
//   $c['device'] / $c['ip'] / $c['video_hash']
//   $c['others']       earlier submissions (everyone else)   $c['applicants']   the applicant list
//   $c['evaluations']  earlier results (for "re-applying after a failed screening")
//   $c['now']          DateTimeImmutable
// Returns: points, band, auto_high, outcome, unmet (texts of the must-have questions answered No), factors[]
// ---------------------------------------------------------------------------
function static_evaluate(array $c): array
{
    $cfg     = static_risk_config();
    $now     = $c['now'] instanceof DateTimeImmutable ? $c['now'] : static_now();
    $answers = is_array($c['answers'] ?? null) ? $c['answers'] : [];
    $me      = is_array($c['me'] ?? null) ? $c['me'] : null;
    $email   = strtolower(trim((string) ($c['email'] ?? '')));
    $myName  = $me ? strtolower(trim((string) $me['name'])) : '';
    $myPhone = $me ? static_eval_digits((string) ($me['contact'] ?? '')) : '';
    $others  = is_array($c['others'] ?? null) ? $c['others'] : [];
    $factors = [];
    $autoWhy = [];

    $add = function (string $label, int $points, int $max, string $reason) use (&$factors): void {
        $factors[] = ['label' => $label, 'points' => $points, 'max' => $max, 'reason' => $reason];
    };

    // ---- OTP: wrong codes before success (max 2) ------------------------------------------------
    $otp    = is_array($c['otp'] ?? null) ? $c['otp'] : [];
    $failed = (int) ($otp['failed'] ?? 0);
    $locked = !empty($otp['locked']);
    if ($locked || $failed >= $cfg['otp_failed']['high']) {
        $add('Failed OTP attempts', 2, 2, $locked ? 'The code page was locked after too many wrong codes.' : $failed . ' wrong codes before success.');
    } elseif ($failed >= $cfg['otp_failed']['mid']) {
        $add('Failed OTP attempts', 1, 2, $failed . ' wrong codes before success.');
    } else {
        $add('Failed OTP attempts', 0, 2, $failed . ' wrong code(s). Typos are normal.');
    }

    // ---- OTP: code requests inside the window (max 2) -------------------------------------------
    $times = array_map('intval', array_values((array) ($otp['requests'] ?? [])));
    sort($times);
    $span   = (int) $cfg['otp_requests']['window_minutes'] * 60;
    $most   = 0;
    foreach ($times as $start) {
        $n = 0;
        foreach ($times as $t) {
            if ($t >= $start && $t < $start + $span) $n++;
        }
        $most = max($most, $n);
    }
    if ($most >= $cfg['otp_requests']['high']) {
        $add('OTP requests in ' . $cfg['otp_requests']['window_minutes'] . ' minutes', 2, 2, $most . ' codes were requested.');
    } elseif ($most >= $cfg['otp_requests']['mid']) {
        $add('OTP requests in ' . $cfg['otp_requests']['window_minutes'] . ' minutes', 1, 2, $most . ' codes were requested.');
    } else {
        $add('OTP requests in ' . $cfg['otp_requests']['window_minutes'] . ' minutes', 0, 2, $most . ' code request(s). Email delays make this normal.');
    }

    // ---- Questionnaire speed (max 3) -------------------------------------------------------------
    $timing = [];
    foreach ((array) ($c['timing'] ?? []) as $ms) {
        if (is_numeric($ms)) $timing[] = (float) $ms;
    }
    if (count($timing) > 0) {
        $total = array_sum($timing) / 1000;
        $fast  = count(array_filter($timing, fn($ms) => $ms < $cfg['speed']['fast_item_ms']));
        $share = $fast / count($timing);
        [$t1, $t2, $t3] = $cfg['speed']['total_seconds'];
        [$s1, $s2, $s3] = $cfg['speed']['fast_share'];
        $byTotal = $total < $t3 ? 3 : ($total < $t2 ? 2 : ($total < $t1 ? 1 : 0));
        $byShare = $share >= $s3 ? 3 : ($share >= $s2 ? 2 : ($share >= $s1 ? 1 : 0));
        $add('Questionnaire speed', max($byTotal, $byShare), 3,
            'Finished in ' . round($total) . ' seconds; ' . round($share * 100) . '% of the items were answered in under '
            . ($cfg['speed']['fast_item_ms'] / 1000) . ' seconds.');
    } else {
        $add('Questionnaire speed', 0, 3, 'No timing was recorded.');
    }

    // ---- Same email or phone as another record (max 3) -------------------------------------------
    $phoneNames = [];
    foreach ((array) ($c['applicants'] ?? []) as $a) {
        if ($me && (int) $a['id'] === (int) $me['id']) continue;
        $name = strtolower(trim((string) $a['name']));
        if ($myPhone !== '' && static_eval_digits((string) ($a['contact'] ?? '')) === $myPhone && $name !== $myName) {
            $phoneNames[$name] = true;
        }
    }
    $emailClash = false;
    foreach ($others as $s) {
        $theirName = strtolower(trim((string) ($s['name'] ?? '')));
        if (strtolower((string) ($s['email'] ?? '')) === $email && $myName !== '' && $theirName !== '' && $theirName !== $myName) {
            $emailClash = true;
        }
    }
    if ($emailClash) {
        $add('Same email or phone as another record', 3, 3, 'The same email was used under a different name.');
        $autoWhy[] = 'Same email under a different name';
    } elseif (count($phoneNames) >= 2) {
        $add('Same email or phone as another record', 2, 3, 'The phone number is on ' . count($phoneNames) . ' records with other names.');
    } elseif (count($phoneNames) === 1) {
        $add('Same email or phone as another record', 1, 3, 'The phone number is on one record with a different name (families often share numbers).');
    } else {
        $add('Same email or phone as another record', 0, 3, 'No other record shares the email or phone number.');
    }

    // ---- Re-applying after a failed screening (max 2) ---------------------------------------------
    $daysAgo = null;
    foreach ((array) ($c['evaluations'] ?? []) as $e) {
        if (strtolower((string) ($e['email'] ?? '')) !== $email || ($e['outcome'] ?? '') !== 'failed') continue;
        $ts = static_eval_ts((string) ($e['evaluated_at'] ?? ''));
        if ($ts === null) continue;
        $d = (int) floor(($now->getTimestamp() - $ts) / 86400);
        if ($daysAgo === null || $d < $daysAgo) $daysAgo = $d;
    }
    if ($daysAgo !== null && $daysAgo <= $cfg['reapply_days']['high']) {
        $add('Re-applying after a failed screening', 2, 2, 'The last screening was failed ' . $daysAgo . ' day(s) ago.');
    } elseif ($daysAgo !== null && $daysAgo <= $cfg['reapply_days']['mid']) {
        $add('Re-applying after a failed screening', 1, 2, 'The last screening was failed ' . $daysAgo . ' days ago.');
    } else {
        $add('Re-applying after a failed screening', 0, 2, 'No recent failed screening.');
    }

    // ---- Same device or IP as other applicants (max 3) --------------------------------------------
    $device = (string) ($c['device'] ?? '');
    $ip     = (string) ($c['ip'] ?? '');
    $sameDevice = [];
    $sameIp     = [];
    foreach ($others as $s) {
        $theirEmail = strtolower((string) ($s['email'] ?? ''));
        if ($theirEmail === $email) continue;                                     // the same applicant trying again
        $ts = static_eval_ts((string) ($s['submitted_at'] ?? ''));
        if ($ts === null) continue;
        $hoursAgo = ($now->getTimestamp() - $ts) / 3600;
        if ($device !== '' && ($s['device'] ?? '') === $device && $hoursAgo <= $cfg['device']['window_days'] * 24) $sameDevice[$theirEmail] = true;
        if ($ip !== '' && ($s['ip'] ?? '') === $ip && $hoursAgo <= $cfg['ip']['window_hours']) $sameIp[$theirEmail] = true;
    }
    if (count($sameDevice) >= 2) {
        $add('Same device or IP as other applicants', 3, 3, 'The same device was used by ' . count($sameDevice) . ' other applicants.');
        $autoWhy[] = 'Same device as 2 or more other applicants';
    } elseif (count($sameDevice) === 1) {
        $add('Same device or IP as other applicants', 2, 3, 'The same device was used by another applicant this week.');
    } elseif (count($sameIp) >= $cfg['ip']['min_others']) {
        $add('Same device or IP as other applicants', 1, 3, count($sameIp) . ' other applicants used the same IP address within ' . $cfg['ip']['window_hours'] . ' hours.');
    } else {
        $add('Same device or IP as other applicants', 0, 3, 'No suspicious sharing (a shared IP alone is not scored).');
    }

    // ---- Contradictory logistics answers (max 3) --------------------------------------------------
    $employed = (string) ($answers['q7'] ?? '');
    $follow   = (string) ($answers['q7:follow'] ?? '');
    $direct   = 0;
    $minor    = 0;
    $notes    = [];
    if ($employed === 'No' && $follow !== '') {
        $direct++;
        $notes[] = 'Not employed, but a notice period was given.';
    }
    if ($employed === 'Yes' && $follow === '') {
        $direct++;
        $notes[] = 'Employed, but no notice period was given.';
    }
    $start = DateTimeImmutable::createFromFormat('Y-m-d', (string) ($answers['q6'] ?? ''), new DateTimeZone('Asia/Manila'));
    if ($employed === 'Yes' && $start && isset($cfg['notice_days'][$follow])) {
        $days = (int) $now->setTime(0, 0)->diff($start->setTime(0, 0))->format('%r%a');
        if ($days < $cfg['notice_days'][$follow]) {
            $minor++;
            $notes[] = 'The earliest start date is sooner than the notice period.';
        }
    }
    if ($direct >= 2) {
        $add('Contradictory logistics answers', 3, 3, implode(' ', $notes));
    } elseif ($direct === 1) {
        $add('Contradictory logistics answers', 2, 3, implode(' ', $notes));
    } elseif ($minor >= 1) {
        $add('Contradictory logistics answers', 1, 3, implode(' ', $notes));
    } else {
        $add('Contradictory logistics answers', 0, 3, 'The logistics answers agree with each other.');
    }

    // ---- Video: time on the page (skipped: applicants can upload from their gallery) -------------
    $add('Time on the video page', 0, 2, 'Not scored, because applicants can upload a file instead of recording live.');

    // ---- Video: same file as another applicant (max 3) --------------------------------------------
    $hash  = (string) ($c['video_hash'] ?? '');
    $match = false;
    foreach ($others as $s) {
        if ($hash !== '' && (($s['video']['hash'] ?? '') === $hash) && strtolower((string) ($s['email'] ?? '')) !== $email) $match = true;
    }
    if ($match) {
        $add('Video matches another applicant', 3, 3, 'The video file is identical to another applicant\'s video.');
        $autoWhy[] = 'Video matches another applicant';
    } else {
        $add('Video matches another applicant', 0, 3, $hash === '' ? 'No file fingerprint was recorded.' : 'No other applicant sent this video.');
    }

    // ---- Total, band, result ------------------------------------------------------------------------
    $points = 0;
    foreach ($factors as $f) $points += $f['points'];
    $band = $points <= $cfg['bands']['low_max'] ? 'Low' : ($points <= $cfg['bands']['medium_max'] ? 'Medium' : 'High');
    if (count($autoWhy) > 0) $band = 'High';

    $unmet = [];
    foreach ((array) ($c['questions'] ?? []) as $q) {
        if (in_array($q['id'], $cfg['knockout'], true) && ($answers[$q['id']] ?? '') === 'No') {
            $unmet[] = (string) $q['text'];
        }
    }
    $outcome = count($unmet) > 0 ? 'failed' : ($band === 'High' ? 'review' : 'passed');

    return [
        'points'    => $points,
        'band'      => $band,
        'auto_high' => $autoWhy,
        'outcome'   => $outcome,
        'unmet'     => $unmet,
        'factors'   => $factors
    ];
}

// ---------------------------------------------------------------------------
// Runs the evaluation for the applicant who just sent the screening (reads the session and the store),
// saves the result for HR ("evaluations") and sets the applicant's status in the HR lists.
// ---------------------------------------------------------------------------
function static_run_evaluation(): array
{
    $email     = strtolower(trim((string) ($_SESSION['applicant_email'] ?? '')));
    $reference = (string) ($_SESSION['screening_reference'] ?? '');
    $store     = static_store_read();
    $all       = array_values((array) ($store['submissions'] ?? []));
    $mine      = count($all) > 0 ? array_pop($all) : [];      // the newest one is the screening just sent, $all = the earlier ones

    $applicants = static_applicants();
    $me = null;
    foreach ($applicants as $a) {
        if ($email !== '' && strtolower($a['email']) === $email) { $me = $a; break; }
    }

    $result = static_evaluate([
        'answers'     => $mine['answers'] ?? [],
        'questions'   => static_questionnaire(),
        'timing'      => $mine['timing'] ?? [],
        'otp'         => $mine['otp'] ?? [],
        'me'          => $me,
        'email'       => $email,
        'device'      => $mine['device'] ?? '',
        'ip'          => $mine['ip'] ?? '',
        'video_hash'  => $mine['video']['hash'] ?? '',
        'others'      => $all,
        'applicants'  => $applicants,
        'evaluations' => (array) ($store['evaluations'] ?? []),
        'now'         => static_now()
    ]);

    static_store_update(function (array &$store) use ($result, $reference, $email) {
        $store['evaluations'][] = [
            'reference'    => $reference,
            'email'        => $email,
            'points'       => $result['points'],
            'band'         => $result['band'],
            'auto_high'    => $result['auto_high'],
            'outcome'      => $result['outcome'],
            'factors'      => $result['factors'],
            'evaluated_at' => static_now()->format('Y-m-d H:i')
        ];
    });

    // the HR lists: Passed / Failed; a held (review) applicant stays "Completed" without a result
    if ($me) {
        if ($result['outcome'] === 'passed')      static_set_status((int) $me['id'], 'Completed', 'Passed');
        elseif ($result['outcome'] === 'failed')  static_set_status((int) $me['id'], 'Completed', 'Failed');
        else                                      static_set_status((int) $me['id'], 'Completed', null);
    }

    return $result;
}