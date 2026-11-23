<?php
declare(strict_types=1);
require_once __DIR__ . '/src/Helpers/security.php';
require_once __DIR__ . '/src/Helpers/db.php';

start_secure_session(1800);
send_security_headers(false);

$rememberCookieSecure = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https');
$rememberCookieOptions = [
    'path' => '/',
    'secure' => $rememberCookieSecure,
    'httponly' => true,
    'samesite' => 'Lax',
];

// Arriving from "Log out": end the old session so the dashboards can't be reopened with Back
if (($_GET['transition'] ?? '') === 'out') {
    $logoutUserId = (int) ($_SESSION['user_id'] ?? 0);
    if ($logoutUserId > 0) {
        try {
            db()->prepare("UPDATE users SET remember_token = NULL, remember_token_expires_at = NULL WHERE user_id = :id")
                ->execute([':id' => $logoutUserId]);
        } catch (Throwable $e) {
            error_log('Remember-me token revocation failed during logout: ' . $e->getMessage());
        }
    }
    $_SESSION = [];
    session_regenerate_id(true);
    setcookie('remember', '', $rememberCookieOptions + ['expires' => time() - 3600]);
}

// Generate the form token after logout has reset the session.
$csrf = csrf_token();

// Login accounts now come from the database (users table): HR staff + Admin.
require_once __DIR__ . '/src/Helpers/db.php';

