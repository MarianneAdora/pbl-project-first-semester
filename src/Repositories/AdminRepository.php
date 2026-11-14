<?php
// src/Repositories/AdminRepository.php — DB reads for the Admin dashboard.
// Questions come from question_config; stats are derived from response.answers_json (JSONB).

require_once __DIR__ . '/../Helpers/db.php';
require_once __DIR__ . '/ActivityRepository.php';

function repo_admin_overview(?PDO $pdo = null): array
{
    $pdo = $pdo ?: db();
    $hrTotal = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'hr' AND deleted_at IS NULL")->fetchColumn();
    $questions = (int) $pdo->query("SELECT COUNT(*) FROM question_config WHERE deleted_at IS NULL AND is_active = TRUE")->fetchColumn();
    $sections = (int) $pdo->query("SELECT COUNT(DISTINCT section) FROM question_config WHERE deleted_at IS NULL AND is_active = TRUE")->fetchColumn();
    $week = repo_activities_week($pdo);
    return [
        'hr_active' => $hrTotal,
        'hr_total' => $hrTotal,
        'questions' => $questions,
        'sections' => $sections,
        'activities_week' => $week,
    ];
}

// ---------------------------------------------------------------------------
// HR ACCOUNTS LIST (hr-list.php)
// ---------------------------------------------------------------------------
function repo_admin_accounts(?PDO $pdo = null): array
{
    $pdo = $pdo ?: db();
    $rows = $pdo->query(
        'SELECT user_id AS id, name, email, role, status, deleted_at IS NULL AS is_active, created_at' .
        ' FROM users WHERE role = \'hr\' AND deleted_at IS NULL ORDER BY created_at ASC, user_id ASC'
    )->fetchAll();
    $out = [];
    foreach ($rows as $r) {
        $out[] = [
            'id'      => (int) $r['id'],
            'name'    => (string) $r['name'],
            'email'   => (string) $r['email'],
            'role'    => 'HR Staff',
            'status'  => (string) ($r['status'] ?? 'Active'),
            'created' => $r['created_at'] ? date('Y-m-d H:i', strtotime((string) $r['created_at'])) : '',
        ];
    }
    return $out;
}

// ---------------------------------------------------------------------------
// QUESTIONS LIST (question-list.php)
// ---------------------------------------------------------------------------
function repo_admin_questions_list(?PDO $pdo = null): array
{
    $pdo = $pdo ?: db();
    $rows = $pdo->query(
        'SELECT question_id, section_no, section, question_text, question_type,' .
        ' answer_config, passing_answer, is_knockout, is_active, created_at' .
        ' FROM question_config WHERE deleted_at IS NULL ORDER BY section_no, display_order, question_id'
    )->fetchAll();
    $out = [];
    foreach ($rows as $r) {
        $cfg = $r['answer_config'];
        if (is_string($cfg)) $cfg = json_decode($cfg, true);
        if (!is_array($cfg)) $cfg = [];

        $pass = $r['passing_answer'];
        if (is_string($pass)) $pass = json_decode($pass, true);
        if (!is_array($pass)) $pass = [];

        $type = (string) $r['question_type'];
        $text = (string) $r['question_text'];

        $q = [
            'id'         => 'q' . $r['question_id'],
            'section_no' => (int) $r['section_no'],
            'section'    => (string) $r['section'],
            'text'       => $text,
            'type'       => $type,
            'status'     => $r['is_active'] ? 'Active' : 'Inactive',
            'created'    => $r['created_at'] ? date('Y-m-d H:i', strtotime((string) $r['created_at'])) : '',
            'knockout'   => (bool) $r['is_knockout'],
        ];

        if (!empty($r['is_knockout'])) {
            if ($type === 'scale') {
                $q['pass_answer'] = (int) ($pass['min'] ?? 1);
                $q['max'] = (int) ($cfg['max'] ?? 5);
            } else {
                $q['pass_answer'] = (string) ($pass['answer'] ?? 'Yes');
                if (!empty($pass['pass_follow'])) $q['pass_follow'] = (array) $pass['pass_follow'];
            }
        }
        if (!empty($cfg['link'])) $q['link'] = $cfg['link'];
        $out[] = $q;
    }
    return $out;
}

