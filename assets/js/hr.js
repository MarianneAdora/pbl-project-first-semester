/* ==========================================================================
   hr.js : every hr page's script in ONE file.

   The shared script runs everywhere. Each page script is wrapped in a check of
   <html data-page="..."> so it only runs on the page(s) that used to load it.
   The code inside each section is unchanged.
   ========================================================================== */

/* ===== SHARED : hr-common.js (runs on every hr page) ===== */
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

    // ---- Settings (hr/settinghr.php) ----------------------------------------------------------
    // The HR preferences live in ONE browser key for now, like the archive. Every HR page reads
    // them here, so a change on the Settings page shows up everywhere:
    //   theme          'light' | 'dark' | 'system'      -> <html data-theme>   (assets/css/theme.css)
    //   animations     false = no page motion            -> <html data-motion="off">
    //   notifications  master switch for the red badges / marks
    //   notifyCompleted  badge + red mark for completed screenings nobody has opened yet
    //   notifyNew        badge on Applicants > Screening List for newly added applicants
    // BACKEND: when the database is ready, load these from a user_settings table and print them
    // into window.HR_SERVER_SETTINGS; only readSettings() and saveSettings() have to change.
    const SETTINGS_KEY = 'hr_settings';
    const PROFILE_KEY = 'hr_profile';       // { name, email } edited on the Settings page
    const SETTINGS_DEFAULTS = { theme: 'light', animations: true, notifications: true, notifyCompleted: true, notifyNew: true };

    function readSettings() {
        try {
            const v = JSON.parse(localStorage.getItem(SETTINGS_KEY) || '{}');
            return Object.assign({}, SETTINGS_DEFAULTS, v && typeof v === 'object' && !Array.isArray(v) ? v : {});
        } catch (err) {
            return Object.assign({}, SETTINGS_DEFAULTS);
        }
    }

    function readProfile() {
        try {
            const v = JSON.parse(localStorage.getItem(PROFILE_KEY) || '{}');
            return v && typeof v === 'object' && !Array.isArray(v) ? v : {};
        } catch (err) {
            return {};
        }
    }

    function resolveTheme(theme) {
        if (theme === 'dark' || theme === 'light') return theme;
        const dark = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
        return dark ? 'dark' : 'light';
    }

    // Puts a settings object on the page. fade = true lets the colours glide for half a second
    // (used when the person changes the theme, not when a page simply opens).
    let fadeTimer = null;
    function applySettings(s, fade) {
        s = s || readSettings();
        const root = document.documentElement;
        const motionOff = s.animations === false;
        if (motionOff) root.classList.remove('theme-fade');
        if (fade && !motionOff) {
            root.classList.add('theme-fade');
            clearTimeout(fadeTimer);
            fadeTimer = setTimeout(() => root.classList.remove('theme-fade'), 520);
        }
        root.setAttribute('data-theme', resolveTheme(s.theme));
        if (motionOff) root.setAttribute('data-motion', 'off');
        else root.removeAttribute('data-motion');
    }

    // kind: 'completed' | 'new'. False when the master switch or that kind is off.
    function notifyOn(kind) {
        const s = readSettings();
        return s.notifications !== false && s[kind === 'completed' ? 'notifyCompleted' : 'notifyNew'] !== false;
    }

    function saveSettings(partial) {
        const next = Object.assign(readSettings(), partial || {});
        const ok = store(SETTINGS_KEY, next);
        applySettings(next, true);
        refreshDashboardBadge();               // badges appear / disappear right away
        refreshApplicantBadges();
        document.dispatchEvent(new CustomEvent('hr:settings-changed', { detail: next }));
        return ok;
    }

    function saveProfile(profile) {
        const next = Object.assign(readProfile(), profile || {});
        const ok = store(PROFILE_KEY, next);
        applyProfile();
        return ok;
    }

    // The name HR saved replaces the one the session gave ("Hello, ...!" on the dashboard)
    function applyProfile() {
        const h = document.getElementById('greeting');
        const name = String(readProfile().name || '').trim();
        if (h && name) {
            h.textContent = 'Hello, ' + name + '!';
            h.title = h.textContent;
        }
    }

    applySettings();                           // the <head> script already did this; harmless backup
    if (window.matchMedia) {
        const mq = window.matchMedia('(prefers-color-scheme: dark)');
        const onScheme = () => { if (readSettings().theme === 'system') applySettings(); };
        if (mq.addEventListener) mq.addEventListener('change', onScheme);
        else if (mq.addListener) mq.addListener(onScheme);
    }

    // ---- Server link (archive-action.php) -----------------------------------------------------
    // The server is the truth: Delete sets deleted_at (Archive), Restore clears it,
    // Delete forever hard-deletes archived rows. Every HR page prints
    // window.HR_SERVER_ARCHIVE before this file; the browser keys below
    // are rewritten from it.
    const SERVER_API = 'archive-action.php';      // same folder as the HR pages

    function syncArchiveFromServer() {
        const s = window.HR_SERVER_ARCHIVE;
        if (!s || !Array.isArray(s.ids)) return;
        store(KEY, s.ids.map(Number));
        store(AT_KEY, s.at && typeof s.at === 'object' ? s.at : {});
        store(DEL_KEY, []);                    // purged applicants no longer exist on the server
    }
    syncArchiveFromServer();

    // One request to static-data.php. Rejects with a message that is safe to show.
    function serverRequest(action, ids, opts) {
        const body = { action: action, ids: (ids || []).map(Number) };
        if (opts && opts.at) body.at = opts.at;
        return fetch(SERVER_API, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'same-origin',
            body: JSON.stringify(body)
        })
            .then(
                res => res.json().catch(() => { throw new Error('The server did not answer correctly. Please try again.'); }),
                () => { throw new Error('Could not reach the server. Please check your connection and try again.'); }
            )
            .then(data => {
                if (data.status !== 'success') throw new Error(data.message || 'Something went wrong. Please try again.');
                return data;
            });
    }

    // Same job as before (and the same answer), but the change is also saved on the server.
    // The browser copy changes right away so the page reacts at once; if the server says no,
    // the copy goes back to how it was and the error is shown.
    function archiveRequest(action, ids, opts) {
        const keys = [KEY, AT_KEY, DEL_KEY];
        let snapshot = [];
        try { snapshot = keys.map(k => localStorage.getItem(k)); } catch (err) { /* storage unavailable */ }

        return archiveRequestLocal(action, ids, opts).then(local =>
            serverRequest(action, ids, opts).then(() => local, err => {
                try {
                    keys.forEach((k, i) => {
                        if (snapshot[i] === null) localStorage.removeItem(k);
                        else localStorage.setItem(k, snapshot[i]);
                    });
                } catch (e) { /* storage unavailable */ }
                throw err;
            })
        );
    }

    // (original, browser only) action: 'archive' = move to the Archive (the 30 days start now)
    //         'restore' = back to the Screening List
    //         'purge'   = delete for good
    // opts.at = { id: time } keeps the original archive time (used by Undo)
    function archiveRequestLocal(action, ids, opts) {
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
        if (expired.length) {
            archiveRequestLocal('purge', expired);                     // the browser copy, at once
            serverRequest('purge', expired).catch(() => { /* the server also removes expired ones itself */ });
        }
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
        return notifyOn('completed') && !readSeen().includes(Number(id));
    }

    // Completed, not yet opened by HR, and not archived
    function unseenCompleted() {
        if (!notifyOn('completed')) return [];          // Settings > Notifications is off
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
        const count = notifyOn('new') ? readIds(NEW_KEY).length : 0;     // Settings > Notifications
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
        applyProfile();                       // name saved in Settings -> "Hello, ...!"
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
        if (e.key === SETTINGS_KEY) {          // Settings saved in another tab
            applySettings(null, true);
            refreshDashboardBadge();
            refreshApplicantBadges();
        }
        if (e.key === PROFILE_KEY) applyProfile();
    });

    // arrivedViaTransition is filled in below (page scripts can read it after DOMContentLoaded)
    return {
        archiveRequest, archiveRequestLocal, serverRequest, syncArchiveFromServer, readArchived, readArchivedAt, readDeleted, purgeExpired, ARCHIVE_DAYS,
        setArchiveBadge, bumpArchive, toast,
        syncCompleted, isUnseen, markSeen, unseenCompleted, refreshDashboardBadge,
        noteNewApplicant, clearNewApplicants, refreshApplicantBadges,
        settings: { get: readSettings, save: saveSettings, apply: applySettings, defaults: SETTINGS_DEFAULTS, profile: readProfile, saveProfile: saveProfile },
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
        HR.settings.apply();                   // a setting may have changed while this page was cached
        if (sidebar) sidebar.classList.toggle('collapsed', sidebarSaved());
        if (!pageOverlay) return;
        root.classList.remove('is-leaving', 'is-transitioning');
        pageOverlay.classList.remove('sweep-in', 'sweep-out');
        if (confirmLogout) confirmLogout.disabled = false;
    });
});

/* ===== PAGE : addapplicants.js (runs only on: addapplicants) ===== */
if (document.documentElement.getAttribute('data-page') === 'addapplicants') {
/* ==========================================================================
   Add Applicant behaviour.
   Entrance, sidebar, logout and the sidebar badges live in hr-common.js
   (load hr-common.js BEFORE this file).
   ========================================================================== */
document.addEventListener('DOMContentLoaded', () => {
    const form = document.getElementById('applicantForm');
    const formCard = document.getElementById('formCard');
    const successCard = document.getElementById('successCard');
    const sendBtn = document.getElementById('sendBtn');
    const cancelBtn = document.getElementById('cancelBtn');

    const fileInput = document.getElementById('cv');
    const fileBox = document.getElementById('fileBox');
    const fileName = document.getElementById('fileName');
    const fileRemove = document.getElementById('fileRemove');
    const FILE_HINT = fileName.textContent;

    const sentModal = document.getElementById('sentModal');
    const discardModal = document.getElementById('discardModal');

    // CV upload is enabled: the file is sent to addapplicants.php -> Cloudinary (ebsmi_cvs/{id}).
    // Optional: validated client-side (PDF/DOC/DOCX, 5 MB max) and again on the server.
    const CV_DISABLED = false;
    const MAX_CV = 5 * 1024 * 1024;
    const CV_TYPES = ['pdf', 'doc', 'docx'];
    let sending = false;
    let lastApplicant = null;

    const fieldOf = (name) => form.querySelector(`.field[data-field="${name}"]`);

    /* ---------- validation ---------- */
    // Each rule returns the error text (or '' when the value is fine). The messages say WHAT is wrong.
    const rules = {
        name(v) {
            const t = v.trim();
            if (!t) return 'Please enter the full name.';
            if (/\d/.test(t)) return 'The name cannot contain numbers.';
            if (!/^[\p{L}\p{M}'.\- ]+$/u.test(t)) return 'Use letters only (spaces, apostrophes, periods and hyphens are fine).';
            if (t.replace(/[^\p{L}]/gu, '').length < 2) return 'The name is too short (at least 2 letters).';
            if (t.length > 100) return 'The name is too long (100 characters maximum).';
            return '';
        },
        email(v) {
            const t = v.trim();
            if (!t) return 'Please enter the email address.';
            if (/\s/.test(t)) return 'The email cannot contain spaces.';
            if (!t.includes('@')) return 'The email must include an @ (example: name@gmail.com).';
            if (t.split('@').length > 2) return 'The email can only have one @.';
            const [local, domain] = t.split('@');
            if (!local) return 'Add the part before the @ (example: name@gmail.com).';
            if (!domain) return 'Add the part after the @ (example: name@gmail.com).';
            if (!/\.[^.]{2,}$/.test(domain) || domain.startsWith('.') || domain.includes('..')) {
                return 'The email needs a valid domain (example: gmail.com).';
            }
            if (!/^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(t)) return 'Enter a valid email address (example: name@gmail.com).';
            return '';
        },
        contact(v) {
            const t = v.trim();
            if (!t) return 'Please enter the contact number.';
            if (/[A-Za-z]/.test(t)) return 'The contact number cannot contain letters.';
            if (!/^\+?[0-9][0-9\s-]*$/.test(t)) return 'Use digits only (you may start with + and use spaces or dashes).';
            const digits = t.replace(/\D/g, '').length;
            if (digits < 7) return 'The number is too short (at least 7 digits).';
            if (digits > 15) return 'The number is too long (15 digits maximum).';
            return '';
        },
        cv() {
            if (CV_DISABLED) return '';   // CV upload not functional yet: optional, never blocks
            const f = fileInput.files[0];
            if (!f) return 'Please upload the applicant\'s CV.';
            const ext = f.name.includes('.') ? f.name.split('.').pop().toLowerCase() : '';
            if (!CV_TYPES.includes(ext)) return (ext ? '.' + ext + ' files are not allowed. ' : '') + 'Upload a PDF, DOC or DOCX file.';
            if (f.size > MAX_CV) return 'The CV is ' + prettySize(f.size) + '. It must be 5 MB or smaller.';
            if (f.size === 0) return 'This file is empty. Choose a different CV.';
            return '';
        }
    };

    const NOTE_MAX = 500;
    const noteInput = form.elements.note;
    const noteCount = document.getElementById('noteCount');

    function setError(name, message, shake) {
        const field = fieldOf(name);
        if (!field) return;
        const input = name === 'cv' ? fileInput : form.elements[name];
        field.classList.toggle('has-error', !!message);
        field.querySelector('.field-error').textContent = message || '';
        if (input) input.setAttribute('aria-invalid', message ? 'true' : 'false');
        if (message && shake) {
            field.classList.remove('shake');
            void field.offsetWidth;
            field.classList.add('shake');
        }
    }

    function validateField(name, shake) {
        const value = name === 'cv' ? '' : form.elements[name].value;
        const message = rules[name](value);
        setError(name, message, shake);
        return !message;
    }

    // Typing: once a box has been used, it is re-checked on every keystroke, so the message
    // appears the moment something is wrong and goes away the moment it is fixed.
    // Leaving a box that was used (even if now empty) also checks it.
    const touched = new Set();
    ['name', 'email', 'contact'].forEach(name => {
        const input = form.elements[name];
        input.setAttribute('aria-describedby', name + 'Error');
        fieldOf(name).querySelector('.field-error').id = name + 'Error';

        input.addEventListener('input', () => {
            touched.add(name);
            validateField(name, false);
        });
        input.addEventListener('blur', () => { if (touched.has(name)) validateField(name, false); });
    });

    // Note is optional, but it still tells you when you hit the limit
    function updateNote() {
        const len = noteInput.value.length;
        noteCount.textContent = len + '/' + NOTE_MAX;
        noteCount.classList.toggle('at-limit', len >= NOTE_MAX);
        setError('note', len >= NOTE_MAX ? 'You reached the ' + NOTE_MAX + '-character limit.' : '');
    }
    noteInput.addEventListener('input', updateNote);
    updateNote();

    /* ---------- CV picker (click or drag a file onto the box) ---------- */
    function prettySize(bytes) {
        return bytes < 1024 * 1024
            ? Math.max(1, Math.round(bytes / 1024)) + ' KB'
            : (bytes / 1024 / 1024).toFixed(1) + ' MB';
    }

    function showFile() {
        const f = fileInput.files[0];
        fileBox.classList.toggle('has-file', !!f);
        fileRemove.hidden = !f;
        fileName.textContent = f ? `${f.name} (${prettySize(f.size)})` : FILE_HINT;
        fileName.title = f ? f.name : '';
    }

    function clearFile() {
        fileInput.value = '';
        showFile();
    }

    fileInput.addEventListener('change', () => {
        showFile();
        if (!fileInput.files[0]) { setError('cv', ''); setAutofill(''); return; }
        const ok = validateField('cv', true);          // wrong type / too big: show why
        if (!ok) {
            // drop the unusable file so the box is empty again, but keep the message visible
            const msg = rules.cv();
            fileInput.value = '';
            showFile();
            setError('cv', msg, false);
            return;
        }
        parseCvAutofill(fileInput.files[0]);           // valid file: read name/email/contact into the form
    });

    fileRemove.addEventListener('click', () => { clearFile(); setError('cv', ''); setAutofill(''); });

    /* ---------- CV autofill: Gemini reads the CV, fills name / email / contact ---------- */
    const cvAutofill = document.getElementById('cvAutofill');
    let cvParsing = false;

    function setAutofill(message, isError) {
        if (!cvAutofill) return;
        cvAutofill.textContent = message || '';
        cvAutofill.classList.toggle('is-error', !!isError);
        cvAutofill.classList.toggle('is-loading', message === 'Reading CV…');
    }

    function fillField(name, value) {
        value = (value || '').trim();
        if (!value) return false;
        const input = form.elements[name];
        if (!input) return false;
        input.value = value;
        touched.add(name);
        validateField(name, false);
        fieldOf(name).classList.add('autofilled');
        setTimeout(() => fieldOf(name).classList.remove('autofilled'), 2500);
        return true;
    }

    async function parseCvAutofill(file) {
        if (cvParsing) return;
        cvParsing = true;
        setAutofill('Reading CV…');
        try {
            const fd = new FormData();
            fd.append('action', 'parse_cv');
            fd.append('cv', file, file.name);
            const res = await fetch('addapplicants.php', { method: 'POST', body: fd, credentials: 'same-origin' });
            let data;
            try { data = await res.json(); }
            catch (e) { throw new Error('The server did not answer correctly. Please type the details manually.'); }
            if (data.status !== 'success' || !data.parsed) throw new Error(data.message || 'Could not read that CV. Please type the details manually.');
            const filled = ['name', 'email', 'contact'].filter(n => fillField(n, data.parsed[n]));
            setAutofill(
                filled.length ? 'Details filled from CV — please review before sending.' : 'Could not find contact details in this CV. Please type them manually.',
                !filled.length
            );
        } catch (err) {
            setAutofill(err.message || 'Could not read that CV. Please type the details manually.', true);
        } finally {
            cvParsing = false;
        }
    }

    ['dragenter', 'dragover'].forEach(type => fileBox.addEventListener(type, (e) => {
        e.preventDefault();
        fileBox.classList.add('drag');
    }));
    ['dragleave', 'drop'].forEach(type => fileBox.addEventListener(type, (e) => {
        e.preventDefault();
        fileBox.classList.remove('drag');
    }));
    fileBox.addEventListener('drop', (e) => {
        const f = e.dataTransfer && e.dataTransfer.files[0];
        if (!f) return;
        const dt = new DataTransfer();
        dt.items.add(f);
        fileInput.files = dt.files;
        fileInput.dispatchEvent(new Event('change'));
    });

    /* ---------- submit ---------- */
    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        if (sending) return;

        // validate everything, focus the first problem
        let firstBad = null;
        ['name', 'email', 'contact', 'cv'].forEach(name => {
            if (!validateField(name, true) && !firstBad) firstBad = name;
        });
        if (firstBad) {
            const bad = form.querySelectorAll('.field.has-error').length;
            HR.toast('Please fix the ' + bad + ' highlighted field' + (bad === 1 ? '' : 's') + ' before sending.', { error: true, duration: 4000 });
            const target = firstBad === 'cv' ? fileBox.querySelector('.choose-btn') : form.elements[firstBad];
            if (target && target.focus) target.focus({ preventScroll: false });
            return;
        }

        setSending(true);
        try {
            const data = await sendInvitation(new FormData(form));
            lastApplicant = data.applicant;
            HR.noteNewApplicant(data.applicant.id);      // red badge on Applicants > Screening List
            openSentModal(data.applicant);
        } catch (err) {
            HR.toast(err.message || 'Something went wrong. Please try again.', { error: true, duration: 8000 });
        } finally {
            setSending(false);
        }
    });

    /* ---------- sending (front end only) ----------
       There is no server yet, so this just waits a moment and pretends the email was sent.
       BACKEND: replace the body with a real request, for example:

           const res = await fetch('addapplicants.php', { method: 'POST', body: formData });
           const data = await res.json();                 // { status: 'success', applicant: { id, name, email, contact } }
           if (data.status !== 'success') throw new Error(data.message);
           return data;

       The email itself is designed in shortlist-email.html. When the server sends it, it replaces:
           {{FIRST_NAME}}  first word of the applicant's name
           {{LINK}}        the applicant's personal link:  .../applicant/opening.php?token=...
           {{YEAR}}        current year
       and, before redirecting from that link to opening.php, sets the session values
       listed at the top of opening.php (applicant_name, applicant_email). */
    /* Real request: saves the applicant through addapplicants.php (it is the endpoint too),
       so the Screening List, Dashboard cards and Recent Applicants all show the new person.
       The CV file (if chosen) is uploaded flat to ebsmi_cvs as cv-{id}-{filename}
       (PDFs = previewable image type) and its URL is saved as cv_file_url.
       Invitation email still not sent. */
    async function sendInvitation(formData) {
        formData.delete('note'); // no notes column yet
        const res = await fetch('addapplicants.php', { method: 'POST', body: formData, credentials: 'same-origin' });
        let data;
        try { data = await res.json(); }
        catch (e) { throw new Error('The server did not answer correctly. Please try again.'); }
        if (data.status !== 'success') throw new Error(data.message || 'Something went wrong. Please try again.');
        return data;
    }

    // The original pretend version, kept for testing without the server
    async function sendInvitationFake(formData) {
        await new Promise(resolve => setTimeout(resolve, 1200));
        return {
            status: 'success',
            applicant: {
                id: Date.now() % 1000000000,           // placeholder id until the database gives one
                name: formData.get('name').trim(),
                email: formData.get('email').trim(),
                contact: formData.get('contact').trim()
            }
        };
    }

    function setSending(state) {
        sending = state;
        sendBtn.disabled = state;
        sendBtn.classList.toggle('sending', state);
        sendBtn.querySelector('.btn-label').textContent = state ? 'SENDING...' : 'SEND';
        cancelBtn.disabled = state;
    }

    /* ---------- "Invitation Sent" modal -> success panel ---------- */
    function openSentModal(applicant) {
        document.getElementById('sentEmail').textContent = applicant.email;
        sentModal.classList.add('active');
        document.getElementById('sentDone').focus();
    }

    function finishSent() {
        if (!sentModal.classList.contains('active')) return;
        sentModal.classList.remove('active');
        if (!lastApplicant) return;

        document.getElementById('detName').textContent = lastApplicant.name;
        document.getElementById('detEmail').textContent = lastApplicant.email;
        document.getElementById('detContact').textContent = lastApplicant.contact;

        formCard.hidden = true;
        successCard.hidden = false;
        window.scrollTo(0, 0);
        document.querySelector('.main-content').scrollTop = 0;
    }

    document.getElementById('sentDone').addEventListener('click', finishSent);
    document.getElementById('sentClose').addEventListener('click', finishSent);
    sentModal.addEventListener('click', (e) => { if (e.target === sentModal) finishSent(); });

    document.getElementById('addAnother').addEventListener('click', () => {
        form.reset();
        clearFile();
        ['name', 'email', 'contact', 'cv'].forEach(n => setError(n, ''));
        touched.clear();
        updateNote();
        successCard.hidden = true;
        formCard.hidden = false;
        // replay the small rise-in so the swap does not feel abrupt
        formCard.style.animation = 'none';
        void formCard.offsetWidth;
        formCard.style.animation = '';
        form.elements.name.focus();
    });

    /* ---------- Cancel (asks first when something was typed) ---------- */
    const isDirty = () => ['name', 'email', 'contact', 'note'].some(n => form.elements[n].value.trim() !== '')
        || fileInput.files.length > 0;

    cancelBtn.addEventListener('click', () => {
        if (isDirty()) discardModal.classList.add('active');
        else window.location.href = 'screeninglist.php';
    });

    document.getElementById('keepEditing').addEventListener('click', () => discardModal.classList.remove('active'));
    document.getElementById('confirmDiscard').addEventListener('click', () => {
        discardModal.classList.remove('active');
        window.location.href = 'screeninglist.php';
    });
    discardModal.addEventListener('click', (e) => { if (e.target === discardModal) discardModal.classList.remove('active'); });

    document.addEventListener('keydown', (e) => {
        if (e.key !== 'Escape') return;
        if (sentModal.classList.contains('active')) finishSent();
        else if (discardModal.classList.contains('active')) discardModal.classList.remove('active');
    });
});
}

