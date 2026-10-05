/* ==========================================================================
   Dashboard (HR) behaviour.
   Entrance, sidebar, logout and the Archive badge live in hr-common.js
   (load hr-common.js BEFORE this file).
   ========================================================================== */
document.addEventListener('DOMContentLoaded', () => {
    // set by hr-common.js (it removes ?transition=out from the URL, so read it from there)
    const arrivedViaTransition = !!(window.HR && HR.arrivedViaTransition);

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
    document.querySelectorAll('.clickable-row').forEach(row => {
        row.addEventListener('click', () => {
            const targetUrl = row.getAttribute('data-url');
            if (targetUrl) {
                window.location.href = targetUrl;
            }
        });
    });

    // 3. Dynamic Pie Chart (created once the chart card has risen in, so its own
    //    draw animation is actually seen)
    const ctx = document.getElementById('passedRejectedChart');
    if (ctx && typeof chartData !== 'undefined' && (chartData.passed + chartData.failed) === 0) {
        // Nothing to draw yet
        ctx.parentElement.innerHTML = '<p class="chart-empty">No results yet</p>';
    } else if (ctx && typeof chartData !== 'undefined') {
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
                    // room around the pie so a hovered slice (hoverOffset) is never cut by the canvas edge
                    layout: { padding: 12 },
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
});