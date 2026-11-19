<?php
/**
 * DashboardMetricsRepository - hiring funnel, scheduling turnaround, and
 * completion-time insights for the HR dashboard.
 * All functions fail soft (return empty/safe values) so the dashboard never breaks.
 */

require_once __DIR__ . '/../Helpers/db.php';

// ---------------------------------------------------------------------------
// Funnel stages: invited -> started -> submitted -> reviewed -> accepted
// ---------------------------------------------------------------------------
function repo_funnel_stages(?PDO $pdo = null): array
{
    $pdo = $pdo ?: db();

    $stages = [
        ['label' => 'Invited',   'sub' => 'Applicants invited to screen'],
        ['label' => 'Started',   'sub' => 'Began the screening'],
        ['label' => 'Submitted', 'sub' => 'Finished the screening'],
        ['label' => 'Reviewed',  'sub' => 'Reviewed by HR'],
        ['label' => 'Accepted',  'sub' => 'Application accepted'],
    ];

    $counts = [];
    $counts[] = (int) $pdo->query(
        "SELECT COUNT(*) FROM applicant WHERE deleted_at IS NULL"
    )->fetchColumn();
    $counts[] = (int) $pdo->query(
        "SELECT COUNT(*) FROM applicant WHERE deleted_at IS NULL AND status = 'in_progress'"
    )->fetchColumn();
    $counts[] = (int) $pdo->query(
        "SELECT COUNT(*) FROM applicant WHERE deleted_at IS NULL AND status = 'submitted'"
    )->fetchColumn();
    $counts[] = (int) $pdo->query(
        "SELECT COUNT(*) FROM applicant WHERE deleted_at IS NULL AND status = 'reviewed'"
    )->fetchColumn();
    $counts[] = (int) $pdo->query(
        "SELECT COUNT(*) FROM applicant WHERE deleted_at IS NULL AND status = 'accepted'"
    )->fetchColumn();

    $start = $counts[0] > 0 ? $counts[0] : 1;
    $out = [];
    foreach ($stages as $i => $s) {
        $c = $counts[$i];
        $lost = $i > 0 ? $counts[$i - 1] - $c : 0;
        $lostPct = $i > 0 && $counts[$i - 1] > 0 ? (int) round($lost / $counts[$i - 1] * 100) : 0;
        $pctOfStart = $start > 0 ? (int) round($c / $start * 100) : 0;
        $out[] = [
            'label'         => $s['label'],
            'sub'           => $s['sub'],
            'count'         => $c,
            'lost'          => $lost,
            'lost_pct'      => $lostPct,
            'pct_of_start'  => $pctOfStart,
        ];
    }
    return $out;
}

function repo_funnel_summary(array $funnel): array
{
    if (empty($funnel)) {
        return ['reach_pct' => 0, 'worst' => null];
    }

    $start = $funnel[0]['count'] > 0 ? $funnel[0]['count'] : 1;
    $accepted = $funnel[count($funnel) - 1]['count'] ?? 0;
    $reachPct = $start > 0 ? (int) round($accepted / $start * 100) : 0;

    $worst = null;
    $worstPct = 0;
    for ($i = 1; $i < count($funnel); $i++) {
        $prev = $funnel[$i - 1];
        $cur  = $funnel[$i];
        if ($prev['count'] > 0) {
            $pct = (int) round(($prev['count'] - $cur['count']) / $prev['count'] * 100);
            if ($pct > $worstPct) {
                $worstPct = $pct;
                $worst = [
                    'from'    => $prev['label'],
                    'to'      => $cur['label'],
                    'from_i'  => $i - 1,
                    'to_i'    => $i,
                    'pct'     => $pct,
                ];
            }
        }
    }

    return ['reach_pct' => $reachPct, 'worst' => $worst];
}

