document.addEventListener('DOMContentLoaded', () => {
    const root = document.documentElement;
    const loginForm = document.getElementById('loginForm');
    const togglePassword = document.getElementById('togglePassword');
    const passwordInput = document.getElementById('password');
    const errorMessage = document.getElementById('errorMessage');
    const pageOverlay = document.getElementById('pageOverlay');
    const submitBtn = loginForm ? loginForm.querySelector('.btn-submit') : null;

    // 1. Page entrance
    const urlParams = new URLSearchParams(window.location.search);
    const arrivedViaTransition = urlParams.get('transition') === 'out' && pageOverlay;

    if (arrivedViaTransition) {
        // Page is already covered (set in <head>). Start the stripes sweeping out and
        // let the elements rise in just behind them.
        root.style.setProperty('--reveal-base', '0.3s');

        // Wait for the first painted frame so the solid red is actually shown first
        requestAnimationFrame(() => {
            requestAnimationFrame(() => {
                root.classList.remove('is-transitioning');
                pageOverlay.classList.add('sweep-out');
                root.classList.add('is-ready');
            });
        });

        // Clean up URL parameter without page refresh
        window.history.replaceState({}, document.title, window.location.pathname);
    } else {
        // Normal visit: elements rise in right away
        requestAnimationFrame(() => root.classList.add('is-ready'));
    }

    // 2. Toggle password visibility
    if (togglePassword && passwordInput) {
        togglePassword.addEventListener('click', () => {
            const isHidden = passwordInput.type === 'password';
            passwordInput.type = isHidden ? 'text' : 'password';
        });
    }

    // 3. AJAX login: elements lift away while the stripes sweep in, then redirect
    if (loginForm) {
        loginForm.addEventListener('submit', (e) => {
            e.preventDefault();
            if (errorMessage) errorMessage.style.display = 'none';
            if (submitBtn) submitBtn.disabled = true;

            fetch('index.php', { method: 'POST', body: new FormData(loginForm) })
                .then(response => response.json())
                .then(data => {
                    if (data.status !== 'success') {
                        showError(data.message);
                        return;
                    }

                    let redirected = false;
                    const go = () => {
                        if (redirected) return;
                        redirected = true;
                        window.location.href = data.redirect + '?transition=out';
                    };

                    // Exit animation for the content + stripes together
                    root.classList.add('is-leaving');
                    pageOverlay.classList.remove('sweep-out');
                    pageOverlay.classList.add('sweep-in');

                    // Redirect once all 8 stripes have finished (with a safety fallback)
                    let finished = 0;
                    const stripes = pageOverlay.querySelectorAll('.overlay-stripe');
                    stripes.forEach(stripe => {
                        stripe.addEventListener('animationend', () => {
                            finished++;
                            if (finished === stripes.length) go();
                        }, { once: true });
                    });
                    setTimeout(go, 1200);
                })
                .catch(() => showError('An unexpected error occurred. Please try again.'));
        });
    }

    // 4. Browser back button (page restored from cache): reset the leaving state
    window.addEventListener('pageshow', (event) => {
        if (!event.persisted || !pageOverlay) return;
        root.classList.remove('is-leaving', 'is-transitioning');
        pageOverlay.classList.remove('sweep-in', 'sweep-out');
        if (submitBtn) submitBtn.disabled = false;
    });

    function showError(message) {
        if (errorMessage) {
            errorMessage.textContent = message;
            errorMessage.style.display = 'block';
        }
        if (submitBtn) submitBtn.disabled = false;
    }
});