/* ===== PAGE : archive.js (runs only on: archive) ===== */
if (document.documentElement.getAttribute('data-page') === 'archive') {
/* ==========================================================================
   archive.js: the Archive page.
   Shared helpers (window.HR: archive storage, badges, toast), the sidebar, logout and the
   entrance animation live in hr-common.js (load hr-common.js BEFORE this file).

   Deleted applicants stay here for 30 days (HR.ARCHIVE_DAYS), then they are deleted for good.
   Select all, press-and-hold, the number <-> checkbox swap and the flying rows work exactly
   like the Screening List.
   ========================================================================== */
document.addEventListener('DOMContentLoaded', () => {
    const config = window.ARCHIVE || { perPage: 6, days: 30, demo: false, applicants: [] };
    const sourceList = Array.isArray(config.applicants) ? config.applicants : [];
    const DAYS = parseInt(config.days, 10) || HR.ARCHIVE_DAYS || 30;
    const DAY_MS = 24 * 60 * 60 * 1000;
    const SOON_DAYS = 7;          // "7 days or less" filter + amber pill
    const URGENT_DAYS = 3;        // red pill

    let applicants = [];          // applicants that are in the Archive right now (newest first)
    // config.perPage is the starting value; fitPerPage() then adjusts it to the window height
    let perPage = Math.max(1, parseInt(config.perPage, 10) || 6);

    const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    const toolbar = document.getElementById('toolbar');
    const searchInput = document.getElementById('searchInput');
    const searchClear = document.getElementById('searchClear');
    const selectAllBtn = document.getElementById('selectAllBtn');
    const selectActions = document.getElementById('selectActions');
    const selectSwap = document.getElementById('selectSwap');
    const cancelSelect = document.getElementById('cancelSelect');
    const restoreSelected = document.getElementById('restoreSelected');
    const restoreCount = document.getElementById('restoreCount');
    const deleteSelected = document.getElementById('deleteSelected');
    const deleteCount = document.getElementById('deleteCount');
    const filterToggle = document.getElementById('filterToggle');
    const filterPanel = document.getElementById('filterPanel');
    const filterDot = document.getElementById('filterDot');
    const table = document.getElementById('archiveTable');
    const tableScroll = document.getElementById('tableScroll');
    const tableBody = document.getElementById('tableBody');
    const emptyState = document.getElementById('emptyState');
    const emptyTitle = document.getElementById('emptyTitle');
    const emptyText = document.getElementById('emptyText');
    const clearFilters = document.getElementById('clearFilters');
    const selectAll = document.getElementById('selectAll');
    const showingText = document.getElementById('showingText');
    const pagination = document.getElementById('pagination');
    const deleteModal = document.getElementById('deleteModal');
    const deleteTitle = document.getElementById('deleteTitle');
    const deleteText = document.getElementById('deleteText');
    const cancelDelete = document.getElementById('cancelDelete');
    const confirmDelete = document.getElementById('confirmDelete');

    const state = { q: '', status: 'all', left: 'all', page: 1 };
    const selected = new Set();       // ids ticked by the user (kept across pages)
    let selectMode = false;
    let visible = [];                 // rows on the current page
    let animateRows = false;          // first render comes with the card entrance
    let busy = false;                 // a restore / delete request + animation is running
    let switchTimer = null;           // staggers the number <-> checkbox swap
    let clearTimer = null;            // clears the ticks after the leave animation
    let pendingDelete = [];           // ids waiting for the "Delete permanently?" answer

    // ---------- helpers ----------
    // Build elements with textContent only, so names/emails can never inject HTML
    function el(tag, className, text) {
        const node = document.createElement(tag);
        if (className) node.className = className;
        if (text !== undefined && text !== null) node.textContent = text;
        return node;
    }

    const STATUS_CLASS = {
        'pending': 'status-pending',
        'in progress': 'status-progress',
        'completed': 'status-completed',
        'passed': 'status-passed',
        'failed': 'status-failed',
        'rejected': 'status-failed'
    };

    function pill(text) {
        const cls = STATUS_CLASS[String(text).trim().toLowerCase()] || 'status-default';
        return el('span', 'status-pill ' + cls, text);
    }

    const plural = (n, word) => n + ' ' + word + (n === 1 ? '' : 's');
    const dateFmt = new Intl.DateTimeFormat('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
    const fmtDate = (ms) => dateFmt.format(new Date(ms));
    const sleep = (ms) => new Promise(resolve => setTimeout(resolve, ms));

    // ---------- the 30-day clock ----------
    // BACKEND: send "archived_at" (ISO date) with every archived applicant and the page uses it.
    // Until then the time HR clicked Delete is kept in this browser (see hr-common.js).
    function loadArchived() {
        HR.purgeExpired();                                  // older than 30 days = gone for good
        const at = HR.readArchivedAt();
        const ids = new Set(HR.readArchived());
        applicants = sourceList
            .filter(a => ids.has(a.id))
            .map(a => {
                const archivedAt = a.archived_at ? Date.parse(a.archived_at) : (Number(at[a.id]) || Date.now());
                return Object.assign({}, a, { archivedAt, deleteAt: archivedAt + DAYS * DAY_MS });
            })
            .sort((x, y) => y.archivedAt - x.archivedAt);
    }

    const daysLeft = (a) => Math.max(0, Math.ceil((a.deleteAt - Date.now()) / DAY_MS));
    const urgency = (d) => (d <= URGENT_DAYS ? 'urgent' : d <= SOON_DAYS ? 'soon' : 'ok');
    const leftText = (d) => (d <= 0 ? 'Deleting today' : d === 1 ? '1 day left' : d + ' days left');

    // FRONT-END TESTING ONLY: archive.php?demo=1 fills an empty Archive with sample applicants
    // of different ages, so the countdown colours can be checked.
    function seedDemo() {
        if (!config.demo || HR.readArchived().length) return;
        const ages = { 103: 1, 107: 6, 110: 12, 115: 24, 121: 27, 126: 29 };   // days ago
        const at = {};
        const now = Date.now();
        Object.keys(ages).forEach(id => { at[id] = now - ages[id] * DAY_MS - 3600000; });
        HR.archiveRequest('archive', Object.keys(ages).map(Number), { at });
    }

    // ======================================================================
    // Custom dropdown (keyboard + mouse), same as the Screening List
    // ======================================================================
    const dropdowns = [];

    function createDropdown(root, onChange) {
        const btn = root.querySelector('.dropdown-btn');
        const menu = root.querySelector('.dropdown-menu');
        const valueEl = root.querySelector('.dropdown-value');
        const btnDot = btn.querySelector('.dot');
        const card = root.closest('.filter-card');
        const options = Array.from(menu.querySelectorAll('.dropdown-option'));
        let value = 'all';
        let focusIdx = -1;

        options.forEach(o => o.querySelector('.dot').style.setProperty('--dot', o.dataset.dot));

        const isOpen = () => root.classList.contains('open');

        function highlight(i) {
            options.forEach((o, k) => o.classList.toggle('is-focused', k === i));
            focusIdx = i;
        }

        function setValue(v, silent) {
            const opt = options.find(o => o.dataset.value === v) || options[0];
            value = opt.dataset.value;
            options.forEach(o => o.setAttribute('aria-selected', String(o === opt)));
            valueEl.textContent = opt.querySelector('.opt-label').textContent;
            btnDot.style.setProperty('--dot', opt.dataset.dot);
            if (!silent) onChange(value);
        }

        function open() {
            dropdowns.forEach(d => d.close());
            root.classList.add('open');
            btn.setAttribute('aria-expanded', 'true');
            if (card) card.classList.add('is-open');
            highlight(Math.max(0, options.findIndex(o => o.dataset.value === value)));
        }

        function close() {
            root.classList.remove('open');
            btn.setAttribute('aria-expanded', 'false');
            if (card) card.classList.remove('is-open');
            highlight(-1);
        }

        function choose(i) {
            if (i < 0) return;
            setValue(options[i].dataset.value);
            close();
            btn.focus();
        }

        btn.addEventListener('click', () => (isOpen() ? close() : open()));

        btn.addEventListener('keydown', (e) => {
            if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
                e.preventDefault();
                if (!isOpen()) { open(); return; }
                const step = e.key === 'ArrowDown' ? 1 : -1;
                highlight((focusIdx + step + options.length) % options.length);
            } else if ((e.key === 'Enter' || e.key === ' ') && isOpen()) {
                e.preventDefault();
                choose(focusIdx);
            } else if (e.key === 'Escape' && isOpen()) {
                e.preventDefault();       // so select mode does not also react to this Escape
                close();
            } else if (e.key === 'Tab' && isOpen()) {
                close();
            }
        });

        options.forEach((o, i) => {
            o.addEventListener('mousedown', (e) => e.preventDefault());   // keep focus on the button
            o.addEventListener('click', () => choose(i));
            o.addEventListener('mouseenter', () => highlight(i));
        });

        const api = { root, options, setValue, close, getValue: () => value };
        dropdowns.push(api);
        return api;
    }

    document.addEventListener('click', (e) => {
        dropdowns.forEach(d => { if (!d.root.contains(e.target)) d.close(); });
    });

    const statusDD = createDropdown(document.getElementById('statusDropdown'), (v) => {
        state.status = v; state.page = 1; render();
    });
    const leftDD = createDropdown(document.getElementById('leftDropdown'), (v) => {
        state.left = v; state.page = 1; render();
    });

    document.getElementById('statusReset').addEventListener('click', () => { statusDD.setValue('all'); });
    document.getElementById('leftReset').addEventListener('click', () => { leftDD.setValue('all'); });

    // numbers next to each option ("Pending 4")
    function updateCounts() {
        statusDD.options.forEach(o => {
            const v = o.dataset.value;
            o.querySelector('.opt-count').textContent =
                v === 'all' ? applicants.length : applicants.filter(a => a.status === v).length;
        });
        leftDD.options.forEach(o => {
            const v = o.dataset.value;
            let n;
            if (v === 'all') n = applicants.length;
            else if (v === 'soon') n = applicants.filter(a => daysLeft(a) <= SOON_DAYS).length;
            else n = applicants.filter(a => daysLeft(a) > SOON_DAYS).length;
            o.querySelector('.opt-count').textContent = n;
        });
    }

    function syncFilterUi() {
        const sActive = state.status !== 'all';
        const lActive = state.left !== 'all';
        const activeCount = (sActive ? 1 : 0) + (lActive ? 1 : 0);
        filterDot.hidden = activeCount === 0;
        filterDot.textContent = String(activeCount);
        filterToggle.title = activeCount ? activeCount + ' filter' + (activeCount === 1 ? '' : 's') + ' active' : 'Show or hide filters';
        document.getElementById('statusCard').classList.toggle('is-active', sActive);
        document.getElementById('leftCard').classList.toggle('is-active', lActive);
        document.getElementById('statusReset').hidden = !sActive;
        document.getElementById('leftReset').hidden = !lActive;
    }

    // ---------- rows per page: as many as fit, so the card has no empty band ----------
    const MIN_ROWS = 4;
    const MAX_ROWS = 15;

    function fitPerPage() {
        // measure a row at its natural height (undo the stretch from the last fit first)
        tableScroll.style.removeProperty('--row-h');
        const firstRow = tableBody.querySelector('tr');
        const rowHeight = firstRow ? Math.ceil(firstRow.getBoundingClientRect().height) : 53;
        const head = tableScroll.querySelector('thead');
        const room = tableScroll.clientHeight - (head ? head.offsetHeight : 46);
        if (!rowHeight || room <= 0) return perPage;
        const rows = Math.min(MAX_ROWS, Math.max(MIN_ROWS, Math.floor((room - 4) / rowHeight)));
        // share the leftover pixels between the rows, so there is no empty band under the last one
        tableScroll.style.setProperty('--row-h', Math.max(rowHeight, Math.floor((room - 2) / rows)) + 'px');
        return rows;
    }

    // Window or filter panel changed size: re-fit, keeping the first visible applicant in view
    function refit() {
        const next = fitPerPage();
        if (next === perPage) return;
        const firstIndex = (state.page - 1) * perPage;
        perPage = next;
        state.page = Math.floor(firstIndex / perPage) + 1;
        const wasAnimating = animateRows;
        animateRows = false;
        render();
        animateRows = wasAnimating;
    }

    // ---------- filtering ----------
    function getFiltered() {
        const q = state.q.trim().toLowerCase();
        return applicants.filter(a => {
            if (state.status !== 'all' && a.status !== state.status) return false;
            if (state.left !== 'all') {
                const d = daysLeft(a);
                if (state.left === 'soon' && d > SOON_DAYS) return false;
                if (state.left === 'later' && d <= SOON_DAYS) return false;
            }
            if (q) {
                const haystack = (a.name + ' ' + a.email + ' ' + a.contact).toLowerCase();
                if (!haystack.includes(q)) return false;
            }
            return true;
        });
    }

    const filtersActive = () => state.status !== 'all' || state.left !== 'all';

    // ---------- rendering ----------
    function render() {
        const filtered = getFiltered();
        const total = filtered.length;
        const pages = Math.max(1, Math.ceil(total / perPage));
        state.page = Math.min(Math.max(1, state.page), pages);

        const start = (state.page - 1) * perPage;
        visible = filtered.slice(start, start + perPage);

        // rows
        tableBody.textContent = '';
        visible.forEach((a, index) => tableBody.appendChild(buildRow(a, index, start + index + 1)));
        tableScroll.scrollTop = 0;

        // empty state: an empty Archive says so; a search with no match offers to clear the filters
        emptyState.hidden = total !== 0;
        if (total === 0) {
            const archiveEmpty = applicants.length === 0;
            emptyTitle.textContent = archiveEmpty ? 'The Archive is empty' : 'No applicants found';
            emptyText.textContent = archiveEmpty
                ? 'Deleted applicants stay here for ' + DAYS + ' days before they are permanently deleted.'
                : 'Try a different search or clear the filters.';
            clearFilters.hidden = archiveEmpty;
        }

        // footer
        renderShowing(total, start);
        renderPagination(pages, total);

        // header checkbox + filter indicator + URL
        updateSelectAll();
        updateSelectionUi();
        syncFilterUi();
        searchClear.hidden = state.q === '';
        syncUrl();
    }

    function buildRow(a, index, number) {
        const tr = document.createElement('tr');
        tr.dataset.id = a.id;
        tr.style.setProperty('--k', index);          // row position: staggers the number <-> checkbox swap
        if (selected.has(a.id)) tr.classList.add('is-selected');
        if (animateRows) {
            tr.classList.add('row-in');
            tr.style.setProperty('--r', index);
        }

        // row number; in select mode the number gives way to the checkbox
        const tdCheck = el('td', 'col-check');
        const wrap = el('span', 'idx-wrap');
        const box = document.createElement('input');
        box.type = 'checkbox';
        box.className = 'row-check';
        box.checked = selected.has(a.id);
        box.tabIndex = selectMode ? 0 : -1;
        box.setAttribute('aria-label', 'Select ' + a.name);
        wrap.append(el('span', 'row-num', String(number)), box);
        tdCheck.appendChild(wrap);

        // name / email (full text on hover when it is cut with "...")
        const tdName = el('td', 'cell-name');
        tdName.title = a.name;
        tdName.appendChild(el('strong', null, a.name));

        const tdEmail = el('td', null, a.email);
        tdEmail.title = a.email;

        // when it was deleted, and how long is left
        const tdDate = el('td', null, fmtDate(a.archivedAt));
        tdDate.title = 'Deleted on ' + fmtDate(a.archivedAt);

        const d = daysLeft(a);
        const tdLeft = el('td');
        const leftPill = el('span', 'left-pill left-' + urgency(d), leftText(d));
        leftPill.title = 'Permanently deleted on ' + fmtDate(a.deleteAt);
        tdLeft.appendChild(leftPill);

        const tdStatus = el('td');
        tdStatus.appendChild(pill(a.status));

        // action
        const tdAction = el('td', 'col-action');
        const restore = el('button', 'btn-restore');
        restore.type = 'button';
        restore.setAttribute('aria-label', 'Restore ' + a.name);
        restore.innerHTML = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M13 3a9 9 0 0 0-9 9H1l3.89 3.89.07.14L9 12H6c0-3.87 3.13-7 7-7s7 3.13 7 7-3.13 7-7 7c-1.93 0-3.68-.79-4.94-2.06l-1.42 1.42A8.954 8.954 0 0 0 13 21a9 9 0 0 0 0-18zm-1 5v5l4.28 2.54.72-1.21-3.5-2.08V8H12z"/></svg><span>RESTORE</span>';
        restore.addEventListener('click', () => restoreIds([a.id]));
        tdAction.appendChild(restore);

        tr.append(tdCheck, tdName, tdEmail, tdDate, tdLeft, tdStatus, tdAction);
        return tr;
    }

    function renderShowing(total, start) {
        showingText.textContent = '';
        if (total === 0) {
            showingText.append('No applicants found');
        } else {
            const from = start + 1;
            const to = start + visible.length;
            showingText.append('Showing ' + from + '\u2013' + to + ' of ' + plural(total, 'applicant'));
        }
        if (selected.size > 0) {
            const shown = new Set(getFiltered().map(a => a.id));
            const hidden = Array.from(selected).filter(id => !shown.has(id)).length;
            showingText.append(' \u00B7 ');
            showingText.appendChild(el('span', 'selected-count', selected.size + ' selected'));
            if (hidden > 0) showingText.append(' (' + hidden + ' hidden by filters)');
        }
    }

    function refreshFooter() {
        renderShowing(getFiltered().length, (state.page - 1) * perPage);
    }

    function pageList(current, pages) {
        if (pages <= 7) return Array.from({ length: pages }, (_, i) => i + 1);
        const keep = new Set([1, pages, current - 1, current, current + 1]);
        const nums = [...keep].filter(n => n >= 1 && n <= pages).sort((a, b) => a - b);
        const out = [];
        nums.forEach((n, i) => {
            if (i > 0) {
                const gap = n - nums[i - 1];
                if (gap === 2) out.push(n - 1);      // a single missing number: just show it
                else if (gap > 2) out.push('gap');
            }
            out.push(n);
        });
        return out;
    }

    const CHEVRON_LEFT = 'M15.41 7.41 14 6l-6 6 6 6 1.41-1.41L10.83 12z';
    const CHEVRON_RIGHT = 'M8.59 16.59 13.17 12 8.59 7.41 10 6l6 6-6 6z';
    const DOUBLE_LEFT = 'M17.59 18 19 16.59 14.42 12 19 7.41 17.59 6l-6 6zM11 18l1.41-1.41L7.83 12l4.58-4.59L11 6l-6 6z';
    const DOUBLE_RIGHT = 'M6.41 6 5 7.41 9.58 12 5 16.59 6.41 18l6-6zM13 6l-1.41 1.41L16.17 12l-4.58 4.59L13 18l6-6z';

    function arrowButton(path, label, disabled, goTo) {
        const btn = el('button', 'page-btn');
        btn.type = 'button';
        btn.disabled = disabled;
        btn.setAttribute('aria-label', label);
        btn.innerHTML = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="' + path + '"/></svg>';
        btn.addEventListener('click', () => goToPage(goTo));
        return btn;
    }

    function renderPagination(pages, total) {
        pagination.textContent = '';
        if (total === 0 || pages <= 1) return;

        pagination.appendChild(arrowButton(DOUBLE_LEFT, 'First page', state.page === 1, 1));
        pagination.appendChild(arrowButton(CHEVRON_LEFT, 'Previous page', state.page === 1, state.page - 1));

        pageList(state.page, pages).forEach(item => {
            if (item === 'gap') {
                pagination.appendChild(el('span', 'page-gap', '\u2026'));
                return;
            }
            const btn = el('button', 'page-btn' + (item === state.page ? ' is-current' : ''), String(item));
            btn.type = 'button';
            btn.setAttribute('aria-label', 'Page ' + item);
            if (item === state.page) btn.setAttribute('aria-current', 'page');
            btn.addEventListener('click', () => goToPage(item));
            pagination.appendChild(btn);
        });

        pagination.appendChild(arrowButton(CHEVRON_RIGHT, 'Next page', state.page === pages, state.page + 1));
        pagination.appendChild(arrowButton(DOUBLE_RIGHT, 'Last page', state.page === pages, pages));
    }

    function goToPage(n) {
        if (n === state.page) return;
        state.page = n;
        render();
    }

    // ======================================================================
    // Selection mode (same behaviour as the Screening List)
    // ======================================================================
    // "All" means every applicant in the current list (search + filters), on every page
    function updateSelectAll() {
        const ids = getFiltered().map(a => a.id);
        const count = ids.filter(id => selected.has(id)).length;
        selectAll.disabled = ids.length === 0;
        selectAll.checked = ids.length > 0 && count === ids.length;
        selectAll.indeterminate = count > 0 && count < ids.length;
    }

    // the "Select all" / "Cancel + Restore + Delete" slot always takes the width of whichever one is showing
    function sizeSwap() {
        if (!selectSwap) return;
        const target = selectMode ? selectActions : selectAllBtn;
        selectSwap.style.width = target.offsetWidth + 'px';
    }

    function updateSelectionUi() {
        const n = selected.size;
        restoreSelected.disabled = busy || n === 0;
        deleteSelected.disabled = busy || n === 0;
        restoreCount.textContent = n ? '(' + n + ')' : '';
        deleteCount.textContent = n ? '(' + n + ')' : '';
        sizeSwap();
    }

    function syncRowChecks() {
        tableBody.querySelectorAll('tr').forEach(tr => {
            const on = selected.has(Number(tr.dataset.id));
            tr.classList.toggle('is-selected', on);
            const box = tr.querySelector('.row-check');
            if (box) box.checked = on;
        });
        updateSelectAll();
        updateSelectionUi();
        refreshFooter();
    }

    function setSelectMode(on, immediate) {
        if (selectMode === on) return;
        selectMode = on;
        table.classList.toggle('select-mode', on);
        toolbar.classList.toggle('is-selecting', on);

        // stagger the number <-> checkbox swap, in both directions
        table.classList.add('select-switching');
        clearTimeout(switchTimer);
        switchTimer = setTimeout(() => table.classList.remove('select-switching'), 800);

        selectAllBtn.inert = on;              // hidden controls stay out of the tab order
        selectActions.inert = !on;
        selectAll.tabIndex = on ? 0 : -1;
        tableBody.querySelectorAll('.row-check').forEach(b => { b.tabIndex = on ? 0 : -1; });

        const pending = clearTimer !== null;
        clearTimeout(clearTimer);
        clearTimer = null;

        if (on) {
            if (pending) selected.clear();    // re-entered while the old ticks were still fading
            syncRowChecks();
        } else if (immediate || reduceMotion) {
            selected.clear();
            syncRowChecks();
        } else {
            // keep the ticks until the checkboxes have shrunk away, then clear them
            updateSelectionUi();
            clearTimer = setTimeout(() => {
                clearTimer = null;
                if (selectMode) return;
                selected.clear();
                syncRowChecks();
            }, 650);
        }
    }

    function toggleRow(id, force) {
        const on = force !== undefined ? force : !selected.has(id);
        if (on) selected.add(id); else selected.delete(id);
        syncRowChecks();
    }

    // "Select all" in the top bar: enter select mode with the WHOLE list ticked (every page)
    selectAllBtn.addEventListener('click', () => {
        setSelectMode(true);
        getFiltered().forEach(a => selected.add(a.id));
        syncRowChecks();
    });

    cancelSelect.addEventListener('click', () => { if (!busy) setSelectMode(false); });

    // header checkbox: tick / untick the whole list, not just this page
    selectAll.addEventListener('change', () => {
        getFiltered().forEach(a => {
            if (selectAll.checked) selected.add(a.id);
            else selected.delete(a.id);
        });
        syncRowChecks();
    });

    tableBody.addEventListener('change', (e) => {
        if (!e.target.classList.contains('row-check')) return;
        const tr = e.target.closest('tr');
        toggleRow(Number(tr.dataset.id), e.target.checked);
    });

    // ---- press and hold a row to start selecting ----
    const HOLD_MS = 500;
    const MOVE_TOLERANCE = 8;
    let holdTimer = null;
    let holdRow = null;
    let holdX = 0;
    let holdY = 0;
    let swallowClick = false;

    function cancelHold() {
        clearTimeout(holdTimer);
        holdTimer = null;
        if (holdRow) holdRow.classList.remove('pressing');
        holdRow = null;
    }

    tableBody.addEventListener('pointerdown', (e) => {
        swallowClick = false;
        if (e.button !== undefined && e.button !== 0) return;
        if (selectMode || busy) return;
        if (e.target.closest('a, button, input, label')) return;
        const tr = e.target.closest('tr');
        if (!tr) return;

        holdRow = tr;
        holdX = e.clientX;
        holdY = e.clientY;
        tr.classList.add('pressing');

        holdTimer = setTimeout(() => {
            const id = Number(tr.dataset.id);
            cancelHold();
            swallowClick = true;                      // the click that follows the release must do nothing
            setSelectMode(true);
            toggleRow(id, true);
            if (navigator.vibrate) navigator.vibrate(12);
        }, HOLD_MS);
    });

    tableBody.addEventListener('pointermove', (e) => {
        if (!holdTimer) return;
        if (Math.abs(e.clientX - holdX) > MOVE_TOLERANCE || Math.abs(e.clientY - holdY) > MOVE_TOLERANCE) cancelHold();
    });
    ['pointerup', 'pointercancel', 'pointerleave'].forEach(type => tableBody.addEventListener(type, cancelHold));

    // keep the browser's long-press menu away while holding a row on touch screens
    tableBody.addEventListener('contextmenu', (e) => {
        if (holdRow || swallowClick) e.preventDefault();
    });

    tableBody.addEventListener('click', (e) => {
        if (swallowClick) {
            swallowClick = false;
            e.preventDefault();
            return;
        }
        if (!selectMode || busy) return;
        if (e.target.closest('a, button, .row-check')) return;     // RESTORE keeps working, checkbox has its own handler
        const tr = e.target.closest('tr');
        if (tr) toggleRow(Number(tr.dataset.id));
    });

    // ======================================================================
    // Restore (rows fly to "Applicants") and Delete forever (rows dissolve)
    // ======================================================================
    function rowsFor(idSet) {
        return Array.from(tableBody.querySelectorAll('tr')).filter(tr => idSet.has(Number(tr.dataset.id)));
    }

    // rows turn into little chips and fly to a sidebar icon
    function flyTo(rows, icon) {
        if (!rows.length || !icon || reduceMotion || !document.body.animate) {
            rows.forEach(tr => tr.classList.add('row-archiving'));
            return Promise.resolve();
        }

        const t = icon.getBoundingClientRect();
        const tx = t.left + t.width / 2;
        const ty = t.top + t.height / 2;
        const flights = [];

        rows.slice(0, 12).forEach((tr, i) => {
            const r = tr.getBoundingClientRect();
            const cx = r.left + r.width / 2;
            const cy = r.top + r.height / 2;

            const chip = el('div', 'fly-chip');
            chip.appendChild(el('span', 'fly-name', tr.querySelector('.cell-name').textContent));
            chip.style.left = cx + 'px';
            chip.style.top = cy + 'px';
            chip.style.width = r.width + 'px';
            chip.style.height = r.height + 'px';
            document.body.appendChild(chip);
            tr.classList.add('row-archiving');

            const base = 'translate(-50%, -50%)';
            const anim = chip.animate([
                { width: r.width + 'px', height: r.height + 'px', opacity: 1,
                  transform: base + ' translate(0, 0) scale(1)',
                  easing: 'cubic-bezier(0.22, 1, 0.36, 1)', offset: 0 },
                { width: '210px', height: '46px', opacity: 1,
                  transform: base + ' translate(0, -14px) scale(1.04)',
                  easing: 'cubic-bezier(0.55, 0, 0.2, 1)', offset: 0.3 },
                { width: '210px', height: '46px', opacity: 0.1,
                  transform: base + ' translate(' + (tx - cx) + 'px, ' + (ty - cy) + 'px) scale(0.1) rotate(12deg)',
                  offset: 1 }
            ], { duration: 850, delay: Math.min(i, 9) * 70, easing: 'linear', fill: 'both' });

            flights.push(anim.finished.then(() => chip.remove()).catch(() => chip.remove()));
        });

        // rows beyond the 12th just fade
        rows.slice(12).forEach(tr => tr.classList.add('row-archiving'));
        return Promise.all(flights);
    }

    // rows slide away and fade, one after another (nothing is left to fly to)
    function dissolve(rows) {
        rows.forEach((tr, i) => {
            tr.style.setProperty('--k', i);
            tr.classList.add('row-purging');
        });
        return sleep(reduceMotion ? 0 : 480 + Math.min(rows.length, 10) * 40);
    }

    // applicants gone from the list: drop them from the selection, leave select mode when it is empty
    function afterRemoval(idSet) {
        idSet.forEach(id => selected.delete(id));
        busy = false;
        if (selectMode && selected.size === 0) setSelectMode(false, true);
        updateCounts();
        render();
    }

    function bumpApplicants() {
        const link = document.getElementById('applicantsMainLink');
        if (!link) return;
        link.classList.remove('bump');
        void link.offsetWidth;
        link.classList.add('bump');
        setTimeout(() => link.classList.remove('bump'), 700);
    }

    async function restoreIds(ids) {
        if (!ids.length || busy) return;
        busy = true;
        updateSelectionUi();

        const idSet = new Set(ids);
        const rows = rowsFor(idSet);
        const originalTimes = {};                     // so Undo puts them back with the same 30-day clock
        applicants.filter(a => idSet.has(a.id)).forEach(a => { originalTimes[a.id] = a.archivedAt; });

        const icon = document.querySelector('#applicantsMainLink .nav-icon');
        const request = HR.archiveRequest('restore', ids).then(r => ({ ok: true, r }), err => ({ ok: false, err }));
        const [result] = await Promise.all([request, flyTo(rows, icon)]);

        if (!result.ok) {
            rows.forEach(tr => tr.classList.remove('row-archiving'));
            busy = false;
            updateSelectionUi();
            HR.toast(result.err.message, { error: true });
            return;
        }

        const removed = applicants.filter(a => idSet.has(a.id));
        applicants = applicants.filter(a => !idSet.has(a.id));
        afterRemoval(idSet);
        HR.setArchiveBadge(result.r.count);
        bumpApplicants();

        HR.toast(plural(removed.length, 'applicant') + ' restored to the Screening List.', {
            actionLabel: 'Undo',
            onAction: async () => {
                try {
                    const back = await HR.archiveRequest('archive', removed.map(a => a.id), { at: originalTimes });
                    applicants = applicants.concat(removed).sort((x, y) => y.archivedAt - x.archivedAt);
                    updateCounts();
                    render();
                    HR.setArchiveBadge(back.count);
                } catch (err) {
                    HR.toast(err.message, { error: true });
                }
            }
        });
    }

    async function purgeIds(ids) {
        if (!ids.length || busy) return;
        busy = true;
        updateSelectionUi();

        const idSet = new Set(ids);
        const rows = rowsFor(idSet);

        const request = HR.archiveRequest('purge', ids).then(r => ({ ok: true, r }), err => ({ ok: false, err }));
        const [result] = await Promise.all([request, dissolve(rows)]);

        if (!result.ok) {
            rows.forEach(tr => { tr.classList.remove('row-purging'); tr.style.removeProperty('--k'); });
            busy = false;
            updateSelectionUi();
            HR.toast(result.err.message, { error: true });
            return;
        }

        const removedCount = applicants.filter(a => idSet.has(a.id)).length;
        applicants = applicants.filter(a => !idSet.has(a.id));
        afterRemoval(idSet);
        HR.setArchiveBadge(result.r.count);
        HR.toast(plural(removedCount, 'applicant') + ' permanently deleted.');     // no Undo: it is final
    }

    restoreSelected.addEventListener('click', () => restoreIds(Array.from(selected)));

    // ---- "Delete permanently?" confirmation ----
    function openDeleteModal(ids) {
        if (!ids.length || busy) return;
        pendingDelete = ids;
        deleteTitle.textContent = ids.length === 1 ? 'Delete this applicant permanently?' : 'Delete ' + ids.length + ' applicants permanently?';
        deleteText.textContent = (ids.length === 1 ? 'This applicant' : 'These applicants') + ' will be removed for good and cannot be restored.';
        deleteModal.classList.add('active');
        cancelDelete.focus();
    }

    function closeDeleteModal() {
        deleteModal.classList.remove('active');
        pendingDelete = [];
    }

    deleteSelected.addEventListener('click', () => openDeleteModal(Array.from(selected)));
    cancelDelete.addEventListener('click', closeDeleteModal);
    confirmDelete.addEventListener('click', () => {
        const ids = pendingDelete.slice();
        closeDeleteModal();
        purgeIds(ids);
    });
    deleteModal.addEventListener('click', (e) => { if (e.target === deleteModal) closeDeleteModal(); });

    // Escape: close the confirmation first; otherwise leave select mode
    // (unless it was already used to close a dropdown / clear the search / close the logout box)
    document.addEventListener('keydown', (e) => {
        if (e.key !== 'Escape') return;
        if (deleteModal.classList.contains('active')) {
            e.preventDefault();
            closeDeleteModal();
            return;
        }
        if (e.defaultPrevented || !selectMode || busy) return;
        if (document.getElementById('logoutModal')?.classList.contains('active')) return;
        setSelectMode(false);
    });

    // ---------- URL (so a refresh keeps the filters) ----------
    function syncUrl() {
        const p = new URLSearchParams();
        if (config.demo) p.set('demo', '1');
        if (state.q.trim()) p.set('q', state.q.trim());
        if (state.status !== 'all') p.set('status', state.status);
        if (state.left !== 'all') p.set('left', state.left);
        if (state.page > 1) p.set('page', state.page);
        const qs = p.toString();
        window.history.replaceState({}, document.title, window.location.pathname + (qs ? '?' + qs : ''));
    }

    function readUrl() {
        const p = new URLSearchParams(window.location.search);
        const norm = v => String(v || '').trim().toLowerCase();
        const statusMap = { 'pending': 'Pending', 'in progress': 'In Progress', 'completed': 'Completed' };
        const leftMap = { 'soon': 'soon', 'later': 'later' };

        const status = statusMap[norm(p.get('status'))];
        const left = leftMap[norm(p.get('left'))];
        if (status) state.status = status;
        if (left) state.left = left;

        state.q = p.get('q') || '';
        state.page = parseInt(p.get('page'), 10) || 1;

        statusDD.setValue(state.status, true);
        leftDD.setValue(state.left, true);
        searchInput.value = state.q;
    }

    // ---------- events ----------
    let searchTimer = null;
    searchInput.addEventListener('input', () => {
        clearTimeout(searchTimer);
        searchTimer = setTimeout(() => {
            state.q = searchInput.value;
            state.page = 1;
            render();
        }, 120);
        searchClear.hidden = searchInput.value === '';
    });

    searchInput.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && searchInput.value !== '') {
            e.preventDefault();
            searchClear.click();
        }
    });

    searchClear.addEventListener('click', () => {
        searchInput.value = '';
        state.q = '';
        state.page = 1;
        render();
        searchInput.focus();
    });

    filterToggle.addEventListener('click', () => {
        const willCollapse = !filterPanel.classList.contains('collapsed');
        dropdowns.forEach(d => d.close());
        filterPanel.classList.remove('is-settled');          // clip the menus again while it slides
        filterPanel.classList.toggle('collapsed', willCollapse);
        filterToggle.setAttribute('aria-expanded', String(!willCollapse));
        filterPanel.inert = willCollapse;                    // keep the hidden dropdowns out of the tab order
        if (!willCollapse) {
            // safety net in case the transitionend below does not fire
            setTimeout(() => {
                if (!filterPanel.classList.contains('collapsed')) filterPanel.classList.add('is-settled');
            }, 500);
        }
    });

    let resizeTimer = null;
    window.addEventListener('resize', () => {
        clearTimeout(resizeTimer);
        resizeTimer = setTimeout(() => { sizeSwap(); refit(); }, 150);
    });

    // the table grows/shrinks while the filter panel slides, so re-fit when it settles
    filterPanel.addEventListener('transitionend', (e) => {
        if (e.target !== filterPanel || e.propertyName !== 'grid-template-rows') return;
        if (!filterPanel.classList.contains('collapsed')) filterPanel.classList.add('is-settled');
        refit();
    });

    clearFilters.addEventListener('click', () => {
        state.q = '';
        state.page = 1;
        searchInput.value = '';
        statusDD.setValue('all', true);
        leftDD.setValue('all', true);
        state.status = 'all';
        state.left = 'all';
        render();
    });

    // ---------- keeping the list honest ----------
    // Re-read the Archive when the page comes back from the Back button, when another tab
    // changes it, and once a minute (an applicant can reach day 30 while the page is open).
    function refreshFromStorage() {
        if (busy) return;
        loadArchived();
        const present = new Set(applicants.map(a => a.id));
        Array.from(selected).forEach(id => { if (!present.has(id)) selected.delete(id); });
        updateCounts();
        const wasAnimating = animateRows;
        animateRows = false;
        render();
        animateRows = wasAnimating;
    }

    window.addEventListener('pageshow', (e) => { if (e.persisted) refreshFromStorage(); });
    window.addEventListener('storage', (e) => {
        if (['hr_archived', 'hr_archived_at', 'hr_deleted'].includes(e.key)) refreshFromStorage();
    });
    setInterval(() => {
        if (!busy && HR.purgeExpired().length) refreshFromStorage();
    }, 60000);

    // ---------- start ----------
    seedDemo();
    loadArchived();
    sizeSwap();
    if (document.fonts && document.fonts.ready) document.fonts.ready.then(sizeSwap);   // re-measure once the font has loaded
    updateCounts();
    perPage = fitPerPage();
    readUrl();
    render();
    refit();                // now that a real row exists, measure again for an exact fit
    animateRows = true;     // later renders (search, filters, pages) animate the rows in
});
}

