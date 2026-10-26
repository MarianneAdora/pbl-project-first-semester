<?php
// src/Repositories/ScheduleRepository.php — DB reads/writes for interview scheduling.
// schedule table: one row per applicant (preferred_* from applicant, final_* from HR).

require_once __DIR__ . '/../Helpers/db.php';

const SCHEDULE_INTERVIEW_TYPE = 'On Site Interview';

function repo_schedule_interview_type(): string
{
    return SCHEDULE_INTERVIEW_TYPE;
}

function repo_schedule_find_raw(int $applicantId, PDO $pdo = null): ?array
{
    $pdo = $pdo ?: db();
    $st = $pdo->prepare(
        'SELECT schedule_id, applicant_id, preferred_date, preferred_time, final_date, final_time, ' .
        ' venue, status, email_sent_at, created_at, updated_at ' .
        ' FROM schedule WHERE applicant_id = :id ORDER BY schedule_id DESC LIMIT 1'
    );
    $st->execute([':id' => $applicantId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : null;
}

function repo_schedule_map_row(array $row): array
{
    $status = (string) ($row['status'] ?? 'pending');
    if ($status === 'pending') $status = 'awaiting';

    $preferredTime = (string) ($row['preferred_time'] ?? '');
    if (strlen($preferredTime) > 5) $preferredTime = substr($preferredTime, 0, 5);

    $finalDate = $row['final_date'] ?? null;
    $finalTime = $row['final_time'] ?? null;
    if (is_string($finalTime) && strlen($finalTime) > 5) $finalTime = substr($finalTime, 0, 5);

    return [
        'status'         => $status,
        'preferred_date' => (string) ($row['preferred_date'] ?? ''),
        'preferred_time' => $preferredTime,
        'type'           => repo_schedule_interview_type(),
        'date'           => $finalDate !== null ? (string) $finalDate : null,
        'time'           => $finalTime !== null ? (string) $finalTime : null,
        'source'         => 'db',
        'updated'        => !empty($row['updated_at']) ? date('Y-m-d H:i', strtotime((string) $row['updated_at'])) : '',
    ];
}

function repo_schedule_find(int $applicantId, PDO $pdo = null): ?array
{
    $pdo = $pdo ?: db();
    $row = repo_schedule_find_raw($applicantId, $pdo);
    if (!$row) return null;
    return repo_schedule_map_row($row);
}

function repo_schedule_request(int $applicantId, string $date, string $time24, string $source = 'applicant', PDO $pdo = null): array
{
    $pdo = $pdo ?: db();
    $raw = repo_schedule_find_raw($applicantId, $pdo);

    if ($raw) {
        $st = $pdo->prepare(
            'UPDATE schedule SET preferred_date = :date, preferred_time = :time, status = \'pending\', ' .
            ' final_date = NULL, final_time = NULL, updated_at = NOW() WHERE schedule_id = :id'
        );
        $st->execute([
            ':date' => $date,
            ':time' => $time24,
            ':id'   => (int) $raw['schedule_id'],
        ]);
    } else {
        $st = $pdo->prepare(
            'INSERT INTO schedule (applicant_id, preferred_date, preferred_time, status, created_at, updated_at) ' .
            ' VALUES (:applicant, :date, :time, \'pending\', NOW(), NOW())'
        );
        $st->execute([
            ':applicant' => $applicantId,
            ':date'      => $date,
            ':time'      => $time24,
        ]);
    }

    $found = repo_schedule_find($applicantId, $pdo);
    if (!$found) throw new RuntimeException('Failed to save interview schedule.');
    return $found;
}

function repo_schedule_approve(int $applicantId, PDO $pdo = null): array
{
    $pdo = $pdo ?: db();
    $raw = repo_schedule_find_raw($applicantId, $pdo);
    if (!$raw) throw new InvalidArgumentException('This applicant has not chosen an interview schedule yet.');

    $st = $pdo->prepare(
        'UPDATE schedule SET status = \'confirmed\', final_date = preferred_date, final_time = preferred_time, ' .
        ' updated_at = NOW() WHERE schedule_id = :id'
    );
    $st->execute([':id' => (int) $raw['schedule_id']]);

    $found = repo_schedule_find($applicantId, $pdo);
    if (!$found) throw new RuntimeException('Failed to confirm interview schedule.');
    return $found;
}

function repo_schedule_reschedule(int $applicantId, string $date, string $time24, PDO $pdo = null): array
{
    $pdo = $pdo ?: db();
    $raw = repo_schedule_find_raw($applicantId, $pdo);
    if (!$raw) throw new InvalidArgumentException('This applicant has not chosen an interview schedule yet.');

    $st = $pdo->prepare(
        'UPDATE schedule SET status = \'rescheduled\', final_date = :date, final_time = :time, updated_at = NOW() ' .
        ' WHERE schedule_id = :id'
    );
    $st->execute([
        ':date' => $date,
        ':time' => $time24,
        ':id'   => (int) $raw['schedule_id'],
    ]);

    $found = repo_schedule_find($applicantId, $pdo);
    if (!$found) throw new RuntimeException('Failed to reschedule interview.');
    return $found;
}
function repo_schedule_time24(string $time12): ?string
{
    $d = DateTimeImmutable::createFromFormat('h:i A', trim($time12));
    return $d ? $d->format('H:i') : null;
}

function repo_schedule_time12(?string $time24): string
{
    $d = $time24 ? DateTimeImmutable::createFromFormat('H:i', $time24) : false;
    return $d ? $d->format('h:i A') : '';
}

function repo_schedule_session_shape(array $row): array
{
    $shape = [
        'date'   => (string) ($row['preferred_date'] ?? ''),
        'time'   => repo_schedule_time12($row['preferred_time'] ?? null),
        'status' => ($row['status'] ?? '') === 'confirmed' ? 'confirmed' : (($row['status'] ?? '') === 'rescheduled' ? 'suggested' : 'pending'),
    ];
    if (($row['status'] ?? '') === 'rescheduled') {
        $shape['new_date'] = (string) ($row['date'] ?? '');
        $shape['new_time'] = repo_schedule_time12($row['time'] ?? null);
    }
    return $shape;
}

