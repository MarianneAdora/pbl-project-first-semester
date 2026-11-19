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
require_once __DIR__ . '/../src/Helpers/admin_mfa.php';
require_once __DIR__ . '/../src/Helpers/db.php';
require_once __DIR__ . '/../hr/hr-data.php';

function admin_respond(array $data, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function admin_action_hr_name_error(string $name): ?string
{
    if ($name === '') return 'Please enter the full name.';
    if (mb_strlen($name, 'UTF-8') > 100) return 'The name is too long (100 characters maximum).';
    if (preg_match('/\d/', $name) || !preg_match("/^[\\p{L}\\p{M}'.\\- ]+$/u", $name)) {
        return 'Use letters only (spaces, apostrophes, periods and hyphens are fine).';
    }
    if (preg_match_all('/\p{L}/u', $name) < 2) return 'The name is too short (at least 2 letters).';
    return null;
}

function admin_action_hr_email_error(string $email): ?string
{
    if (strlen($email) > 150 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return 'Please enter a valid email address (150 characters maximum).';
    }
    return null;
}

function admin_action_hr_password_error(string $password): ?string
{
    if (strlen($password) > 72) return 'Password must be 72 bytes or fewer.';
    if (preg_match('/\s/', $password)) return 'The password cannot contain spaces.';
    if (strlen($password) < 8 || !preg_match('/[A-Za-z]/', $password) || !preg_match('/\d/', $password)) {
        return 'Password must be at least 8 characters with letters and a number.';
    }
    return null;
}

function admin_action_hr_create_data(array $account): array
{
    $name = trim((string) ($account['name'] ?? ''));
    $email = strtolower(trim((string) ($account['email'] ?? '')));
    $password = (string) ($account['password'] ?? '');
    $passwordConfirm = (string) ($account['password_confirm'] ?? '');
    $status = ($account['status'] ?? 'Active') === 'Inactive' ? 'Inactive' : 'Active';
    $sendMail = !empty($account['send_email']);

    $nameError = admin_action_hr_name_error($name);
    if ($nameError !== null) admin_respond(['status' => 'error', 'message' => $nameError], 422);
    $emailError = admin_action_hr_email_error($email);
    if ($emailError !== null) admin_respond(['status' => 'error', 'message' => $emailError], 422);
    $passwordError = admin_action_hr_password_error($password);
    if ($passwordError !== null) admin_respond(['status' => 'error', 'message' => $passwordError], 422);
    if ($passwordConfirm !== $password) {
        admin_respond(['status' => 'error', 'message' => 'The passwords do not match.'], 422);
    }

    return ['name' => $name, 'email' => $email, 'password' => $password, 'status' => $status, 'send_email' => $sendMail];
}

// CSRF + auth guard for all state-changing POSTs
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    admin_respond(['status' => 'error', 'message' => 'Invalid request.'], 405);
}
$json  = json_decode((string) file_get_contents('php://input'), true);
$input = is_array($json) ? $json : $_POST;
$action = (string) ($input['action'] ?? '');

$csrfIn = (string) ($input['csrf_token'] ?? '');
if (($_SESSION['user_role'] ?? '') !== 'admin') {
    admin_respond(['status' => 'error', 'message' => 'Your session has ended. Please log in again.'], 403);
}
if (!csrf_verify($csrfIn)) {
    admin_respond([
        'status' => 'error',
        'message' => 'Invalid CSRF token. Please try again.',
        'csrf_token' => csrf_token(),
    ], 403);
}