/* ===== PAGE : dashboardhr.js (runs only on: dashboardhr) ===== */
if (document.documentElement.getAttribute('data-page') === 'dashboardhr') {
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
                    labels: ['Passed', 'Rejected'],
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
                            showCenter(n, (i === 0 ? 'Passed' : 'Rejected') + ' · ' + Math.round(n / total * 100) + '%');
                        } else {
                            showCenter(defaults.value, defaults.label);
                        }
                    },
                    onClick: (evt, elements) => {
                        if (elements.length > 0) {
                            const index = elements[0].index;
                            const statusFilter = index === 0 ? 'Passed' : 'Rejected';
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
        [['legendPassed', 'Passed', chartData.passed], ['legendFailed', 'Rejected', chartData.failed]].forEach(([id, status, n]) => {
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
}

/* ===== PAGE : details.js (runs only on: details) ===== */
if (document.documentElement.getAttribute('data-page') === 'details') {
/* ==========================================================================
   Applicant Details (hr/details.php)
   Load AFTER hr-common.js (it uses HR.toast). The page prints  window.DETAILS  before this file:
     id, name, email      the applicant
     endpoint             hr/static-details.php now, the backend later (same two POST actions)
     today, maxDate, slots  for the "Adjust Schedule" form (weekdays only, same slots as the applicant's picker)
     tab, locked          the open tab, and the message for every locked tab
     interview            the schedule (null when the tab is not open for this applicant)
     videoUrl             the video file (empty until the upload storage is connected)

   Part 1  tabs (locked tabs explain why they are locked)
   Part 2  video
   Part 3  interview schedule: Available -> email, Not available -> new schedule -> email
           (the date + time pickers are the same as on the applicant result page)
   ========================================================================== */
(function () {
    'use strict';

    var D = window.DETAILS || {};

    function $(id) { return document.getElementById(id); }
    function esc(value) {
        return String(value == null ? '' : value).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }
    function toast(message, opts) {
        if (window.HR && typeof window.HR.toast === 'function') return window.HR.toast(message, opts || {});
        return null;
    }

    /* ======================================================================
       PART 1: tabs
       ====================================================================== */
    var tabs = Array.prototype.slice.call(document.querySelectorAll('.tab'));
    var panels = Array.prototype.slice.call(document.querySelectorAll('.panel'));

    // One red line under the active tab that SLIDES to the next tab (instead of each tab drawing its own)
    var tabBar = $('tabs');
    var ink = null;
    if (tabBar) {
        ink = document.createElement('span');
        ink.className = 'tab-ink no-anim';
        ink.setAttribute('aria-hidden', 'true');
        tabBar.appendChild(ink);
    }
    function moveInk(instant) {
        if (!ink) return;
        var cur = tabs.filter(function (t) { return t.classList.contains('is-active'); })[0];
        if (!cur) return;
        var w = cur.offsetWidth * 0.64;
        ink.classList.toggle('no-anim', !!instant);
        ink.style.setProperty('--ink-w', w + 'px');
        ink.style.setProperty('--ink-x', (cur.offsetLeft + (cur.offsetWidth - w) / 2) + 'px');
        if (instant) { void ink.offsetWidth; ink.classList.remove('no-anim'); }
    }

    // The bars and the ring fill up each time the AI Evaluation tab opens
    function playEvaluation() {
        var panel = $('panel-evaluation');
        if (!panel) return;
        panel.classList.remove('is-in');
        void panel.offsetWidth;
        window.requestAnimationFrame(function () {
            window.requestAnimationFrame(function () { panel.classList.add('is-in'); });
        });
    }

    function activate(key, silent) {
        var btn = tabs.filter(function (t) { return t.dataset.tab === key; })[0];
        var panel = $('panel-' + key);
        if (!btn || !panel) return;

        if (btn.classList.contains('is-locked')) return;

        tabs.forEach(function (t) {
            var on = t === btn;
            t.classList.toggle('is-active', on);
            t.setAttribute('aria-selected', on ? 'true' : 'false');
            t.tabIndex = on ? 0 : -1;
        });
        panels.forEach(function (p) {
            var on = p === panel;
            p.hidden = !on;
            p.classList.toggle('is-active', on);
        });

        if (!silent) {                                  // keep the tab in the address (reload / share)
            try {
                var url = new URL(window.location.href);
                if (key === 'overview') url.searchParams.delete('tab'); else url.searchParams.set('tab', key);
                window.history.replaceState(null, '', url.toString());
            } catch (e) { /* old browser: the tab still changes */ }
        }
        moveInk(false);
        if (!silent) {                                  // every tab opens from its very top, never from the old scroll spot
            var sc = $('details');
            if (sc && sc.scrollTop > 0) {
                sc.style.scrollBehavior = 'auto';       // jump at once (a smooth glide would be cut short by the header changing size)
                sc.scrollTop = 0;
                sc.style.scrollBehavior = '';
                wrapReset();
            }
        }
        if (key === 'evaluation') playEvaluation();
    }

    // back at the top: the header opens up again straight away
    function wrapReset() {
        var wrap = $('detailsSticky');
        if (wrap) { wrap.classList.remove('is-compact'); wrap.classList.remove('is-stuck'); }
    }

    function explainLocked(btn) {
        toast((D.locked && D.locked[btn.dataset.tab]) || 'This section is not available yet.', { duration: 4500 });
        btn.classList.remove('shake');
        void btn.offsetWidth;
        btn.classList.add('shake');
        setTimeout(function () { btn.classList.remove('shake'); }, 450);
    }

    tabs.forEach(function (btn, i) {
        btn.addEventListener('click', function () {
            if (btn.classList.contains('is-locked')) { explainLocked(btn); return; }
            activate(btn.dataset.tab);
        });
        btn.addEventListener('keydown', function (e) {
            var next = null;
            if (e.key === 'ArrowRight') next = tabs[(i + 1) % tabs.length];
            else if (e.key === 'ArrowLeft') next = tabs[(i - 1 + tabs.length) % tabs.length];
            else if (e.key === 'Home') next = tabs[0];
            else if (e.key === 'End') next = tabs[tabs.length - 1];
            if (!next) return;
            e.preventDefault();
            next.focus();
            if (!next.classList.contains('is-locked')) activate(next.dataset.tab);
        });
    });

    moveInk(true);
    window.addEventListener('resize', function () { moveInk(true); });
    if (document.fonts && document.fonts.ready) document.fonts.ready.then(function () { moveInk(true); });

    // The top part (title, applicant card, tabs) stays put while the page scrolls; once the page has
    // scrolled it shrinks to a slim bar so the content keeps most of the screen.
    (function () {
        var wrap = $('detailsSticky');
        var scroller = $('details');                   // <main class="main-content details"> is what scrolls
        if (!wrap || !scroller) return;
        var compact = false;
        var queued = false;
        var lockUntil = 0;                              // after a change, wait for the animation to finish before deciding again

        function update() {
            queued = false;
            var y = scroller.scrollTop;
            var room = scroller.scrollHeight - scroller.clientHeight;
            wrap.classList.toggle('is-stuck', y > 4);

            if (Date.now() < lockUntil) return;
            // two thresholds (80 down / 10 up) so it never flickers at the edge; short pages never shrink
            var next = compact;
            if (!compact && y > 80 && room > 260) next = true;
            else if (compact && y < 10) next = false;
            if (next === compact) return;

            compact = next;
            wrap.classList.toggle('is-compact', compact);
            lockUntil = Date.now() + 480;
            window.setTimeout(update, 500);            // look again once the header has settled
        }

        scroller.addEventListener('scroll', function () {
            if (!queued) { queued = true; window.requestAnimationFrame(update); }
        }, { passive: true });
        update();
    })();

    if (D.tab === 'evaluation') playEvaluation();

    // "Back" returns to the exact page / filters of the list you came from
    var back = $('backLink');
    if (back) {
        back.addEventListener('click', function (e) {
            if (document.referrer && /\/(screeninglist|dashboardhr)\.php/i.test(document.referrer) && window.history.length > 1) {
                e.preventDefault();
                window.history.back();
            }
        });
    }

    /* ======================================================================
       PART 2: video
       ====================================================================== */
    var playBtn = $('videoPlayBtn');
    if (playBtn) {
        playBtn.addEventListener('click', function () {
            var video = $('videoEl');
            if (video) {
                video.scrollIntoView({ behavior: 'smooth', block: 'center' });
                var p = video.play();
                if (p && typeof p.catch === 'function') p.catch(function () { /* the person can press play */ });
                return;
            }
            var empty = $('videoEmpty');
            if (empty) {
                empty.classList.add('is-pulse');
                setTimeout(function () { empty.classList.remove('is-pulse'); }, 900);
            }
            toast('The video file is not stored yet, so it can not be played here.', { duration: 4500 });
        });
    }

    /* ======================================================================
       PART 3: interview schedule
       ====================================================================== */
    var root = $('interviewRoot');
    if (!root || !D.interview) return;

    var iv = D.interview;
    var mode = 'summary';          // 'summary' | 'adjust'
    var adjustFrom = '';           // 'reject' (Not available) | 'resched' (Re-schedule button)
    var busy = false;
    var firstPaint = true;         // the whole tab animates in once; later changes only animate what changed
    var lastEmail = D.lastEmail || null;     // the email that was sent (for "View email"; kept by the server)
    var pick = { date: '', time: '' };       // what HR chose in "Adjust Schedule" (time is 24-hour "13:00")

    var MONTHS = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
    var DOW = ['Su', 'Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa'];

    var ICON = {
        cal: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M19 3h-1V1h-2v2H8V1H6v2H5c-1.11 0-1.99.9-1.99 2L3 19c0 1.1.89 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm0 16H5V8h14v11zM7 10h5v5H7z"/></svg>',
        clock: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M11.99 2C6.47 2 2 6.48 2 12s4.47 10 9.99 10C17.52 22 22 17.52 22 12S17.52 2 11.99 2zM12 20c-4.42 0-8-3.58-8-8s3.58-8 8-8 8 3.58 8 8-3.58 8-8 8zm.5-13H11v6l5.25 3.15.75-1.23-4.5-2.67z"/></svg>',
        chat: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M21 6h-2v9H6v2c0 .55.45 1 1 1h11l4 4V7c0-.55-.45-1-1-1zm-4 6V3c0-.55-.45-1-1-1H3c-.55 0-1 .45-1 1v14l4-4h10c.55 0 1-.45 1-1z"/></svg>',
        ok: '<svg class="iv-ok-svg" viewBox="0 0 80 80" aria-hidden="true"><circle class="ok-circle" cx="40" cy="40" r="36"/><path class="ok-tick" d="M24 41l11 11 21-23"/></svg>',
        // the picker icons (same drawings as the applicant's page)
        pCal: '<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3.5" y="5" width="17" height="15" rx="2.5"/><path d="M3.5 10h17M8 3v4M16 3v4"/><path d="M8 14h.01M12 14h.01M16 14h.01M8 17.5h.01M12 17.5h.01"/></svg>',
        pClock: '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 7v5.5l3.5 2"/></svg>',
        pChev: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 9l6 6 6-6"/></svg>',
        pNext: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M9 6l6 6-6 6"/></svg>',
        pTick: '<svg class="picker__check" viewBox="0 0 24 24" aria-hidden="true"><path d="M9 16.2 4.8 12l-1.4 1.4L9 19 21 7l-1.4-1.4z"/></svg>',
        tick: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12.5l4.5 4.5L19 7.5"/></svg>'
    };

    /* ---------- talking to the server (hr/static-details.php now, the backend later) ---------- */
    function post(payload) {
        return fetch(D.endpoint, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
            body: JSON.stringify(Object.assign({ id: D.id }, payload))
        }).then(function (res) {
            return res.json().catch(function () { return {}; }).then(function (data) {
                if (!res.ok || data.status !== 'success') {
                    throw new Error(data.message || 'We could not reach the server. Please try again.');
                }
                return data;
            });
        });
    }

    /* ---------- small helpers ---------- */
    function two(n) { return (n < 10 ? '0' : '') + n; }
    function ymd(y, m, d) { return y + '-' + two(m + 1) + '-' + two(d); }          // m = 0..11

    // "2026-09-27" -> "September 27, 2026" (no timezone maths, so the day never shifts)
    function pretty(value) {
        var p = String(value).split('-');
        return MONTHS[Number(p[1]) - 1] + ' ' + Number(p[2]) + ', ' + p[0];
    }

    function weekend(value) {
        var p = String(value).split('-');
        var day = new Date(Number(p[0]), Number(p[1]) - 1, Number(p[2])).getDay();
        return day === 0 || day === 6;
    }

    function slotLabel(value) {
        var s = (D.slots || []).filter(function (x) { return x.value === value; })[0];
        return s ? s.label : '';
    }

    // returns '' when the date is fine, otherwise the message (same rules as the server)
    function dateProblem(value) {
        if (!/^\d{4}-\d{2}-\d{2}$/.test(value)) return 'Please choose a date.';
        if (value < D.today) return 'Please choose today or a later date.';
        if (value > D.maxDate) return 'Please choose a date within the next 60 days.';
        if (weekend(value)) return 'Interviews are held Monday to Friday. Please choose a weekday.';
        return '';
    }

    /* ---------- drawing ---------- */
    function badge() {
        if (mode === 'adjust' && adjustFrom === 'reject') return { cls: 'is-no', text: 'Not Available' };
        if (iv.status === 'confirmed') return { cls: 'is-confirmed', text: 'Confirmed' };
        if (iv.status === 'rescheduled') return { cls: 'is-rescheduled', text: 'Rescheduled' };
        return { cls: 'is-awaiting', text: 'Awaiting HR Confirmation' };
    }

    function subtitle() {
        if (mode === 'adjust' && adjustFrom === 'resched') return 'Current Schedule';
        if (iv.status === 'rescheduled') return 'New Schedule (set by HR)';
        return 'Applicant\u2019s Preferred Schedule';
    }

    function row(icon, label, value) {
        return '<div class="iv-row"><span class="iv-ico">' + icon + '</span>' +
            '<div class="iv-text"><span class="iv-label">' + esc(label) + '</span>' +
            '<span class="iv-value">' + esc(value) + '</span></div></div>';
    }

    // Chosen > HR answers > Applicant is emailed
    function tracker() {
        var answered = iv.status !== 'awaiting';
        var steps = [
            { s: 'done', title: 'Schedule chosen', text: 'Applicant picked ' + iv.preferred.date },
            { s: answered ? 'done' : 'current',
              title: answered ? (iv.status === 'confirmed' ? 'HR confirmed' : 'HR set a new schedule') : 'HR response',
              text: answered ? 'Answered' : 'Waiting for you' },
            { s: answered ? 'done' : 'todo', title: 'Applicant emailed', text: answered ? D.email : 'After you answer' }
        ];
        return '<ol class="iv-track" aria-label="Interview progress">' + steps.map(function (st, i) {
            var next = steps[i + 1];
            var line = next ? '<span class="iv-step-line' + (next.s === 'done' ? ' is-full' : (next.s === 'current' ? ' is-half' : '')) + '" aria-hidden="true"></span>' : '';
            return '<li class="iv-step is-' + st.s + '" style="--n:' + i + '"' + (st.s === 'current' ? ' aria-current="step"' : '') + '>' +
                '<div class="iv-step-top"><span class="iv-step-dot">' + (st.s === 'done' ? ICON.tick : '<b>' + (i + 1) + '</b>') + '</span>' + line + '</div>' +
                '<div class="iv-step-text"><strong>' + esc(st.title) + '</strong><small>' + esc(st.text) + '</small></div></li>';
        }).join('') + '</ol>';
    }

    function adjustForm() {
        var slots = (D.slots || []).map(function (s, k) {
            return '<li class="picker__option" role="option" aria-selected="false" data-value="' + esc(s.value) + '" style="--k:' + k + '">' +
                '<span class="picker__optlabel">' + esc(s.label) + '</span>' + ICON.pTick + '</li>';
        }).join('');

        return '<div class="iv-adjust">' +
            '<h3 class="iv-adjust-title">Adjust Schedule</h3>' +
            '<div class="iv-fields">' +
              '<div class="iv-field">' +
                '<span class="iv-flabel" id="ivDateLabel">Preferred Date<b>*</b></span>' +
                '<div class="picker" id="ivDatePicker">' +
                  '<button type="button" class="picker__btn" id="ivDateBtn" aria-haspopup="dialog" aria-expanded="false" aria-labelledby="ivDateLabel ivDateValue">' +
                    '<span class="picker__icon">' + ICON.pCal + '</span>' +
                    '<span class="picker__value is-placeholder" id="ivDateValue">Select a date</span>' +
                    '<span class="picker__chevron">' + ICON.pChev + '</span></button>' +
                  '<div class="picker__pop pcal" id="ivDatePop" role="dialog" aria-label="Choose a date"></div>' +
                '</div></div>' +
              '<div class="iv-field">' +
                '<span class="iv-flabel" id="ivTimeLabel">Available Time<b>*</b></span>' +
                '<div class="picker" id="ivTimePicker">' +
                  '<button type="button" class="picker__btn" id="ivTimeBtn" aria-haspopup="listbox" aria-expanded="false" aria-labelledby="ivTimeLabel ivTimeValue">' +
                    '<span class="picker__icon">' + ICON.pClock + '</span>' +
                    '<span class="picker__value is-placeholder" id="ivTimeValue">Select a time</span>' +
                    '<span class="picker__chevron">' + ICON.pChev + '</span></button>' +
                  '<ul class="picker__pop picker__menu" id="ivTimeMenu" role="listbox" aria-labelledby="ivTimeLabel" tabindex="-1">' + slots + '</ul>' +
                '</div></div>' +
            '</div>' +
            '<div class="iv-new">' +
              '<span class="iv-new-title">New schedule</span>' +
              '<span class="iv-new-item">' + ICON.cal + '<span id="ivPickedDate">Not chosen yet</span></span>' +
              '<span class="iv-new-item">' + ICON.clock + '<span id="ivPickedTime">Not chosen yet</span></span>' +
            '</div>' +
            '<p class="iv-hint">Interviews are held Monday to Friday. The applicant will be emailed the new schedule.</p>' +
            '<p class="iv-error" id="ivError" role="alert"></p>' +
            '<div class="iv-adjust-actions">' +
              '<button type="button" class="iv-btn cancel" data-act="cancel">Cancel</button>' +
              '<button type="button" class="iv-btn send" data-act="send" id="ivSend" disabled>Send New Schedule</button>' +
            '</div></div>';
    }

    function render() {
        var b = badge();
        var html = '<div class="iv-head"><h3 class="iv-title">Interview Schedule</h3>' +
            '<span class="iv-badge ' + b.cls + '">' + esc(b.text) + '</span></div>' + tracker() +
            '<p class="iv-sub-text">' + esc(subtitle()) + '</p>';

        html += '<div class="iv-card">' +
            row(ICON.cal, 'Date', iv.current.date) +
            row(ICON.clock, 'Time', iv.current.time) +
            (mode === 'summary' ? row(ICON.chat, 'Interview Type', iv.type) : '') +
            '</div>';

        if (mode === 'adjust') {
            html += adjustForm();
        } else if (iv.status === 'awaiting') {
            html += '<div class="iv-foot"><div class="iv-ask">' +
                '<p>Is this schedule available?</p>' +
                '<div class="iv-actions">' +
                  '<button type="button" class="iv-btn yes" data-act="approve">Available</button>' +
                  '<button type="button" class="iv-btn no" data-act="reject">Not Available</button>' +
                '</div></div></div>';
        } else {
            var rescheduled = iv.status === 'rescheduled';
            html += (rescheduled
                    ? '<p class="iv-was">The applicant\u2019s first choice was <s>' + esc(iv.preferred.date) + ' at ' + esc(iv.preferred.time) + '</s>.</p>'
                    : '') +
                '<div class="iv-foot"><div class="iv-ok">' + ICON.ok + '<div>' +
                  '<strong>' + (rescheduled ? 'New Schedule Sent' : 'Schedule Confirmed') + '</strong>' +
                  '<span>' + (rescheduled
                        ? 'The new schedule was emailed to ' + esc(D.email) + '.'
                        : 'The applicant was emailed the confirmed schedule.') + '</span>' +
                '</div></div>' +
                '<div class="iv-actions">' +
                  (lastEmail ? '<button type="button" class="iv-btn neutral" data-act="email">View Email</button>' : '') +
                  '<button type="button" class="iv-btn neutral" data-act="resched">Re-schedule</button>' +
                '</div></div>';
        }

        root.classList.toggle('iv--first', firstPaint);
        firstPaint = false;
        root.innerHTML = html;
    }

    function setMode(next, from, focusDate) {
        mode = next;
        adjustFrom = from || '';
        pick = { date: '', time: '' };
        render();
        if (focusDate) {
            var d = $('ivDateBtn');
            if (d) d.focus({ preventScroll: true });
        }
    }

    /* ---------- the date + time pickers (same behaviour as the applicant's result page) ---------- */
    var viewY = 0, viewM = 0, active = -1;

    function pickerParts(kind) {
        return kind === 'date'
            ? { box: $('ivDatePicker'), btn: $('ivDateBtn') }
            : { box: $('ivTimePicker'), btn: $('ivTimeBtn') };
    }

    function setOpen(kind, open) {
        var p = pickerParts(kind);
        if (!p.box) return;
        p.box.classList.toggle('open', open);
        p.btn.setAttribute('aria-expanded', open ? 'true' : 'false');
    }

    function closePickers(except) {
        if (except !== 'date') setOpen('date', false);
        if (except !== 'time') setOpen('time', false);
    }

    function pickerOpen() { return !!root.querySelector('.picker.open'); }

    function fieldError(which, message) {
        var err = $('ivError');
        if (err) {
            err.textContent = message || '';
            err.classList.remove('is-in');
            if (message) { void err.offsetWidth; err.classList.add('is-in'); }
        }
        var d = $('ivDateBtn'), t = $('ivTimeBtn');
        if (d) d.classList.toggle('has-error', !!message && which === 'date');
        if (t) t.classList.toggle('has-error', !!message && which === 'time');
    }

    function refreshPick(bump) {
        var okDate = pick.date && !dateProblem(pick.date);
        var pd = $('ivPickedDate'), pt = $('ivPickedTime'), send = $('ivSend');
        if (!pd || !pt || !send) return;
        pd.textContent = okDate ? pretty(pick.date) : 'Not chosen yet';
        pt.textContent = pick.time ? slotLabel(pick.time) : 'Not chosen yet';
        pd.classList.toggle('is-set', !!okDate);
        pt.classList.toggle('is-set', !!pick.time);
        send.disabled = busy || !(okDate && pick.time);
        send.classList.toggle('is-ready', !send.disabled);
        if (bump) {
            var el = bump === 'date' ? pd : pt;
            el.classList.remove('bump');
            void el.offsetWidth;
            el.classList.add('bump');
        }
    }

    function drawCalendar(direction) {
        var pop = $('ivDatePop');
        if (!pop) return;
        var minP = D.today.split('-').map(Number);
        var maxP = D.maxDate.split('-').map(Number);
        var first = new Date(viewY, viewM, 1).getDay();
        var days = new Date(viewY, viewM + 1, 0).getDate();
        var here = viewY * 12 + viewM;
        var canPrev = here > minP[0] * 12 + minP[1] - 1;
        var canNext = here < maxP[0] * 12 + maxP[1] - 1;
        var cur = iv.current.raw_date;

        var html = '<div class="pcal__head">' +
            '<button type="button" class="pcal__nav pcal__nav--prev" aria-label="Previous month"' + (canPrev ? '' : ' disabled') + '>' + ICON.pNext + '</button>' +
            '<div class="pcal__title" aria-live="polite">' + MONTHS[viewM] + ' ' + viewY + '</div>' +
            '<button type="button" class="pcal__nav pcal__nav--next" aria-label="Next month"' + (canNext ? '' : ' disabled') + '>' + ICON.pNext + '</button>' +
            '</div><div class="pcal__dow">' + DOW.map(function (d) { return '<span>' + d + '</span>'; }).join('') + '</div>' +
            '<div class="pcal__grid' + (direction ? ' pcal__grid--' + direction : '') + '">';

        for (var i = 0; i < first; i++) html += '<span></span>';
        for (var d = 1; d <= days; d++) {
            var value = ymd(viewY, viewM, d);
            var off = value < D.today || value > D.maxDate || weekend(value);
            var cls = 'pcal__day' + (weekend(value) ? ' is-weekend' : '') + (value === D.today ? ' is-today' : '') +
                (value === cur ? ' is-current' : '') + (value === pick.date ? ' is-selected' : '');
            html += '<button type="button" class="' + cls + '" data-value="' + value + '"' + (off ? ' disabled' : '') +
                (value === cur ? ' title="Current schedule"' : '') + '>' + d + '</button>';
        }
        html += '</div><p class="pcal__hint">Interviews are held Monday to Friday.' +
            '<span class="pcal__legend"><i></i>Current schedule</span></p>';
        pop.innerHTML = html;
    }

    function toggleDate() {
        var p = pickerParts('date');
        var open = !p.box.classList.contains('open');
        closePickers('date');
        if (open) {
            var start = pick.date || (iv.current.raw_date >= D.today ? iv.current.raw_date : D.today);
            var parts = start.split('-').map(Number);
            viewY = parts[0]; viewM = parts[1] - 1;
            drawCalendar(null);
        }
        setOpen('date', open);
    }

    function timeOptionsList() { return Array.prototype.slice.call(root.querySelectorAll('.picker__option')); }

    function setActive(i) {
        var opts = timeOptionsList();
        if (!opts.length) return;
        active = Math.max(0, Math.min(opts.length - 1, i));
        opts.forEach(function (o, k) { o.classList.toggle('is-focused', k === active); });
        opts[active].scrollIntoView({ block: 'nearest' });
    }

    function openTime() {
        closePickers('time');
        setOpen('time', true);
        var opts = timeOptionsList();
        var sel = opts.filter(function (o) { return o.getAttribute('aria-selected') === 'true'; })[0];
        setActive(sel ? opts.indexOf(sel) : 0);
        var menu = $('ivTimeMenu');
        if (menu) menu.focus({ preventScroll: true });
    }

    function chooseTime(option) {
        timeOptionsList().forEach(function (o) { o.setAttribute('aria-selected', o === option ? 'true' : 'false'); });
        pick.time = option.dataset.value;
        var v = $('ivTimeValue');
        v.textContent = slotLabel(pick.time);
        v.classList.remove('is-placeholder');
        fieldError(null, '');
        refreshPick('time');
        closePickers(null);
        $('ivTimeBtn').focus();
    }

    function chooseDay(day) {
        pick.date = day.dataset.value;
        var v = $('ivDateValue');
        v.textContent = pretty(pick.date);
        v.classList.remove('is-placeholder');
        fieldError(null, '');
        drawCalendar(null);
        var chosen = $('ivDatePop').querySelector('.is-selected');
        if (chosen) chosen.classList.add('picked');
        refreshPick('date');
        setTimeout(function () { closePickers(null); var b = $('ivDateBtn'); if (b) b.focus(); }, 160);
    }

    /* ---------- the two modals ---------- */
    var approveModal = $('approveModal');
    var approveConfirm = $('approveConfirm');
    var approveCancel = $('approveCancel');
    var emailModal = $('emailModal');

    function openModal(m, focusEl) {
        if (!m) return;
        m.classList.add('active');
        m.setAttribute('aria-hidden', 'false');
        if (focusEl) setTimeout(function () { focusEl.focus(); }, 60);
    }
    function closeModal(m) {
        if (!m) return;
        m.classList.remove('active');
        m.setAttribute('aria-hidden', 'true');
    }

    function openApprove() {
        $('approveWhen').innerHTML =
            '<span class="ap-when"><b>' + esc(iv.current.date) + '</b><span>at</span><b>' + esc(iv.current.time) + '</b></span>' +
            '<span class="ap-type">' + esc(iv.type) + '</span>';
        $('approveText').innerHTML =
            'An email saying this schedule is confirmed, with a link to the applicant\u2019s status page, will be sent to <b>' + esc(D.email) + '</b> right away.';
        approveConfirm.disabled = false;
        approveConfirm.textContent = 'Confirm & Send Email';
        openModal(approveModal, approveConfirm);
    }

    function showEmail(email) {
        if (!email || !emailModal) return;
        $('emailTo').textContent = email.to;
        $('emailSubject').textContent = email.subject;
        $('emailFrame').srcdoc = email.html;
        openModal(emailModal, $('emailClose'));
    }

    [approveModal, emailModal].forEach(function (m) {
        if (!m) return;
        m.addEventListener('click', function (e) { if (e.target === m && !busy) closeModal(m); });
    });
    if (approveCancel) approveCancel.addEventListener('click', function () { if (!busy) closeModal(approveModal); });
    if ($('emailClose')) $('emailClose').addEventListener('click', function () { closeModal(emailModal); });

    document.addEventListener('keydown', function (e) {
        if (e.key !== 'Escape' || busy) return;
        if (emailModal && emailModal.classList.contains('active')) closeModal(emailModal);
        else if (approveModal && approveModal.classList.contains('active')) closeModal(approveModal);
        else if (pickerOpen()) {
            var wasDate = $('ivDatePicker') && $('ivDatePicker').classList.contains('open');
            closePickers(null);
            var b = $(wasDate ? 'ivDateBtn' : 'ivTimeBtn');
            if (b) b.focus();
        }
    });

    // a click outside closes the open picker (the path is read from the event itself, because the
    // calendar redraws its buttons on a click)
    document.addEventListener('click', function (e) {
        if (!pickerOpen()) return;
        var path = e.composedPath ? e.composedPath() : [];
        var inside = path.some(function (n) { return n && n.classList && n.classList.contains('picker'); });
        if (!inside) closePickers(null);
    });

    /* ---------- Available: confirm, then the email goes out ---------- */
    approveConfirm.addEventListener('click', function () {
        if (busy) return;
        busy = true;
        approveConfirm.disabled = true;
        approveCancel.disabled = true;
        approveConfirm.textContent = 'Sending...';

        post({ action: 'interview_approve' }).then(function (data) {
            iv = data.interview;
            lastEmail = data.email;
            closeModal(approveModal);
            setMode('summary');
            toast('Schedule confirmed. An email was sent to ' + D.email + '.', {
                actionLabel: 'View email',
                onAction: function () { showEmail(lastEmail); },
                duration: 8000
            });
        }).catch(function (err) {
            toast(err.message, { error: true, duration: 6000 });
            approveConfirm.disabled = false;
            approveConfirm.textContent = 'Confirm & Send Email';
        }).then(function () {
            busy = false;
            approveCancel.disabled = false;
        });
    });

    /* ---------- Not available / Re-schedule: a new date and time, then the email ---------- */
    function sendNewSchedule() {
        if (busy) return;
        var problem = dateProblem(pick.date);
        if (problem) { fieldError('date', problem); $('ivDateBtn').focus(); return; }
        if (!pick.time) { fieldError('time', 'Please choose a time.'); $('ivTimeBtn').focus(); return; }
        if (pick.date === iv.current.raw_date && pick.time === iv.current.raw_time) {
            fieldError('time', 'That is the same schedule as before. Choose a different date or time.');
            return;
        }
        fieldError(null, '');

        busy = true;
        var send = $('ivSend');
        var cancel = root.querySelector('[data-act="cancel"]');
        send.disabled = true;
        cancel.disabled = true;
        send.classList.remove('is-ready');
        send.classList.add('is-loading');
        send.textContent = 'Sending...';

        post({ action: 'interview_reschedule', date: pick.date, time: pick.time }).then(function (data) {
            iv = data.interview;
            lastEmail = data.email;
            busy = false;
            setMode('summary');
            toast('New schedule sent to ' + D.email + '.', {
                actionLabel: 'View email',
                onAction: function () { showEmail(lastEmail); },
                duration: 8000
            });
        }).catch(function (err) {
            busy = false;
            send.disabled = false;
            cancel.disabled = false;
            send.classList.remove('is-loading');
            send.classList.add('is-ready');
            send.textContent = 'Send New Schedule';
            fieldError(null, err.message);
        });
    }

    /* ---------- clicks + keys (one listener each for the whole panel) ---------- */
    root.addEventListener('click', function (e) {
        var t = e.target;

        // calendar: month arrows and days
        var nav = t.closest('.pcal__nav');
        if (nav) {
            if (nav.disabled) return;
            var step = nav.classList.contains('pcal__nav--next') ? 1 : -1;
            viewM += step;
            if (viewM > 11) { viewM = 0; viewY++; }
            if (viewM < 0) { viewM = 11; viewY--; }
            drawCalendar(step > 0 ? 'next' : 'prev');
            return;
        }
        var day = t.closest('.pcal__day');
        if (day) { if (!day.disabled) chooseDay(day); return; }

        // time list
        var option = t.closest('.picker__option');
        if (option) { chooseTime(option); return; }

        // the two picker buttons
        var pbtn = t.closest('.picker__btn');
        if (pbtn) {
            if (pbtn.id === 'ivDateBtn') toggleDate();
            else if ($('ivTimePicker').classList.contains('open')) closePickers(null);
            else openTime();
            return;
        }

        var btn = t.closest('[data-act]');
        if (!btn || busy) return;
        switch (btn.getAttribute('data-act')) {
            case 'approve': openApprove(); break;
            case 'reject':  setMode('adjust', 'reject', true); break;
            case 'resched': setMode('adjust', 'resched', true); break;
            case 'cancel':  setMode('summary'); break;
            case 'send':    sendNewSchedule(); break;
            case 'email':   showEmail(lastEmail); break;
        }
    });

    root.addEventListener('mousemove', function (e) {
        var option = e.target.closest ? e.target.closest('.picker__option') : null;
        if (option) setActive(timeOptionsList().indexOf(option));
    });

    root.addEventListener('keydown', function (e) {
        var id = e.target && e.target.id;
        if (id === 'ivTimeBtn' && (e.key === 'ArrowDown' || e.key === 'ArrowUp')) { e.preventDefault(); openTime(); return; }
        if (id !== 'ivTimeMenu') return;
        var opts = timeOptionsList();
        if (e.key === 'ArrowDown') { e.preventDefault(); setActive(active + 1); }
        else if (e.key === 'ArrowUp') { e.preventDefault(); setActive(active - 1); }
        else if (e.key === 'Home') { e.preventDefault(); setActive(0); }
        else if (e.key === 'End') { e.preventDefault(); setActive(opts.length - 1); }
        else if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); if (active >= 0) chooseTime(opts[active]); }
        else if (e.key === 'Tab') { closePickers(null); }
    });

    render();
})();
}

