<?php
// src/Repositories/AdminRepository.php — DB reads for the Admin dashboard.
// Questions come from question_config; stats are derived from response.answers_json (JSONB).

require_once __DIR__ . '/../Helpers/db.php';

function repo_admin_overview(PDO $pdo = null): array
{
    $pdo = $pdo ?: db();
    $hrTotal = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'hr' AND deleted_at IS NULL")->fetchColumn();
    $questions = (int) $pdo->query("SELECT COUNT(*) FROM question_config WHERE deleted_at IS NULL AND is_active = TRUE")->fetchColumn();
    $sections = (int) $pdo->query("SELECT COUNT(DISTINCT section) FROM question_config WHERE deleted_at IS NULL AND is_active = TRUE")->fetchColumn();
    $week = (int) $pdo->query("SELECT COUNT(*) FROM applicant WHERE deleted_at IS NULL AND created_at >= NOW() - INTERVAL '7 days'")->fetchColumn();
    return [
        'hr_active' => $hrTotal,
        'hr_total' => $hrTotal,
        'questions' => $questions,
        'sections' => $sections,
        'activities_week' => $week,
    ];
}

/**
 * Active screening questions shaped for the applicant questionnaire page.
 * Reads question_type directly (no derivation). answer_config and passing_answer
 * use the same normalized keys that static_admin_clean_question() writes.
 */
function db_questions_for_applicant(PDO $pdo = null): array
{
    $pdo = $pdo ?: db();
    $rows = $pdo->query(
        'SELECT question_id, section_no, section, question_text, question_type, answer_config,' .
        ' passing_answer, is_knockout, is_consent, is_data_protection, reminder_text' .
        ' FROM question_config WHERE deleted_at IS NULL AND is_active = TRUE ORDER BY section_no, question_id'
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
        ];

        // consent questions replace 'text' with 'body' + 'checkbox'
        if ($type === 'consent') {
            $q['body']     = is_array($cfg['body'] ?? null) ? $cfg['body'] : [(string) $r['question_text']];
            $q['checkbox'] = $cfg['checkbox'] ?? 'I agree';
        } else {
            $q['text'] = (string) $r['question_text'];
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

function repo_admin_questions(PDO $pdo = null): array
{
    $pdo = $pdo ?: db();
    return $pdo->query(
        'SELECT question_id, section_no, section, question_text, question_type, answer_config,' .
        ' passing_answer, is_knockout, is_consent, is_data_protection, reminder_text, is_active, created_at' .
        ' FROM question_config WHERE deleted_at IS NULL ORDER BY section_no, question_id'
    )->fetchAll();
}

/**
 * Build the exact stats shape admin.js expects:
 * { submissions, sections: [{no,name,count}], questions: [{id,section_no,section,text,type,status,answered,top,top_count,share,options,note,expected}] }
 */
function repo_admin_question_stats(PDO $pdo = null): array
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
        $key = (string) $q['question_id'];

        foreach ($subs as $s) {
            $answers = $s['answers_json'];
            if (is_string($answers)) $answers = json_decode($answers, true);
            if (!is_array($answers) || !array_key_exists($key, $answers)) continue;
            $v = $answers[$key];
            if ($v === null || $v === '' || $v === false) continue;
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