try {
    $missing = 'That item was not found. It may have been removed already.';
    $missing = 'That item was not found. It may have been removed already.';

    switch ($action) {
        case 'account_add_mfa_send':
            $acc = (array) ($input['account'] ?? []);
            $account = admin_action_hr_create_data($acc);
            $adminPassword = (string) ($acc['admin_password'] ?? '');
            if ($adminPassword === '' || strlen($adminPassword) > 1024) {
                admin_respond(['status' => 'error', 'message' => 'Enter your current Admin password to continue.'], 422);
            }

            $adminId = (int) ($_SESSION['user_id'] ?? 0);
            if ($adminId <= 0) {
                admin_respond(['status' => 'error', 'message' => 'Your session has ended. Please log in again.'], 403);
            }
            $sendLimit = rate_limit('admin-hr-mfa-send:' . $adminId, 5, 15 * 60);
            if (!$sendLimit['allowed']) {
                admin_respond([
                    'status' => 'error',
                    'message' => 'Too many verification codes were requested. Try again in ' . $sendLimit['retry_after'] . ' seconds.',
                ], 429);
            }

            $pdo = db();
            $adminStmt = $pdo->prepare(
                "SELECT name, email, password_hash FROM users
                 WHERE user_id = :id AND role = 'admin' AND status = 'Active' AND deleted_at IS NULL"
            );
            $adminStmt->execute([':id' => $adminId]);
            $admin = $adminStmt->fetch();
            if (!$admin || !password_verify($adminPassword, (string) ($admin['password_hash'] ?? ''))) {
                admin_respond(['status' => 'error', 'message' => 'The current Admin password is incorrect.'], 403);
            }

            $exists = $pdo->prepare("SELECT 1 FROM users WHERE email = :e AND deleted_at IS NULL");
            $exists->execute([':e' => $account['email']]);
            if ($exists->fetchColumn()) {
                admin_respond(['status' => 'error', 'message' => 'That email address is already in use.'], 422);
            }

            $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            $challengeToken = admin_mfa_issue_challenge($pdo, $adminId, session_id(), $code, $account);
            $emailService = new EmailService();
            if (!$emailService->sendAdminHrMfaEmail($account['email'], $account['name'], $code)) {
                admin_mfa_delete_challenge($pdo, $challengeToken);
                error_log('Admin HR creation MFA email failed: ' . ($emailService->lastFailure() ?? 'unknown'));
                admin_respond(['status' => 'error', 'message' => 'Could not send the verification code. Check email configuration and try again.'], 502);
            }

            admin_mfa_invalidate_other_challenges($pdo, $adminId, session_id(), $challengeToken);
            admin_respond([
                'status' => 'success',
                'message' => 'A verification code was sent to the email address entered for this HR account.',
                'result' => [
                    'challenge_token' => $challengeToken,
                    'email' => admin_mfa_mask_email($account['email']),
                ],
            ]);
            break;

        case 'account_add':
            $acc = (array) ($input['account'] ?? []);
            $account = admin_action_hr_create_data($acc);
            $adminId = (int) ($_SESSION['user_id'] ?? 0);
            if ($adminId <= 0) {
                admin_respond(['status' => 'error', 'message' => 'Your session has ended. Please log in again.'], 403);
            }
            $pdo = db();
            $activeAdmin = $pdo->prepare(
                "SELECT 1 FROM users WHERE user_id = :id AND role = 'admin' AND status = 'Active' AND deleted_at IS NULL"
            );
            $activeAdmin->execute([':id' => $adminId]);
            if (!$activeAdmin->fetchColumn()) {
                admin_respond(['status' => 'error', 'message' => 'Your Admin account is no longer active.'], 403);
            }

            $exists = $pdo->prepare("SELECT 1 FROM users WHERE email = :e AND deleted_at IS NULL");
            $exists->execute([':e' => $account['email']]);
            if ($exists->fetchColumn()) admin_respond(['status' => 'error', 'message' => 'That email address is already in use.'], 422);

            $verification = admin_mfa_verify_challenge(
                $pdo,
                $adminId,
                session_id(),
                (string) ($input['mfa_challenge'] ?? ''),
                (string) ($input['mfa_code'] ?? ''),
                $account
            );
            if (!$verification['ok']) {
                $statusCode = !empty($verification['locked'])
                    ? 429
                    : (strpos(strtolower($verification['error']), 'invalid') !== false ? 403 : 422);
                admin_respond(['status' => 'error', 'message' => $verification['error']], $statusCode);
            }

            $hash = password_hash($account['password'], PASSWORD_DEFAULT);
            $ins = $pdo->prepare("INSERT INTO users (name, email, email_normalized, password_hash, role, status, created_at) VALUES (:n, :e, :en, :p, 'hr', :s, NOW()) RETURNING user_id");
            $ins->execute([
                ':n' => $account['name'],
                ':e' => $account['email'],
                ':en' => $account['email'],
                ':p' => $hash,
                ':s' => $account['status'],
            ]);
            $newId = (int) $ins->fetchColumn();
            repo_log_activity('HR Account Added', $account['name'] . ' (HR) · ' . $account['email']);

            $emailed = false;
            if ($account['send_email']) {
                $emailed = (new EmailService())->sendHrCredentialsEmail($account['email'], $account['name'], $account['password']);
            }

            admin_respond([
                'status'  => 'success',
                'message' => 'HR account created.',
                'result'  => ['id' => $newId, 'name' => $account['name'], 'email' => $account['email'], 'role' => 'HR Staff', 'emailed' => $emailed],
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
            $nameError = admin_action_hr_name_error($name);
            if ($nameError !== null) admin_respond(['status' => 'error', 'message' => $nameError], 422);
            $emailError = admin_action_hr_email_error($email);
            if ($emailError !== null) admin_respond(['status' => 'error', 'message' => $emailError], 422);

            $pdo = db();
            $dup = $pdo->prepare("SELECT 1 FROM users WHERE email = :e AND user_id != :id AND deleted_at IS NULL");
            $dup->execute([':e' => $email, ':id' => $id]);
            if ($dup->fetchColumn()) admin_respond(['status' => 'error', 'message' => 'That email address is already in use.'], 422);

            $en = strtolower(trim($email));
            if ($password !== '') {
                $passwordError = admin_action_hr_password_error($password);
                if ($passwordError !== null) admin_respond(['status' => 'error', 'message' => $passwordError], 422);
                if ((string) ($acc['password_confirm'] ?? '') !== $password) {
                    admin_respond(['status' => 'error', 'message' => 'The passwords do not match.'], 422);
                }
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
            $pdo = db();
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
            $notify = repo_admin_notify();
            admin_respond(['status' => 'success', 'message' => '', 'result' => null, 'data' => ['notify' => $notify]]);
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