/* ===== PAGE : screeninglist.js (runs only on: screeninglist) ===== */
if (document.documentElement.getAttribute('data-page') === 'screeninglist') {
/* ==========================================================================
   screeninglist.js: the Screening List page.
   Shared helpers (window.HR: archive storage, badge, toast), the sidebar, logout and the
   entrance animation live in hr-common.js (load hr-common.js BEFORE this file).
   ========================================================================== */
document.addEventListener('DOMContentLoaded', () => {
    const config = window.SCREENING || { perPage: 6, applicants: [] };
    let applicants = Array.isArray(config.applicants) ? config.applicants.slice() : [];
    // applicants in the Archive (or deleted for good after 30 days) are not listed
    const archivedNow = new Set(HR.readArchived().concat(HR.readDeleted()));
    applicants = applicants.filter(a => !archivedNow.has(a.id));
    // config.perPage is the starting value; fitPerPage() then adjusts it to the window height
    let perPage = Math.max(1, parseInt(config.perPage, 10) || 6);

    const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    // Tell the shared helper who has completed the screening, so the red dots and the
    // Dashboard badge are counted from the same list on every page
    HR.syncCompleted((Array.isArray(config.applicants) ? config.applicants : [])
        .filter(a => String(a.status).toLowerCase() === 'completed')
        .map(a => a.id));

    // Completed screening that HR has not opened yet
    const isUnseenCompleted = (a) => String(a.status).toLowerCase() === 'completed' && HR.isUnseen(a.id);

    const toolbar = document.getElementById('toolbar');
    const searchInput = document.getElementById('searchInput');
    const searchClear = document.getElementById('searchClear');
    const selectAllBtn = document.getElementById('selectAllBtn');
    const selectActions = document.getElementById('selectActions');
    const selectSwap = document.getElementById('selectSwap');
    const cancelSelect = document.getElementById('cancelSelect');
    const deleteSelected = document.getElementById('deleteSelected');
    const deleteCount = document.getElementById('deleteCount');
    const filterToggle = document.getElementById('filterToggle');
    const filterPanel = document.getElementById('filterPanel');
    const filterDot = document.getElementById('filterDot');
    const table = document.getElementById('screeningTable');
    const tableScroll = document.getElementById('tableScroll');
    const tableBody = document.getElementById('tableBody');
    const emptyState = document.getElementById('emptyState');
    const clearFilters = document.getElementById('clearFilters');
    const selectAll = document.getElementById('selectAll');
    const showingText = document.getElementById('showingText');
    const pagination = document.getElementById('pagination');
    const archiveLink = document.getElementById('archiveLink');

    const state = { q: '', status: 'all', result: 'all', page: 1 };
    const selected = new Set();       // ids ticked by the user (kept across pages)
    let selectMode = false;
    let visible = [];                 // rows on the current page
    let animateRows = false;          // first render comes with the card entrance
    let busy = false;                 // an archive request/animation is running
    let switchTimer = null;           // staggers the number <-> checkbox swap
    let clearTimer = null;            // clears the ticks after the leave animation

    // ---------- helpers ----------
    // Build elements with textContent only, so names/emails can never inject HTML
    function el(tag, className, text) {
        const node = document.createElement(tag);
        if (className) node.className = className;
        if (text !== undefined && text !== null) node.textContent = text;
        return node;
    }

    const STATUS_CLASS = {
        'pending': 'status-pending',
        'in progress': 'status-progress',
        'completed': 'status-completed',
        'passed': 'status-passed',
        'failed': 'status-failed',
        'rejected': 'status-failed'
    };

    function pill(text) {
        const cls = STATUS_CLASS[String(text).trim().toLowerCase()] || 'status-default';
        return el('span', 'status-pill ' + cls, text);
    }

    const plural = (n, word) => n + ' ' + word + (n === 1 ? '' : 's');

    // ======================================================================
    // Custom dropdown (keyboard + mouse), replaces the native <select>
    // ======================================================================
    const dropdowns = [];

    function createDropdown(root, onChange) {
        const btn = root.querySelector('.dropdown-btn');
        const menu = root.querySelector('.dropdown-menu');
        const valueEl = root.querySelector('.dropdown-value');
        const btnDot = btn.querySelector('.dot');
        const card = root.closest('.filter-card');
        const options = Array.from(menu.querySelectorAll('.dropdown-option'));
        let value = 'all';
        let focusIdx = -1;

        options.forEach(o => o.querySelector('.dot').style.setProperty('--dot', o.dataset.dot));

        const isOpen = () => root.classList.contains('open');

        function highlight(i) {
            options.forEach((o, k) => o.classList.toggle('is-focused', k === i));
            focusIdx = i;
        }

        function setValue(v, silent) {
            const opt = options.find(o => o.dataset.value === v) || options[0];
            value = opt.dataset.value;
            options.forEach(o => o.setAttribute('aria-selected', String(o === opt)));
            valueEl.textContent = opt.querySelector('.opt-label').textContent;
            btnDot.style.setProperty('--dot', opt.dataset.dot);
            if (!silent) onChange(value);
        }

        function open() {
            dropdowns.forEach(d => d.close());
            root.classList.add('open');
            btn.setAttribute('aria-expanded', 'true');
            if (card) card.classList.add('is-open');
            highlight(Math.max(0, options.findIndex(o => o.dataset.value === value)));
        }

        function close() {
            root.classList.remove('open');
            btn.setAttribute('aria-expanded', 'false');
            if (card) card.classList.remove('is-open');
            highlight(-1);
        }

        function choose(i) {
            if (i < 0) return;
            setValue(options[i].dataset.value);
            close();
            btn.focus();
        }

        btn.addEventListener('click', () => (isOpen() ? close() : open()));

        btn.addEventListener('keydown', (e) => {
            if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
                e.preventDefault();
                if (!isOpen()) { open(); return; }
                const step = e.key === 'ArrowDown' ? 1 : -1;
                highlight((focusIdx + step + options.length) % options.length);
            } else if ((e.key === 'Enter' || e.key === ' ') && isOpen()) {
                e.preventDefault();
                choose(focusIdx);
            } else if (e.key === 'Escape' && isOpen()) {
                e.preventDefault();       // so select mode does not also react to this Escape
                close();
            } else if (e.key === 'Tab' && isOpen()) {
                close();
            }
        });

        options.forEach((o, i) => {
            o.addEventListener('mousedown', (e) => e.preventDefault());   // keep focus on the button
            o.addEventListener('click', () => choose(i));
            o.addEventListener('mouseenter', () => highlight(i));
        });

        const api = { root, options, setValue, close, getValue: () => value };
        dropdowns.push(api);
        return api;
    }

    document.addEventListener('click', (e) => {
        dropdowns.forEach(d => { if (!d.root.contains(e.target)) d.close(); });
    });

    const statusDD = createDropdown(document.getElementById('statusDropdown'), (v) => {
        state.status = v; state.page = 1; render();
    });
    const resultDD = createDropdown(document.getElementById('resultDropdown'), (v) => {
        state.result = v; state.page = 1; render();
    });

    document.getElementById('statusReset').addEventListener('click', () => { statusDD.setValue('all'); });
    document.getElementById('resultReset').addEventListener('click', () => { resultDD.setValue('all'); });

    // numbers next to each option ("Pending 13")
    function updateCounts() {
        statusDD.options.forEach(o => {
            const v = o.dataset.value;
            o.querySelector('.opt-count').textContent =
                v === 'all' ? applicants.length : applicants.filter(a => a.status === v).length;
        });
        resultDD.options.forEach(o => {
            const v = o.dataset.value;
            let n;
            if (v === 'all') n = applicants.length;
            else if (v === 'none') n = applicants.filter(a => !a.result).length;
            else n = applicants.filter(a => a.result === v).length;
            o.querySelector('.opt-count').textContent = n;
        });
    }

    function syncFilterUi() {
        const sActive = state.status !== 'all';
        const rActive = state.result !== 'all';
        const activeCount = (sActive ? 1 : 0) + (rActive ? 1 : 0);
        filterDot.hidden = activeCount === 0;
        filterDot.textContent = String(activeCount);
        filterToggle.title = activeCount ? activeCount + ' filter' + (activeCount === 1 ? '' : 's') + ' active' : 'Show or hide filters';
        document.getElementById('statusCard').classList.toggle('is-active', sActive);
        document.getElementById('resultCard').classList.toggle('is-active', rActive);
        document.getElementById('statusReset').hidden = !sActive;
        document.getElementById('resultReset').hidden = !rActive;
    }

    // ---------- rows per page: as many as fit, so the card has no empty band ----------
    const MIN_ROWS = 4;
    const MAX_ROWS = 15;

    function fitPerPage() {
        // measure a row at its natural height (undo the stretch from the last fit first)
        tableScroll.style.removeProperty('--row-h');
        const firstRow = tableBody.querySelector('tr');
        const rowHeight = firstRow ? Math.ceil(firstRow.getBoundingClientRect().height) : 53;   // rows can differ by half a pixel
        const head = tableScroll.querySelector('thead');
        const room = tableScroll.clientHeight - (head ? head.offsetHeight : 46);
        if (!rowHeight || room <= 0) return perPage;
        const rows = Math.min(MAX_ROWS, Math.max(MIN_ROWS, Math.floor((room - 4) / rowHeight)));
        // share the leftover pixels between the rows, so there is no empty band under the last one
        tableScroll.style.setProperty('--row-h', Math.max(rowHeight, Math.floor((room - 2) / rows)) + 'px');
        return rows;
    }

    // Window or filter panel changed size: re-fit, keeping the first visible applicant in view
    function refit() {
        const next = fitPerPage();
        if (next === perPage) return;
        const firstIndex = (state.page - 1) * perPage;
        perPage = next;
        state.page = Math.floor(firstIndex / perPage) + 1;
        const wasAnimating = animateRows;
        animateRows = false;
        render();
        animateRows = wasAnimating;
    }

    // ---------- filtering ----------
    function getFiltered() {
        const q = state.q.trim().toLowerCase();
        return applicants.filter(a => {
            if (state.status !== 'all' && a.status !== state.status) return false;
            if (state.result === 'none' && a.result) return false;
            if (state.result !== 'all' && state.result !== 'none' && a.result !== state.result) return false;
            if (q) {
                const haystack = (a.name + ' ' + a.email + ' ' + a.contact).toLowerCase();
                if (!haystack.includes(q)) return false;
            }
            return true;
        });
    }

    function filtersActive() {
        return state.status !== 'all' || state.result !== 'all';
    }

    // ---------- rendering ----------
    function render() {
        const filtered = getFiltered();
        const total = filtered.length;
        const pages = Math.max(1, Math.ceil(total / perPage));
        state.page = Math.min(Math.max(1, state.page), pages);

        const start = (state.page - 1) * perPage;
        visible = filtered.slice(start, start + perPage);

        // rows
        tableBody.textContent = '';
        visible.forEach((a, index) => tableBody.appendChild(buildRow(a, index, start + index + 1)));
        tableScroll.scrollTop = 0;

        // empty state
        emptyState.hidden = total !== 0;

        // footer
        renderShowing(total, start);
        renderPagination(pages, total);

        // header checkbox + filter indicator + URL
        updateSelectAll();
        updateSelectionUi();
        syncFilterUi();
        searchClear.hidden = state.q === '';
        syncUrl();
    }

    function buildRow(a, index, number) {
        const tr = document.createElement('tr');
        tr.dataset.id = a.id;
        tr.style.setProperty('--k', index);          // row position: staggers the number <-> checkbox swap
        if (selected.has(a.id)) tr.classList.add('is-selected');
        if (animateRows) {
            tr.classList.add('row-in');
            tr.style.setProperty('--r', index);
        }

        // row number; in select mode the number gives way to the checkbox
        const tdCheck = el('td', 'col-check');
        const wrap = el('span', 'idx-wrap');
        const box = document.createElement('input');
        box.type = 'checkbox';
        box.className = 'row-check';
        box.checked = selected.has(a.id);
        box.tabIndex = selectMode ? 0 : -1;
        box.setAttribute('aria-label', 'Select ' + a.name);
        wrap.append(el('span', 'row-num', String(number)), box);
        tdCheck.appendChild(wrap);

        // name / email / contact (full text on hover when it is cut with "...")
        const tdName = el('td', 'cell-name');
        tdName.title = a.name;
        if (isUnseenCompleted(a)) {
            // red dot: completed, not viewed yet
            tr.classList.add('is-unseen');
            const dot = el('span', 'unseen-dot');
            dot.title = 'Completed screening, not viewed yet';
            tdName.appendChild(dot);
        }
        tdName.appendChild(el('strong', null, a.name));

        const tdEmail = el('td', null, a.email);
        tdEmail.title = a.email;

        const tdContact = el('td', null, a.contact);

        // status + result
        const tdStatus = el('td');
        tdStatus.appendChild(pill(a.status));

        const tdResult = el('td');
        if (a.result) {
            tdResult.appendChild(pill(a.result));
        } else {
            tdResult.appendChild(el('span', 'dash', '\u2013'));
        }

        // action
        const tdAction = el('td', 'col-action');
        const view = el('a', 'btn-view', 'VIEW');
        view.href = 'details.php?id=' + encodeURIComponent(a.id);
        view.setAttribute('aria-label', 'View ' + a.name);
        // opening the applicant clears the red dot and lowers the Dashboard badge
        view.addEventListener('click', () => {
            HR.markSeen(a.id);
            tr.classList.remove('is-unseen');
            const dot = tr.querySelector('.unseen-dot');
            if (dot) dot.remove();
        });
        tdAction.appendChild(view);

        tr.append(tdCheck, tdName, tdEmail, tdContact, tdStatus, tdResult, tdAction);
        return tr;
    }

    function renderShowing(total, start) {
        showingText.textContent = '';
        if (total === 0) {
            showingText.append('No applicants found');
        } else {
            const from = start + 1;
            const to = start + visible.length;
            showingText.append('Showing ' + from + '\u2013' + to + ' of ' + plural(total, 'applicant'));
        }
        if (selected.size > 0) {
            const shown = new Set(getFiltered().map(a => a.id));
            const hidden = Array.from(selected).filter(id => !shown.has(id)).length;
            showingText.append(' \u00B7 ');
            showingText.appendChild(el('span', 'selected-count', selected.size + ' selected'));
            if (hidden > 0) showingText.append(' (' + hidden + ' hidden by filters)');
        }
    }

    function refreshFooter() {
        renderShowing(getFiltered().length, (state.page - 1) * perPage);
    }

    function pageList(current, pages) {
        if (pages <= 7) return Array.from({ length: pages }, (_, i) => i + 1);
        const keep = new Set([1, pages, current - 1, current, current + 1]);
        const nums = [...keep].filter(n => n >= 1 && n <= pages).sort((a, b) => a - b);
        const out = [];
        nums.forEach((n, i) => {
            if (i > 0) {
                const gap = n - nums[i - 1];
                if (gap === 2) out.push(n - 1);      // a single missing number: just show it
                else if (gap > 2) out.push('gap');
            }
            out.push(n);
        });
        return out;
    }

    const CHEVRON_LEFT = 'M15.41 7.41 14 6l-6 6 6 6 1.41-1.41L10.83 12z';
    const CHEVRON_RIGHT = 'M8.59 16.59 13.17 12 8.59 7.41 10 6l6 6-6 6z';
    // « and »: jump straight to the first / last page
    const DOUBLE_LEFT = 'M17.59 18 19 16.59 14.42 12 19 7.41 17.59 6l-6 6zM11 18l1.41-1.41L7.83 12l4.58-4.59L11 6l-6 6z';
    const DOUBLE_RIGHT = 'M6.41 6 5 7.41 9.58 12 5 16.59 6.41 18l6-6zM13 6l-1.41 1.41L16.17 12l-4.58 4.59L13 18l6-6z';

    function arrowButton(path, label, disabled, goTo) {
        const btn = el('button', 'page-btn');
        btn.type = 'button';
        btn.disabled = disabled;
        btn.setAttribute('aria-label', label);
        btn.innerHTML = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="' + path + '"/></svg>';
        btn.addEventListener('click', () => goToPage(goTo));
        return btn;
    }

    function renderPagination(pages, total) {
        pagination.textContent = '';
        if (total === 0 || pages <= 1) return;

        pagination.appendChild(arrowButton(DOUBLE_LEFT, 'First page', state.page === 1, 1));
        pagination.appendChild(arrowButton(CHEVRON_LEFT, 'Previous page', state.page === 1, state.page - 1));

        pageList(state.page, pages).forEach(item => {
            if (item === 'gap') {
                pagination.appendChild(el('span', 'page-gap', '\u2026'));
                return;
            }
            const btn = el('button', 'page-btn' + (item === state.page ? ' is-current' : ''), String(item));
            btn.type = 'button';
            btn.setAttribute('aria-label', 'Page ' + item);
            if (item === state.page) btn.setAttribute('aria-current', 'page');
            btn.addEventListener('click', () => goToPage(item));
            pagination.appendChild(btn);
        });

        pagination.appendChild(arrowButton(CHEVRON_RIGHT, 'Next page', state.page === pages, state.page + 1));
        pagination.appendChild(arrowButton(DOUBLE_RIGHT, 'Last page', state.page === pages, pages));
    }

    function goToPage(n) {
        if (n === state.page) return;
        state.page = n;
        render();
    }

    // ======================================================================
    // Selection mode
    // ======================================================================
    // "All" means every applicant in the current list (search + filters), on every page
    function updateSelectAll() {
        const ids = getFiltered().map(a => a.id);
        const count = ids.filter(id => selected.has(id)).length;
        selectAll.disabled = ids.length === 0;
        selectAll.checked = ids.length > 0 && count === ids.length;
        selectAll.indeterminate = count > 0 && count < ids.length;
    }

    // the "Select all" / "Cancel + Delete" slot always takes the width of whichever one is showing
    function sizeSwap() {
        if (!selectSwap) return;
        const target = selectMode ? selectActions : selectAllBtn;
        selectSwap.style.width = target.offsetWidth + 'px';
    }

    function updateSelectionUi() {
        const n = selected.size;
        deleteSelected.disabled = busy || n === 0;
        deleteCount.textContent = n ? '(' + n + ')' : '';
        sizeSwap();
    }

    function syncRowChecks() {
        tableBody.querySelectorAll('tr').forEach(tr => {
            const on = selected.has(Number(tr.dataset.id));
            tr.classList.toggle('is-selected', on);
            const box = tr.querySelector('.row-check');
            if (box) box.checked = on;
        });
        updateSelectAll();
        updateSelectionUi();
        refreshFooter();
    }

    function setSelectMode(on, immediate) {
        if (selectMode === on) return;
        selectMode = on;
        table.classList.toggle('select-mode', on);
        toolbar.classList.toggle('is-selecting', on);

        // stagger the number <-> checkbox swap, in both directions
        table.classList.add('select-switching');
        clearTimeout(switchTimer);
        switchTimer = setTimeout(() => table.classList.remove('select-switching'), 800);

        selectAllBtn.inert = on;              // hidden controls stay out of the tab order
        selectActions.inert = !on;
        selectAll.tabIndex = on ? 0 : -1;
        tableBody.querySelectorAll('.row-check').forEach(b => { b.tabIndex = on ? 0 : -1; });

        const pending = clearTimer !== null;
        clearTimeout(clearTimer);
        clearTimer = null;

        if (on) {
            if (pending) selected.clear();    // re-entered while the old ticks were still fading
            syncRowChecks();
        } else if (immediate || reduceMotion) {
            selected.clear();
            syncRowChecks();
        } else {
            // keep the ticks until the checkboxes have shrunk away, then clear them
            updateSelectionUi();
            clearTimer = setTimeout(() => {
                clearTimer = null;
                if (selectMode) return;
                selected.clear();
                syncRowChecks();
            }, 650);
        }
    }

    function toggleRow(id, force) {
        const on = force !== undefined ? force : !selected.has(id);
        if (on) selected.add(id); else selected.delete(id);
        syncRowChecks();
    }

    // "Select all" in the top bar: enter select mode with the WHOLE list ticked (every page)
    selectAllBtn.addEventListener('click', () => {
        setSelectMode(true);
        getFiltered().forEach(a => selected.add(a.id));
        syncRowChecks();
    });

    cancelSelect.addEventListener('click', () => { if (!busy) setSelectMode(false); });

    // header checkbox: tick / untick the whole list, not just this page
    selectAll.addEventListener('change', () => {
        getFiltered().forEach(a => {
            if (selectAll.checked) selected.add(a.id);
            else selected.delete(a.id);
        });
        syncRowChecks();
    });

    tableBody.addEventListener('change', (e) => {
        if (!e.target.classList.contains('row-check')) return;
        const tr = e.target.closest('tr');
        toggleRow(Number(tr.dataset.id), e.target.checked);
    });

    // ---- press and hold a row to start selecting ----
    const HOLD_MS = 500;
    const MOVE_TOLERANCE = 8;
    let holdTimer = null;
    let holdRow = null;
    let holdX = 0;
    let holdY = 0;
    let swallowClick = false;

    function cancelHold() {
        clearTimeout(holdTimer);
        holdTimer = null;
        if (holdRow) holdRow.classList.remove('pressing');
        holdRow = null;
    }

    tableBody.addEventListener('pointerdown', (e) => {
        swallowClick = false;
        if (e.button !== undefined && e.button !== 0) return;
        if (selectMode || busy) return;
        if (e.target.closest('a, button, input, label')) return;
        const tr = e.target.closest('tr');
        if (!tr) return;

        holdRow = tr;
        holdX = e.clientX;
        holdY = e.clientY;
        tr.classList.add('pressing');

        holdTimer = setTimeout(() => {
            const id = Number(tr.dataset.id);
            cancelHold();
            swallowClick = true;                      // the click that follows the release must do nothing
            setSelectMode(true);
            toggleRow(id, true);
            if (navigator.vibrate) navigator.vibrate(12);
        }, HOLD_MS);
    });

    tableBody.addEventListener('pointermove', (e) => {
        if (!holdTimer) return;
        if (Math.abs(e.clientX - holdX) > MOVE_TOLERANCE || Math.abs(e.clientY - holdY) > MOVE_TOLERANCE) cancelHold();
    });
    ['pointerup', 'pointercancel', 'pointerleave'].forEach(type => tableBody.addEventListener(type, cancelHold));

    // keep the browser's long-press menu away while holding a row on touch screens
    tableBody.addEventListener('contextmenu', (e) => {
        if (holdRow || swallowClick) e.preventDefault();
    });

    tableBody.addEventListener('click', (e) => {
        if (swallowClick) {
            swallowClick = false;
            e.preventDefault();
            return;
        }
        if (!selectMode || busy) return;
        if (e.target.closest('a, .row-check')) return;     // VIEW keeps working, checkbox has its own handler
        const tr = e.target.closest('tr');
        if (tr) toggleRow(Number(tr.dataset.id));
    });

    // Escape leaves select mode (unless it was already used to close a dropdown / clear the search)
    document.addEventListener('keydown', (e) => {
        if (e.key !== 'Escape' || e.defaultPrevented || !selectMode || busy) return;
        if (document.getElementById('logoutModal')?.classList.contains('active')) return;
        setSelectMode(false);
    });

    // ======================================================================
    // Delete -> Archive (with the rows flying to the sidebar)
    // ======================================================================
    function flyToArchive(rows) {
        const icon = archiveLink ? archiveLink.querySelector('.nav-icon') : null;
        if (!rows.length || !icon || reduceMotion || !document.body.animate) {
            rows.forEach(tr => tr.classList.add('row-archiving'));
            return Promise.resolve();
        }

        const t = icon.getBoundingClientRect();
        const tx = t.left + t.width / 2;
        const ty = t.top + t.height / 2;
        const flights = [];

        rows.slice(0, 12).forEach((tr, i) => {
            const r = tr.getBoundingClientRect();
            const cx = r.left + r.width / 2;
            const cy = r.top + r.height / 2;

            const chip = el('div', 'fly-chip');
            chip.appendChild(el('span', 'fly-name', tr.querySelector('.cell-name').textContent));
            chip.style.left = cx + 'px';
            chip.style.top = cy + 'px';
            chip.style.width = r.width + 'px';
            chip.style.height = r.height + 'px';
            document.body.appendChild(chip);
            tr.classList.add('row-archiving');

            const base = 'translate(-50%, -50%)';
            const anim = chip.animate([
                { width: r.width + 'px', height: r.height + 'px', opacity: 1,
                  transform: base + ' translate(0, 0) scale(1)',
                  easing: 'cubic-bezier(0.22, 1, 0.36, 1)', offset: 0 },
                { width: '210px', height: '46px', opacity: 1,
                  transform: base + ' translate(0, -14px) scale(1.04)',
                  easing: 'cubic-bezier(0.55, 0, 0.2, 1)', offset: 0.3 },
                { width: '210px', height: '46px', opacity: 0.1,
                  transform: base + ' translate(' + (tx - cx) + 'px, ' + (ty - cy) + 'px) scale(0.1) rotate(12deg)',
                  offset: 1 }
            ], { duration: 850, delay: Math.min(i, 9) * 70, easing: 'linear', fill: 'both' });

            flights.push(anim.finished.then(() => chip.remove()).catch(() => chip.remove()));
        });

        // rows beyond the 12th just fade
        rows.slice(12).forEach(tr => tr.classList.add('row-archiving'));
        return Promise.all(flights);
    }

    async function archiveSelected() {
        const ids = Array.from(selected);
        if (!ids.length || busy) return;
        busy = true;
        updateSelectionUi();

        const idSet = new Set(ids);
        const rows = Array.from(tableBody.querySelectorAll('tr')).filter(tr => idSet.has(Number(tr.dataset.id)));

        // the request and the animation run together
        const request = HR.archiveRequest('archive', ids).then(r => ({ ok: true, r }), err => ({ ok: false, err }));
        const [result] = await Promise.all([request, flyToArchive(rows)]);

        if (!result.ok) {
            rows.forEach(tr => tr.classList.remove('row-archiving'));
            busy = false;
            updateSelectionUi();
            HR.toast(result.err.message, { error: true });
            return;
        }

        const removed = applicants.filter(a => idSet.has(a.id));
        applicants = applicants.filter(a => !idSet.has(a.id));
        busy = false;
        setSelectMode(false, true);      // clears the selection right away
        updateCounts();
        render();
        HR.setArchiveBadge(result.r.count);
        HR.bumpArchive();

        HR.toast(plural(removed.length, 'applicant') + ' moved to the Archive.', {
            actionLabel: 'Undo',
            onAction: async () => {
                try {
                    const back = await HR.archiveRequest('restore', removed.map(a => a.id));
                    applicants = applicants.concat(removed).sort((a, b) => a.id - b.id);   // keep the original order
                    updateCounts();
                    render();
                    HR.setArchiveBadge(back.count);
                } catch (err) {
                    HR.toast(err.message, { error: true });
                }
            }
        });
    }

    deleteSelected.addEventListener('click', archiveSelected);

    // ---------- URL (so a refresh keeps the filters, and the dashboard links work) ----------
    function syncUrl() {
        const p = new URLSearchParams();
        if (state.q.trim()) p.set('q', state.q.trim());
        if (state.status !== 'all') p.set('status', state.status);
        if (state.result !== 'all') p.set('result', state.result);
        if (state.page > 1) p.set('page', state.page);
        const qs = p.toString();
        window.history.replaceState({}, document.title, window.location.pathname + (qs ? '?' + qs : ''));
    }

    function readUrl() {
        const p = new URLSearchParams(window.location.search);
        const norm = v => String(v || '').trim().toLowerCase();

        const status = norm(p.get('status'));
        const result = norm(p.get('result'));
        const statusMap = { 'pending': 'Pending', 'in progress': 'In Progress', 'completed': 'Completed' };
        const resultMap = { 'passed': 'Passed', 'failed': 'Rejected', 'rejected': 'Rejected', 'none': 'none' };

        if (statusMap[status]) state.status = statusMap[status];
        // The dashboard pie chart links with ?status=Passed / ?status=Rejected
        if (resultMap[status]) state.result = resultMap[status];
        if (resultMap[result]) state.result = resultMap[result];

        state.q = p.get('q') || '';
        state.page = parseInt(p.get('page'), 10) || 1;

        statusDD.setValue(state.status, true);
        resultDD.setValue(state.result, true);
        searchInput.value = state.q;
    }

    // ---------- events ----------
    let searchTimer = null;
    searchInput.addEventListener('input', () => {
        clearTimeout(searchTimer);
        searchTimer = setTimeout(() => {
            state.q = searchInput.value;
            state.page = 1;
            render();
        }, 120);
        searchClear.hidden = searchInput.value === '';
    });

    searchInput.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && searchInput.value !== '') {
            e.preventDefault();
            searchClear.click();
        }
    });

    searchClear.addEventListener('click', () => {
        searchInput.value = '';
        state.q = '';
        state.page = 1;
        render();
        searchInput.focus();
    });

    filterToggle.addEventListener('click', () => {
        const willCollapse = !filterPanel.classList.contains('collapsed');
        dropdowns.forEach(d => d.close());
        filterPanel.classList.remove('is-settled');          // clip the menus again while it slides
        filterPanel.classList.toggle('collapsed', willCollapse);
        filterToggle.setAttribute('aria-expanded', String(!willCollapse));
        filterPanel.inert = willCollapse;                    // keep the hidden dropdowns out of the tab order
        if (!willCollapse) {
            // safety net in case the transitionend below does not fire
            setTimeout(() => {
                if (!filterPanel.classList.contains('collapsed')) filterPanel.classList.add('is-settled');
            }, 500);
        }
    });

    let resizeTimer = null;
    window.addEventListener('resize', () => {
        clearTimeout(resizeTimer);
        resizeTimer = setTimeout(() => { sizeSwap(); refit(); }, 150);
    });

    // the table grows/shrinks while the filter panel slides, so re-fit when it settles
    filterPanel.addEventListener('transitionend', (e) => {
        if (e.target !== filterPanel || e.propertyName !== 'grid-template-rows') return;
        if (!filterPanel.classList.contains('collapsed')) filterPanel.classList.add('is-settled');
        refit();
    });

    clearFilters.addEventListener('click', () => {
        state.q = '';
        state.page = 1;
        searchInput.value = '';
        statusDD.setValue('all', true);
        resultDD.setValue('all', true);
        state.status = 'all';
        state.result = 'all';
        render();
    });

    // ---------- start ----------
    sizeSwap();
    if (document.fonts && document.fonts.ready) document.fonts.ready.then(sizeSwap);   // re-measure once the font has loaded
    updateCounts();
    perPage = fitPerPage();
    readUrl();
    render();
    refit();                // now that a real row exists, measure again for an exact fit
    animateRows = true;     // later renders (search, filters, pages) animate the rows in
});
}

