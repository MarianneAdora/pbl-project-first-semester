document.addEventListener('DOMContentLoaded', () => {
    const loginForm = document.getElementById('loginForm');
    const togglePassword = document.getElementById('togglePassword');
    const passwordInput = document.getElementById('password');
    const errorMessage = document.getElementById('errorMessage');
    const pageOverlay = document.getElementById('pageOverlay');

    // 1. On Page Load: Start fully covered, then smoothly sweep OUT to reveal page
    const urlParams = new URLSearchParams(window.location.search);
    if (urlParams.get('transition') === 'out' && pageOverlay) {
        // Hold full solid red screen initially while DOM loads
        pageOverlay.classList.add('hold-solid');

        // Brief loading pause, then trigger outward sweep to reveal login page
        setTimeout(() => {
            pageOverlay.classList.remove('hold-solid');
            pageOverlay.classList.add('sweep-out');
        }, 200);

        // Clean up URL parameter without page refresh
        window.history.replaceState({}, document.title, window.location.pathname);
    }

    // 2. Toggle Password Visibility
    if (togglePassword && passwordInput) {
        togglePassword.addEventListener('click', () => {
            const type = passwordInput.getAttribute('type') === 'password' ? 'text' : 'password';
            passwordInput.setAttribute('type', type);
        });
    }

    // 3. AJAX Login & Sweep-In Transition
    if (loginForm) {
        loginForm.addEventListener('submit', (e) => {
            e.preventDefault();
            if (errorMessage) errorMessage.style.display = 'none';

            const formData = new FormData(loginForm);

            fetch('index.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.status === 'success') {
                    // Sweep stripes in from left to cover the screen
                    pageOverlay.classList.remove('sweep-out');
                    pageOverlay.classList.add('sweep-in');

                    // Wait until full red screen is reached before redirecting
                    setTimeout(() => {
                        window.location.href = data.redirect + '?transition=out';
                    }, 850);
                } else {
                    if (errorMessage) {
                        errorMessage.textContent = data.message;
                        errorMessage.style.display = 'block';
                    }
                }
            })
            .catch(() => {
                if (errorMessage) {
                    errorMessage.textContent = 'An unexpected error occurred. Please try again.';
                    errorMessage.style.display = 'block';
                }
            });
        });
    }
});