// ---------------------------------------------------------------------------
// ARCHIVE (archive-admin.php)
// ---------------------------------------------------------------------------
define('ARCHIVE_DAYS', 30);

function repo_admin_archive(?PDO $pdo = null): array
{
    $pdo = $pdo ?: db();
    // auto-purge expired rows first
    $pdo->exec("DELETE FROM users WHERE role = 'hr' AND deleted_at IS NOT NULL AND deleted_at < NOW() - INTERVAL '30 days'");
    $pdo->exec("DELETE FROM question_config WHERE deleted_at IS NOT NULL AND deleted_at < NOW() - INTERVAL '30 days'");

    $accounts = [];
    $rows = $pdo->query(
        "SELECT user_id AS id, name, email, deleted_at FROM users WHERE role = 'hr' AND deleted_at IS NOT NULL ORDER BY deleted_at DESC"
    )->fetchAll();
    foreach ($rows as $r) {
        $deletedAt = strtotime((string) $r['deleted_at']);
        $daysLeft  = max(0, 30 - (int) floor((time() - $deletedAt) / 86400));
        $accounts[] = [
            'id'        => (int) $r['id'],
            'name'      => (string) $r['name'],
            'email'     => (string) $r['email'],
            'deleted'   => date('Y-m-d H:i:s', $deletedAt),
            'days_left' => $daysLeft,
        ];
    }

    $questions = [];
    $rows = $pdo->query(
        'SELECT question_id, section_no, section, question_text, question_type, answer_config, deleted_at' .
        ' FROM question_config WHERE deleted_at IS NOT NULL ORDER BY deleted_at DESC'
    )->fetchAll();
    foreach ($rows as $r) {
        $cfg = $r['answer_config'];
        if (is_string($cfg)) $cfg = json_decode($cfg, true);
        if (!is_array($cfg)) $cfg = [];
        $text = (string) $r['question_text'];
        if (!empty($cfg['link']['text'])) $text = str_replace('{link}', $cfg['link']['text'], $text);
        $deletedAt = strtotime((string) $r['deleted_at']);
        $daysLeft  = max(0, 30 - (int) floor((time() - $deletedAt) / 86400));
        $questions[] = [
            'id'         => 'q' . $r['question_id'],
            'section_no' => (int) $r['section_no'],
            'section'    => (string) $r['section'],
            'text'       => $text,
            'type'       => (string) $r['question_type'],
            'deleted'    => date('Y-m-d H:i:s', $deletedAt),
            'days_left'  => $daysLeft,
        ];
    }
    return ['questions' => $questions, 'accounts' => $accounts];
}

function repo_admin_notify(?PDO $pdo = null): array
{
    $pdo = $pdo ?: db();
    $rows = $pdo->query(
        "SELECT created_at FROM activity_log WHERE actor = 'HR' ORDER BY created_at DESC, log_id DESC LIMIT 100"
    )->fetchAll();
    $activity = array_map(fn($r) => date('Y-m-d H:i:s', strtotime((string) $r['created_at'])), $rows);

    $days = 7;
    $expiring = (int) $pdo->query(
        "SELECT COUNT(*) FROM (" .
        "  SELECT deleted_at FROM users WHERE role = 'hr' AND deleted_at IS NOT NULL" .
        "  UNION ALL" .
        "  SELECT deleted_at FROM question_config WHERE deleted_at IS NOT NULL" .
        ") t WHERE deleted_at > NOW() - INTERVAL '30 days' AND deleted_at < NOW() - INTERVAL '" . (30 - $days) . " days'"
    )->fetchColumn();
    return ['activity' => $activity, 'expiring' => $expiring, 'days' => $days];
}

// ---------------------------------------------------------------------------
// ARCHIVE ACTIONS
// ---------------------------------------------------------------------------
function repo_account_archive(int $id, ?PDO $pdo = null): bool
{
    $pdo = $pdo ?: db();
    $st = $pdo->prepare("UPDATE users SET deleted_at = NOW() WHERE user_id = :id AND role = 'hr' AND deleted_at IS NULL");
    $st->execute([':id' => $id]);
    return $st->rowCount() > 0;
}

function repo_account_restore(int $id, ?PDO $pdo = null): bool
{
    $pdo = $pdo ?: db();
    $st = $pdo->prepare("UPDATE users SET deleted_at = NULL WHERE user_id = :id AND role = 'hr' AND deleted_at IS NOT NULL");
    $st->execute([':id' => $id]);
    return $st->rowCount() > 0;
}