$credentials = [];
$pdo = null;
try {
    $pdo = db();
    $rows = $pdo->query(
        "SELECT user_id, role, name, email, password_hash FROM users WHERE deleted_at IS NULL AND status = 'Active' ORDER BY user_id"
    )->fetchAll();
    foreach ($rows as $r) {
        $role  = (string) ($r['role'] ?? '');
        $email = strtolower(trim((string) ($r['email'] ?? '')));
        if ($email === '' || !in_array($role, ['hr', 'admin'], true)) continue;
        $credentials[$email] = [
            'hash'     => (string) ($r['password_hash'] ?? ''),
            'role'     => $role,
            'name'     => (string) ($r['name'] ?? ''),
            'redirect' => $role === 'admin' ? 'admin/dashboard-admin.php' : 'hr/dashboard-hr.php',
            'user_id'  => (int) ($r['user_id'] ?? 0),
        ];
    }
    } catch (Throwable $e) {
        error_log('index.php DB login load failed: ' . $e->getMessage());
        $credentials = [];
    }

    if (empty($_SESSION['user_role']) && isset($_COOKIE['remember'])) restore_remembered_session();

    // Already logged in (session or restored via remember me): go straight to the dashboard
    if (!empty($_SESSION['user_role'])) {
        $redirect = $_SESSION['user_role'] === 'admin' ? 'admin/dashboard-admin.php' : 'hr/dashboard-hr.php';
        header('Location: ' . $redirect);
        exit();
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        header('Content-Type: application/json');

        // Rate limit login attempts (5 attempts per 15 minutes per IP)
        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
        $rl = rate_limit('login:' . $ip, 5, 15 * 60);
        if (!$rl['allowed']) {
            $seconds = (int) $rl['retry_after'];
            $waitText = $seconds >= 60
                ? ceil($seconds / 60) . ' minute' . (ceil($seconds / 60) > 1 ? 's' : '')
                : ($seconds > 0 ? $seconds . ' second' . ($seconds > 1 ? 's' : '') : 'now');
            http_response_code(429);
            echo json_encode([
                'status' => 'error',
                'message' => 'Too many login attempts. Please try again in ' . $waitText . '.',
                'retry_after' => $seconds,
            ]);
            exit();
        }

        $email = filter_var(trim($_POST['email'] ?? ''), FILTER_SANITIZE_EMAIL);
    $password = $_POST['password'] ?? '';

    // Fallback: some web setups disable $_POST population (enable_post_data_reading=Off);
    // parse the raw body so login still works.
    if (($email === '' || $password === '') && ($raw = @file_get_contents('php://input')) !== false) {
        $body = [];
        parse_str($raw, $body);
        if ($email === '') $email = filter_var(trim($body['email'] ?? ''), FILTER_SANITIZE_EMAIL);
        if ($password === '') $password = $body['password'] ?? '';
    }

    if (strlen($email) > 150) $email = '';
    $email = strtolower($email);
    $account = $credentials[$email] ?? null;
    $passwordOk = false;
    if ($account) {
        if (!empty($account['hash'])) $passwordOk = password_verify($password, $account['hash']);          // accounts the Admin created
        elseif (isset($account['password'])) $passwordOk = hash_equals((string) $account['password'], (string) $password);
    }

    if ($account && $passwordOk) {
        rotate_session();
        $_SESSION['user_role'] = $credentials[$email]['role'];
        $_SESSION['user_email'] = $email;
        $_SESSION['user_name'] = $credentials[$email]['name'];
        $_SESSION['user_id']   = $credentials[$email]['user_id'] ?? null;

        // Consume the one-time CSRF token on successful login
        csrf_consume($csrfIn);

        // "Remember me": issue a long-lived token cookie + store its hash in the DB
        $remember = !empty($_POST['remember']);
        if ($remember && $pdo instanceof PDO && !empty($credentials[$email]['user_id'])) {
            $uid     = (int) $credentials[$email]['user_id'];
            $token   = bin2hex(random_bytes(32));
            $tokenHash = hash('sha256', $token);
            $expires = date('Y-m-d H:i:s', time() + 30 * 24 * 3600);
            $pdo->prepare(
                "UPDATE users SET remember_token = :hash, remember_token_expires_at = :expires WHERE user_id = :id"
            )->execute([':hash' => $tokenHash, ':expires' => $expires, ':id' => $uid]);
            setcookie('remember', $uid . '|' . $token, $rememberCookieOptions + ['expires' => time() + 30 * 24 * 3600]);
        } elseif (!$remember && $pdo instanceof PDO && !empty($credentials[$email]['user_id'])) {
            // User explicitly did NOT check remember me: clear any existing token
            $pdo->prepare(
                "UPDATE users SET remember_token = NULL, remember_token_expires_at = NULL WHERE user_id = :id"
            )->execute([':id' => (int) $credentials[$email]['user_id']]);
            setcookie('remember', '', $rememberCookieOptions + ['expires' => time() - 3600]);
        }

        echo json_encode([
            'status' => 'success',
            'redirect' => $credentials[$email]['redirect']
        ]);
        exit();
    } else {
        echo json_encode([
            'status' => 'error',
            'message' => 'Invalid email address or password.',
            'csrf_token' => csrf_token(),
        ]);
        exit();
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Expertise Management Portal</title>

    <!-- Runs before first paint so the screen is already covered when arriving via transition -->
    <script>
        document.documentElement.classList.add('js');
        if (new URLSearchParams(location.search).get('transition') === 'out') {
            document.documentElement.classList.add('is-transitioning');
        }
    </script>

    <!-- Plus Jakarta Sans Google Font -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:ital,wght@0,400;0,500;0,600;0,700;0,800;1,400&display=swap" rel="stylesheet">

    <link rel="stylesheet" href="assets/css/style.css?v=<?php echo @filemtime(__DIR__ . '/assets/css/style.css'); ?>">
</head>
<body>

    <!-- 8-Stripe Transition Overlay -->
    <div class="page-overlay" id="pageOverlay">
        <div class="overlay-stripe stripe-1"></div>
        <div class="overlay-stripe stripe-2"></div>
        <div class="overlay-stripe stripe-3"></div>
        <div class="overlay-stripe stripe-4"></div>
        <div class="overlay-stripe stripe-5"></div>
        <div class="overlay-stripe stripe-6"></div>
        <div class="overlay-stripe stripe-7"></div>
        <div class="overlay-stripe stripe-8"></div>
    </div>

    <div class="portal-wrapper">
        <!-- Logo Header -->
        <div class="logo-container">
            <div class="logo-crop reveal" style="--i:0">
                <img src="assets/images/expertise.png" alt="Expertise Logo">
            </div>
            <div class="portal-title reveal" style="--i:1">MANAGEMENT PORTAL</div>
        </div>

        <!-- Login Card -->
        <div class="login-card reveal" style="--i:2">
            <div class="card-header reveal" style="--i:3">
                <h2>Welcome Back!</h2>
                <p>Sign in to access your administrative workspace.</p>
            </div>

            <div class="error-message" id="errorMessage"></div>

            <form id="loginForm" method="POST" autocomplete="off" novalidate>
    <input type="hidden" name="csrf_token" value="<?= attr($csrf) ?>">
                <div class="form-group reveal" style="--i:4">
                    <label for="email">Email</label>
                    <input type="email" id="email" name="email" class="form-control" maxlength="150" placeholder="name@example.com" aria-describedby="emailError" required>
                    <p class="field-error" id="emailError" role="alert"></p>
                </div>

                <div class="form-group reveal" style="--i:5">
                    <label for="password">Password</label>
                    <div class="input-wrapper">
                        <input type="password" id="password" name="password" class="form-control" placeholder="Enter your password" aria-describedby="passwordError" required>
                        <svg class="eye-icon" id="togglePassword" viewBox="0 0 24 24">
                            <path d="M12 4.5C7 4.5 2.73 7.61 1 12c1.73 4.39 6 7.5 11 7.5s9.27-3.11 11-7.5c-1.73-4.39-6-7.5-11-7.5zM12 17c-2.76 0-5-2.24-5-5s2.24-5 5-5 5 2.24 5 5-2.24 5-5 5zm0-8c-1.66 0-3 1.34-3 3s1.34 3 3 3 3-1.34 3-3-1.34-3-3-3z"/>
                        </svg>
                    </div>
                    <p class="field-error" id="passwordError" role="alert"></p>
                </div>

                <div class="remember-wrapper reveal" style="--i:6">
                    <label class="remember-label" for="remember">
                        <span>Remember me</span>
                        <input type="checkbox" name="remember" id="remember">
                    </label>
                </div>

                <button type="submit" class="btn-submit reveal" style="--i:7">LOGIN</button>
            </form>

            <p class="forgot-link"><a href="auth/forgot-password.php">Forgot Password?</a></p>
        </div>
    </div>

    <script src="assets/js/script.js?v=<?php echo @filemtime(__DIR__ . '/assets/js/script.js'); ?>"></script>
</body>
</html>