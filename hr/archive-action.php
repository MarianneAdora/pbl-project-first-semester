<?php
// hr/archive-action.php — shared POST endpoint for Screening List actions.
// Body: JSON { action: archive | restore | purge | status, ids: [int, ...], id: int, status: string, result: string }

session_start();
header("Content-Type: application/json");

$respond = function (array $data, int $code = 200): void {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
};

if (!isset($_SESSION["user_role"]) || $_SESSION["user_role"] !== "hr") {
    $respond(["status" => "error", "message" => "Your session has ended. Please log in again."], 403);
}

require_once __DIR__ . "/../src/Repositories/ApplicantRepository.php";

$json = json_decode((string) file_get_contents("php://input"), true);
$input = is_array($json) ? $json : $_POST;
$action = (string) ($input["action"] ?? "");
$ids = array_map("intval", (array) ($input["ids"] ?? []));

try {
    repo_auto_archive_terminal_applicants();
    try { repo_purge_expired_applicants(30); } catch (Throwable $ignored) { /* page still works */ }

    // Status change: Pass / Reject a single applicant from the Screening List.
    if ($action === "status") {
        $sid = (int) ($input["id"] ?? 0);
        $newStatus = (string) ($input["status"] ?? "");
        $newResult = isset($input["result"]) && $input["result"] !== "" ? (string) $input["result"] : null;
        if ($sid <= 0) {
            $respond(["status" => "error", "message" => "Invalid applicant id."], 400);
        }
        $ok = repo_set_status($sid, $newStatus, $newResult);
        if (!$ok) {
            $respond(["status" => "error", "message" => "That applicant or status was not found."], 404);
        }
        $nameRow = repo_find_applicant($sid);
        $label = $nameRow ? (string) $nameRow["name"] : "#" . $sid;
        repo_log_activity("Applicant Status Changed", $label . " · " . $newStatus . ($newResult ? " · " . $newResult : ""));
        $respond([
            "status" => "success",
            "count" => 1,
            "stats" => repo_dashboard_stats(),
            "completed_ids" => [],
        ]);
        exit;
    }

    // Manual HR rejection of a single applicant (before or after they answered).
    // Stores result='rejected' and HR's reason/note in rejection_reason.
    if ($action === "reject") {
        $sid = (int) ($input["id"] ?? 0);
        $reason = (string) ($input["reason"] ?? "");
        if ($sid <= 0) {
            $respond(["status" => "error", "message" => "Invalid applicant id."], 400);
        }
        require_once __DIR__ . "/../src/Repositories/ApplicantRepository.php";
        $ok = repo_reject_applicant($sid, $reason);
        if (!$ok) {
            $respond(["status" => "error", "message" => "That applicant was not found."], 404);
        }
        $nameRow = repo_find_applicant($sid);
        $label = $nameRow ? (string) $nameRow["name"] : "#" . $sid;
        repo_log_activity("Applicant Manually Rejected", $label . " · reason: " . ($reason !== '' ? $reason : '(none)'));
        $respond([
            "status" => "success",
            "count" => 1,
            "stats" => repo_dashboard_stats(),
            "completed_ids" => [],
        ]);
        exit;
    }

    // Names for the activity log (fetched before the move).
    if ($action === "archive") {
        $names = repo_applicant_names($ids, false);
    } else {
        $names = repo_applicant_names($ids, true);
    }
    $label = repo_names_label($names);

    if ($action === "archive") {
        $n = repo_archive_applicants($ids);
        repo_log_activity("Applicant Deleted", $label);
    } elseif ($action === "restore") {
        $n = repo_restore_applicants($ids);
        repo_log_activity("Applicant Restored", $label);
    } elseif ($action === "purge") {
        $n = repo_purge_applicants($ids);
        repo_log_activity("Applicant Deleted Forever", $label);
        if ($n <= 0 && count($ids) > 0) {
            $respond(["status" => "error", "message" => "Those applicants were already removed from the Archive."], 404);
        }
    } else {
        $respond(["status" => "error", "message" => "Unknown action."], 400);
    }

    $archived = repo_applicants_archived();
    $sync = ["ids" => [], "at" => []];
    foreach ($archived as $a) {
        $sync["ids"][] = (int) $a["id"];
        if (!empty($a["archived_at"])) $sync["at"][(int) $a["id"]] = (int) (strtotime((string) $a["archived_at"]) * 1000);
    }

    $respond([
        "status" => "success",
        "count" => $n,
        "stats" => repo_dashboard_stats(),
        "completed_ids" => [],
        "archived" => count($archived),
        "archive" => $sync,
    ]);
} catch (InvalidArgumentException $e) {
    $respond(["status" => "error", "message" => $e->getMessage()], 422);
} catch (Throwable $e) {
    error_log("archive-action DB error: " . $e->getMessage());
    $respond(["status" => "error", "message" => "Something went wrong while saving. Please try again."], 500);
}