function repo_account_purge(int $id, ?PDO $pdo = null): bool
{
    $pdo = $pdo ?: db();
    $st = $pdo->prepare("DELETE FROM users WHERE user_id = :id AND role = 'hr' AND deleted_at IS NOT NULL");
    $st->execute([':id' => $id]);
    return $st->rowCount() > 0;
}

function repo_question_archive(int $id, ?PDO $pdo = null): bool
{
    $pdo = $pdo ?: db();
    $active = (int) $pdo->query("SELECT COUNT(*) FROM question_config WHERE deleted_at IS NULL AND is_active = TRUE AND question_id != $id")->fetchColumn();
    if ($active < 1) throw new InvalidArgumentException('At least one Active question must remain in the questionnaire.');
    $st = $pdo->prepare('UPDATE question_config SET deleted_at = NOW() WHERE question_id = :id AND deleted_at IS NULL');
    $st->execute([':id' => $id]);
    return $st->rowCount() > 0;
}

function repo_question_restore(int $id, ?PDO $pdo = null): bool
{
    $pdo = $pdo ?: db();
    $st = $pdo->prepare('UPDATE question_config SET deleted_at = NULL WHERE question_id = :id AND deleted_at IS NOT NULL');
    $st->execute([':id' => $id]);
    return $st->rowCount() > 0;
}

function repo_question_purge(int $id, ?PDO $pdo = null): bool
{
    $pdo = $pdo ?: db();
    $st = $pdo->prepare('DELETE FROM question_config WHERE question_id = :id AND deleted_at IS NOT NULL');
    $st->execute([':id' => $id]);
    return $st->rowCount() > 0;
}

/**
 * Active screening questions shaped for the applicant questionnaire page.
 * Reads question_type directly (no derivation). answer_config and passing_answer
 * use the same normalized keys that static_admin_clean_question() writes.
 */
function db_questions_for_applicant(?PDO $pdo = null): array
{
    $pdo = $pdo ?: db();
    $rows = $pdo->query(
        'SELECT question_id, section_no, section, question_text, question_type, answer_config,' .
        ' passing_answer, is_knockout, is_consent, is_data_protection, reminder_text' .
        ' FROM question_config WHERE deleted_at IS NULL AND is_active = TRUE ORDER BY section_no, display_order, question_id'
    )->fetchAll();

    $out = [];
    foreach ($rows as $r) {
        $cfg  = $r['answer_config'];
        if (is_string($cfg)) $cfg = json_decode($cfg, true);
        if (!is_array($cfg)) $cfg = [];

        $pass = $r['passing_answer'];
        if (is_string($pass)) $pass = json_decode($pass, true);
        if (!is_array($pass)) $pass = [];

        $type = (string) $r['question_type'];

        $q = [
            'id'         => 'q' . $r['question_id'],
            'section_no' => (int) $r['section_no'],
            'section'    => (string) $r['section'],
            'type'       => $type,
            'text'       => (string) $r['question_text'],
        ];

        // consent questions also carry body + checkbox
        if ($type === 'consent') {
            $q['body']     = is_array($cfg['body'] ?? null) ? $cfg['body'] : [(string) $r['question_text']];
            $q['checkbox'] = $cfg['checkbox'] ?? 'I agree';
        }

        if (!empty($cfg['link'])) {
            $q['link'] = ['text' => (string) ($cfg['link']['text'] ?? 'link'), 'url' => (string) ($cfg['link']['url'] ?? '')];
        }

        switch ($type) {
            case 'yesno':
                if (!empty($cfg['no_note']))  $q['no_note']  = (string) $cfg['no_note'];
                if (isset($cfg['favorable'])) $q['favorable'] = (string) $cfg['favorable'];
                break;
            case 'yesno_select':
                $q['followup'] = [
                    'when'    => (string) ($cfg['followup_when']    ?? 'Yes'),
                    'label'   => (string) ($cfg['followup_label']   ?? 'Please tell us more'),
                    'options' => (array)  ($cfg['followup_options'] ?? []),
                ];
                break;
            case 'scale':
                $q['max']    = (int)   ($cfg['max']    ?? 5);
                $q['labels'] = (array) ($cfg['labels'] ?? ['', '']);
                break;
            case 'date':
                if (!empty($cfg['hint'])) $q['hint'] = (string) $cfg['hint'];
                break;
            case 'text':
                $q['hint']        = (string) ($cfg['hint']        ?? '');
                $q['placeholder'] = (string) ($cfg['placeholder'] ?? 'Type your answer here...');
                $q['min_chars']   = (int)    ($cfg['min_chars']   ?? 0);
                $q['max_chars']   = (int)    ($cfg['max_chars']   ?? 500);
                break;
        }

        $reminder = trim((string) ($r['reminder_text'] ?? ''));
        if ($reminder !== '') $q['no_note'] = $reminder;

        if (!empty($r['is_knockout'])) {
            $q['knockout'] = true;
            if ($type === 'scale') {
                $q['pass_answer'] = max(1, (int) ($pass['min'] ?? 1));
            } elseif (in_array($type, ['yesno', 'yesno_select'], true)) {
                $q['pass_answer'] = ($pass['answer'] ?? 'Yes') === 'No' ? 'No' : 'Yes';
                if ($type === 'yesno_select' && !empty($pass['pass_follow'])) {
                    $q['pass_follow'] = (array) $pass['pass_follow'];
                }
            }
        }
        if (!empty($r['is_consent']))         $q['is_consent']         = true;
        if (!empty($r['is_data_protection'])) $q['is_data_protection'] = true;

        $out[] = $q;
    }
    return $out;
}

