<?php
session_start();

$token = $_GET['token'] ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password - Expertise Management Portal</title>

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
                        <img src="../assets/images/expertise.png" alt="Expertise Logo">
            </div>
            <div class="portal-title">MANAGEMENT PORTAL</div>
        </div>

        <!-- Reset Password Card -->
        <div class="login-card">
            <div class="card-header">
                <h2>Reset Password</h2>
                <p>Choose a new password you do not use anywhere else.</p>
            </div>

            <div class="error-message" id="errorMessage"></div>
            <div class="success-message" id="successMessage" style="display:none;"></div>

            <?php if ($token === ''): ?>
                <p class="error-message" style="display:block;">No reset token supplied. Please use the link from the email.</p>
            <?php else: ?>
                <form id="resetForm" autocomplete="off">
                    <input type="hidden" name="token" value="<?php echo htmlspecialchars((string) $token, ENT_QUOTES, 'UTF-8'); ?>">

                    <div class="set-field reset-field" data-field="pwNew">
                        <label for="pwNew">New password</label>
                        <div class="set-input reset-input">
                            <svg viewBox="0 0 24 24" aria-hidden="true" class="input-icon"><path d="M12 2C9.24 2 7 4.24 7 7v2h2V7c0-1.66 1.34-3 3-3s3 1.34 3 3v2h2V7c0-2.76-2.24-5-5-5z"/><path d="M18 9H6c-1.1 0-2 .9-2 2v9c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2v-9c0-1.1-.9-2-2-2zm-6 7.5c-.83 0-1.5-.67-1.5-1.5s.67-1.5 1.5-1.5 1.5.67 1.5 1.5-.67 1.5-1.5 1.5z"/></svg>
                            <input type="password" id="pwNew" name="password" autocomplete="new-password" required>
                            <button type="button" class="eye eye-reset" aria-pressed="false" aria-label="Show new password"><svg class="eye-on" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 4.5C7 4.5 2.73 7.61 1 12c1.73 4.39 6 7.5 11 7.5s9.27-3.11 11-7.5c-1.73-4.39-6-7.5-11-7.5zM12 17c-2.76 0-5-2.24-5-5s2.24-5 5-5 5 2.24 5 5-2.24 5-5 5zm0-8c-1.66 0-3 1.34-3 3s1.34 3 3 3 3-1.34 3-3-1.34-3-3-3z"/></svg><svg class="eye-off" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 7c2.76 0 5 2.24 5 5 0 .65-.13 1.26-.36 1.83l2.92 2.92c1.51-1.26 2.7-2.89 3.43-4.75-1.73-4.39-6-7.5-11-7.5-1.4 0-2.74.25-3.98.7l2.16 2.16C10.74 7.13 11.35 7 12 7zM2 4.27l2.28 2.28.46.46C3.08 8.3 1.78 10.02 1 12c1.73 4.39 6 7.5 11 7.5 1.55 0 3.03-.3 4.38-.84l.42.42L19.73 22 21 20.73 3.27 3 2 4.27zM7.53 9.8l1.55 1.55c-.05.21-.08.43-.08.65 0 1.66 1.34 3 3 3 .22 0 .44-.03.65-.08l1.55 1.55c-.67.33-1.41.53-2.2.53-2.76 0-5-2.24-5-5 0-.79.2-1.53.53-2.2z"/></svg></button>
                        </div>
                        <div class="meter" id="pwMeter" data-level="0"><i></i><i></i><i></i><i></i></div>
                        <div class="meter-label" id="pwMeterLabel"></div>
                        <p class="set-error reset-error" role="alert"></p>
                    </div>

                    <ul class="pw-rules reset-rules">
                        <li data-rule="len"><span class="dot"></span>At least 8 characters</li>
                        <li data-rule="case"><span class="dot"></span>Upper and lower case letters</li>
                        <li data-rule="num"><span class="dot"></span>At least one number</li>
                    </ul>

                    <div class="set-field reset-field" data-field="pwConfirm">
                        <label for="pwConfirm">Confirm new password</label>
                        <div class="set-input reset-input">
                            <svg viewBox="0 0 24 24" aria-hidden="true" class="input-icon"><path d="M12 2C9.24 2 7 4.24 7 7v2h2V7c0-1.66 1.34-3 3-3s3 1.34 3 3v2h2V7c0-2.76-2.24-5-5-5z"/><path d="M18 9H6c-1.1 0-2 .9-2 2v9c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2v-9c0-1.1-.9-2-2-2zm-6 7.5c-.83 0-1.5-.67-1.5-1.5s.67-1.5 1.5-1.5 1.5.67 1.5 1.5-.67 1.5-1.5 1.5z"/></svg>
                            <input type="password" id="pwConfirm" name="confirm" autocomplete="new-password" required>
                            <button type="button" class="eye eye-reset" aria-pressed="false" aria-label="Show confirmation password"><svg class="eye-on" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 4.5C7 4.5 2.73 7.61 1 12c1.73 4.39 6 7.5 11 7.5s9.27-3.11 11-7.5c-1.73-4.39-6-7.5-11-7.5zM12 17c-2.76 0-5-2.24-5-5s2.24-5 5-5 5 2.24 5 5-2.24 5-5 5zm0-8c-1.66 0-3 1.34-3 3s1.34 3 3 3 3-1.34 3-3-1.34-3-3-3z"/></svg><svg class="eye-off" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 7c2.76 0 5 2.24 5 5 0 .65-.13 1.26-.36 1.83l2.92 2.92c1.51-1.26 2.7-2.89 3.43-4.75-1.73-4.39-6-7.5-11-7.5-1.4 0-2.74.25-3.98.7l2.16 2.16C10.74 7.13 11.35 7 12 7zM2 4.27l2.28 2.28.46.46C3.08 8.3 1.78 10.02 1 12c1.73 4.39 6 7.5 11 7.5 1.55 0 3.03-.3 4.38-.84l.42.42L19.73 22 21 20.73 3.27 3 2 4.27zM7.53 9.8l1.55 1.55c-.05.21-.08.43-.08.65 0 1.66 1.34 3 3 3 .22 0 .44-.03.65-.08l1.55 1.55c-.67.33-1.41.53-2.2.53-2.76 0-5-2.24-5-5 0-.79.2-1.53.53-2.2z"/></svg></button>
                        </div>
                        <p class="set-error reset-error" role="alert"></p>
                    </div>

                    <div class="modal-actions reset-actions">
                        <button type="button" class="btn-cancel reset-btn-cancel" id="resetCancel">Cancel</button>
                        <button type="submit" class="btn-blue reset-btn-reset" id="resetBtn">Reset password</button>
                    </div>
                </form>

                <p class="forgot-link"><a href="index.php">&larr; Back to login</a></p>
            <?php endif; ?>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const form = document.getElementById('resetForm');
            if (!form) return;
            const btn = document.getElementById('resetBtn');
            const cancel = document.getElementById('resetCancel');
            const error = document.getElementById('errorMessage');
            const success = document.getElementById('successMessage');
            const pwNew = document.getElementById('pwNew');
            const pwConfirm = document.getElementById('pwConfirm');
            const meter = document.getElementById('pwMeter');
            const meterLabel = document.getElementById('pwMeterLabel');
            const ruleEls = {
                len: form.querySelector('[data-rule="len"] .dot'),
                case: form.querySelector('[data-rule="case"] .dot'),
                num: form.querySelector('[data-rule="num"] .dot')
            };
            const ruleLabels = {
                len: form.querySelector('[data-rule="len"]'),
                case: form.querySelector('[data-rule="case"]'),
                num: form.querySelector('[data-rule="num"]')
            };

            // Toggle password visibility
            ['pwNew', 'pwConfirm'].forEach(id => {
                const input = document.getElementById(id);
                const eye = input.parentElement.querySelector('.eye');
                eye.addEventListener('click', () => {
                    const isHidden = input.type === 'password';
                    input.type = isHidden ? 'text' : 'password';
                    eye.setAttribute('aria-pressed', isHidden ? 'true' : 'false');
                });
            });

            function checks(v) {
                return {
                    len: v.length >= 8,
                    case: /[a-z]/.test(v) && /[A-Z]/.test(v),
                    num: /\d/.test(v)
                };
            }

            function score(v) {
                const c = checks(v);
                let s = [c.len, c.case, c.num].filter(Boolean).length;
                if (s === 3 && (v.length >= 12 || /[^A-Za-z0-9]/.test(v))) s = 4;
                return v ? Math.max(1, s) : 0;
            }

            function paint() {
                const v = pwNew.value;
                const c = checks(v);
                Object.keys(ruleEls).forEach(k => {
                    const ok = c[k];
                    ruleEls[k].parentElement.classList.toggle('ok', ok);
                    ruleEls[k].style.background = ok ? '#2e7d32' : '#dcdcdc';
                });
                const s = score(v);
                meter.dataset.level = String(s);
                meterLabel.textContent = ['', 'Weak', 'Okay', 'Good', 'Strong'][s];
                meter.querySelectorAll('i').forEach((i, idx) => {
                    i.style.background = idx < s ? (['#e60000', '#e29a00', '#7cb518', '#2e7d32'][s - 1] || '#2e7d32') : '#dcdcdc';
                });
            }

            pwNew.addEventListener('input', () => {
                paint();
                if (pwConfirm.value) {
                    const match = pwConfirm.value === pwNew.value;
                    pwConfirm.parentElement.querySelector('.reset-error').textContent = match ? '' : 'The passwords do not match.';
                }
            });
            pwConfirm.addEventListener('input', () => {
                pwConfirm.parentElement.querySelector('.reset-error').textContent = pwConfirm.value === pwNew.value ? '' : 'The passwords do not match.';
            });

            cancel.addEventListener('click', () => { window.location.href = 'index.php'; });

            form.addEventListener('submit', async (e) => {
                e.preventDefault();
                if (error) error.style.display = 'none';
                if (success) { success.style.display = 'none'; success.textContent = ''; }
                pwNew.parentElement.querySelector('.reset-error').textContent = '';
                pwConfirm.parentElement.querySelector('.reset-error').textContent = '';

                const c = checks(pwNew.value);
                if (!c.len || !c.case || !c.num) {
                    pwNew.parentElement.querySelector('.reset-error').textContent = 'The password does not meet all the requirements.';
                    pwNew.focus();
                    return;
                }
                if (!pwConfirm.value || pwConfirm.value !== pwNew.value) {
                    pwConfirm.parentElement.querySelector('.reset-error').textContent = 'Please enter the same password in both fields.';
                    pwConfirm.focus();
                    return;
                }

                const label = btn.dataset.label || btn.textContent;
                btn.disabled = true;
                btn.textContent = 'Resetting...';

                try {
                    const res = await fetch('../api/password-reset.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        credentials: 'same-origin',
                        body: JSON.stringify({
                            action: 'reset',
                            token: form.elements.token.value,
                            password: pwNew.value,
                            confirm: pwConfirm.value
                        })
                    });
                    const data = await res.json().catch(() => ({ status: 'error', message: 'The server did not answer correctly.' }));
                    if (data.status !== 'success') {
                        showError(data.message || 'Something went wrong. Please try again.');
                        return;
                    }
                    if (success) {
                        success.textContent = data.message || 'Your password has been reset.';
                        success.style.display = 'block';
                    }
                    btn.disabled = true;
                    btn.textContent = 'Done';
                } catch (err) {
                    showError('Could not reach the server. Please check your connection and try again.');
                } finally {
                    if (!btn.disabled) { btn.disabled = false; btn.textContent = label; }
                }
            });

            function showError(message) {
                if (error) {
                    error.textContent = message;
                    error.style.display = 'block';
                }
            }

            paint();
        });
    </script>
</body>
</html>