// ---------------------------------------------------------------------------
// Scheduling turnaround: applicant created -> schedule created
// ---------------------------------------------------------------------------
function repo_schedule_turnaround(?PDO $pdo = null): array
{
    $pdo = $pdo ?: db();

    $out = [
        'n'                => 0,
        'avg_hours'        => null,
        'median_hours'     => null,
        'target_hours'     => 24,
        'within_target_pct'=> null,
        'trend_delta_hours'=> null,
        'waiting'          => 0,
        'oldest_wait_hours'=> null,
        'oldest_wait_id'   => null,
    ];

    // Applicants with a schedule entry (time from applicant created to schedule created)
    $rows = $pdo->query(
        "SELECT a.applicant_id AS id,
                 EXTRACT(EPOCH FROM (s.created_at - a.created_at)) / 3600.0 AS hours
          FROM applicant a
          JOIN schedule s ON s.applicant_id = a.applicant_id
          WHERE a.deleted_at IS NULL
          ORDER BY s.created_at DESC"
    )->fetchAll();

    // Waiting: applicants with status submitted/reviewed but no schedule
    $waitingRows = $pdo->query(
        "SELECT a.applicant_id AS id,
                 EXTRACT(EPOCH FROM (NOW() - a.created_at)) / 3600.0 AS hours
          FROM applicant a
          WHERE a.deleted_at IS NULL
            AND a.status IN ('submitted', 'reviewed')
            AND NOT EXISTS (
                SELECT 1 FROM schedule s WHERE s.applicant_id = a.applicant_id
            )
          ORDER BY a.created_at ASC"
    )->fetchAll();

    $hours = [];
    foreach ($rows as $r) {
        $h = (float) $r['hours'];
        if ($h >= 0) $hours[] = $h;
    }

    $out['n'] = count($hours);
    if ($out['n'] > 0) {
        sort($hours);
        $out['avg_hours'] = array_sum($hours) / $out['n'];
        $mid = (int) floor($out['n'] / 2);
        $out['median_hours'] = $out['n'] % 2 === 1
            ? $hours[$mid]
            : ($hours[$mid - 1] + $hours[$mid]) / 2;

        $within = array_reduce($hours, function ($c, $h) {
            return $c + ($h <= 24 ? 1 : 0);
        }, 0);
        $out['within_target_pct'] = $out['n'] > 0 ? (int) round($within / $out['n'] * 100) : 0;
    }

    $out['waiting'] = count($waitingRows);
    if ($out['waiting'] > 0) {
        $oldest = $waitingRows[0];
        $out['oldest_wait_hours'] = (float) $oldest['hours'];
        $out['oldest_wait_id']    = (int) $oldest['id'];
    }

    return $out;
}

// ---------------------------------------------------------------------------
// Completion histogram: applicant created -> response submitted
// ---------------------------------------------------------------------------
function repo_completion_histogram(?PDO $pdo = null): array
{
    $pdo = $pdo ?: db();

    $out = [
        'n'             => 0,
        'median_hours'  => null,
        'within24_pct'  => 0,
        'over72_pct'    => 0,
        'not_submitted' => 0,
    ];

    // Invited but never submitted (no response with submitted_at)
    $out['not_submitted'] = (int) $pdo->query(
        "SELECT COUNT(*) FROM applicant a
         WHERE a.deleted_at IS NULL
           AND NOT EXISTS (
               SELECT 1 FROM response r WHERE r.applicant_id = a.applicant_id
                 AND r.submitted_at IS NOT NULL
           )"
    )->fetchColumn();

    // Submitted applicants: time from applicant created to response submitted
    $rows = $pdo->query(
        "SELECT EXTRACT(EPOCH FROM (r.submitted_at - a.created_at)) / 3600.0 AS hours
         FROM applicant a
         JOIN response r ON r.applicant_id = a.applicant_id
         WHERE a.deleted_at IS NULL
           AND r.submitted_at IS NOT NULL
           AND r.submitted_at >= a.created_at
         ORDER BY hours"
    )->fetchAll();

    $hours = [];
    foreach ($rows as $r) {
        $hours[] = (float) $r['hours'];
    }

    $out['n'] = count($hours);
    if ($out['n'] > 0) {
        $mid = (int) floor($out['n'] / 2);
        $out['median_hours'] = $out['n'] % 2 === 1
            ? $hours[$mid]
            : ($hours[$mid - 1] + $hours[$mid]) / 2;

        $within24 = array_reduce($hours, function ($c, $h) {
            return $c + ($h <= 24 ? 1 : 0);
        }, 0);
        $out['within24_pct'] = (int) round($within24 / $out['n'] * 100);

        $over72 = array_reduce($hours, function ($c, $h) {
            return $c + ($h > 72 ? 1 : 0);
        }, 0);
        $out['over72_pct'] = (int) round($over72 / $out['n'] * 100);
    }

    return $out;
}