/* ===== PAGE : settinghr.js (runs only on: settinghr) ===== */
if (document.documentElement.getAttribute('data-page') === 'settinghr') {
/* ==========================================================================
   Settings page behaviour.
   Entrance, sidebar, logout and the sidebar badges live in hr-common.js
   (load hr-common.js BEFORE this file). The saved values are read and written
   through HR.settings (see "Settings" in hr-common.js), which is also what the
   other HR pages read, so a saved change shows up across the whole portal.

   Preview rule: the theme and the animations switch show their effect at once so HR can see
   what they pick, but nothing is kept until SAVE. CANCEL puts the saved look back.
   ========================================================================== */
document.addEventListener('DOMContentLoaded', () => {
    const $ = (id) => document.getElementById(id);

    const form = $('settingsForm');
    const nameInput = $('setName');
    const emailInput = $('setEmail');
    const saveBtn = $('saveBtn');
    const cancelBtn = $('cancelBtn');
    const prefCard = $('prefCard');
    const subGroup = $('subGroup');

    const toggles = {
        notifications: $('optNotifications'),
        notifyCompleted: $('optCompleted'),
        notifyNew: $('optNew'),
        animations: $('optAnimations')
    };
    const themeRadios = Array.from(form.querySelectorAll('input[name="theme"]'));

    const fieldOf = (name) => form.querySelector('.set-field[data-field="' + name + '"]');
    let saving = false;

    /* ---------- the saved state ---------- */
    // Name + email: what HR saved before, otherwise what the login session gave
    // (window.HR_ACCOUNT is printed by settinghr.php).
    const account = window.HR_ACCOUNT || { name: '', email: '' };
    function readSaved() {
        const profile = HR.settings.profile();
        const s = HR.settings.get();
        return {
            name: String(profile.name || account.name || '').trim(),
            email: String(profile.email || account.email || '').trim(),
            theme: ['light', 'dark', 'system'].includes(s.theme) ? s.theme : 'light',
            notifications: s.notifications !== false,
            notifyCompleted: s.notifyCompleted !== false,
            notifyNew: s.notifyNew !== false,
            animations: s.animations !== false
        };
    }
    let saved = readSaved();

    /* ---------- form <-> state ---------- */
    function collect() {
        const checked = themeRadios.find(r => r.checked);
        return {
            name: nameInput.value.trim(),
            email: emailInput.value.trim(),
            theme: checked ? checked.value : 'light',
            notifications: toggles.notifications.checked,
            notifyCompleted: toggles.notifyCompleted.checked,
            notifyNew: toggles.notifyNew.checked,
            animations: toggles.animations.checked
        };
    }

    function fill(state) {
        nameInput.value = state.name;
        emailInput.value = state.email;
        themeRadios.forEach(r => { r.checked = r.value === state.theme; });
        Object.keys(toggles).forEach(k => { toggles[k].checked = !!state[k]; });
        syncSubGroup();
    }

    const isDirty = () => JSON.stringify(collect()) !== JSON.stringify(saved);

    function refreshButtons() {
        const dirty = isDirty();
        prefCard.classList.toggle('is-dirty', dirty);
        if (saving) return;
        saveBtn.disabled = !dirty;
        cancelBtn.disabled = !dirty;
    }

    // Notifications master switch: the two kinds fold away (and cannot be tabbed to) while it is off
    function syncSubGroup() {
        const on = toggles.notifications.checked;
        subGroup.classList.toggle('is-off', !on);
        subGroup.setAttribute('aria-hidden', on ? 'false' : 'true');
        toggles.notifyCompleted.disabled = !on;
        toggles.notifyNew.disabled = !on;
    }

    // Shows the chosen look right now (not saved yet)
    function preview() {
        const s = collect();
        HR.settings.apply(Object.assign(HR.settings.get(), { theme: s.theme, animations: s.animations }), true);
    }

    /* ---------- validation (same wording as Add Applicant) ---------- */
    const rules = {
        name(v) {
            const t = v.trim();
            if (!t) return 'Please enter your name.';
            if (/\d/.test(t)) return 'The name cannot contain numbers.';
            if (!/^[\p{L}\p{M}'.\- ]+$/u.test(t)) return 'Use letters only (spaces, apostrophes, periods and hyphens are fine).';
            if (t.replace(/[^\p{L}]/gu, '').length < 2) return 'The name is too short (at least 2 letters).';
            return '';
        },
        email(v) {
            const t = v.trim();
            if (!t) return 'Please enter your email address.';
            if (/\s/.test(t)) return 'The email cannot contain spaces.';
            if (!t.includes('@')) return 'The email must include an @ (example: name@gmail.com).';
            if (!/^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(t)) return 'Enter a valid email address (example: name@gmail.com).';
            return '';
        }
    };

    function setError(field, message, shake) {
        if (!field) return;
        field.classList.toggle('has-error', !!message);
        field.querySelector('.set-error').textContent = message || '';
        const input = field.querySelector('input');
        if (input) input.setAttribute('aria-invalid', message ? 'true' : 'false');
        if (message && shake) {
            field.classList.remove('shake');
            void field.offsetWidth;
            field.classList.add('shake');
        }
    }

    function validate(name, shake) {
        const input = name === 'name' ? nameInput : emailInput;
        const message = rules[name](input.value);
        setError(fieldOf(name), message, shake);
        return !message;
    }

    const touched = new Set();
    [['name', nameInput], ['email', emailInput]].forEach(([name, input]) => {
        input.addEventListener('input', () => {
            touched.add(name);
            validate(name, false);
            refreshButtons();
        });
        input.addEventListener('blur', () => { if (touched.has(name)) validate(name, false); });
    });

    themeRadios.forEach(r => r.addEventListener('change', () => { preview(); refreshButtons(); }));
    toggles.notifications.addEventListener('change', () => { syncSubGroup(); refreshButtons(); });
    toggles.notifyCompleted.addEventListener('change', refreshButtons);
    toggles.notifyNew.addEventListener('change', refreshButtons);
    toggles.animations.addEventListener('change', () => { preview(); refreshButtons(); });

    /* ---------- SAVE ---------- */
    function setSaving(state) {
        saving = state;
        saveBtn.classList.toggle('saving', state);
        saveBtn.querySelector('.btn-label').textContent = state ? 'SAVING...' : 'SAVE';
        saveBtn.disabled = state;
        cancelBtn.disabled = state;
    }

    /* BACKEND: replace the body with a real request, for example
           const res = await fetch('settinghr.php', { method: 'POST', body: JSON.stringify(state), ... });
           const data = await res.json();                 // { status: 'success' }
           if (data.status !== 'success') throw new Error(data.message);
       Until then the values are kept in this browser (HR.settings / HR.settings.saveProfile). */
    async function persist(state) {
        await new Promise(resolve => setTimeout(resolve, 650));
        const okSettings = HR.settings.save({
            theme: state.theme,
            animations: state.animations,
            notifications: state.notifications,
            notifyCompleted: state.notifyCompleted,
            notifyNew: state.notifyNew
        });
        const okProfile = HR.settings.saveProfile({ name: state.name, email: state.email });
        if (!okSettings || !okProfile) throw new Error('Could not save. Please check your browser storage settings.');
    }

    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        if (saving || !isDirty()) return;

        let firstBad = null;
        ['name', 'email'].forEach(n => { if (!validate(n, true) && !firstBad) firstBad = n; });
        if (firstBad) {
            HR.toast('Fix the highlighted field before saving.', { error: true, duration: 4000 });
            (firstBad === 'name' ? nameInput : emailInput).focus();
            return;
        }

        const state = collect();
        setSaving(true);
        try {
            await persist(state);
            saved = readSaved();
            saveBtn.classList.add('saved');
            saveBtn.querySelector('.btn-label').textContent = 'SAVED';
            HR.toast('Settings saved.', { duration: 3500 });
            setSaving(false);
            saveBtn.disabled = true;               // nothing left to save
            cancelBtn.disabled = true;
            prefCard.classList.remove('is-dirty');
            setTimeout(() => {
                saveBtn.classList.remove('saved');
                saveBtn.querySelector('.btn-label').textContent = 'SAVE';
                refreshButtons();
            }, 1600);
        } catch (err) {
            setSaving(false);
            refreshButtons();
            HR.toast(err.message || 'Something went wrong. Please try again.', { error: true, duration: 6000 });
        }
    });

    /* ---------- CANCEL: back to what is saved ---------- */
    cancelBtn.addEventListener('click', () => {
        if (saving || !isDirty()) return;
        fill(saved);
        ['name', 'email'].forEach(n => setError(fieldOf(n), ''));
        touched.clear();
        HR.settings.apply(HR.settings.get(), true);      // the saved look
        refreshButtons();
        HR.toast('Changes discarded.', { duration: 2500 });
    });

    /* ---------- Update password ---------- */
    const pwModal = $('pwModal');
    const pwForm = $('pwForm');
    const pwOpen = $('pwOpen');
    const pwCurrent = $('pwCurrent');
    const pwNew = $('pwNew');
    const pwConfirm = $('pwConfirm');
    const pwSubmit = $('pwSubmit');
    const meter = $('pwMeter');
    const meterLabel = $('pwMeterLabel');
    const ruleItems = {
        len: pwModal.querySelector('[data-rule="len"]'),
        case: pwModal.querySelector('[data-rule="case"]'),
        num: pwModal.querySelector('[data-rule="num"]')
    };
    let pwBusy = false;
    let lastFocus = null;

    function pwChecks(v) {
        return {
            len: v.length >= 8,
            case: /[a-z]/.test(v) && /[A-Z]/.test(v),
            num: /\d/.test(v)
        };
    }

    function pwScore(v) {
        const c = pwChecks(v);
        let score = [c.len, c.case, c.num].filter(Boolean).length;
        if (score === 3 && (v.length >= 12 || /[^A-Za-z0-9]/.test(v))) score = 4;
        return v ? Math.max(1, score) : 0;
    }

    function paintStrength() {
        const v = pwNew.value;
        const c = pwChecks(v);
        Object.keys(ruleItems).forEach(k => ruleItems[k].classList.toggle('ok', c[k]));
        const score = pwScore(v);
        meter.dataset.level = String(score);
        meterLabel.textContent = ['', 'Weak', 'Okay', 'Good', 'Strong'][score];
    }

    const pwField = (name) => pwModal.querySelector('.set-field[data-field="' + name + '"]');

    function pwValidate(shake) {
        const checks = pwChecks(pwNew.value);
        const problems = {
            pwCurrent: pwCurrent.value ? '' : 'Enter your current password.',
            pwNew: !pwNew.value ? 'Enter a new password.'
                : !(checks.len && checks.case && checks.num) ? 'The new password does not meet all the requirements.'
                : pwNew.value === pwCurrent.value ? 'The new password must be different from the current one.' : '',
            pwConfirm: !pwConfirm.value ? 'Type the new password again.'
                : pwConfirm.value !== pwNew.value ? 'The passwords do not match.' : ''
        };
        let first = null;
        Object.keys(problems).forEach(k => {
            setError(pwField(k), problems[k], shake);
            if (problems[k] && !first) first = k;
        });
        return first;
    }

    function openPw() {
        lastFocus = document.activeElement;
        pwForm.reset();
        pwModal.querySelectorAll('.set-field').forEach(f => setError(f, ''));
        pwModal.querySelectorAll('.eye').forEach(b => { b.setAttribute('aria-pressed', 'false'); b.previousElementSibling.type = 'password'; });
        paintStrength();
        pwModal.classList.add('active');
        setTimeout(() => pwCurrent.focus(), 120);
    }

    function closePw() {
        if (pwBusy) return;
        pwModal.classList.remove('active');
        if (lastFocus && lastFocus.focus) lastFocus.focus();
    }

    pwOpen.addEventListener('click', openPw);
    $('pwCancel').addEventListener('click', closePw);
    pwModal.addEventListener('click', (e) => { if (e.target === pwModal) closePw(); });

    pwModal.querySelectorAll('.eye').forEach(btn => btn.addEventListener('click', () => {
        const input = btn.previousElementSibling;
        const show = input.type === 'password';
        input.type = show ? 'text' : 'password';
        btn.setAttribute('aria-pressed', show ? 'true' : 'false');
        btn.setAttribute('aria-label', (show ? 'Hide ' : 'Show ') + btn.dataset.what);
    }));

    pwNew.addEventListener('input', () => {
        paintStrength();
        if (pwConfirm.value) setError(pwField('pwConfirm'), pwConfirm.value === pwNew.value ? '' : 'The passwords do not match.');
    });
    pwConfirm.addEventListener('input', () => setError(pwField('pwConfirm'), pwConfirm.value === pwNew.value ? '' : 'The passwords do not match.'));
    pwCurrent.addEventListener('input', () => setError(pwField('pwCurrent'), ''));

    /* BACKEND: check the current password and store the new one, for example
           const res = await fetch('settinghr.php', { method: 'POST', body: JSON.stringify({ action: 'password', current, next }) });
       and show data.message when the current password is wrong (use setError(pwField('pwCurrent'), data.message, true)).
       The browser cannot check the current password on its own, so for now it only has to be filled in. */
    pwForm.addEventListener('submit', async (e) => {
        e.preventDefault();
        if (pwBusy) return;
        const bad = pwValidate(true);
        if (bad) { $(bad).focus(); return; }

        pwBusy = true;
        pwSubmit.disabled = true;
        pwSubmit.textContent = 'Updating...';
        await new Promise(resolve => setTimeout(resolve, 800));
        pwBusy = false;
        pwSubmit.disabled = false;
        pwSubmit.textContent = 'Update Password';
        closePw();
        HR.toast('Password updated.', { duration: 3500 });
        const mask = $('pwMask').closest('.set-input');
        mask.classList.remove('pulse');
        void mask.offsetWidth;
        mask.classList.add('pulse');
    });

    // Tab stays inside the open dialog
    pwModal.addEventListener('keydown', (e) => {
        if (e.key !== 'Tab') return;
        const items = Array.from(pwModal.querySelectorAll('input, button')).filter(el => !el.disabled && el.offsetParent !== null);
        if (!items.length) return;
        const first = items[0], last = items[items.length - 1];
        if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
        else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
    });

    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && pwModal.classList.contains('active') && !$('logoutModal').classList.contains('active')) closePw();
    });

    /* ---------- start ---------- */
    fill(saved);
    refreshButtons();

    // Another tab saved Settings: show its values here
    window.addEventListener('storage', (e) => {
        if (e.key !== 'hr_settings' && e.key !== 'hr_profile') return;
        if (isDirty() || saving) return;               // never overwrite what is being typed
        saved = readSaved();
        fill(saved);
        refreshButtons();
    });
});
}