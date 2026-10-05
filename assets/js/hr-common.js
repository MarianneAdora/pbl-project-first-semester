/* ==========================================================================
   HR common behaviour: shared by every HR page
   Part 1: window.HR helpers (archive storage, sidebar badge, toast)
   Part 2: entrance animation, sidebar (collapsed state is remembered),
           Applicants dropdown, logout confirmation + stripe sweep-in
   Load this file BEFORE the page's own script.
   ========================================================================== */

/* ---------- PART 1: shared helpers (window.HR) ---------- */
window.HR = (function () {
    // Archived applicant ids are kept in this browser (localStorage) for now.
    // When the database is ready, replace archiveRequest() with a fetch() to your PHP.
    const KEY = 'hr_archived';

    function readArchived() {
        try {
            const v = JSON.parse(localStorage.getItem(KEY) || '[]');
            return Array.isArray(v) ? v.map(Number) : [];
        } catch (err) {
            return [];
        }
    }

    function archiveRequest(action, ids) {
        const set = new Set(readArchived());
        ids.forEach(id => (action === 'archive' ? set.add(Number(id)) : set.delete(Number(id))));
        const list = Array.from(set);
        try {
            localStorage.setItem(KEY, JSON.stringify(list));
        } catch (err) {
            return Promise.reject(new Error('Could not save. Please check your browser storage settings.'));
        }
        return Promise.resolve({ ok: true, changed: ids.length, count: list.length });
    }

    // The Archive is storage, not an alert, so by default it does NOT show a number badge.
    // (Feedback comes from the fly-to-archive animation, the icon bump and the Undo toast.)
    // Set to true if you want the count bubble back.
    const SHOW_ARCHIVE_COUNT = false;

    function setArchiveBadge(count, animate) {
        const badge = document.getElementById('archiveBadge');
        if (!badge) return;
        if (!SHOW_ARCHIVE_COUNT) { badge.hidden = true; return; }
        badge.textContent = String(count);
        badge.hidden = count <= 0;
        badge.classList.remove('pop');
        if (animate !== false && count > 0) {
            void badge.offsetWidth;           // restart the animation
            badge.classList.add('pop');
        }
    }

    // ---------- Completed-screening notifications ----------
    // Kept in this browser (localStorage) for now, like the archive.
    // When the database is ready: "completed" = applicants with status Completed,
    // "seen" = a viewed_at column that is set when HR opens the applicant's details page.
    const SEEN_KEY = 'hr_seen_completed';
    const COMPLETED_KEY = 'hr_completed_ids';

    function readIds(key) {
        try {
            const v = JSON.parse(localStorage.getItem(key) || '[]');
            return Array.isArray(v) ? v.map(Number) : [];
        } catch (err) {
            return [];
        }
    }

    function writeIds(key, list) {
        try { localStorage.setItem(key, JSON.stringify(list)); } catch (err) { /* storage unavailable */ }
    }

    // The dashboard tells us which applicants have completed the screening
    function syncCompleted(ids) {
        writeIds(COMPLETED_KEY, (ids || []).map(Number));
    }

    function readSeen() { return readIds(SEEN_KEY); }

    function isUnseen(id) {
        return !readSeen().includes(Number(id));
    }

    // Completed, not yet opened by HR, and not archived
    function unseenCompleted() {
        const seen = new Set(readSeen());
        const archived = new Set(readArchived());
        return readIds(COMPLETED_KEY).filter(id => !seen.has(id) && !archived.has(id));
    }

    function markSeen(id) {
        id = Number(id);
        if (!id) return;
        const list = readSeen();
        if (!list.includes(id)) {
            list.push(id);
            writeIds(SEEN_KEY, list);
        }
        refreshDashboardBadge();
    }

    function ensureDashboardBadge() {
        let badge = document.getElementById('dashboardBadge');
        if (badge) return badge;
        const link = document.querySelector('.sidebar-nav a[href$="dashboardhr.php"]');
        if (!link) return null;
        badge = document.createElement('span');
        badge.className = 'nav-badge alert';
        badge.id = 'dashboardBadge';
        badge.hidden = true;
        link.appendChild(badge);
        return badge;
    }

    let lastDashCount = -1;
    function refreshDashboardBadge() {
        const badge = ensureDashboardBadge();
        const count = unseenCompleted().length;
        if (badge) {
            badge.textContent = count > 99 ? '99+' : String(count);
            badge.hidden = count <= 0;
            badge.setAttribute('title', count + ' completed screening' + (count === 1 ? '' : 's') + ' to review');
            if (count > lastDashCount && count > 0) {
                badge.classList.remove('pop');
                void badge.offsetWidth;
                badge.classList.add('pop');
            }
        }
        lastDashCount = count;
        document.dispatchEvent(new CustomEvent('hr:unseen-changed', { detail: { count } }));
        return count;
    }

    function bumpArchive() {
        const link = document.getElementById('archiveLink');
        if (!link) return;
        link.classList.remove('bump');
        void link.offsetWidth;
        link.classList.add('bump');
        setTimeout(() => link.classList.remove('bump'), 700);
    }

    function toast(message, opts) {
        const o = Object.assign({ actionLabel: '', onAction: null, duration: 6000, error: false }, opts);
        let host = document.getElementById('toastHost');
        if (!host) {
            host = document.createElement('div');
            host.id = 'toastHost';
            host.className = 'toast-host';
            document.body.appendChild(host);
        }

        const t = document.createElement('div');
        t.className = 'toast' + (o.error ? ' toast-error' : '');
        t.setAttribute('role', o.error ? 'alert' : 'status');
        t.style.setProperty('--toast-time', o.duration + 'ms');

        const text = document.createElement('span');
        text.className = 'toast-text';
        text.textContent = message;
        t.appendChild(text);

        let timer = null;
        const close = () => {
            clearTimeout(timer);
            t.classList.remove('show');
            setTimeout(() => t.remove(), 350);
        };

        if (o.actionLabel && typeof o.onAction === 'function') {
            const act = document.createElement('button');
            act.type = 'button';
            act.className = 'toast-action';
            act.textContent = o.actionLabel;
            act.addEventListener('click', () => { close(); o.onAction(); });
            t.appendChild(act);
        }

        const x = document.createElement('button');
        x.type = 'button';
        x.className = 'toast-close';
        x.setAttribute('aria-label', 'Dismiss');
        x.textContent = '\u00D7';
        x.addEventListener('click', close);
        t.appendChild(x);

        const bar = document.createElement('span');
        bar.className = 'toast-bar';
        t.appendChild(bar);

        // one toast at a time
        host.querySelectorAll('.toast').forEach(old => old.remove());
        host.appendChild(t);
        requestAnimationFrame(() => t.classList.add('show'));
        timer = setTimeout(close, o.duration);
        return { close };
    }

    // Sidebar badges on every HR page
    document.addEventListener('DOMContentLoaded', () => {
        setArchiveBadge(readArchived().length, false);

        // Opening an applicant's details page counts as "viewed" (works with no change to details.php)
        const q = new URLSearchParams(window.location.search);
        if (/details\.php$/i.test(window.location.pathname) && q.get('id')) markSeen(q.get('id'));

        refreshDashboardBadge();
    });

    // Keep the badge right when another tab changes things
    window.addEventListener('storage', (e) => {
        if ([SEEN_KEY, COMPLETED_KEY, KEY].includes(e.key)) refreshDashboardBadge();
    });

    // arrivedViaTransition is filled in below (page scripts can read it after DOMContentLoaded)
    return {
        archiveRequest, readArchived, setArchiveBadge, bumpArchive, toast,
        syncCompleted, isUnseen, markSeen, unseenCompleted, refreshDashboardBadge,
        arrivedViaTransition: false
    };
})();


