/* ==========================================================================
   HR common behaviour: shared by every HR page
   Part 1: window.HR helpers (archive storage, sidebar badge, toast)
   Part 2: entrance animation, sidebar (collapsed state is remembered),
           Applicants dropdown, logout confirmation + stripe sweep-in
   Load this file BEFORE the page's own script.
   ========================================================================== */

/* ---------- PART 1: shared helpers (window.HR) ---------- */
window.HR = (function () {
    // Archive data is kept in this browser (localStorage) for now.
    // When the database is ready, replace archiveRequest() with a fetch() to your PHP, and
    // purgeExpired() with a scheduled job:
    //     DELETE FROM applicants WHERE archived_at < NOW() - INTERVAL 30 DAY
    const KEY = 'hr_archived';          // ids that are in the Archive
    const AT_KEY = 'hr_archived_at';    // { id: time it was moved to the Archive (ms) }
    const DEL_KEY = 'hr_deleted';       // ids deleted for good (30 days passed, or HR deleted them)
    const ARCHIVE_DAYS = 30;            // how long an applicant stays in the Archive
    const DAY_MS = 24 * 60 * 60 * 1000;

    function readArchived() {
        try {
            const v = JSON.parse(localStorage.getItem(KEY) || '[]');
            return Array.isArray(v) ? v.map(Number) : [];
        } catch (err) {
            return [];
        }
    }

    function readArchivedAt() {
        try {
            const v = JSON.parse(localStorage.getItem(AT_KEY) || '{}');
            return v && typeof v === 'object' && !Array.isArray(v) ? v : {};
        } catch (err) {
            return {};
        }
    }

    function readDeleted() {
        try {
            const v = JSON.parse(localStorage.getItem(DEL_KEY) || '[]');
            return Array.isArray(v) ? v.map(Number) : [];
        } catch (err) {
            return [];
        }
    }

    function store(key, value) {
        try { localStorage.setItem(key, JSON.stringify(value)); return true; } catch (err) { return false; }
    }

    // action: 'archive' = move to the Archive (the 30 days start now)
    //         'restore' = back to the Screening List
    //         'purge'   = delete for good
    // opts.at = { id: time } keeps the original archive time (used by Undo)
    function archiveRequest(action, ids, opts) {
        const set = new Set(readArchived());
        const at = readArchivedAt();
        const gone = new Set(readDeleted());
        ids.forEach(raw => {
            const id = Number(raw);
            if (action === 'archive') {
                set.add(id);
                at[id] = Number(opts && opts.at && opts.at[id]) || Number(at[id]) || Date.now();
            } else {
                set.delete(id);
                delete at[id];
                if (action === 'purge') gone.add(id);
            }
        });
        const list = Array.from(set);
        if (!store(KEY, list) || !store(AT_KEY, at) || !store(DEL_KEY, Array.from(gone))) {
            return Promise.reject(new Error('Could not save. Please check your browser storage settings.'));
        }
        return Promise.resolve({ ok: true, changed: ids.length, count: list.length });
    }

    // Applicants that have been in the Archive for 30 days are deleted for good.
    // Runs on every HR page. Returns the ids that were removed.
    function purgeExpired() {
        const ids = readArchived();
        const at = readArchivedAt();
        const now = Date.now();
        const expired = [];
        let stamped = false;
        ids.forEach(id => {
            if (!at[id]) { at[id] = now; stamped = true; }          // archived before dates existed: the 30 days start now
            else if (now - Number(at[id]) >= ARCHIVE_DAYS * DAY_MS) expired.push(id);
        });
        if (stamped) store(AT_KEY, at);
        if (expired.length) archiveRequest('purge', expired);
        return expired;
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
        const archived = new Set(readArchived().concat(readDeleted()));
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

    // ---------- New-applicant notification (Applicants > Screening List) ----------
    // Set when HR adds an applicant; cleared as soon as the Screening List page is opened.
    // Kept in this browser for now; with the database this becomes a "seen by HR" flag.
    const NEW_KEY = 'hr_new_applicants';

    function noteNewApplicant(id) {
        const list = readIds(NEW_KEY);
        id = Number(id);
        if (id && !list.includes(id)) {
            list.push(id);
            writeIds(NEW_KEY, list);
        }
        refreshApplicantBadges();
    }

    function clearNewApplicants() {
        writeIds(NEW_KEY, []);
        refreshApplicantBadges();
    }

    function ensureApplicantBadges() {
        let main = document.getElementById('applicantsBadge');
        if (!main) {
            const link = document.getElementById('applicantsMainLink');
            if (link) {
                main = document.createElement('span');
                main.className = 'nav-badge alert';
                main.id = 'applicantsBadge';
                main.hidden = true;
                link.appendChild(main);
            }
        }
        let sub = document.getElementById('screeningBadge');
        if (!sub) {
            const item = document.querySelector('.sub-menu a.sub-item[href$="screeninglist.php"]');
            if (item) {
                sub = document.createElement('span');
                sub.className = 'nav-badge alert mini';
                sub.id = 'screeningBadge';
                sub.hidden = true;
                item.appendChild(sub);
            }
        }
        return [main, sub].filter(Boolean);
    }

    let lastNewCount = -1;
    function refreshApplicantBadges() {
        const count = readIds(NEW_KEY).length;
        ensureApplicantBadges().forEach(badge => {
            badge.textContent = count > 99 ? '99+' : String(count);
            badge.hidden = count <= 0;
            badge.setAttribute('title', count + ' new applicant' + (count === 1 ? '' : 's'));
            if (count > lastNewCount && count > 0) {
                badge.classList.remove('pop');
                void badge.offsetWidth;
                badge.classList.add('pop');
            }
        });
        lastNewCount = count;
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
        purgeExpired();                       // 30 days in the Archive: gone for good
        setArchiveBadge(readArchived().length, false);

        // Opening an applicant's details page counts as "viewed" (works with no change to details.php)
        const q = new URLSearchParams(window.location.search);
        if (/details\.php$/i.test(window.location.pathname) && q.get('id')) markSeen(q.get('id'));

        // Opening the Screening List counts as "seen" for the new-applicant badge
        if (/screeninglist\.php$/i.test(window.location.pathname)) clearNewApplicants();

        refreshDashboardBadge();
        refreshApplicantBadges();
    });

    // Keep the badge right when another tab changes things
    window.addEventListener('storage', (e) => {
        if ([SEEN_KEY, COMPLETED_KEY, KEY].includes(e.key)) refreshDashboardBadge();
        if (e.key === NEW_KEY) refreshApplicantBadges();
    });

    // arrivedViaTransition is filled in below (page scripts can read it after DOMContentLoaded)
    return {
        archiveRequest, readArchived, readArchivedAt, readDeleted, purgeExpired, ARCHIVE_DAYS,
        setArchiveBadge, bumpArchive, toast,
        syncCompleted, isUnseen, markSeen, unseenCompleted, refreshDashboardBadge,
        noteNewApplicant, clearNewApplicants, refreshApplicantBadges,
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