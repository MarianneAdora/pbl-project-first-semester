<?php
// api/admin-action.php — DB-backed AJAX endpoint for admin archive/restore/purge actions.
require_once __DIR__ . '/../src/Helpers/security.php';
secure_session(1800);
session_start();
send_security_headers(false);

header('Content-Type: application/json; charset=UTF-8');
date_default_timezone_set('Asia/Manila');

require_once __DIR__ . '/../src/Repositories/AdminRepository.php';
require_once __DIR__ . '/../src/Services/EmailService.php';
require_once __DIR__ . '/../src/Helpers/db.php';
require_once __DIR__ . '/../hr/hr-data.php';

function admin_respond(array $data, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

// CSRF + auth guard for all state-changing POSTs
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    admin_respond(['status' => 'error', 'message' => 'Invalid request.'], 405);
}
$json  = json_decode((string) file_get_contents('php://input'), true);
$input = is_array($json) ? $json : $_POST;
$action = (string) ($input['action'] ?? '');

$csrfIn = (string) ($input['csrf_token'] ?? '');
    if (!csrf_verify($csrfIn)) {
        admin_respond(['status' => 'error', 'message' => 'Invalid CSRF token. Please refresh and try again.'], 403);
    }
if (($_SESSION['user_role'] ?? '') !== 'admin') {
    admin_respond(['status' => 'error', 'message' => 'Your session has ended. Please log in again.'], 403);
}