function repo_admin_questions(?PDO $pdo = null): array
{
    $pdo = $pdo ?: db();
    return $pdo->query(
        'SELECT question_id, section_no, section, question_text, question_type, answer_config,' .
        ' passing_answer, is_knockout, is_consent, is_data_protection, reminder_text, is_active, created_at' .
        ' FROM question_config WHERE deleted_at IS NULL ORDER BY section_no, display_order, question_id'
    )->fetchAll();
}

/**
 * Build the exact stats shape admin.js expects:
 * { submissions, sections: [{no,name,count}], questions: [{id,section_no,section,text,type,status,answered,top,top_count,share,options,note,expected}] }
 */
function repo_admin_question_stats(?PDO $pdo = null): array
{
    $pdo = $pdo ?: db();
    $questions = repo_admin_questions($pdo);

    $subs = $pdo->query("SELECT answers_json FROM response WHERE submitted_at IS NOT NULL")->fetchAll();
    $total = count($subs);

    // Section numbering: distinct section names in first-seen order -> 1..N
    $secNo = [];
    foreach ($questions as $q) {
        if (!isset($secNo[$q['section']])) $secNo[$q['section']] = count($secNo) + 1;
    }
    $sections = [];
    foreach ($secNo as $name => $no) {
        $sections[] = ['no' => $no, 'name' => $name, 'count' => 0];
    }
    foreach ($questions as $q) $sections[$secNo[$q['section']] - 1]['count']++;

    $out = [];
    foreach ($questions as $q) {
        $type = (string) $q['question_type'];

        $cfg = $q['answer_config'];
        if (is_string($cfg)) $cfg = json_decode($cfg, true);
        if (!is_array($cfg)) $cfg = [];

        $counts = [];
        $answered = 0;
        $written = 0;
        $key = 'q' . (string) $q['question_id'];

        foreach ($subs as $s) {
            $answers = $s['answers_json'];
            if (is_string($answers)) $answers = json_decode($answers, true);
            if (!is_array($answers) || !array_key_exists($key, $answers)) continue;
            $v = $answers[$key];
            if ($v === null || $v === '') continue;
            if ($type === 'text') { $written++; continue; }
            $answered++;
            $label = is_bool($v) ? ($v ? 'Yes' : 'No') : (string) $v;
            $counts[$label] = ($counts[$label] ?? 0) + 1;
        }

        if ($type === 'text') $answered = 0;

        $top = null; $topCount = 0;
        if ($counts) {
            arsort($counts);
            $top = (string) array_key_first($counts);
            $topCount = (int) $counts[$top];
        }

        $row = [
            'id' => 'q' . $q['question_id'],
            'section_no' => $secNo[$q['section']],
            'section' => (string) $q['section'],
            'text' => (string) $q['question_text'],
            'type' => $type,
            'status' => $q['is_active'] ? 'Active' : 'Inactive',
            'answered' => $answered,
            'top' => $top,
            'top_count' => $topCount,
            'share' => $answered > 0 ? round($topCount / $answered * 100, 1) : 0,
            'options' => [],
            'note' => '',
            'expected' => $type === 'yesno' ? (string) ($q['passing_answer'] ?? 'Yes') : null,
        ];

        if ($type === 'text') {
            $row['share'] = $total > 0 ? round($written / $total * 100, 1) : 0;
            $row['note'] = $written . ' written answer' . ($written === 1 ? '' : 's');
        } else {
            arsort($counts);
            foreach (array_slice($counts, 0, 6, true) as $label => $n) {
                $row['options'][] = [
                    'label' => (string) $label,
                    'count' => (int) $n,
                    'pct' => $answered > 0 ? round($n / $answered * 100, 1) : 0,
                ];
            }
        }
        $out[] = $row;
    }

    return ['submissions' => $total, 'sections' => $sections, 'questions' => $out];
}