/* ---------- PART 2: entrance, sidebar, logout ---------- */
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

    // The sidebar state (collapsed / expanded) is remembered across every HR page
    const SIDEBAR_KEY = 'hr_sidebar_collapsed';
    const sidebarSaved = () => {
        try { return localStorage.getItem(SIDEBAR_KEY) === '1'; } catch (err) { return false; }
    };
    const saveSidebar = () => {
        try { localStorage.setItem(SIDEBAR_KEY, sidebar.classList.contains('collapsed') ? '1' : '0'); } catch (err) { /* storage unavailable */ }
    };

    // Normally the small inline script under the <aside> has already done this before the
    // first paint; this is the fallback for a page that does not have it yet.
    if (sidebar && sidebarSaved()) sidebar.classList.add('collapsed');

    // 1. Page entrance
    const params = new URLSearchParams(window.location.search);
    const arrivedViaTransition = params.get('transition') === 'out' && pageOverlay;
    HR.arrivedViaTransition = !!arrivedViaTransition;

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

        // Remove only the transition flag from the URL (keep any filters)
        params.delete('transition');
        const qs = params.toString();
        window.history.replaceState({}, document.title, window.location.pathname + (qs ? '?' + qs : ''));
    } else {
        // Normal visit (no stripes): elements rise in right away
        requestAnimationFrame(() => root.classList.add('is-ready'));
    }

    // 2. Sidebar collapse / expand (and remember it)
    if (sidebarToggle && sidebar) {
        sidebarToggle.addEventListener('click', () => {
            sidebar.classList.toggle('collapsed');
            saveSidebar();
        });
    }

    // 3. Applicants dropdown (arrow only)
    if (arrowToggleBtn && applicantsGroup && sidebar) {
        arrowToggleBtn.addEventListener('click', (e) => {
            e.stopPropagation();
            e.preventDefault();
            if (!sidebar.classList.contains('collapsed')) {
                applicantsGroup.classList.toggle('active');
            }
        });
    }

    // 4. Logout: confirm, then elements lift away while the stripes sweep in
    if (logoutBtn && logoutModal) {
        logoutBtn.addEventListener('click', () => logoutModal.classList.add('active'));

        // Click on the dark backdrop or press Escape to cancel
        logoutModal.addEventListener('click', (e) => {
            if (e.target === logoutModal) logoutModal.classList.remove('active');
        });
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && logoutModal.classList.contains('active')) {
                e.preventDefault();       // so a page's own Escape handling does not also react
                logoutModal.classList.remove('active');
            }
        });
    }

    if (cancelLogout && logoutModal) {
        cancelLogout.addEventListener('click', () => logoutModal.classList.remove('active'));
    }

    if (confirmLogout && pageOverlay) {
        confirmLogout.addEventListener('click', () => {
            confirmLogout.disabled = true;
            logoutModal.classList.remove('active');

            let redirected = false;
            const go = () => {
                if (redirected) return;
                redirected = true;
                window.location.href = '../index.php?transition=out';
            };

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

    // 5. Browser back button (page restored from cache): reset the leaving state
    //    and re-apply the sidebar state that was saved meanwhile
    window.addEventListener('pageshow', (event) => {
        if (!event.persisted) return;
        if (sidebar) sidebar.classList.toggle('collapsed', sidebarSaved());
        if (!pageOverlay) return;
        root.classList.remove('is-leaving', 'is-transitioning');
        pageOverlay.classList.remove('sweep-in', 'sweep-out');
        if (confirmLogout) confirmLogout.disabled = false;
    });
});