try {
    $missing = 'That item was not found. It may have been removed already.';
    $missing = 'That item was not found. It may have been removed already.';

    switch ($action) {
        case 'account_add':
            $acc = $input['account'] ?? [];
            $name     = trim((string) ($acc['name']     ?? ''));
            $email    = strtolower(trim((string) ($acc['email']    ?? '')));
            $password = (string) ($acc['password'] ?? '');
            $status   = ($acc['status'] ?? 'Active') === 'Inactive' ? 'Inactive' : 'Active';
            $sendMail = !empty($acc['send_email']);

            if (!$name)  admin_respond(['status' => 'error', 'message' => 'Please enter the full name.'], 422);
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) admin_respond(['status' => 'error', 'message' => 'Please enter a valid email address.'], 422);
            if (strlen($password) < 8 || !preg_match('/[A-Za-z]/', $password) || !preg_match('/\d/', $password))
                admin_respond(['status' => 'error', 'message' => 'Password must be at least 8 characters with letters and a number.'], 422);

            $pdo = db();
            $exists = $pdo->prepare("SELECT 1 FROM users WHERE email = :e AND deleted_at IS NULL");
            $exists->execute([':e' => $email]);
            if ($exists->fetchColumn()) admin_respond(['status' => 'error', 'message' => 'That email address is already in use.'], 422);

            $hash = password_hash($password, PASSWORD_DEFAULT);
            $ins = $pdo->prepare("INSERT INTO users (name, email, password_hash, role, status, created_at) VALUES (:n, :e, :p, 'hr', :s, NOW()) RETURNING user_id");
            $ins->execute([':n' => $name, ':e' => $email, ':p' => $hash, ':s' => $status]);
            $newId = (int) $ins->fetchColumn();
            repo_log_activity('HR Account Added', $name . ' (HR) · ' . $email);

            $emailed = false;
            if ($sendMail) {
                $emailed = (new EmailService())->sendHrCredentialsEmail($email, $name, $password);
            }

            admin_respond([
                'status'  => 'success',
                'message' => 'HR account created.',
                'result'  => ['id' => $newId, 'name' => $name, 'email' => $email, 'role' => 'HR Staff', 'emailed' => $emailed],
                'data'    => ['accounts' => repo_admin_accounts(), 'questions' => repo_admin_questions_list(), 'archive' => repo_admin_archive()],
            ]);
            break;

        case 'account_update':
            $id  = (int) ($input['id'] ?? 0);
            $acc = $input['account'] ?? [];
            $name     = trim((string) ($acc['name']     ?? ''));
            $email    = strtolower(trim((string) ($acc['email']    ?? '')));
            $password = (string) ($acc['password'] ?? '');
            $status   = ($acc['status'] ?? 'Active') === 'Inactive' ? 'Inactive' : 'Active';
            $sendMail = !empty($acc['send_email']);

            if (!$id)   admin_respond(['status' => 'error', 'message' => 'Invalid account ID.'], 422);
            if (!$name) admin_respond(['status' => 'error', 'message' => 'Please enter the full name.'], 422);
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) admin_respond(['status' => 'error', 'message' => 'Please enter a valid email address.'], 422);

            $pdo = db();
            $dup = $pdo->prepare("SELECT 1 FROM users WHERE email = :e AND user_id != :id AND deleted_at IS NULL");
            $dup->execute([':e' => $email, ':id' => $id]);
            if ($dup->fetchColumn()) admin_respond(['status' => 'error', 'message' => 'That email address is already in use.'], 422);

            $en = strtolower(trim($email));
            if ($password !== '') {
                if (strlen($password) < 8 || !preg_match('/[A-Za-z]/', $password) || !preg_match('/\d/', $password))
                    admin_respond(['status' => 'error', 'message' => 'Password must be at least 8 characters with letters and a number.'], 422);
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $upd = $pdo->prepare("UPDATE users SET name=:n, email=:e, email_normalized=:en, password_hash=:p, status=:s WHERE user_id=:id AND role='hr' AND deleted_at IS NULL");
                $upd->execute([':n' => $name, ':e' => $email, ':en' => $en, ':p' => $hash, ':s' => $status, ':id' => $id]);
            } else {
                $upd = $pdo->prepare("UPDATE users SET name=:n, email=:e, email_normalized=:en, status=:s WHERE user_id=:id AND role='hr' AND deleted_at IS NULL");
                $upd->execute([':n' => $name, ':e' => $email, ':en' => $en, ':s' => $status, ':id' => $id]);
            }
            if (!$upd->rowCount()) admin_respond(['status' => 'error', 'message' => 'Account not found or already deleted.'], 404);
            repo_log_activity('HR Account Edited', $name . ' (HR) · ' . $email);

            $emailed = false;
            if ($sendMail && $password !== '') {
                $emailed = (new EmailService())->sendHrCredentialsEmail($email, $name, $password);
            }

            admin_respond([
                'status'  => 'success',
                'message' => 'HR account updated.',
                'result'  => ['id' => $id, 'name' => $name, 'email' => $email, 'role' => 'HR Staff', 'emailed' => $emailed],
                'data'    => ['accounts' => repo_admin_accounts(), 'questions' => repo_admin_questions_list(), 'archive' => repo_admin_archive()],
            ]);
            break;

        case 'password_decide':
            if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'XMLHttpRequest') {
                admin_respond(['status' => 'error', 'message' => 'Invalid request.'], 400);
            }
            $decision = (string) ($input['decision'] ?? '');
            $result = static_profile_decide_request(
                (int) ($input['id'] ?? 0),
                $decision,
                (string) ($input['reason'] ?? '')
            );
            admin_respond([
                'status'  => 'success',
                'message' => $decision === 'approve' ? 'Password change approved.' : 'Password change rejected.',
                'result'  => $result
            ]);
            break;

        case 'account_archive':
            $aid = (int) ($input['id'] ?? 0);
            $ast = db()->prepare("SELECT name, email FROM users WHERE user_id = :id"); $ast->execute([':id' => $aid]); $ar = $ast->fetch(); $aname = (string) ($ar['name'] ?? ''); $aemail = (string) ($ar['email'] ?? '');
            if (!repo_account_archive($aid)) admin_respond(['status' => 'error', 'message' => $missing], 404);
            repo_log_activity('HR Account Deleted', $aname . ' (HR) · ' . $aemail);
            $message = 'HR account moved to the Archive.';
            break;
        case 'account_restore':
            $aid = (int) ($input['id'] ?? 0);
            $ast = db()->prepare("SELECT name, email FROM users WHERE user_id = :id"); $ast->execute([':id' => $aid]); $ar = $ast->fetch(); $aname = (string) ($ar['name'] ?? ''); $aemail = (string) ($ar['email'] ?? '');
            if (!repo_account_restore($aid)) admin_respond(['status' => 'error', 'message' => $missing], 404);
            repo_log_activity('HR Account Restored', $aname . ' (HR) · ' . $aemail);
            $message = 'HR account restored.';
            break;
        case 'account_purge':
            $aid = (int) ($input['id'] ?? 0);
            $ast = db()->prepare("SELECT name, email FROM users WHERE user_id = :id"); $ast->execute([':id' => $aid]); $ar = $ast->fetch(); $aname = (string) ($ar['name'] ?? ''); $aemail = (string) ($ar['email'] ?? '');
            if (!repo_account_purge($aid)) admin_respond(['status' => 'error', 'message' => $missing], 404);
            repo_log_activity('HR Account Deleted Forever', $aname . ' (HR) · ' . $aemail);
            $message = 'HR account deleted forever.';
            break;

        case 'question_add':
            require_once __DIR__ . '/../admin/question-admin.php';

            $qIn = (array) ($input['question'] ?? []);

            // all questions (including archived) in the shape static_admin_clean_question expects
            $all = [];
            foreach (db()->query("SELECT question_id, section_no, section, is_active, deleted_at FROM question_config ORDER BY question_id")->fetchAll() as $r) {
                $all[] = [
                    'id'          => 'q' . $r['question_id'],
                    'section_no'  => (int) $r['section_no'],
                    'section'     => (string) $r['section'],
                    'status'      => $r['is_active'] ? 'Active' : 'Inactive',
                    'archived_at' => $r['deleted_at'],
                ];
            }

            // optional section rename
            $renamed = null;
            $renameTitle = trim((string) ($qIn['section_rename'] ?? ''));
            if ($renameTitle !== '') {
                $cur = trim((string) ($qIn['section'] ?? ''));
                $no = 0; $oldTitle = '';
                foreach ($all as $q) {
                    if (strcasecmp((string) $q['section'], $cur) === 0) { $no = (int) $q['section_no']; $oldTitle = (string) $q['section']; break; }
                }
                if ($no === 0) admin_respond(['status' => 'error', 'message' => 'Choose an existing section to rename.'], 422);
                if (mb_strlen($renameTitle) > 60) admin_respond(['status' => 'error', 'message' => 'The section name is too long (60 characters at most).'], 422);
                foreach ($all as $q) {
                    if ((int) $q['section_no'] !== $no && strcasecmp((string) $q['section'], $renameTitle) === 0) {
                        admin_respond(['status' => 'error', 'message' => 'Another section already uses that title.'], 422);
                    }
                }
                if ($renameTitle !== $oldTitle) {
                    db()->prepare("UPDATE question_config SET section = :t WHERE section_no = :no")->execute([':t' => $renameTitle, ':no' => $no]);
                    foreach ($all as &$q) {
                        if ((int) $q['section_no'] === $no) $q['section'] = $renameTitle;
                    }
                    unset($q);
                    $qIn['section'] = $renameTitle;
                    $renamed = ['no' => $no, 'from' => $oldTitle, 'to' => $renameTitle];
                }
            }

            try {
                $cleaned = static_admin_clean_question($qIn, $all);
            } catch (InvalidArgumentException $e) {
                admin_respond(['status' => 'error', 'message' => $e->getMessage()], 422);
            }

            $pdo = db();
            $answerConfig = isset($cleaned['answer_config']) && is_array($cleaned['answer_config']) ? $cleaned['answer_config'] : null;
            $passing = isset($cleaned['passing_answer']) && is_array($cleaned['passing_answer']) ? $cleaned['passing_answer'] : null;

            $order = $pdo->prepare('SELECT COALESCE(MAX(display_order), -1) + 1 FROM question_config WHERE section_no = :section_no');
            $order->execute([':section_no' => (int) $cleaned['section_no']]);
            $displayOrder = (int) $order->fetchColumn();

            $ins = $pdo->prepare(
                "INSERT INTO question_config (section_no, display_order, section, question_text, question_type, answer_config, passing_answer, is_knockout, is_consent, is_data_protection, reminder_text, is_active, created_at) " .
                "VALUES (:sn, :order, :sec, :txt, :typ, :ac, :pa, :ko, :consent, :data_protection, :rem, :active, :created) RETURNING question_id"
            );
            $ins->execute([
                ':sn'      => (int) $cleaned['section_no'],
                ':order'   => $displayOrder,
                ':sec'     => (string) $cleaned['section'],
                ':txt'     => (string) $cleaned['text'],
                ':typ'     => (string) $cleaned['type'],
                ':ac'      => $answerConfig ? json_encode($answerConfig) : '{}',
                ':pa'      => $passing ? json_encode($passing) : null,
                ':ko'      => !empty($cleaned['knockout']) ? 't' : 'f',
                ':consent' => ($cleaned['type'] === 'consent') ? 't' : 'f',
                ':data_protection' => !empty($cleaned['is_data_protection']) ? 't' : 'f',
                ':rem'     => $cleaned['no_note'] ?? null,
                ':active'  => (($cleaned['status'] ?? 'Active') === 'Active') ? 't' : 'f',
                ':created' => $cleaned['created'] ?? date('Y-m-d H:i'),
            ]);
            $newId = (int) $ins->fetchColumn();
            $cleaned['id'] = 'q' . $newId;

            if ($renamed) repo_log_activity('Section Renamed', 'Sec ' . $renamed['no'] . ' · ' . $renamed['from'] . ' → ' . $renamed['to']);
            repo_log_activity('Questionnaires Added', 'Q' . $newId . ' · ' . $cleaned['section'] . ' · ' . mb_substr(trim($cleaned['text']), 0, 110));

            admin_respond([
                'status'  => 'success',
                'message' => 'Question added.',
                'result'  => $cleaned,
                'data'    => ['questions' => repo_admin_questions_list(), 'accounts' => repo_admin_accounts(), 'archive' => repo_admin_archive()],
            ]);
            break;


        case 'question_update':
            require_once __DIR__ . '/../admin/question-admin.php';

            $id = (int) ltrim((string) ($input['id'] ?? ''), 'q');
            $qIn = (array) ($input['question'] ?? []);

            $all = [];
            foreach (db()->query("SELECT question_id, section_no, section, is_active, deleted_at, created_at FROM question_config ORDER BY question_id")->fetchAll() as $r) {
                $all[] = [
                    'id'          => 'q' . $r['question_id'],
                    'section_no'  => (int) $r['section_no'],
                    'section'     => (string) $r['section'],
                    'status'      => $r['is_active'] ? 'Active' : 'Inactive',
                    'archived_at' => $r['deleted_at'],
                    'created'     => $r['created_at'] ? date('Y-m-d H:i', strtotime((string) $r['created_at'])) : date('Y-m-d H:i'),
                ];
            }

            $pos = null;
            foreach ($all as $i => $q) {
                if ($q['id'] === ('q' . $id) && empty($q['archived_at'])) { $pos = $i; break; }
            }
            if ($pos === null) admin_respond(['status' => 'error', 'message' => 'That question was not found. It may have been deleted.'], 404);

            $oldRow = null;
            $stmt = $pdo->prepare("SELECT * FROM question_config WHERE question_id = :id");
            $stmt->execute([':id' => $id]);
            foreach ($stmt->fetchAll() as $r) { $oldRow = $r; }
            $old = [
                'id'      => 'q' . $id,
                'status'  => $oldRow && $oldRow['is_active'] ? 'Active' : 'Inactive',
                'created' => $oldRow && $oldRow['created_at'] ? date('Y-m-d H:i', strtotime((string) $oldRow['created_at'])) : date('Y-m-d H:i'),
            ];

            $renamed = null;
            $renameTitle = trim((string) ($qIn['section_rename'] ?? ''));
            if ($renameTitle !== '') {
                $cur = trim((string) ($qIn['section'] ?? ''));
                $no = 0; $oldTitle = '';
                foreach ($all as $q) {
                    if (strcasecmp((string) $q['section'], $cur) === 0) { $no = (int) $q['section_no']; $oldTitle = (string) $q['section']; break; }
                }
                if ($no === 0) admin_respond(['status' => 'error', 'message' => 'Choose an existing section to rename.'], 422);
                if (mb_strlen($renameTitle) > 60) admin_respond(['status' => 'error', 'message' => 'The section name is too long (60 characters at most).'], 422);
                foreach ($all as $q) {
                    if ((int) $q['section_no'] !== $no && strcasecmp((string) $q['section'], $renameTitle) === 0) {
                        admin_respond(['status' => 'error', 'message' => 'Another section already uses that title.'], 422);
                    }
                }
                if ($renameTitle !== $oldTitle) {
                    db()->prepare("UPDATE question_config SET section = :t WHERE section_no = :no")->execute([':t' => $renameTitle, ':no' => $no]);
                    foreach ($all as &$q) {
                        if ((int) $q['section_no'] === $no) $q['section'] = $renameTitle;
                    }
                    unset($q);
                    $qIn['section'] = $renameTitle;
                    $renamed = ['no' => $no, 'from' => $oldTitle, 'to' => $renameTitle];
                }
            }

            $activeCount = count(array_filter($all, fn($q) => empty($q['archived_at']) && ($q['status'] ?? 'Active') === 'Active' && $q['id'] !== ('q' . $id)));

            try {
                $new = static_admin_clean_question($qIn, $all, $old);
            } catch (InvalidArgumentException $e) {
                admin_respond(['status' => 'error', 'message' => $e->getMessage()], 422);
            }

            if (($new['status'] ?? 'Active') === 'Inactive' && $activeCount < 1) {
                admin_respond(['status' => 'error', 'message' => 'At least one question has to stay Active.'], 422);
            }

            $pdo = db();
            $answerConfig = isset($new['answer_config']) && is_array($new['answer_config']) ? $new['answer_config'] : null;
            $passing = isset($new['passing_answer']) && is_array($new['passing_answer']) ? $new['passing_answer'] : null;

            $displayOrder = (int) ($oldRow['display_order'] ?? 0);
            if ((int) $oldRow['section_no'] !== (int) $new['section_no']) {
                $order = $pdo->prepare('SELECT COALESCE(MAX(display_order), -1) + 1 FROM question_config WHERE section_no = :section_no');
                $order->execute([':section_no' => (int) $new['section_no']]);
                $displayOrder = (int) $order->fetchColumn();
            }

            $upd = $pdo->prepare(
                "UPDATE question_config SET section_no = :sn, display_order = :order, section = :sec, question_text = :txt, question_type = :typ, " .
                "answer_config = :ac, passing_answer = :pa, is_knockout = :ko, is_consent = :consent, is_data_protection = :data_protection, reminder_text = :rem, is_active = :active " .
                "WHERE question_id = :id"
            );
            $upd->execute([
                ':sn'      => (int) $new['section_no'],
                ':order'   => $displayOrder,
                ':sec'     => (string) $new['section'],
                ':txt'     => (string) $new['text'],
                ':typ'     => (string) $new['type'],
                ':ac'      => $answerConfig ? json_encode($answerConfig) : '{}',
                ':pa'      => $passing ? json_encode($passing) : null,
                ':ko'      => !empty($new['knockout']) ? 't' : 'f',
                ':consent' => ($new['type'] === 'consent') ? 't' : 'f',
                ':data_protection' => !empty($new['is_data_protection']) ? 't' : 'f',
                ':rem'     => $new['no_note'] ?? null,
                ':active'  => (($new['status'] ?? 'Active') === 'Active') ? 't' : 'f',
                ':id'      => $id,
            ]);

            if ($renamed) repo_log_activity('Section Renamed', 'Sec ' . $renamed['no'] . ' · ' . $renamed['from'] . ' → ' . $renamed['to']);
            repo_log_activity('Questionnaires Edited', 'Q' . $id . ' · ' . $new['section'] . ' · ' . mb_substr(trim($new['text']), 0, 110));

            admin_respond([
                'status'  => 'success',
                'message' => 'Question saved.',
                'result'  => $new,
                'data'    => ['questions' => repo_admin_questions_list(), 'accounts' => repo_admin_accounts(), 'archive' => repo_admin_archive()],
            ]);
            break;

        case 'question_reorder':
            $sectionNo = filter_var($input['section_no'] ?? null, FILTER_VALIDATE_INT);
            $orderedIds = $input['question_ids'] ?? null;
            if ($sectionNo === false || $sectionNo < 1 || !is_array($orderedIds) || count($orderedIds) < 2) {
                admin_respond(['status' => 'error', 'message' => 'Invalid question order.'], 422);
            }

            $ids = [];
            foreach ($orderedIds as $questionId) {
                if (!is_string($questionId) || !preg_match('/^q([1-9][0-9]*)$/D', $questionId, $match)) {
                    admin_respond(['status' => 'error', 'message' => 'Invalid question order.'], 422);
                }
                $ids[] = (int) $match[1];
            }
            if (count(array_unique($ids)) !== count($ids)) {
                admin_respond(['status' => 'error', 'message' => 'Invalid question order.'], 422);
            }

            $pdo = db();
            $secName = '';
            $sn = $pdo->prepare("SELECT section FROM question_config WHERE section_no = :s AND deleted_at IS NULL LIMIT 1");
            $sn->execute([':s' => $sectionNo]);
            $secName = (string) ($sn->fetchColumn() ?? '');

            $pdo->beginTransaction();
            try {
                $sectionQuestions = $pdo->prepare(
                    'SELECT question_id FROM question_config WHERE section_no = :section_no AND deleted_at IS NULL ORDER BY display_order, question_id FOR UPDATE'
                );
                $sectionQuestions->execute([':section_no' => $sectionNo]);
                $currentIds = array_map('intval', $sectionQuestions->fetchAll(PDO::FETCH_COLUMN));
                $expectedIds = $ids;
                sort($expectedIds);
                $actualIds = $currentIds;
                sort($actualIds);
                if ($expectedIds !== $actualIds) {
                    $pdo->rollBack();
                    admin_respond(['status' => 'error', 'message' => 'The questions in this section changed. Refresh and try again.'], 409);
                }

                $updateOrder = $pdo->prepare('UPDATE question_config SET display_order = :position WHERE question_id = :id AND section_no = :section_no AND deleted_at IS NULL');
                foreach ($ids as $position => $questionId) {
                    $updateOrder->execute([
                        ':position' => $position,
                        ':id' => $questionId,
                        ':section_no' => $sectionNo,
                    ]);
                }
                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                throw $e;
            }

            $orderedLabels = [];
            foreach ($ids as $position => $questionId) {
                $orderedLabels[] = 'Q' . $questionId . ($position + 1 === count($ids) ? '' : ' → ');
            }
            repo_log_activity(
                'Questions Reordered',
                'Sec ' . $sectionNo . ($secName !== '' ? ' · ' . $secName : '') . ' · ' . count($ids) . ' questions: ' . implode('', $orderedLabels)
            );
            admin_respond([
                'status' => 'success',
                'message' => 'Question order saved.',
                'result' => null,
                'data' => ['questions' => repo_admin_questions_list()],
            ]);
            break;

        case 'question_archive':
            $id = (int) ltrim((string) ($input['id'] ?? ''), 'q');
            $qst = db()->prepare("SELECT section FROM question_config WHERE question_id = :id"); $qst->execute([':id' => $id]); $qsec = (string) $qst->fetchColumn();
            if (!repo_question_archive($id)) admin_respond(['status' => 'error', 'message' => $missing], 404);
            repo_log_activity('Questionnaires Deleted', 'Q' . $id . ' · ' . $qsec . ' · removed from the questionnaire');
            $message = 'Question moved to the Archive.';
            break;
        case 'question_restore':
            $id = (int) ltrim((string) ($input['id'] ?? ''), 'q');
            $qst = db()->prepare("SELECT section FROM question_config WHERE question_id = :id"); $qst->execute([':id' => $id]); $qsec = (string) $qst->fetchColumn();
            if (!repo_question_restore($id)) admin_respond(['status' => 'error', 'message' => $missing], 404);
            repo_log_activity('Questionnaires Restored', 'Q' . $id . ' · ' . $qsec . ' · back on the questionnaire');
            $message = 'Question restored.';
            break;
        case 'question_purge':
            $id = (int) ltrim((string) ($input['id'] ?? ''), 'q');
            $qst = db()->prepare("SELECT section FROM question_config WHERE question_id = :id"); $qst->execute([':id' => $id]); $qsec = (string) $qst->fetchColumn();
            if (!repo_question_purge($id)) admin_respond(['status' => 'error', 'message' => $missing], 404);
            repo_log_activity('Questionnaires Deleted Forever', 'Q' . $id . ' · ' . $qsec . ' · removed for good');
            $message = 'Question deleted forever.';
            break;

        case 'notify':
            admin_respond(['status' => 'success', 'message' => '', 'result' => null, 'data' => ['notify' => repo_admin_notify()]]);
            break;

        default:
            admin_respond(['status' => 'error', 'message' => 'Unknown action.'], 400);
    }

    admin_respond([
        'status'  => 'success',
        'message' => $message,
        'result'  => null,
        'data'    => [
            'accounts'  => repo_admin_accounts(),
            'questions' => repo_admin_questions_list(),
            'archive'   => repo_admin_archive(),
        ],
    ]);

} catch (InvalidArgumentException $e) {
    admin_respond(['status' => 'error', 'message' => $e->getMessage()], 422);
} catch (Throwable $e) {
    error_log('admin-action.php error: ' . $e->getMessage());
    admin_respond(['status' => 'error', 'message' => 'Something went wrong. Please try again.'], 500);
}