// ---------------------------------------------------------------------------
// DASHBOARD INSIGHT CARDS (live from the database)
// ---------------------------------------------------------------------------

// Active knock-out questions: share of applicants who did not pass each one (highest first).
// BACKEND: per knock-out question, COUNT(responses that fail passing answer) / COUNT(responses answered).
function repo_admin_failure_rates(int $limit = 5, ?PDO $pdo = null): array
{
    $pdo = $pdo ?: db();

    $questions = $pdo->query(
        'SELECT question_id, section, question_text, passing_answer, question_type' .
        ' FROM question_config WHERE deleted_at IS NULL AND is_active = TRUE AND is_knockout = TRUE' .
        ' ORDER BY section_no, display_order, question_id'
    )->fetchAll();

    $subs = $pdo->query("SELECT answers_json FROM response WHERE submitted_at IS NOT NULL")->fetchAll();
    $totalSubs = count($subs);

    $rows = [];
    foreach ($questions as $q) {
        $pass = $q['passing_answer'];
        if (is_string($pass)) $pass = json_decode($pass, true);
        if (!is_array($pass)) $pass = [];
        $passAnswer = (string) ($pass['answer'] ?? 'Yes');

        $type = (string) $q['question_type'];
        $key = 'q' . (int) $q['question_id'];

        $judged = 0;
        $failed = 0;

        foreach ($subs as $s) {
            $answers = $s['answers_json'];
            if (is_string($answers)) $answers = json_decode($answers, true);
            if (!is_array($answers) || !array_key_exists($key, $answers)) continue;
            $v = $answers[$key];
            if ($v === null || $v === '') continue;
            $judged++;

            $label = is_bool($v) ? ($v ? 'Yes' : 'No') : (string) $v;
            if ($label !== $passAnswer) $failed++;
        }

        $rows[] = [
            'id'     => 'q' . (int) $q['question_id'],
            'label'  => 'Q' . (int) $q['question_id'] . ': ' . (string) $q['question_text'],
            'failed' => $failed,
            'judged' => $judged,
            'rate'   => $judged > 0 ? round($failed / $judged * 100, 1) : 0,
        ];
    }

    usort($rows, fn($a, $b) => [$b['rate'], $b['failed']] <=> [$a['rate'], $a['failed']]);

    return [
        'submissions' => $totalSubs,
        'knockouts'   => count($questions),
        'rows'        => array_slice($rows, 0, $limit),
    ];
}

