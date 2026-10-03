<?php
session_start();

$credentials = [
    'hr@expertise.com' => [
        'password' => 'hr123',
        'role' => 'hr',
        'redirect' => 'hr/dashboardhr.php'
    ],
    'admin@expertise.com' => [
        'password' => 'admin123',
        'role' => 'admin',
        'redirect' => 'admindashboardadmin.php'
    ]
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    
    $email = filter_var(trim($_POST['email'] ?? ''), FILTER_SANITIZE_EMAIL);
    $password = $_POST['password'] ?? '';

    if (isset($credentials[$email]) && $credentials[$email]['password'] === $password) {
        $_SESSION['user_role'] = $credentials[$email]['role'];
        $_SESSION['user_email'] = $email;
        
        echo json_encode([
            'status' => 'success',
            'redirect' => $credentials[$email]['redirect']
        ]);
        exit();
    } else {
        echo json_encode([
            'status' => 'error',
            'message' => 'Invalid email address or password.'
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
    <!-- Plus Jakarta Sans Google Font -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:ital,wght@0,400;0,500;0,600;0,700;0,800;1,400&display=swap" rel="stylesheet">
    
    <link rel="stylesheet" href="assets/css/style.css">
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
            <div class="logo-crop">
                <img src="pic/expertise.png" alt="Expertise Logo">
            </div>
            <div class="portal-title">MANAGEMENT PORTAL</div>
        </div>

        <!-- Login Card -->
        <div class="login-card">
            <div class="card-header">
                <h2>Welcome Back!</h2>
                <p>Sign in to access your administrative workspace.</p>
            </div>

            <div class="error-message" id="errorMessage"></div>

            <form id="loginForm" method="POST" autocomplete="off">
                <div class="form-group">
                    <label for="email">Email</label>
                    <input type="email" id="email" name="email" class="form-control" required>
                </div>

                <div class="form-group">
                    <label for="password">Password</label>
                    <div class="input-wrapper">
                        <input type="password" id="password" name="password" class="form-control" required>
                        <svg class="eye-icon" id="togglePassword" viewBox="0 0 24 24">
                            <path d="M12 4.5C7 4.5 2.73 7.61 1 12c1.73 4.39 6 7.5 11 7.5s9.27-3.11 11-7.5c-1.73-4.39-6-7.5-11-7.5zM12 17c-2.76 0-5-2.24-5-5s2.24-5 5-5 5 2.24 5 5-2.24 5-5 5zm0-8c-1.66 0-3 1.34-3 3s1.34 3 3 3 3-1.34 3-3-1.34-3-3-3z"/>
                        </svg>
                    </div>
                </div>

                <div class="remember-wrapper">
                    <label class="remember-label" for="remember">
                        <span>Remember me</span>
                        <input type="checkbox" name="remember" id="remember">
                    </label>
                </div>

                <button type="submit" class="btn-submit">LOGIN</button>
            </form>
        </div>
    </div>

    <script src="assets/js/script.js"></script>
</body>
</html>