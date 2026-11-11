<?php
/**
 * ActivityRepository - real activity log for HR and Admin actions.
 * Rows are written by the action handlers (api/admin-action.php,
 * api/hr-action.php, hr/archive-action.php, hr/add-applicants.php, etc.).
 */

require_once __DIR__ . '/../Helpers/db.php';

function repo_log_activity(string $activity, string $details = '-', ?string $actor = null, ?PDO $pdo = null): void
{
    $role = (string) ($_SESSION['user_role'] ?? '');
    if (!in_array($role, ['hr', 'admin'], true)) return;
    $sessionActor = $role === 'hr' ? 'HR' : 'Admin';
    if ($actor !== null && $actor !== $sessionActor) return;
    $actor = $sessionActor;
    $pdo = $pdo ?: db();
    $details = trim($details);
    if ($details === '') $details = '-';
    try {
        $st = $pdo->prepare(
            "INSERT INTO activity_log (actor, activity, details, created_at) VALUES (:a, :act, :d, NOW())"
        );
        $st->execute([':a' => $actor, ':act' => $activity, ':d' => $details]);
    } catch (Throwable $e) {
        // never break the calling action because of logging; record the error for diagnosis
        error_log('repo_log_activity failed: ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
    }
}

function repo_recent_activity(?PDO $pdo = null, int $limit = 10): array
{
    $pdo = $pdo ?: db();
    $st = $pdo->prepare(
        "SELECT actor, activity, details, created_at FROM activity_log
         WHERE actor IN ('HR', 'Admin')
         ORDER BY created_at DESC, log_id DESC LIMIT :lim"
    );
    $st->bindValue(':lim', $limit, PDO::PARAM_INT);
    $st->execute();
    $out = [];
    foreach ($st->fetchAll() as $r) {
        $out[] = [
            'at'       => $r['created_at'] ? date('Y-m-d H:i:s', strtotime((string) $r['created_at'])) : '',
            'activity' => (string) $r['activity'],
            'details'  => (string) $r['details'],
            'by'       => (string) $r['actor'],
        ];
    }
    return $out;
}

function repo_activities_week(?PDO $pdo = null): int
{
    $pdo = $pdo ?: db();
    $st = $pdo->query(
        "SELECT COUNT(*) FROM activity_log
         WHERE actor IN ('HR', 'Admin') AND created_at >= (NOW() - INTERVAL '7 days')"
    );
    return (int) $st->fetchColumn();
}

function repo_applicant_names(array $ids, bool $archived = false, ?PDO $pdo = null): array
{
    $pdo = $pdo ?: db();
    if (empty($ids)) return [];
    $ids = array_values(array_unique(array_map('intval', $ids)));
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $where = $archived ? 'deleted_at IS NOT NULL' : 'deleted_at IS NULL';
    $st = $pdo->prepare("SELECT name FROM applicant WHERE applicant_id IN ($ph) AND $where");
    $st->execute($ids);
    return array_column($st->fetchAll(), 'name');
}

function repo_names_label(array $names): string
{
    $names = array_values(array_filter(array_map('trim', $names), fn($n) => $n !== ''));
    if (!$names) return '-';
    if (count($names) <= 2) return implode(', ', $names);
    return $names[0] . ', ' . $names[1] . ' +' . (count($names) - 2) . ' more';
}
