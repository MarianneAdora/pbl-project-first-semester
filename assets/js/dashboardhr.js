document.addEventListener('DOMContentLoaded', () => {
    const root = document.documentElement;
    const sidebar = document.getElementById('sidebar');
    const sidebarToggle = document.getElementById('sidebarToggle');
    const arrowToggleBtn = document.getElementById('arrowToggleBtn');
    const applicantsGroup = document.getElementById('applicantsGroup');
    const logoutBtn = document.getElementById('logoutBtn');
    const logoutModal = document.getElementById('logoutModal');
    const cancelLogout = document.getElementById('cancelLogout');
    const confirmLogout = document.getElementById('confirmLogout');
    const pageOverlay = document.getElementById('pageOverlay');

    // 1. Page entrance
    const urlParams = new URLSearchParams(window.location.search);
    const arrivedViaTransition = urlParams.get('transition') === 'out' && pageOverlay;

    if (arrivedViaTransition) {
        // Page is already covered (set in <head>). Sweep the stripes out and let the
        // elements rise in just behind them.
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
        // Normal visit (no stripes): elements rise in right away
        requestAnimationFrame(() => root.classList.add('is-ready'));
    }

    // 2. Sidebar Collapse/Expand Toggle
    if (sidebarToggle && sidebar) {
        sidebarToggle.addEventListener('click', () => {
            sidebar.classList.toggle('collapsed');
        });
    }

    // 3. Applicants Dropdown Toggle (Arrow Only)
    if (arrowToggleBtn && applicantsGroup) {
        arrowToggleBtn.addEventListener('click', (e) => {
            e.stopPropagation();
            e.preventDefault();
            if (!sidebar.classList.contains('collapsed')) {
                applicantsGroup.classList.toggle('active');
            }
        });
    }

    // 4. Stat Card Clicking -> Route to screeninglist.php
    document.querySelectorAll('.stat-card').forEach(card => {
        card.addEventListener('click', () => {
            const targetUrl = card.getAttribute('data-url');
            if (targetUrl) {
                window.location.href = targetUrl;
            }
        });
    });

    // 5. Clickable Applicant Table Rows -> Route to details.php
    document.querySelectorAll('.clickable-row').forEach(row => {
        row.addEventListener('click', () => {
            const targetUrl = row.getAttribute('data-url');
            if (targetUrl) {
                window.location.href = targetUrl;
            }
        });
    });

    // 6. Dynamic Pie Chart (created once the chart card has risen in, so its own
    //    draw animation is actually seen)
    const ctx = document.getElementById('passedRejectedChart');
    if (ctx && typeof chartData !== 'undefined') {
        // After a login, start drawing just as the stripes finish clearing, so the chart
        // card is never left empty while the rest of the page is already up
        const chartDelay = arrivedViaTransition ? 700 : 50 + 4 * 70 + 200;

        setTimeout(() => {
            new Chart(ctx, {
                type: 'pie',
                data: {
                    labels: ['Passed', 'Failed'],
                    datasets: [{
                        data: [chartData.passed, chartData.failed],
                        backgroundColor: ['#12b700', '#e60000'],
                        borderWidth: 2,
                        borderColor: '#ffffff',
                        hoverOffset: 8
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { display: false }
                    },
                    onClick: (evt, elements) => {
                        if (elements.length > 0) {
                            const index = elements[0].index;
                            const statusFilter = index === 0 ? 'Passed' : 'Failed';
                            window.location.href = `screeninglist.php?status=${statusFilter}`;
                        }
                    },
                    animation: {
                        animateScale: true,
                        animateRotate: true,
                        duration: 1000
                    }
                }
            });
        }, chartDelay);

        document.getElementById('legendPassed')?.addEventListener('click', () => {
            window.location.href = 'screeninglist.php?status=Passed';
        });
        document.getElementById('legendFailed')?.addEventListener('click', () => {
            window.location.href = 'screeninglist.php?status=Failed';
        });
    }

    // 7. Logout Modal & Transition Out
    if (logoutBtn && logoutModal) {
        logoutBtn.addEventListener('click', () => {
            logoutModal.classList.add('active');
        });
    }

    if (cancelLogout && logoutModal) {
        cancelLogout.addEventListener('click', () => {
            logoutModal.classList.remove('active');
        });
    }

    if (confirmLogout) {
        confirmLogout.addEventListener('click', () => {
            confirmLogout.disabled = true;
            logoutModal.classList.remove('active');

            let redirected = false;
            const go = () => {
                if (redirected) return;
                redirected = true;
                window.location.href = '../index.php?transition=out';
            };

            // Elements lift away while the stripes sweep in
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
        });
    }

    // 8. Browser back button (page restored from cache): reset the leaving state
    window.addEventListener('pageshow', (event) => {
        if (!event.persisted || !pageOverlay) return;
        root.classList.remove('is-leaving', 'is-transitioning');
        pageOverlay.classList.remove('sweep-in', 'sweep-out');
        if (confirmLogout) confirmLogout.disabled = false;
    });
});