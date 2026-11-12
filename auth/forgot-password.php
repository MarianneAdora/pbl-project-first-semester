<?php
session_start();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password - Expertise Management Portal</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:ital,wght@0,400;0,500;0,600;0,700;0,800;1,400&display=swap" rel="stylesheet">

    <link rel="stylesheet" href="../assets/css/style.css?v=<?php echo @filemtime(__DIR__ . '/../assets/css/style.css'); ?>">
</head>
<body>

    <div class="portal-wrapper">
        <!-- Logo Header -->
        <div class="logo-container">
            <div class="logo-crop">
                <img src="assets/images/expertise.png" alt="Expertise Logo">
            </div>
            <div class="portal-title">MANAGEMENT PORTAL</div>
        </div>

        <!-- Reset Request Card -->
        <div class="login-card">
            <div class="card-header">
                <h2>Forgot Password?</h2>
                <p>Enter your email address and we will send you a reset link.</p>
            </div>

            <div class="error-message" id="errorMessage"></div>
            <div class="success-message" id="successMessage" style="display:none;"></div>

            <form id="forgotForm" autocomplete="off">
                <div class="form-group">
                    <label for="email">Email</label>
                    <input type="email" id="email" name="email" class="form-control" required>
                </div>

                <button type="submit" class="btn-submit" id="resetBtn">Send reset link</button>
            </form>

            <p class="forgot-link"><a href="../index.php">&larr; Back to login</a></p>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const form = document.getElementById('forgotForm');
            const btn = document.getElementById('resetBtn');
            const error = document.getElementById('errorMessage');
            const success = document.getElementById('successMessage');

            form.addEventListener('submit', async (e) => {
                e.preventDefault();
                if (error) error.style.display = 'none';
                if (success) { success.style.display = 'none'; success.textContent = ''; }
                const label = btn.dataset.label || btn.textContent;
                btn.disabled = true;
                btn.textContent = 'Sending...';

                try {
                    const res = await fetch('../api/password-reset.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        credentials: 'same-origin',
                        body: JSON.stringify({ action: 'request', email: form.elements.email.value })
                    });
                    const data = await res.json().catch(() => ({ status: 'error', message: 'The server did not answer correctly.' }));
                    if (data.status !== 'success') {
                        showError(data.message || 'Something went wrong. Please try again.');
                        return;
                    }
                    // Generic answer: never reveals whether the email exists.
                    if (success) {
                        success.textContent = data.message || 'If the email exists, a reset link has been sent.';
                        success.style.display = 'block';
                    }
                    form.reset();
                } catch (err) {
                    showError('Could not reach the server. Please check your connection and try again.');
                } finally {
                    btn.disabled = false;
                    btn.textContent = label;
                }
            });

            function showError(message) {
                if (error) {
                    error.textContent = message;
                    error.style.display = 'block';
                }
            }
        });
    </script>
</body>
</html>
