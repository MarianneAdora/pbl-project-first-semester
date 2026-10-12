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
                        // the ring between the slices matches the card (white in the light theme)
                        borderColor: getComputedStyle(document.documentElement).getPropertyValue('--chart-ring').trim() || '#ffffff',
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
                        duration: document.documentElement.getAttribute('data-motion') === 'off' ? 0 : 1000   // Settings > Page animations
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

/* ==========================================================================
   Notification bell (HR Dashboard header)
   Lists what HR did (the same lines the Admin sees under Recent Activities) plus completed
   screenings that nobody has opened yet. The number on the bell counts the lines that are new since
   the bell was last opened. Opening it marks them as read (they stay highlighted while it is open).
   Settings > Notifications (the master switch) hides the number; Settings changes are never listed.
   Data: window.HR_ACTIVITY, printed by dashboardhr.php (and refreshed through static-data.php, action 'activity').
   The "last read" time is kept in this browser for now; with the database it becomes a column on the user.
   ========================================================================== */
document.addEventListener('DOMContentLoaded', () => {
    const bell = document.getElementById('notifBell');
    const panel = document.getElementById('notifPanel');
    const badge = document.getElementById('notifBadge');
    const listEl = document.getElementById('notifList');
    const markAllBtn = document.getElementById('notifMarkAll');
    if (!bell || !panel || !badge || !listEl) return;

    const SEEN_KEY = 'hr_activity_seen';              // the time of the newest line HR has already read
    const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
    const ICONS = {
        add:     'M15 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm-9-2V7H4v3H1v2h3v3h2v-3h3v-2H6zm9 4c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z',
        remove:  'M6 19c0 1.1.9 2 2 2h8c1.1 0 2-.9 2-2V7H6v12zM19 4h-3.5l-1-1h-5l-1 1H5v2h14V4z',
        restore: 'M13 3a9 9 0 0 0-9 9H1l3.89 3.89.07.14L9 12H6c0-3.87 3.13-7 7-7s7 3.13 7 7-3.13 7-7 7c-1.93 0-3.68-.79-4.94-2.06l-1.42 1.42A8.954 8.954 0 0 0 13 21a9 9 0 0 0 0-18zm-1 5v5l4.28 2.54.72-1.21-3.5-2.08V8H12z',
        event:   'M19 4h-1V2h-2v2H8V2H6v2H5c-1.11 0-1.99.9-1.99 2L3 20a2 2 0 0 0 2 2h14c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2zm0 16H5V10h14v10zm0-12H5V6h14v2z',
        sync:    'M12 4V1L8 5l4 4V6c3.31 0 6 2.69 6 6 0 1.01-.25 1.97-.7 2.8l1.46 1.46C19.54 15.03 20 13.57 20 12c0-4.42-3.58-8-8-8zm0 14c-3.31 0-6-2.69-6-6 0-1.01.25-1.97.7-2.8L5.24 7.74C4.46 8.97 4 10.43 4 12c0 4.42 3.58 8 8 8v3l4-4-4-4v3z',
        done:    'M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 15l-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z',
        dot:     'M12 2a10 10 0 1 0 0 20 10 10 0 0 0 0-20zm1 15h-2v-6h2v6zm0-8h-2V7h2v2z'
    };

    let feed = Array.isArray(window.HR_ACTIVITY) ? window.HR_ACTIVITY : [];
    let fresh = new Set();                            // lines that were new when the bell was opened (highlighted while it is open)
    let lastCount = -1;

    const esc = (v) => String(v == null ? '' : v).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const keyOf = (row) => row.at + '|' + row.activity + '|' + row.details;

    function readSeen() { try { return localStorage.getItem(SEEN_KEY) || ''; } catch (e) { return ''; } }
    function writeSeen(v) { try { localStorage.setItem(SEEN_KEY, v); } catch (e) { /* storage unavailable */ } }
    function newest() { return feed.reduce((a, r) => (r.at > a ? r.at : a), ''); }

    // The very first visit starts "all read", so the old sample lines do not count as news
    if (readSeen() === '' && feed.length) writeSeen(newest());

    // Settings > Notifications master switch (the bell itself always stays; only the number is hidden)
    function notifyOn() {
        try { return !(window.HR && HR.settings) || HR.settings.get().notifications !== false; } catch (e) { return true; }
    }

    const unread = () => feed.filter(r => r.at > readSeen());
    const completedLeft = () => (window.HR && HR.unseenCompleted ? HR.unseenCompleted().length : 0);

    function when(at) {
        const m = /^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2}))?/.exec(String(at || ''));
        if (!m) return String(at || '');
        let out = MONTHS[Number(m[2]) - 1] + ' ' + Number(m[3]) + ', ' + m[1];
        if (m[4] !== undefined) {
            const h = Number(m[4]);
            out += ' \u00b7 ' + (h % 12 || 12) + ':' + m[5] + ' ' + (h >= 12 ? 'PM' : 'AM');
        }
        return out;
    }

    function look(activity) {
        const a = String(activity).toLowerCase();
        if (a.indexOf('forever') !== -1) return { icon: ICONS.remove, tone: 'tone-red' };
        if (a.indexOf('deleted') !== -1) return { icon: ICONS.remove, tone: 'tone-amber' };
        if (a.indexOf('restored') !== -1) return { icon: ICONS.restore, tone: 'tone-blue' };
        if (a.indexOf('added') !== -1) return { icon: ICONS.add, tone: 'tone-green' };
        if (a.indexOf('interview') !== -1) return { icon: ICONS.event, tone: 'tone-blue' };
        if (a.indexOf('status') !== -1) return { icon: ICONS.sync, tone: 'tone-amber' };
        return { icon: ICONS.dot, tone: '' };
    }

    const svg = (d) => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="' + d + '"/></svg>';

    function paintList() {
        const done = completedLeft();
        let html = '';
        if (done > 0) {
            html += '<li><a class="notif-item is-new" href="screeninglist.php?status=Completed">' +
                '<span class="notif-ico tone-green">' + svg(ICONS.done) + '</span>' +
                '<span class="notif-text"><strong>' + done + ' completed screening' + (done === 1 ? '' : 's') + ' to review</strong>' +
                '<span class="notif-detail">Open the Screening List to see who finished.</span></span></a></li>';
        }
        html += feed.slice(0, 20).map(row => {
            const l = look(row.activity);
            return '<li class="notif-item' + (fresh.has(keyOf(row)) ? ' is-new' : '') + '">' +
                '<span class="notif-ico ' + l.tone + '">' + svg(l.icon) + '</span>' +
                '<span class="notif-text"><strong>' + esc(row.activity) + '</strong>' +
                '<span class="notif-detail">' + esc(row.details) + '</span>' +
                '<span class="notif-time">' + esc(when(row.at)) + '</span></span></li>';
        }).join('');
        if (!html) html = '<li class="notif-empty">Nothing yet. When you add, delete or restore an applicant, or answer an interview schedule, it shows up here.</li>';
        listEl.innerHTML = html;
        if (markAllBtn) markAllBtn.disabled = fresh.size === 0 && unread().length === 0;
    }

    function paintBadge() {
        const total = notifyOn() ? unread().length + completedLeft() : 0;
        badge.textContent = total > 99 ? '99+' : String(total);
        badge.hidden = total <= 0;
        bell.setAttribute('aria-label', total > 0 ? 'Notifications, ' + total + ' new' : 'Notifications');
        bell.title = total > 0 ? total + ' new notification' + (total === 1 ? '' : 's') : 'Notifications';
        if (total > lastCount && lastCount >= 0) {
            badge.classList.remove('pop');
            void badge.offsetWidth;                 // restart the animation
            badge.classList.add('pop');
        }
        lastCount = total;
        return total;
    }

    function paint() { paintBadge(); if (!panel.hidden) paintList(); }

    function openPanel() {
        fresh = new Set(unread().map(keyOf));      // highlighted while the panel is open
        writeSeen(newest() || readSeen());          // opening counts as reading them
        panel.hidden = false;
        bell.setAttribute('aria-expanded', 'true');
        paintList();
        paintBadge();
        refreshFeed();                              // pick up anything done since the page loaded
    }

    function closePanel() {
        if (panel.hidden) return;
        panel.hidden = true;
        fresh = new Set();
        bell.setAttribute('aria-expanded', 'false');
    }

    bell.addEventListener('click', (e) => {
        e.stopPropagation();
        if (panel.hidden) openPanel(); else closePanel();
    });
    panel.addEventListener('click', (e) => e.stopPropagation());
    document.addEventListener('click', closePanel);
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && !panel.hidden) { closePanel(); bell.focus(); }
    });

    if (markAllBtn) {
        markAllBtn.addEventListener('click', () => {
            writeSeen(newest() || readSeen());
            fresh = new Set();
            paint();
        });
    }

    // Latest lines from the server (the page may be old: restored from the cache, or the tab was in the background)
    function refreshFeed() {
        fetch('static-data.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'activity' })
        })
            .then(res => res.json())
            .then(data => {
                if (!data || data.status !== 'success' || !Array.isArray(data.activity)) return;
                if (!panel.hidden) {
                    // lines that arrived while the panel is open count as read, but show highlighted
                    const known = new Set(feed.map(keyOf));
                    data.activity.forEach(r => { if (!known.has(keyOf(r))) fresh.add(keyOf(r)); });
                    feed = data.activity;
                    writeSeen(newest() || readSeen());
                } else {
                    feed = data.activity;
                }
                paint();
            })
            .catch(() => { /* offline: keep what the page already has */ });
    }

    paint();
    if (!badge.hidden && !window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
        bell.classList.add('ring');
        bell.addEventListener('animationend', () => bell.classList.remove('ring'), { once: true, capture: true });
    }

    window.addEventListener('pageshow', (e) => { if (e.persisted) { paint(); refreshFeed(); } });
    document.addEventListener('visibilitychange', () => { if (document.visibilityState === 'visible') refreshFeed(); });
    window.addEventListener('storage', (e) => { if (e.key === SEEN_KEY || e.key === 'hr_settings') paint(); });
    document.addEventListener('hr:settings-changed', paint);
});