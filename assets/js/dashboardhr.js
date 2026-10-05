/* ==========================================================================
   Dashboard (HR) behaviour.
   Entrance, sidebar, logout and the sidebar badges live in hr-common.js
   (load hr-common.js BEFORE this file).
   ========================================================================== */
document.addEventListener('DOMContentLoaded', () => {
    // set by hr-common.js (it removes ?transition=out from the URL, so read it from there)
    const arrivedViaTransition = !!(window.HR && HR.arrivedViaTransition);
    const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    // 0. Completed-screening notifications -----------------------------------
    //    Tell the shared helper who has completed, then mark rows / badge / chip.
    if (typeof completedIds !== 'undefined') HR.syncCompleted(completedIds);

    const completedChip = document.getElementById('completedNewChip');

    // the order the server sent (newest activity first), so rows can go back there once viewed
    const tbody = document.querySelector('.applicants-table tbody');
    const originalRows = Array.from(document.querySelectorAll('.clickable-row'));

    function paintUnseen() {
        // red mark on completed applicants HR has not opened yet
        originalRows.forEach(row => {
            const unseen = row.dataset.completed === '1' && HR.isUnseen(row.dataset.id);
            row.classList.toggle('is-unseen', unseen);
        });

        // unread completed applicants are listed first, then everything else by latest activity
        if (tbody) {
            const unread = originalRows.filter(r => r.classList.contains('is-unseen'));
            const rest = originalRows.filter(r => !r.classList.contains('is-unseen'));
            [...unread, ...rest].forEach(r => tbody.appendChild(r));
        }
        const count = HR.refreshDashboardBadge();
        if (completedChip) {
            completedChip.textContent = count + ' new';
            completedChip.hidden = count <= 0;
        }
    }
    paintUnseen();

    // the page can come back from the cache (Back button) or change in another tab
    window.addEventListener('pageshow', (e) => { if (e.persisted) paintUnseen(); });
    window.addEventListener('storage', paintUnseen);

    // Recent Applicants: fade the bottom edge while there are more rows to scroll to
    const listScroll = document.querySelector('.table-responsive');
    function updateListFade() {
        if (!listScroll) return;
        const more = listScroll.scrollHeight - listScroll.scrollTop - listScroll.clientHeight > 4;
        listScroll.classList.toggle('has-more', more);
    }
    if (listScroll) {
        listScroll.addEventListener('scroll', updateListFade, { passive: true });
        window.addEventListener('resize', updateListFade);
        updateListFade();
        setTimeout(updateListFade, 900);          // again once the entrance animation has settled
    }

    // 1. Stat Card Clicking -> Route to screeninglist.php
    document.querySelectorAll('.stat-card').forEach(card => {
        card.addEventListener('click', () => {
            const targetUrl = card.getAttribute('data-url');
            if (targetUrl) {
                window.location.href = targetUrl;
            }
        });
    });

    // 2. Clickable Applicant Table Rows -> Route to details.php
    //    Opening a completed applicant clears their red mark (and lowers the badge).
    document.querySelectorAll('.clickable-row').forEach(row => {
        row.addEventListener('click', () => {
            const targetUrl = row.getAttribute('data-url');
            if (!targetUrl) return;
            HR.markSeen(row.dataset.id);
            window.location.href = targetUrl;
        });
    });

    // 3. Numbers count up when the cards rise in
    document.querySelectorAll('.card-value[data-count]').forEach(el => {
        const target = parseInt(el.dataset.count, 10);
        if (reduceMotion || !target) return;
        const start = (arrivedViaTransition ? 700 : 300) + 250;
        const duration = 800;
        el.textContent = '0';
        setTimeout(() => {
            const t0 = performance.now();
            const tick = (now) => {
                const p = Math.min(1, (now - t0) / duration);
                el.textContent = String(Math.round(target * (1 - Math.pow(1 - p, 3))));
                if (p < 1) requestAnimationFrame(tick);
            };
            requestAnimationFrame(tick);
        }, start);
    });

    // 4. Doughnut chart (created once the chart card has risen in, so its own
    //    draw animation is actually seen)
    const ctx = document.getElementById('passedRejectedChart');
    const center = document.getElementById('chartCenter');
    if (ctx && typeof chartData !== 'undefined' && (chartData.passed + chartData.failed) === 0) {
        // Nothing to draw yet
        ctx.parentElement.innerHTML = '<p class="chart-empty">No results yet</p>';
    } else if (ctx && typeof chartData !== 'undefined') {
        const total = chartData.passed + chartData.failed;
        const centerValue = document.getElementById('chartCenterValue');
        const centerLabel = document.getElementById('chartCenterLabel');
        const defaults = { value: centerValue.textContent, label: centerLabel.textContent };

        const showCenter = (value, label) => {
            centerValue.textContent = value;
            centerLabel.textContent = label;
        };

        // After a login, start drawing just as the stripes finish clearing, so the chart
        // card is never left empty while the rest of the page is already up
        const chartDelay = arrivedViaTransition ? 700 : 50 + 4 * 70 + 200;

        setTimeout(() => {
            new Chart(ctx, {
                type: 'doughnut',
                data: {
                    labels: ['Passed', 'Failed'],
                    datasets: [{
                        data: [chartData.passed, chartData.failed],
                        backgroundColor: ['#12b700', '#e60000'],
                        borderWidth: 3,
                        borderColor: '#ffffff',
                        borderRadius: 6,
                        spacing: 2,
                        hoverOffset: 8
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    cutout: '68%',
                    // room around the ring so a hovered slice (hoverOffset) is never cut by the canvas edge
                    layout: { padding: 12 },
                    plugins: {
                        legend: { display: false },
                        tooltip: { enabled: false }       // the centre text shows the numbers instead
                    },
                    onHover: (evt, elements) => {
                        evt.native.target.style.cursor = elements.length ? 'pointer' : 'default';
                        if (elements.length) {
                            const i = elements[0].index;
                            const n = i === 0 ? chartData.passed : chartData.failed;
                            showCenter(n, (i === 0 ? 'Passed' : 'Failed') + ' · ' + Math.round(n / total * 100) + '%');
                        } else {
                            showCenter(defaults.value, defaults.label);
                        }
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

        ctx.addEventListener('mouseleave', () => showCenter(defaults.value, defaults.label));

        // Legend chips: click (or Enter) to filter, hover to preview in the centre
        [['legendPassed', 'Passed', chartData.passed], ['legendFailed', 'Failed', chartData.failed]].forEach(([id, status, n]) => {
            const el = document.getElementById(id);
            if (!el) return;
            const go = () => { window.location.href = `screeninglist.php?status=${status}`; };
            el.addEventListener('click', go);
            el.addEventListener('keydown', (e) => { if (e.key === 'Enter') go(); });
            el.addEventListener('mouseenter', () => showCenter(n, status + ' · ' + Math.round(n / total * 100) + '%'));
            el.addEventListener('mouseleave', () => showCenter(defaults.value, defaults.label));
        });
    }
});