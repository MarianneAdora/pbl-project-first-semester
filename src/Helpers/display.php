<?php
// src/Helpers/display.php — translate detailed DB pipeline states into the simplified UI states.
// Schema is NEVER changed to match the UI; these mappers are the only bridge.
//
// DB applicant.status: pending | in_progress | completed | submitted | reviewed | rejected | accepted
// UI status:           Pending | In Progress | Completed
// UI result:           Passed | Rejected | null
//
// Mapping:
//   pending                  -> Pending,     result null
//   in_progress              -> In Progress, result null
//   completed / submitted / reviewed -> Completed, result null (awaiting final decision)
//   accepted                 -> Completed,   Passed
//   rejected                 -> Completed,   Rejected
// A non-empty applicant.result column (passed/rejected variants) always wins when present.

function display_status(string $dbStatus): string
{
    switch (strtolower(trim($dbStatus))) {
        case 'pending': return 'Pending';
        case 'in_progress': return 'In Progress';
        case 'completed':
        case 'submitted':
        case 'reviewed':
        case 'accepted':
        case 'rejected': return 'Completed';
        default: return 'Pending';
    }
}

function display_result(?string $dbResult, string $dbStatus = ''): ?string
{
    $r = strtolower(trim((string) $dbResult));
    if (in_array($r, ['passed', 'pass', 'accepted', 'accept'], true)) return 'Passed';
    if (in_array($r, ['rejected', 'reject', 'failed', 'fail'], true)) return 'Rejected';

    // Fall back to terminal pipeline states when no explicit result was stored.
    switch (strtolower(trim($dbStatus))) {
        case 'accepted': return 'Passed';
        case 'rejected': return 'Rejected';
        default: return null;
    }
}

/**
 * Map one applicant DB row to the shape the HR frontend expects:
 * { id, name, email, contact, status, result } (+ raw db_status for debugging).
 */
function applicant_to_display(array $row): array
{
    $status = display_status((string) ($row['status'] ?? 'pending'));
    return [
        'id'      => (int) ($row['applicant_id'] ?? $row['id'] ?? 0),
        'name'    => (string) ($row['name'] ?? ''),
        'email'   => (string) ($row['email'] ?? ''),
        'contact' => (string) ($row['phone_number'] ?? $row['contact'] ?? ''),
        'status'  => $status,
        'result'  => display_result($row['result'] ?? null, (string) ($row['status'] ?? '')),
        'db_status' => (string) ($row['status'] ?? ''),
    ];
}