// Risk Flag Breakdown by Factor: share of screenings where each factor group raised a flag.
// Reads risk_flags_json from the score table (factors[].label + factors[].points).
function repo_admin_risk_flags(?PDO $pdo = null): array
{
    $pdo = $pdo ?: db();

    $groups = [
        'otp_failed'        => ['label' => 'Failed OTP attempts',          'color' => '#f5a623'],
        'otp_requests'      => ['label' => 'Rapid OTP requests',           'color' => '#ef4444'],
        'questionnaire_speed' => ['label' => 'Fast questionnaire',          'color' => '#3b82f6'],
        'identity'          => ['label' => 'Shared email/phone',            'color' => '#ec4899'],
        'reapply'           => ['label' => 'Re-applying after reject',      'color' => '#a855f7'],
        'device_ip'         => ['label' => 'Shared device/IP',              'color' => '#14b8a6'],
        'logistics'         => ['label' => 'Contradictory logistics',       'color' => '#eab308'],
        'video_duplicate'   => ['label' => 'Duplicate video',               'color' => '#10b981'],
        'consistency'       => ['label' => 'Inconsistent answers',          'color' => '#8b5cf6'],
    ];

    $groupOf = function (string $factor): ?string {
        if (stripos($factor, 'Failed OTP attempts') !== false)        return 'otp_failed';
        if (stripos($factor, 'OTP requests in') !== false)             return 'otp_requests';
        if (stripos($factor, 'Questionnaire speed') === 0)             return 'questionnaire_speed';
        if (stripos($factor, 'Same email or phone') !== false)         return 'identity';
        if (stripos($factor, 'Re-applying after') !== false)           return 'reapply';
        if (stripos($factor, 'Same device or IP') !== false)           return 'device_ip';
        if (stripos($factor, 'Contradictory logistics') !== false)     return 'logistics';
        if (stripos($factor, 'Video matches') !== false)               return 'video_duplicate';
        if (stripos($factor, 'Inconsistent or irrelevant') !== false)  return 'consistency';
        return null;
    };

    $rows = $pdo->query("SELECT risk_flags_json FROM score")->fetchAll();

    $count = array_fill_keys(array_keys($groups), 0);
    $total = 0;

    foreach ($rows as $r) {
        $flags = $r['risk_flags_json'];
        if (is_string($flags)) $flags = json_decode($flags, true);
        if (!is_array($flags)) continue;

        $total++;
        $hit = [];
        foreach ((array) ($flags['factors'] ?? []) as $f) {
            $g = $groupOf((string) ($f['label'] ?? ''));
            if ($g !== null && !isset($hit[$g]) && (int) ($f['points'] ?? 0) > 0) {
                $hit[$g] = true;
                $count[$g]++;
            }
        }
    }

    $out = [];
    foreach ($groups as $key => $g) {
        $out[] = [
            'key'    => $key,
            'label'  => $g['label'],
            'color'  => $g['color'],
            'count'  => $count[$key],
            'share'  => $total > 0 ? round($count[$key] / $total * 100, 1) : 0,
            'sample' => false,
        ];
    }
    usort($out, fn($a, $b) => $b['count'] <=> $a['count']);

    return ['evaluations' => $total, 'total' => $total, 'rows' => $out, 'has_sample' => false];
}

// Applicant Volume Over Time: applications received per week / per month from the applicant table.
function repo_admin_volume(?PDO $pdo = null): array
{
    $pdo = $pdo ?: db();

    $rows = $pdo->query("SELECT created_at FROM applicant WHERE deleted_at IS NULL")->fetchAll();

    $days = [];
    foreach ($rows as $r) {
        $days[] = substr((string) $r['created_at'], 0, 10);
    }

    $countBetween = function (DateTimeImmutable $from, DateTimeImmutable $to) use ($days): int {
        $f = $from->format('Y-m-d');
        $t = $to->format('Y-m-d');
        return count(array_filter($days, fn($d) => $d >= $f && $d < $t));
    };

    $now = new DateTimeImmutable('today');
    $monday = $now->modify('monday this week');

    $week = ['labels' => [], 'ranges' => [], 'values' => [], 'sample' => []];
    for ($i = 5; $i >= 0; $i--) {
        $start = $monday->modify('-' . $i . ' weeks');
        $end   = $start->modify('+7 days');
        $last  = $end->modify('-1 day');
        $week['labels'][] = 'W' . (6 - $i);
        $week['ranges'][] = $start->format('M j') . ' to ' . ($start->format('M') === $last->format('M') ? $last->format('j') : $last->format('M j'));
        $week['values'][] = $countBetween($start, $end);
        $week['sample'][] = false;
    }

    $first = $now->modify('first day of this month');
    $month = ['labels' => [], 'ranges' => [], 'values' => [], 'sample' => []];
    for ($i = 5; $i >= 0; $i--) {
        $start = $first->modify('-' . $i . ' months');
        $month['labels'][] = $start->format('M');
        $month['ranges'][] = $start->format('F Y');
        $end = $start->modify('+1 month');
        $month['values'][] = $countBetween($start, $end);
        $month['sample'][] = false;
    }

    return ['week' => $week, 'month' => $month];
}






