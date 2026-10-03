document.addEventListener('DOMContentLoaded', () => {
    const sidebar = document.getElementById('sidebar');
    const sidebarToggle = document.getElementById('sidebarToggle');
    const arrowToggleBtn = document.getElementById('arrowToggleBtn');
    const applicantsGroup = document.getElementById('applicantsGroup');
    const logoutBtn = document.getElementById('logoutBtn');
    const logoutModal = document.getElementById('logoutModal');
    const cancelLogout = document.getElementById('cancelLogout');
    const confirmLogout = document.getElementById('confirmLogout');
    const pageOverlay = document.getElementById('pageOverlay');

    // 1. On Page Load: Hold screen solid red, then sweep OUT to reveal dashboard
    const urlParams = new URLSearchParams(window.location.search);
    if (urlParams.get('transition') === 'out' && pageOverlay) {
        pageOverlay.classList.add('hold-solid');

        setTimeout(() => {
            pageOverlay.classList.remove('hold-solid');
            pageOverlay.classList.add('sweep-out');
        }, 200);

        window.history.replaceState({}, document.title, window.location.pathname);
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

    // 6. Dynamic Pie Chart Initialization
    const ctx = document.getElementById('passedRejectedChart');
    if (ctx && typeof chartData !== 'undefined') {
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

        document.getElementById('legendPassed')?.addEventListener('click', () => {
            window.location.href = 'screeninglist.php?status=Passed';
        });
        document.getElementById('legendFailed')?.addEventListener('click', () => {
            window.location.href = 'screeninglist.php?status=Failed';
        });
    }

    // 7. Logout Modal & Smooth Transition Out
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
            logoutModal.classList.remove('active');

            // Trigger inward sweep to cover screen
            pageOverlay.classList.remove('sweep-out');
            pageOverlay.classList.add('sweep-in');

            // Wait for sweep-in to complete before switching pages
            setTimeout(() => {
                window.location.href = '../index.php?transition=out';
            }, 850);
        });
    }
});