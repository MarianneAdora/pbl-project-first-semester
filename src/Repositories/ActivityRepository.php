<?php
/**
 * ActivityRepository - real activity log for HR and Admin actions.
 * Rows are written by the action handlers (api/admin-action.php,
 * api/hr-action.php, hr/archive-action.php, hr/add-applicants.php, etc.).
 */

require_once __DIR__ . '/../Helpers/db.php';

function repo_activity_format_timestamp(?string $timestamp): string
{
    if ($timestamp === null || $timestamp === '') return '';

    return (new DateTimeImmutable($timestamp, new DateTimeZone('UTC')))
        ->setTimezone(new DateTimeZone('Asia/Manila'))
        ->format('Y-m-d H:i:s');
}

function repo_activity_format(array $row): array
{
    return [
        'id'         => (int) ($row['log_id'] ?? $row['id'] ?? 0),
        'at'         => repo_activity_format_timestamp(isset($row['created_at']) ? (string) $row['created_at'] : null),
        'activity'   => (string) $row['activity'],
        'details'    => (string) $row['details'],
        'by'         => (string) $row['actor'],
        'actor_name' => trim((string) $row['actor_name']) !== '' ? (string) $row['actor_name'] : (string) $row['actor'],
        'actor'      => (string) $row['actor'],
    ];
}

function repo_log_activity(string $activity, string $details = '-', ?string $actor = null, ?PDO $pdo = null): ?array
{
    $role = (string) ($_SESSION['user_role'] ?? '');
    if (!in_array($role, ['hr', 'admin'], true)) return null;
    $sessionActor = $role === 'hr' ? 'HR' : 'Admin';
    if ($actor !== null && $actor !== $sessionActor) return null;
    $actor = $sessionActor;
    $actorName = trim((string) ($_SESSION['user_name'] ?? ''));
    if ($actorName === '') $actorName = $actor;
    $pdo = $pdo ?: db();
    $details = trim($details);
    if ($details === '') $details = '-';
    try {
        $st = $pdo->prepare(
            "INSERT INTO activity_log (actor, actor_name, activity, details, created_at)
             VALUES (:a, :an, :act, :d, NOW() AT TIME ZONE 'UTC')
             RETURNING log_id, actor, actor_name, activity, details, created_at"
        );
        $st->execute([':a' => $actor, ':an' => $actorName, ':act' => $activity, ':d' => $details]);
        $row = $st->fetch();
        return is_array($row) ? repo_activity_format($row) : null;
    } catch (Throwable $e) {
        // never break the calling action because of logging; record the error for diagnosis
        error_log('repo_log_activity failed: ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
        return null;
    }
}

function repo_recent_activity(?PDO $pdo = null, int $limit = 10): array
{
    $pdo = $pdo ?: db();
    $st = $pdo->prepare(
        "SELECT log_id, actor, actor_name, activity, details, created_at FROM activity_log
         WHERE actor IN ('HR', 'Admin')
         ORDER BY created_at DESC, log_id DESC LIMIT :lim"
    );
    $st->bindValue(':lim', $limit, PDO::PARAM_INT);
    $st->execute();
    $out = [];
    foreach ($st->fetchAll() as $r) $out[] = repo_activity_format($r);
    return $out;
}

function repo_admin_activity_feed(?string $hrActorName = null, bool $hrOnly = false, int $limit = 10, ?PDO $pdo = null): array
{
    $pdo = $pdo ?: db();
    $where = "actor IN ('HR', 'Admin')";
    $params = [];
    if ($hrActorName !== null) {
        $where = "actor = 'HR' AND actor_name = :actor_name";
        $params[':actor_name'] = $hrActorName;
    } elseif ($hrOnly) {
        $where = "actor = 'HR'";
    }

    $count = $pdo->prepare("SELECT COUNT(*) FROM activity_log WHERE $where");
    $count->execute($params);

    $st = $pdo->prepare(
        "SELECT log_id, actor, actor_name, activity, details, created_at FROM activity_log
         WHERE $where
         ORDER BY created_at DESC, log_id DESC LIMIT :lim"
    );
    foreach ($params as $key => $value) $st->bindValue($key, $value, PDO::PARAM_STR);
    $st->bindValue(':lim', $limit, PDO::PARAM_INT);
    $st->execute();

    $rows = [];
    foreach ($st->fetchAll() as $row) $rows[] = repo_activity_format($row);

    return ['rows' => $rows, 'total' => (int) $count->fetchColumn()];
}

function repo_activities_week(?PDO $pdo = null, ?string $actorName = null, bool $hrOnly = false): int
{
    $pdo = $pdo ?: db();
    $where = "actor IN ('HR', 'Admin')";
    $params = [];
    if ($actorName !== null) {
        $where = "actor = 'HR' AND actor_name = :actor_name";
        $params[':actor_name'] = $actorName;
    } elseif ($hrOnly) {
        $where = "actor = 'HR'";
    }

    $st = $pdo->prepare(
        "SELECT COUNT(*) FROM activity_log
         WHERE $where AND created_at >= (NOW() - INTERVAL '7 days')"
    );
    $st->execute($params);
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
