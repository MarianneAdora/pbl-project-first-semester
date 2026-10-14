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

function repo_admin_questions(PDO $pdo = null): array
{
    $pdo = $pdo ?: db();
    return $pdo->query(
        "SELECT question_id, section, question_text, answer_type, passing_answer, is_knockout," .
        " reminder_text, is_active, created_at FROM question_config" .
        " WHERE deleted_at IS NULL ORDER BY question_id ASC"
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

    $typeOf = function (string $dbType): string {
        $t = strtoupper(trim($dbType));
        if ($t === 'BOOLEAN') return 'yesno';
        if ($t === 'SCALE') return 'scale';
        if ($t === 'DATE') return 'date';
        if ($t === 'TEXT') return 'text';
        if ($t === 'MULTIPLE_CHOICE') return 'yesno_select';
        return 'text';
    };

    $out = [];
    foreach ($questions as $q) {
        $type = $typeOf((string) $q['answer_type']);
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
