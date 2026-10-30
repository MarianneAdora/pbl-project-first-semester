/* ==========================================================================
   admin.js : every admin page's script in ONE file.

   The shared script runs everywhere. Each page script is wrapped in a check of
   <html data-page="..."> so it only runs on the page(s) that used to load it.
   The code inside each section is unchanged.
   ========================================================================== */

/* ===== SHARED : admin-common.js (runs on every admin page) ===== */
/* ==========================================================================
   Admin common behaviour: shared by every admin page (same feel as the HR pages).
   Part 1: window.ADMIN helpers (settings, notifications, toast, confirm box, row menu, pager,
           requests to static-admin.php, the row-flies-to-the-Archive animation)
   Part 2: entrance animation, sidebar (collapsed state is remembered), logout confirmation
           + the stripe sweep-in
   Load this file BEFORE the page's own script.
   ========================================================================== */

/* ---------- PART 1: shared helpers (window.ADMIN) ---------- */
window.ADMIN = (function () {
    /* ---- Settings (admin/settings-admin.php) -----------------------------------------------------
       Kept in this browser for now, like the HR settings:
         theme           'light' | 'dark' | 'system'   -> <html data-theme>
         animations      false = no page motion         -> <html data-motion="off">
         notifications   master switch for the sidebar badges
         notifyActivity  Dashboard badge: HR did something since the Admin last looked
         notifyArchive   Archive badge: items that will be deleted for good soon
       BACKEND: load them from a user_settings table and print them into the page;
       only readSettings() and saveSettings() have to change. */
    const SETTINGS_KEY = 'admin_settings';
    const PROFILE_KEY = 'admin_profile';                 // { name, email } edited on the Settings page
    const SETTINGS_DEFAULTS = { theme: 'light', animations: true, notifications: true, notifyActivity: true, notifyArchive: true };

    function readJSON(key) {
        try {
            const v = JSON.parse(localStorage.getItem(key) || '{}');
            return v && typeof v === 'object' && !Array.isArray(v) ? v : {};
        } catch (err) {
            return {};
        }
    }

    function store(key, value) {
        try { localStorage.setItem(key, JSON.stringify(value)); return true; } catch (err) { return false; }
    }

    const readSettings = () => Object.assign({}, SETTINGS_DEFAULTS, readJSON(SETTINGS_KEY));
    const readProfile = () => readJSON(PROFILE_KEY);

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

    function saveSettings(partial) {
        const next = Object.assign(readSettings(), partial || {});
        const ok = store(SETTINGS_KEY, next);
        applySettings(next, true);
        refreshBadges();                               // badges appear / disappear right away
        document.dispatchEvent(new CustomEvent('admin:settings-changed', { detail: next }));
        return ok;
    }

    // The name saved in Settings replaces the one the session gave ("Hello, ...!" on the dashboard)
    function applyProfile() {
        const h = document.getElementById('greeting');
        const name = String(readProfile().name || '').trim();
        if (h && name) {
            h.textContent = 'Hello, ' + name + '!';
            h.title = h.textContent;
        }
    }

    function saveProfile(profile) {
        const ok = store(PROFILE_KEY, Object.assign(readProfile(), profile || {}));
        applyProfile();
        return ok;
    }

    if (window.matchMedia) {
        const mq = window.matchMedia('(prefers-color-scheme: dark)');
        const onScheme = () => { if (readSettings().theme === 'system') applySettings(); };
        if (mq.addEventListener) mq.addEventListener('change', onScheme);
        else if (mq.addListener) mq.addListener(onScheme);
    }

    /* ---- Requests to static-admin.php ------------------------------------------------------
       Resolves with the answer ({ status, message, result, data }); rejects with a message that is safe to show.
       BACKEND: point SERVER_API at the real PHP; the same request / answer shape is used. */
    const SERVER_API = '../api/admin-action.php';

    function request(action, payload) {
        return fetch(SERVER_API, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'same-origin',
            body: JSON.stringify(Object.assign({ action: action }, payload || {}))
        })
            .then(
                res => res.json().catch(() => { throw new Error('The server did not answer correctly. Please try again.'); }),
                () => { throw new Error('Could not reach the server. Please check your connection and try again.'); }
            )
            .then(data => {
                if (data.status !== 'success') throw new Error(data.message || 'Something went wrong. Please try again.');
                if (data.data && data.data.archive) setExpiring(countExpiring(data.data.archive));   // keep the Archive badge right
                return data;
            });
    }

    /* ---- Small helpers ------------------------------------------------------------------- */
    // Text -> safe HTML (every name / question the Admin typed goes through this before it is printed)
    function esc(value) {
        return String(value == null ? '' : value).replace(/[&<>"']/g, ch => (
            { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ch]
        ));
    }

    // '2026-10-08 10:34' -> 'Oct 8, 2026  10:34 AM'  (date only when there is no time part)
    const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
    function formatDate(text, withTime) {
        const m = /^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2}))?/.exec(String(text || ''));
        if (!m) return String(text || '');
        let out = MONTHS[Number(m[2]) - 1] + ' ' + Number(m[3]) + ', ' + m[1];
        if (withTime !== false && m[4] !== undefined) {
            const h = Number(m[4]);
            out += '  ' + (h % 12 || 12) + ':' + m[5] + ' ' + (h >= 12 ? 'PM' : 'AM');
        }
        return out;
    }

    const motionOff = () => document.documentElement.getAttribute('data-motion') === 'off';
    const reduceMotion = () => !!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);
    const noMotion = () => motionOff() || reduceMotion();

    // A number that counts up (dashboard boxes). Shows the final number at once when motion is off.
    function countUp(el, target, delay, duration) {
        target = Number(target) || 0;
        el.textContent = String(target);
        if (noMotion() || !target) return;
        el.textContent = '0';
        setTimeout(() => {
            const t0 = performance.now();
            const tick = (now) => {
                const p = Math.min(1, (now - t0) / (duration || 800));
                el.textContent = String(Math.round(target * (1 - Math.pow(1 - p, 3))));
                if (p < 1) requestAnimationFrame(tick);
            };
            requestAnimationFrame(tick);
        }, delay || 0);
    }

    /* ---- Toast (same as the HR toast: optional action button, a bar counts the time down) ---- */
    const TICK = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12.5l4.5 4.5L19 7.5"/></svg>';
    const CROSS = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7 7l10 10M17 7L7 17"/></svg>';

    function toast(message, opts) {
        const o = Object.assign({ actionLabel: '', onAction: null, duration: 5000, error: false }, opts);
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

        const icon = document.createElement('span');
        icon.className = 'toast-icon';
        icon.innerHTML = o.error ? CROSS : TICK;
        t.appendChild(icon);

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

        host.querySelectorAll('.toast').forEach(old => old.remove());          // one toast at a time
        host.appendChild(t);
        requestAnimationFrame(() => t.classList.add('show'));
        timer = setTimeout(close, o.duration);
        return { close };
    }

    /* ---- Confirm box (same look as the logout box) ----------------------------------------
         ADMIN.confirm({ title, message, confirmLabel, danger, icon }).then(yes => { ... })  */
    function confirmBox(opts) {
        const o = Object.assign({ title: 'Are you sure?', message: '', confirmLabel: 'Confirm', cancelLabel: 'Cancel', danger: false, icon: '' }, opts);
        return new Promise(resolve => {
            const back = document.createElement('div');
            back.className = 'modal-backdrop';
            back.setAttribute('role', 'dialog');
            back.setAttribute('aria-modal', 'true');
            back.innerHTML =
                '<div class="modal-card">' +
                (o.icon ? '<div class="modal-icon" aria-hidden="true">' + o.icon + '</div>' : '') +
                '<h3>' + esc(o.title) + '</h3>' +
                '<p>' + esc(o.message) + '</p>' +
                '<div class="modal-actions">' +
                '<button type="button" class="modal-btn is-cancel">' + esc(o.cancelLabel) + '</button>' +
                '<button type="button" class="modal-btn is-confirm">' + esc(o.confirmLabel) + '</button>' +
                '</div></div>';
            document.body.appendChild(back);

            const done = (answer) => {
                document.removeEventListener('keydown', onKey, true);
                back.classList.remove('active');
                setTimeout(() => back.remove(), 320);
                resolve(answer);
            };
            const onKey = (e) => {
                if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); done(false); }
            };
            document.addEventListener('keydown', onKey, true);
            back.addEventListener('click', (e) => { if (e.target === back) done(false); });
            back.querySelector('.is-cancel').addEventListener('click', () => done(false));
            const yes = back.querySelector('.is-confirm');
            yes.addEventListener('click', () => done(true));
            requestAnimationFrame(() => { back.classList.add('active'); yes.focus(); });
        });
    }

    /* ---- Icons used by the tables (Material-style paths) ---------------------------------- */
    const svg = (d) => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="' + d + '"/></svg>';
    const icons = {
        edit: svg('M3 17.25V21h3.75L17.81 9.94l-3.75-3.75L3 17.25zM20.71 7.04c.39-.39.39-1.02 0-1.41l-2.34-2.34c-.39-.39-1.02-.39-1.41 0l-1.83 1.83 3.75 3.75 1.83-1.83z'),
        trash: svg('M6 19c0 1.1.9 2 2 2h8c1.1 0 2-.9 2-2V7H6v12zM19 4h-3.5l-1-1h-5l-1 1H5v2h14V4z'),
        restore: svg('M13 3a9 9 0 0 0-9 9H1l3.89 3.89.07.14L9 12H6c0-3.87 3.13-7 7-7s7 3.13 7 7-3.13 7-7 7c-1.93 0-3.68-.79-4.94-2.06l-1.42 1.42A8.954 8.954 0 0 0 13 21a9 9 0 0 0 0-18zm-1 5v5l4.28 2.54.72-1.21-3.5-2.08V8H12z'),
        search: svg('M15.5 14h-.79l-.28-.27A6.47 6.47 0 0 0 16 9.5 6.5 6.5 0 1 0 9.5 16c1.61 0 3.09-.59 4.23-1.57l.27.28v.79l5 4.99L20.49 19l-4.99-5zm-6 0C7.01 14 5 11.99 5 9.5S7.01 5 9.5 5 14 7.01 14 9.5 11.99 14 9.5 14z'),
        people: svg('M16 11c1.66 0 2.99-1.34 2.99-3S17.66 5 16 5c-1.66 0-3 1.34-3 3s1.34 3 3 3zm-8 0c1.66 0 2.99-1.34 2.99-3S9.66 5 8 5C6.34 5 5 6.34 5 8s1.34 3 3 3zm0 2c-2.33 0-7 1.17-7 3.5V19h14v-2.5c0-2.33-4.67-3.5-7-3.5zm8 0c-.29 0-.62.02-.97.05 1.16.84 1.97 1.97 1.97 3.45V19h6v-2.5c0-2.33-4.67-3.5-7-3.5z'),
        list: svg('M19 3h-4.18C14.4 1.84 13.3 1 12 1s-2.4.84-2.82 2H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm-7 0c.55 0 1 .45 1 1s-.45 1-1 1-1-.45-1-1 .45-1 1-1zm2 14H7v-2h7v2zm3-4H7v-2h10v2zm0-4H7V7h10v2z'),
        archive: svg('M20.54 5.23l-1.39-1.68C18.88 3.21 18.47 3 18 3H6c-.47 0-.88.21-1.16.55L3.46 5.23C3.17 5.57 3 6.02 3 6.5V19c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V6.5c0-.48-.17-.93-.46-1.27zM12 17.5L6.5 12H10v-2h4v2h3.5L12 17.5zM5.12 5l.81-1h12l.94 1H5.12z'),
        dots: svg('M6 10c-1.1 0-2 .9-2 2s.9 2 2 2 2-.9 2-2-.9-2-2-2zm12 0c-1.1 0-2 .9-2 2s.9 2 2 2 2-.9 2-2-.9-2-2-2zm-6 0c-1.1 0-2 .9-2 2s.9 2 2 2 2-.9 2-2-.9-2-2-2z')
    };

    /* ---- Row menu: the three dots in the Action column --------------------------------------
         ADMIN.menu(button, [{ label, icon, danger, onClick }])  */
    let menuEl = null;
    let menuBtn = null;

    function closeMenu() {
        if (!menuEl) return;
        const el = menuEl;
        if (menuBtn) menuBtn.setAttribute('aria-expanded', 'false');
        menuEl = null;
        menuBtn = null;
        el.classList.remove('open');
        setTimeout(() => el.remove(), 220);
    }

    function openMenu(btn, items) {
        const same = menuBtn === btn;
        closeMenu();
        if (same) return;

        const pop = document.createElement('div');
        pop.className = 'menu-pop';
        pop.setAttribute('role', 'menu');
        items.forEach(it => {
            const b = document.createElement('button');
            b.type = 'button';
            b.className = 'menu-item' + (it.danger ? ' danger' : '');
            b.setAttribute('role', 'menuitem');
            b.innerHTML = (it.icon || '') + '<span>' + esc(it.label) + '</span>';
            b.addEventListener('click', () => { closeMenu(); it.onClick(); });
            pop.appendChild(b);
        });
        document.body.appendChild(pop);

        // under the button, right edges lined up; above it when there is no room below
        const r = btn.getBoundingClientRect();
        const w = pop.offsetWidth;
        const h = pop.offsetHeight;
        let top = r.bottom + 6;
        if (top + h > window.innerHeight - 10) top = Math.max(10, r.top - h - 6);
        pop.style.top = top + 'px';
        pop.style.left = Math.max(10, Math.min(window.innerWidth - w - 10, r.right - w)) + 'px';

        menuEl = pop;
        menuBtn = btn;
        btn.setAttribute('aria-expanded', 'true');
        requestAnimationFrame(() => pop.classList.add('open'));
        const first = pop.querySelector('.menu-item');
        if (first) first.focus({ preventScroll: true });

        // arrow keys move inside the menu
        pop.addEventListener('keydown', (e) => {
            if (e.key !== 'ArrowDown' && e.key !== 'ArrowUp') return;
            e.preventDefault();
            const list = Array.from(pop.querySelectorAll('.menu-item'));
            const i = list.indexOf(document.activeElement);
            list[(i + (e.key === 'ArrowDown' ? 1 : -1) + list.length) % list.length].focus();
        });
    }

    document.addEventListener('click', (e) => {
        if (menuEl && !menuEl.contains(e.target) && !(menuBtn && menuBtn.contains(e.target))) closeMenu();
    });
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && menuEl) { e.preventDefault(); const b = menuBtn; closeMenu(); if (b) b.focus(); }
    });
    window.addEventListener('resize', closeMenu);
    window.addEventListener('scroll', closeMenu, true);

    /* ---- Page buttons under a list:  ADMIN.pager(host, page, pages, goTo) ------------------- */
    function pager(host, page, pages, go) {
        host.innerHTML = '';
        if (pages <= 1) return;

        const make = (label, target, opts) => {
            const o = Object.assign({ current: false, disabled: false, aria: '' }, opts);
            const b = document.createElement('button');
            b.type = 'button';
            b.className = 'pg-btn' + (o.current ? ' is-current' : '');
            b.textContent = label;
            b.disabled = o.disabled;
            b.setAttribute('aria-label', o.aria || ('Page ' + label));
            if (o.current) b.setAttribute('aria-current', 'page');
            b.addEventListener('click', () => go(target));
            host.appendChild(b);
        };

        make('\u2039', page - 1, { disabled: page === 1, aria: 'Previous page' });
        const keep = new Set([1, pages, page - 1, page, page + 1]);
        let last = 0;
        Array.from(keep).filter(n => n >= 1 && n <= pages).sort((a, b) => a - b).forEach(n => {
            if (last && n - last > 1) {
                const gap = document.createElement('span');
                gap.textContent = '\u2026';
                gap.className = 'pg-gap';
                host.appendChild(gap);
            }
            make(String(n), n, { current: n === page });
            last = n;
        });
        make('\u203A', page + 1, { disabled: page === pages, aria: 'Next page' });
    }

    // "Showing 1-3 of 3" text for a list
    function showing(total, start, count) {
        if (!total) return '';
        return 'Showing ' + (start + 1) + '\u2013' + (start + count) + ' of ' + total;
    }

    /* ---- A message that is shown on the NEXT page (after a save + redirect) ------------------ */
    const FLASH_KEY = 'admin_flash';
    function flash(message, opts) {
        try { sessionStorage.setItem(FLASH_KEY, JSON.stringify({ message: message, error: !!(opts && opts.error), id: (opts && opts.id) || '' })); } catch (err) { /* storage unavailable */ }
    }

    // Returns what was flashed (so the list can highlight the saved row) and shows the toast
    function showFlash() {
        try {
            const raw = sessionStorage.getItem(FLASH_KEY);
            if (!raw) return null;
            sessionStorage.removeItem(FLASH_KEY);
            const f = JSON.parse(raw);
            if (f && f.message) setTimeout(() => toast(f.message, { duration: 4000, error: !!f.error }), 450);
            return f || null;
        } catch (err) { return null; }
    }

    /* ---- Notifications (sidebar badges) --------------------------------------------------------
       Same idea as the HR badges:
         Dashboard badge = HR activities the Admin has not looked at yet (cleared when the Dashboard opens)
         Archive badge   = archived items that will be deleted for good within a week
       ADMIN_NOTIFY (printed by layout.php) = { activity: ['2026-10-08 10:34', ...], expiring: 2, days: 7 }
       The "seen" time is kept in this browser for now; with the database it becomes a column
       (users.last_seen_activity_at). */
    const SEEN_KEY = 'admin_activity_seen';
    const notify = window.ADMIN_NOTIFY || { activity: [], expiring: 0, days: 7 };
    let expiring = Number(notify.expiring) || 0;

    function notifyOn(kind) {
        const s = readSettings();
        return s.notifications !== false && s[kind === 'archive' ? 'notifyArchive' : 'notifyActivity'] !== false;
    }

    function readSeen() {
        try { return localStorage.getItem(SEEN_KEY) || ''; } catch (err) { return ''; }
    }

    // The HR activities newer than the last time the Admin opened the Dashboard
    function unseenActivity() {
        if (!notifyOn('activity')) return [];
        const seen = readSeen();
        return (notify.activity || []).filter(at => at > seen);
    }

    // HR changes (name, email, password) the Admin has not opened yet. Unlike the list above they are NOT cleared
    // by opening the Dashboard: a row stays highlighted until the Admin opens it, or approves / rejects it.
    // BACKEND: SELECT COUNT(*) FROM activity_log WHERE actor_role = 'hr' AND read_by_admin_at IS NULL
    function unreadHrChanges() {
        return notifyOn('activity') && window.HRREQ ? HRREQ.adminUnread().length : 0;
    }

    function markActivitySeen() {
        const newest = (notify.activity || []).reduce((a, b) => (b > a ? b : a), readSeen());
        try { localStorage.setItem(SEEN_KEY, newest); } catch (err) { /* storage unavailable */ }
        refreshBadges();
    }

    function countExpiring(archive) {
        const days = Number(notify.days) || 7;
        let n = 0;
        ['questions', 'accounts'].forEach(k => (archive[k] || []).forEach(r => { if (Number(r.days_left) <= days) n++; }));
        return n;
    }

    function setExpiring(n) {
        expiring = Math.max(0, Number(n) || 0);
        refreshBadges();
    }

    /* ---- New HR account (sidebar: HR Accounts) ------------------------------------------------
       Same idea as the HR "new applicant" badge: set when the Admin creates an HR account,
       cleared as soon as the HR Accounts list is opened (hrlist.js reads the ids first, so the new
       rows can still be pointed out on that visit).
       Kept in this browser for now; with the database it becomes a "seen by Admin" flag. */
    const NEW_HR_KEY = 'admin_new_hr';

    function readNewHr() {
        try {
            const v = JSON.parse(localStorage.getItem(NEW_HR_KEY) || '[]');
            return Array.isArray(v) ? v.map(Number).filter(Boolean) : [];
        } catch (err) { return []; }
    }

    function writeNewHr(list) {
        try { localStorage.setItem(NEW_HR_KEY, JSON.stringify(list)); } catch (err) { /* storage unavailable */ }
    }

    function hrNotifyOn() { return readSettings().notifications !== false; }      // Settings > Notifications (master switch)

    function noteNewHr(id) {
        const list = readNewHr();
        id = Number(id);
        if (id && !list.includes(id)) { list.push(id); writeNewHr(list); }
        refreshBadges();
    }

    function clearNewHr() {
        writeNewHr([]);
        refreshBadges();
    }

    // The badge is added to the "HR Accounts" sidebar item by script, so every admin page gets it
    function ensureHrBadge() {
        let badge = document.getElementById('hrBadge');
        if (!badge) {
            const link = document.querySelector('.sidebar-nav a[href$="hr-list.php"]');
            if (!link) return null;
            badge = document.createElement('span');
            badge.className = 'nav-badge alert';
            badge.id = 'hrBadge';
            badge.hidden = true;
            link.appendChild(badge);
        }
        return badge;
    }

    const lastCount = { dashboardBadge: -1, archiveBadge: -1, hrBadge: -1 };
    function paintBadge(id, count, title) {
        const badge = document.getElementById(id);
        if (!badge) return;
        badge.textContent = count > 99 ? '99+' : String(count);
        badge.hidden = count <= 0;
        badge.setAttribute('title', title);
        if (count > lastCount[id] && count > 0) {
            badge.classList.remove('pop');
            void badge.offsetWidth;                   // restart the animation
            badge.classList.add('pop');
        }
        lastCount[id] = count;
    }

    function refreshBadges() {
        const a = unseenActivity().length + unreadHrChanges();
        const e = notifyOn('archive') ? expiring : 0;
        const h = hrNotifyOn() ? readNewHr().length : 0;
        ensureHrBadge();
        paintBadge('hrBadge', h, h + ' new HR ' + (h === 1 ? 'account' : 'accounts'));
        paintBadge('dashboardBadge', a, a + ' HR ' + (a === 1 ? 'update' : 'updates') + ' waiting for you');
        paintBadge('archiveBadge', e, e + ' archived ' + (e === 1 ? 'item' : 'items') + ' will be deleted for good within ' + (notify.days || 7) + ' days');
        document.dispatchEvent(new CustomEvent('admin:badges', { detail: { activity: a, expiring: e, hr: h } }));
        return { activity: a, expiring: e };
    }

    function bumpArchive() { bumpNav('#archiveLink'); }

    // The sidebar item pops when something lands on it (selector = the link, e.g. '#archiveLink')
    function bumpNav(selector) {
        const link = document.querySelector(selector);
        if (!link) return;
        link.classList.remove('bump');
        void link.offsetWidth;
        link.classList.add('bump');
        setTimeout(() => link.classList.remove('bump'), 700);
    }

    /* ---- Row flies to the Archive (same animation as the HR Screening List) ---------------------
         ADMIN.flyToArchive(rows, label)  rows = <tr> elements, label(tr) = the text on the flying chip
       Resolves when every chip has landed. Without motion the rows simply fade (class row-leave). */
    function flyToArchive(rows, label) { return flyToNav(rows, label, '#archiveLink'); }

    /* ---- Row flies to ANY sidebar item (used by Restore in the Archive: the row flies back to
         "HR Accounts" or "Questions"). Same chip, same flight as flyToArchive. ------------------- */
    function flyToNav(rows, label, selector) {
        const link = document.querySelector(selector);
        const icon = link ? link.querySelector('.nav-icon') : null;
        if (!rows.length || !icon || noMotion() || !document.body.animate) {
            rows.forEach(tr => tr.classList.add('row-leave'));
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

            const chip = document.createElement('div');
            chip.className = 'fly-chip';
            const name = document.createElement('span');
            name.className = 'fly-name';
            name.textContent = label ? label(tr) : '';
            chip.appendChild(name);
            chip.style.left = cx + 'px';
            chip.style.top = cy + 'px';
            chip.style.width = r.width + 'px';
            chip.style.height = r.height + 'px';
            document.body.appendChild(chip);
            tr.classList.add('row-leave');

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

        rows.slice(12).forEach(tr => tr.classList.add('row-leave'));    // rows beyond the 12th just fade
        return Promise.all(flights);
    }

    /* ---- Live update: the badges follow what HR does, without reloading the page -----------------
       Every POLL_MS the page asks static-admin.php (action 'notify') for the fresh numbers and repaints
       the badges. The "seen" rule is the same: the Dashboard badge counts HR activities newer than the
       last time the Admin opened the Dashboard, so opening (or clicking) the Dashboard clears it.
       Paused while the tab is hidden; one extra check as soon as the tab is visible again.
       BACKEND: keep the 'notify' action (or a small endpoint) answering { activity: [...], expiring, days }. */
    const POLL_MS = 15000;
    let pollTimer = null;

    function pollNotify() {
        if (document.hidden) return Promise.resolve();
        return request('notify')
            .then(res => {
                const n = res && res.data && res.data.notify;
                if (!n) return;
                notify.activity = Array.isArray(n.activity) ? n.activity : [];
                if (n.days) notify.days = n.days;
                expiring = Math.max(0, Number(n.expiring) || 0);
                refreshBadges();
            })
            .catch(() => { /* offline or session ended: keep the numbers on screen, try again next time */ });
    }

    function startPolling() {
        if (pollTimer) return;
        pollTimer = setInterval(pollNotify, POLL_MS);
        document.addEventListener('visibilitychange', () => { if (!document.hidden) pollNotify(); });
    }

    /* ---- Start ------------------------------------------------------------------------------ */
    applySettings();                                   // the <head> script already did this; harmless backup
    document.addEventListener('DOMContentLoaded', () => {
        applyProfile();
        refreshBadges();
        startPolling();
    });

    window.addEventListener('storage', (e) => {
        if (e.key === SETTINGS_KEY) { applySettings(null, true); refreshBadges(); }   // Settings saved in another tab
        if (e.key === PROFILE_KEY) applyProfile();
        if (e.key === SEEN_KEY || e.key === NEW_HR_KEY) refreshBadges();
        if (e.key === 'ebsmi_activity_extra' || e.key === 'ebsmi_change_requests') refreshBadges();   // HR sent a change (other tab)
    });

    return {
        request, esc, formatDate, toast, confirm: confirmBox, icons, countUp, noMotion,
        menu: openMenu, closeMenu, pager, showing, flash, showFlash,
        flyToArchive, flyToNav, bumpArchive, bumpNav,
        hr: { note: noteNewHr, clear: clearNewHr, ids: readNewHr },
        notify: { unseen: unseenActivity, unreadChanges: unreadHrChanges, markSeen: markActivitySeen, setExpiring: setExpiring, refresh: refreshBadges, poll: pollNotify, on: notifyOn },
        settings: { get: readSettings, save: saveSettings, apply: applySettings, defaults: SETTINGS_DEFAULTS, profile: readProfile, saveProfile: saveProfile },
        arrivedViaTransition: false
    };
})();


/* ---------- PART 2: entrance, sidebar, logout ---------- */
document.addEventListener('DOMContentLoaded', () => {
    const root = document.documentElement;
    const sidebar = document.getElementById('sidebar');
    const sidebarToggle = document.getElementById('sidebarToggle');
    const logoutBtn = document.getElementById('logoutBtn');
    const logoutModal = document.getElementById('logoutModal');
    const cancelLogout = document.getElementById('cancelLogout');
    const confirmLogout = document.getElementById('confirmLogout');
    const pageOverlay = document.getElementById('pageOverlay');

    // The sidebar state (collapsed / expanded) is remembered across every admin page
    const SIDEBAR_KEY = 'admin_sidebar_collapsed';
    const sidebarSaved = () => {
        try { return localStorage.getItem(SIDEBAR_KEY) === '1'; } catch (err) { return false; }
    };
    const saveSidebar = () => {
        try { localStorage.setItem(SIDEBAR_KEY, sidebar.classList.contains('collapsed') ? '1' : '0'); } catch (err) { /* storage unavailable */ }
    };

    // Normally the small inline script under the <aside> has already done this before the first paint
    if (sidebar && sidebarSaved()) sidebar.classList.add('collapsed');

    // 1. Page entrance
    const params = new URLSearchParams(window.location.search);
    const arrivedViaTransition = params.get('transition') === 'out' && pageOverlay;
    ADMIN.arrivedViaTransition = !!arrivedViaTransition;

    if (arrivedViaTransition) {
        // The page is already covered (set in <head>). Sweep the stripes out and let the
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

        // Remove only the transition flag from the URL (keep any other parts)
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

    // 3. Logout: confirm, then elements lift away while the stripes sweep in
    if (logoutBtn && logoutModal) {
        logoutBtn.addEventListener('click', () => {
            logoutModal.classList.add('active');
            setTimeout(() => { if (cancelLogout) cancelLogout.focus(); }, 120);
        });

        // Click on the dark backdrop or press Escape to cancel
        logoutModal.addEventListener('click', (e) => {
            if (e.target === logoutModal) logoutModal.classList.remove('active');
        });
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && logoutModal.classList.contains('active')) {
                e.preventDefault();
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

    // 4. Browser back button (page restored from cache): reset the leaving state
    //    and re-apply what was saved meanwhile (sidebar, settings, badges)
    window.addEventListener('pageshow', (event) => {
        if (!event.persisted) return;
        ADMIN.settings.apply();
        ADMIN.notify.refresh();
        if (sidebar) sidebar.classList.toggle('collapsed', sidebarSaved());
        if (!pageOverlay) return;
        root.classList.remove('is-leaving', 'is-transitioning');
        pageOverlay.classList.remove('sweep-in', 'sweep-out');
        if (confirmLogout) confirmLogout.disabled = false;
    });
});

/* ===== PAGE : addhr.js (runs only on: addhr) ===== */
if (document.documentElement.getAttribute('data-page') === 'addhr') {
/* ==========================================================================
   addhr.js: Create HR Account / Edit HR Account (admin).
   Same behaviour as the HR Add Applicant page (assets/js/addapplicants.js): rules that say WHAT is
   wrong, messages that appear while typing, a shake for a refused field, a spinner while saving,
   a "created" modal and then the success panel.
   Shared helpers (window.ADMIN: requests, toast, notifications), the sidebar, logout and the
   entrance animation live in admin-common.js (load admin-common.js BEFORE this file).
   ========================================================================== */
document.addEventListener('DOMContentLoaded', () => {
    const config = window.ADMIN_HR_FORM || { mode: 'create', account: null };
    const isEdit = config.mode === 'edit' && !!config.account;

    const form = document.getElementById('hrForm');
    const formCard = document.getElementById('formCard');
    const successCard = document.getElementById('successCard');
    const banner = document.getElementById('formBanner');
    const saveBtn = document.getElementById('saveBtn');
    const cancelBtn = document.getElementById('cancelBtn');
    const backLink = document.getElementById('backLink');
    const sendEmail = document.getElementById('sendEmail');

    const createdModal = document.getElementById('createdModal');
    const discardModal = document.getElementById('discardModal');

    let saving = false;
    let lastAccount = null;
    let lastEmailed = false;

    const fieldOf = (name) => form.querySelector('.field[data-field="' + name + '"]');

    /* ---------- custom dropdowns (Role, Status): the same one as the HR filters ---------- */
    const dropdowns = [];

    function createDropdown(root) {
        const btn = root.querySelector('.dropdown-btn');
        const menu = root.querySelector('.dropdown-menu');
        const valueEl = root.querySelector('.dropdown-value');
        const btnDot = btn.querySelector('.dot');
        const field = root.closest('.field');
        const options = Array.from(menu.querySelectorAll('.dropdown-option'));
        let value = options[0].dataset.value;
        let focusIdx = -1;

        options.forEach(o => o.querySelector('.dot').style.setProperty('--dot', o.dataset.dot));

        const isOpen = () => root.classList.contains('open');

        function highlight(i) {
            options.forEach((o, k) => o.classList.toggle('is-focused', k === i));
            focusIdx = i;
        }

        function setValue(v) {
            const opt = options.find(o => o.dataset.value === v) || options[0];
            value = opt.dataset.value;
            options.forEach(o => o.setAttribute('aria-selected', String(o === opt)));
            valueEl.textContent = opt.querySelector('.opt-label').textContent;
            btnDot.style.setProperty('--dot', opt.dataset.dot);
        }

        function open() {
            dropdowns.forEach(d => d.close());
            root.classList.add('open');
            if (field) field.classList.add('is-open');
            btn.setAttribute('aria-expanded', 'true');
            highlight(Math.max(0, options.findIndex(o => o.dataset.value === value)));
        }

        function close() {
            root.classList.remove('open');
            if (field) field.classList.remove('is-open');
            btn.setAttribute('aria-expanded', 'false');
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
                e.preventDefault();
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

        const api = { root, close, setValue, getValue: () => value };
        dropdowns.push(api);
        return api;
    }

    document.addEventListener('click', (e) => {
        dropdowns.forEach(d => { if (!d.root.contains(e.target)) d.close(); });
    });

    const roleDD = createDropdown(document.getElementById('roleDropdown'));
    const statusDD = createDropdown(document.getElementById('statusDropdown'));

    /* ---------- Current password (view only) ----------
       BACKEND: print the account's real current password into #current_password from PHP instead. */
    if (isEdit && document.getElementById('current_password')) {
        // demo value until the backend prints the real one
        document.getElementById('current_password').value = HRREQ.password(config.account.id) || 'Demo@12345';
    }

    /* ---------- the eye: show / hide the password ---------- */
    form.querySelectorAll('.eye-toggle').forEach(btn => {
        btn.addEventListener('click', () => {
            const input = document.getElementById(btn.dataset.target);
            const show = input.type === 'password';
            input.type = show ? 'text' : 'password';
            btn.setAttribute('aria-pressed', String(show));
            btn.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
            input.focus();
        });
    });

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
            if (t.toLowerCase() === 'admin@expertise.com') return 'That email belongs to the Admin account.';
            return '';
        },
        password(v) {
            if (!v) return isEdit ? '' : 'Please enter a password.';       // Edit: empty = keep the current password
            if (/\s/.test(v)) return 'The password cannot contain spaces.';
            if (v.length < 8) return 'The password is too short (at least 8 characters).';
            if (!/[A-Za-z]/.test(v)) return 'Add at least one letter to the password.';
            if (!/\d/.test(v)) return 'Add at least one number to the password.';
            return '';
        },
        password_confirm(v) {
            const pass = form.elements.password.value;
            if (!pass && !v) return '';                                    // Edit with no new password
            if (!v) return 'Please confirm the password.';
            if (v !== pass) return 'The passwords do not match.';
            return '';
        }
    };

    function setError(name, message, shake) {
        const field = fieldOf(name);
        if (!field) return;
        const input = form.elements[name];
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
        const message = rules[name](form.elements[name].value);
        setError(name, message, shake);
        return !message;
    }

    // Typing: once a box has been used, it is re-checked on every keystroke, so the message appears the
    // moment something is wrong and goes away the moment it is fixed. Leaving a used box also checks it.
    const FIELDS = ['name', 'email', 'password', 'password_confirm'];
    const touched = new Set();

    FIELDS.forEach(name => {
        const input = form.elements[name];
        input.setAttribute('aria-describedby', name + 'Error');
        fieldOf(name).querySelector('.field-error').id = name + 'Error';

        input.addEventListener('input', () => {
            touched.add(name);
            hideBanner();
            validateField(name, false);
            // changing the password re-checks the confirmation (and the other way round)
            if (name === 'password' && touched.has('password_confirm')) validateField('password_confirm', false);
        });
        input.addEventListener('blur', () => { if (touched.has(name)) validateField(name, false); });
    });

    function showBanner(message) {
        banner.textContent = message;
        banner.classList.add('show');
    }

    function hideBanner() {
        banner.classList.remove('show');
    }

    /* ---------- start values ---------- */
    if (isEdit) {
        roleDD.setValue(config.account.role || 'HR Staff');
        statusDD.setValue(config.account.status || 'Active');
    } else {
        roleDD.setValue('HR Staff');
        statusDD.setValue('Active');
    }

    /* ---------- submit ---------- */
    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        if (saving) return;
        hideBanner();

        // validate everything, focus the first problem
        let firstBad = null;
        FIELDS.forEach(name => {
            if (!validateField(name, true) && !firstBad) firstBad = name;
        });
        if (firstBad) {
            const bad = form.querySelectorAll('.field.has-error').length;
            ADMIN.toast('Please fix the ' + bad + ' highlighted field' + (bad === 1 ? '' : 's') + ' before saving.', { error: true, duration: 4000 });
            form.elements[firstBad].focus({ preventScroll: false });
            return;
        }

        setSaving(true);
        try {
            const account = {
                name: form.elements.name.value.trim(),
                email: form.elements.email.value.trim(),
                password: form.elements.password.value,
                password_confirm: form.elements.password_confirm.value,
                role: roleDD.getValue(),
                status: statusDD.getValue(),
                send_email: sendEmail.checked
            };

            const res = isEdit
                ? await ADMIN.request('account_update', { id: config.account.id, account: account })
                : await ADMIN.request('account_add', { account: account });

            const saved = res.result || account;

            lastAccount = saved;
            lastEmailed = !!saved.emailed;

            if (isEdit) {
                // "Edited Successfully!" modal; DONE goes back to the list, where the saved row glows
                openCreatedModal(saved);
                return;
            }

            ADMIN.hr.note(saved.id);              // red badge on the HR Accounts sidebar item, gone once the list is opened
            openCreatedModal(saved);
        } catch (err) {
            showServerError(err.message || 'Something went wrong. Please try again.');
        } finally {
            setSaving(false);
        }
    });

    // The server refused something: put the message under the field it is about
    function showServerError(message) {
        const lower = message.toLowerCase();
        let target = null;
        if (lower.includes('match')) target = 'password_confirm';
        else if (lower.includes('password')) target = 'password';
        else if (lower.includes('email')) target = 'email';
        else if (lower.includes('name')) target = 'name';

        if (target) {
            setError(target, message, true);
            form.elements[target].focus();
        } else {
            showBanner(message);
        }
        ADMIN.toast(message, { error: true, duration: 6000 });
        formCard.classList.remove('shake');
        void formCard.offsetWidth;
        formCard.classList.add('shake');
    }

    function setSaving(state) {
        saving = state;
        saveBtn.disabled = state;
        saveBtn.classList.toggle('saving', state);
        saveBtn.querySelector('.btn-label').textContent = state ? 'SAVING...' : (isEdit ? 'SAVE CHANGES' : 'SAVE');
        cancelBtn.disabled = state;
    }

    /* ---------- "created" modal -> success panel ---------- */
    function openCreatedModal(account) {
        document.getElementById('createdTitle').textContent = isEdit ? 'Edited Successfully!' : 'New HR Account created Successfully!';
        document.getElementById('createdEmail').textContent = account.email;
        document.getElementById('createdChip').hidden = !lastEmailed;
        document.getElementById('createdLead').textContent = lastEmailed
            ? (isEdit ? 'The new login credentials will be sent to:' : 'The login credentials will be sent to:')
            : (isEdit ? 'The changes were saved. No login email was sent.' : 'No login email was sent. Share the login details with the new HR yourself.');
        createdModal.classList.add('active');
        document.getElementById('createdDone').focus();
    }

    function finishCreated() {
        if (!createdModal.classList.contains('active')) return;
        createdModal.classList.remove('active');
        if (!lastAccount) return;

        if (isEdit) {
            ADMIN.flash('HR account saved.', { id: lastAccount.id || config.account.id });
            window.location.href = 'hr-list.php';
            return;
        }

        document.getElementById('detName').textContent = lastAccount.name;
        document.getElementById('detEmail').textContent = lastAccount.email;
        document.getElementById('detRole').textContent = lastAccount.role || 'HR Staff';
        document.getElementById('successLead').textContent = lastEmailed
            ? 'The new HR account has been added to the system and the login credentials have been sent via email.'
            : 'The new HR account has been added to the system.';

        formCard.hidden = true;
        successCard.hidden = false;
        window.scrollTo(0, 0);
        document.querySelector('.main-content').scrollTop = 0;
    }

    document.getElementById('createdDone').addEventListener('click', finishCreated);
    document.getElementById('createdClose').addEventListener('click', finishCreated);
    createdModal.addEventListener('click', (e) => { if (e.target === createdModal) finishCreated(); });

    document.getElementById('addAnother').addEventListener('click', () => {
        form.reset();
        roleDD.setValue('HR Staff');
        statusDD.setValue('Active');
        sendEmail.checked = true;
        form.querySelectorAll('.eye-toggle').forEach(b => {
            document.getElementById(b.dataset.target).type = 'password';
            b.setAttribute('aria-pressed', 'false');
            b.setAttribute('aria-label', 'Show password');
        });
        FIELDS.forEach(n => setError(n, ''));
        touched.clear();
        hideBanner();
        lastAccount = null;
        successCard.hidden = true;
        formCard.hidden = false;
        // replay the small rise-in so the swap does not feel abrupt
        formCard.style.animation = 'none';
        void formCard.offsetWidth;
        formCard.style.animation = '';
        form.elements.name.focus();
    });

    /* ---------- Cancel / Back (asks first when something was typed) ---------- */
    const isDirty = () => {
        if (isEdit) {
            const a = config.account;
            return form.elements.name.value.trim() !== a.name
                || form.elements.email.value.trim() !== a.email
                || statusDD.getValue() !== a.status
                || form.elements.password.value !== ''
                || form.elements.password_confirm.value !== ''
                || !sendEmail.checked;
        }
        return ['name', 'email', 'password', 'password_confirm'].some(n => form.elements[n].value.trim() !== '');
    };

    function leave() { window.location.href = 'hr-list.php'; }

    function tryLeave() {
        if (isDirty() && !formCard.hidden) discardModal.classList.add('active');
        else leave();
    }

    cancelBtn.addEventListener('click', tryLeave);
    backLink.addEventListener('click', (e) => {
        if (isDirty() && !formCard.hidden) { e.preventDefault(); discardModal.classList.add('active'); }
    });

    document.getElementById('keepEditing').addEventListener('click', () => discardModal.classList.remove('active'));
    document.getElementById('confirmDiscard').addEventListener('click', () => {
        discardModal.classList.remove('active');
        leave();
    });
    discardModal.addEventListener('click', (e) => { if (e.target === discardModal) discardModal.classList.remove('active'); });

    document.addEventListener('keydown', (e) => {
        if (e.key !== 'Escape') return;
        if (createdModal.classList.contains('active')) finishCreated();
        else if (discardModal.classList.contains('active')) discardModal.classList.remove('active');
    });
});
}

/* ===== PAGE : addquestion.js (runs only on: addquestion) ===== */
if (document.documentElement.getAttribute('data-page') === 'addquestion') {
/* ==========================================================================
   Create / Edit Questionnaire (admin): the form behind "Create Questions" and the pencil in the Questionnaires list.
   Same behaviour as Create HR Account (assets/js/addhr.js): rules that say WHAT is wrong, messages while typing,
   a shake for a refused field, a spinner while saving, "are you sure" when leaving with unsaved work.
   Sections:  - pick one from the list -> the box next to it becomes "Rename Section" (the title changes for every
                question of that section)
              - leave the list empty and type a title in "New Section" -> it becomes the next section number
   Shared helpers (window.ADMIN: requests, toast, flash), the sidebar and logout live in admin-common.js.
   ========================================================================== */
document.addEventListener('DOMContentLoaded', () => {
    const config = window.ADMIN_QFORM || { mode: 'create', question: null, sections: [], nextNo: 1 };
    const isEdit = config.mode === 'edit' && !!config.question;
    const Q = config.question || {};

    const form = document.getElementById('qForm');
    const formCard = document.getElementById('formCard');
    const banner = document.getElementById('formBanner');
    const saveBtn = document.getElementById('saveBtn');
    const cancelBtn = document.getElementById('cancelBtn');
    const backLink = document.getElementById('backLink');
    const discardModal = document.getElementById('discardModal');
    const titleInput = document.getElementById('sectionTitle');
    const titleLabel = document.getElementById('sectionTitleLabel');
    const sectionHint = document.getElementById('sectionHint');
    const textInput = document.getElementById('text');
    const textCount = document.getElementById('textCount');

    let saving = false;
    let leaving = false;

    const fieldOf = (name) => form.querySelector('.field[data-field="' + name + '"]');
    const val = (name) => (form.elements[name] ? form.elements[name].value : '');

    const sections = (config.sections || []).map(s => ({ no: String(s.no), name: String(s.name) }));
    const sectionByNo = (no) => sections.find(s => s.no === String(no)) || null;

    /* ---------- custom dropdowns (Section, Type, Status + two small ones): the same one as the HR forms ---------- */
    const dropdowns = [];

    function createDropdown(root, onChange) {
        const btn = root.querySelector('.dropdown-btn');
        const menu = root.querySelector('.dropdown-menu');
        const valueEl = root.querySelector('.dropdown-value');
        const btnDot = btn.querySelector('.dot');
        const field = root.closest('.field');
        const options = Array.from(menu.querySelectorAll('.dropdown-option'));
        const start = options.find(o => o.getAttribute('aria-selected') === 'true') || options[0];
        let value = start.dataset.value;
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
            root.classList.toggle('is-placeholder', value === '');
            if (!silent && onChange) onChange(value);
        }

        function open() {
            dropdowns.forEach(d => d.close());
            root.classList.add('open');
            if (field) field.classList.add('is-open');
            btn.setAttribute('aria-expanded', 'true');
            highlight(Math.max(0, options.findIndex(o => o.dataset.value === value)));
        }

        function close() {
            root.classList.remove('open');
            if (field) field.classList.remove('is-open');
            btn.setAttribute('aria-expanded', 'false');
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
                e.preventDefault();
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

        setValue(value, true);
        const api = { root, btn, close, setValue, getValue: () => value };
        dropdowns.push(api);
        return api;
    }

    document.addEventListener('click', (e) => {
        dropdowns.forEach(d => { if (!d.root.contains(e.target)) d.close(); });
    });

    const dd = (id, onChange) => createDropdown(document.getElementById(id), onChange);
    const sectionDD = dd('sectionDropdown', () => { onSectionPicked(); if (touched.has('section')) validateField('section', false); });
    const typeDD = dd('typeDropdown', () => { showTypeBox(); hideBanner(); validateField('type', false); });
    const statusDD = dd('statusDropdown', () => hideBanner());
    const favorableDD = dd('favorableDropdown');
    const followupWhenDD = dd('followupWhenDropdown', () => syncKnockout());
    const passDD = dd('passDropdown', () => { syncKnockout(); hideBanner(); setError('pass_answer', ''); });

    /* ---------- Knock-out question (screening rubric): which answer passes ---------- */
    const KO_TYPES = ['yesno', 'yesno_select', 'scale'];
    const koCheck = document.getElementById('knockout');
    const koFields = document.getElementById('koFields');
    const passFollowField = document.getElementById('passFollowField');
    const passFollowList = document.getElementById('passFollowList');
    const passMinField = document.getElementById('passMinField');
    const passMinHint = document.getElementById('passMinHint');
    let acceptedFollow = new Set();       // the list choices ticked as "still pass"

    const koOn = () => KO_TYPES.indexOf(typeDD.getValue()) !== -1 && koCheck.checked;

    /* ---------- Section: pick one (and rename it) or type a new one ---------- */
    function matchSectionByTitle(title, exceptNo) {
        const t = title.trim().toLowerCase();
        return t ? sections.find(s => s.name.toLowerCase() === t && s.no !== String(exceptNo || '')) || null : null;
    }

    function updateSectionBox() {
        const picked = sectionByNo(sectionDD.getValue());
        const title = titleInput.value.trim();
        if (picked) {
            titleLabel.textContent = 'Rename Section';
            titleInput.placeholder = 'Type a new title for Section ' + picked.no;
            sectionHint.textContent = title && title !== picked.name
                ? 'Section ' + picked.no + ' will be renamed to \u201C' + title + '\u201D for every question in it.'
                : 'Change the title to rename Section ' + picked.no + ' (it updates every question in it).';
        } else {
            titleLabel.textContent = 'New Section';
            titleInput.placeholder = 'Type New Section';
            const same = matchSectionByTitle(title);
            sectionHint.textContent = same
                ? '\u201C' + same.name + '\u201D already exists (Section ' + same.no + '). The question goes into it.'
                : 'Type only the title. It is added automatically as Section ' + config.nextNo + '.';
        }
    }

    // The Admin picked another section (or cleared it): the box shows that section's title to rename, or is empty for a new one
    function onSectionPicked() {
        const picked = sectionByNo(sectionDD.getValue());
        titleInput.value = picked ? picked.name : '';
        setError('section_title', '');
        updateSectionBox();
    }

    /* ---------- Type: only the matching extra box is shown ---------- */
    const TYPE_FIELDS = {
        yesno: ['no_note'],
        yesno_select: ['followup_label', 'followup_options'],
        scale: ['max', 'label_left', 'label_right'],
        date: ['date_hint'],
        text: ['text_hint', 'placeholder', 'min_chars', 'max_chars'],
        consent: ['body', 'checkbox']
    };

    const hasLink = () => textInput.value.indexOf('{link}') !== -1;

    function showTypeBox() {
        const type = typeDD.getValue();
        form.querySelectorAll('.sub-fields[data-for]').forEach(box => {
            const f = box.dataset.for;
            if (f === 'link') box.hidden = !hasLink();
            else if (f === 'knockout') box.hidden = KO_TYPES.indexOf(type) === -1;
            else box.hidden = f !== type;
        });
        syncKnockout();
    }

    // Shows what the knock-out box needs for the chosen type (Yes/No: passing answer, list: + choices, rating: lowest rating)
    function syncKnockout() {
        const type = typeDD.getValue();
        const on = koOn();
        koFields.hidden = !on;

        // knock-out Yes/No: the passing answer is the good answer, so the separate "good answer" box is not needed
        const fav = fieldOf('favorable');
        if (fav) fav.hidden = on && type === 'yesno';
        if (on && type === 'yesno') favorableDD.setValue(passDD.getValue(), true);

        fieldOf('pass_answer').hidden = !(on && (type === 'yesno' || type === 'yesno_select'));
        passMinField.hidden = !(on && type === 'scale');
        const showChoices = on && type === 'yesno_select' && passDD.getValue() === followupWhenDD.getValue();
        passFollowField.hidden = !showChoices;
        if (showChoices) renderFollowChoices();
        if (on && type === 'scale') {
            const max = val('max').trim();
            passMinHint.textContent = isWhole(max) && Number(max) >= 3 && Number(max) <= 10
                ? 'The scale goes from 1 to ' + max + '. A rating lower than this fails the screening.'
                : 'A rating lower than this fails the screening.';
        }
        if (!on) { setError('pass_answer', ''); setError('pass_min', ''); }
    }

    // One tick box per choice of the list (the choices come from the "Choices in the list" box)
    function renderFollowChoices() {
        const options = lines(val('followup_options'));
        passFollowList.textContent = '';
        options.forEach(text => {
            const label = document.createElement('label');
            label.className = 'check-row';
            const input = document.createElement('input');
            input.type = 'checkbox';
            input.checked = acceptedFollow.has(text);
            input.addEventListener('change', () => {
                if (input.checked) acceptedFollow.add(text); else acceptedFollow.delete(text);
                hideBanner();
            });
            const box = document.createElement('span');
            box.className = 'box';
            box.innerHTML = '<svg viewBox="0 0 24 24"><path d="M5 12.5l4.5 4.5L19 7.5"/></svg>';
            label.append(input, box, document.createTextNode(text));
            passFollowList.appendChild(label);
        });
        if (!options.length) {
            const empty = document.createElement('p');
            empty.className = 'field-hint';
            empty.textContent = 'Write the choices of the list above first.';
            passFollowList.appendChild(empty);
        }
    }

    function activeFields() {
        const list = ['text', 'section', 'section_title', 'type'].concat(TYPE_FIELDS[typeDD.getValue()] || []);
        if (koOn() && typeDD.getValue() === 'scale') list.push('pass_min');
        if (hasLink()) list.push('link_text', 'link_url');
        return list;
    }

    /* ---------- validation ---------- */
    // Each rule returns the error text (or '' when the value is fine). The messages say WHAT is wrong.
    const isWhole = (t) => /^\d+$/.test(t.trim());
    const lines = (t) => t.split(/\r?\n/).map(s => s.trim()).filter(Boolean);

    const rules = {
        text(v) {
            const t = v.trim();
            if (!t) return 'Please write the question.';
            if (t.length > 400) return 'The question is too long (400 characters at most).';
            return '';
        },
        section() {
            if (!sectionDD.getValue() && !titleInput.value.trim()) return 'Choose a section, or type the title of a new one.';
            return '';
        },
        section_title(v) {
            const t = v.trim();
            if (t.length > 60) return 'The section title is too long (60 characters at most).';
            const picked = sectionByNo(sectionDD.getValue());
            if (picked && t && matchSectionByTitle(t, picked.no)) return 'Another section already uses that title.';
            return '';
        },
        type() { return typeDD.getValue() ? '' : 'Please choose the type of question.'; },
        no_note(v) { return v.trim().length > 200 ? 'The reminder is too long (200 characters at most).' : ''; },
        followup_label(v) {
            const t = v.trim();
            if (!t) return 'Please write the label above the list (example: Your notice period).';
            return t.length > 80 ? 'The label is too long (80 characters at most).' : '';
        },
        followup_options(v) {
            const n = lines(v).length;
            if (n < 2) return 'Add at least two choices, one per line.';
            if (n > 12) return 'The list can have 12 choices at most.';
            return '';
        },
        max(v) {
            const t = v.trim();
            if (!isWhole(t) || Number(t) < 3 || Number(t) > 10) return 'The rating scale must go from 3 up to 10.';
            return '';
        },
        label_left(v) { return v.trim().length > 40 ? 'Keep the label short (40 characters at most).' : ''; },
        label_right(v) { return v.trim().length > 40 ? 'Keep the label short (40 characters at most).' : ''; },
        date_hint(v) { return v.trim().length > 200 ? 'The hint is too long (200 characters at most).' : ''; },
        text_hint(v) { return v.trim().length > 200 ? 'The hint is too long (200 characters at most).' : ''; },
        placeholder(v) { return v.trim().length > 200 ? 'This text is too long (200 characters at most).' : ''; },
        min_chars(v) {
            const t = v.trim();
            if (t && !isWhole(t)) return 'Use a whole number (0 or more).';
            if (t && isWhole(val('max_chars')) && Number(t) > Number(val('max_chars').trim())) return 'The shortest answer cannot be longer than the longest answer.';
            return '';
        },
        max_chars(v) {
            const t = v.trim();
            if (!isWhole(t) || Number(t) < 10 || Number(t) > 2000) return 'The longest answer must be between 10 and 2000 characters.';
            return '';
        },
        pass_min(v) {
            if (!koOn() || typeDD.getValue() !== 'scale') return '';
            const t = v.trim();
            const max = val('max').trim();
            if (!isWhole(t) || Number(t) < 1) return 'Enter the lowest rating that still passes (1 or more).';
            if (isWhole(max) && Number(t) > Number(max)) return 'The lowest passing rating cannot be higher than the highest rating (' + max + ').';
            return '';
        },
        body(v) { return v.trim() ? '' : 'Please write the statement the applicant has to read.'; },
        checkbox(v) {
            const t = v.trim();
            if (!t) return 'Please write the text next to the checkbox (example: I agree).';
            return t.length > 200 ? 'The text is too long (200 characters at most).' : '';
        },
        link_text(v) {
            const t = v.trim();
            if (!t) return 'Write the clickable words of the link.';
            return t.length > 60 ? 'The clickable words are too long (60 characters at most).' : '';
        },
        link_url(v) {
            const t = v.trim();
            let ok = /^https?:\/\//i.test(t);
            if (ok) { try { new URL(t); } catch (err) { ok = false; } }
            return ok ? '' : 'Enter a web address that starts with http:// or https://.';
        }
    };

    function setError(name, message, shake) {
        const field = fieldOf(name);
        if (!field) return;
        const input = form.elements[name];
        field.classList.toggle('has-error', !!message);
        const box = field.querySelector('.field-error');
        if (box) box.textContent = message || '';
        if (input && input.setAttribute) input.setAttribute('aria-invalid', message ? 'true' : 'false');
        if (message && shake) {
            field.classList.remove('shake');
            void field.offsetWidth;
            field.classList.add('shake');
        }
    }

    function validateField(name, shake) {
        if (!rules[name]) return true;
        const message = rules[name](val(name));
        setError(name, message, shake);
        return !message;
    }

    // Typing: once a box has been used, it is re-checked on every keystroke, so the message appears the moment
    // something is wrong and goes away the moment it is fixed. Leaving a used box also checks it.
    const touched = new Set();

    Object.keys(rules).forEach(name => {
        const input = form.elements[name];
        if (!input || !input.addEventListener) return;
        input.addEventListener('input', () => {
            touched.add(name);
            hideBanner();
            validateField(name, false);
        });
        input.addEventListener('blur', () => { if (touched.has(name)) validateField(name, false); });
    });

    // the question: counter + the {link} box
    function onQuestionInput() {
        textCount.textContent = String(textInput.value.length);
        showTypeBox();
    }
    textInput.addEventListener('input', onQuestionInput);

    // the section title: hint, and the section rule (empty list + empty title) is re-checked too
    titleInput.addEventListener('input', () => {
        updateSectionBox();
        touched.add('section_title');
        if (touched.has('section')) validateField('section', false);
    });

    // changing the longest answer re-checks the shortest one
    form.elements.max_chars.addEventListener('input', () => { if (touched.has('min_chars')) validateField('min_chars', false); });

    // knock-out: the switch, the highest rating (re-checks the passing rating) and the list choices (the tick boxes follow them)
    koCheck.addEventListener('change', () => { hideBanner(); syncKnockout(); });
    form.elements.max.addEventListener('input', () => { syncKnockout(); if (touched.has('pass_min')) validateField('pass_min', false); });
    form.elements.followup_options.addEventListener('input', () => { if (!passFollowField.hidden) renderFollowChoices(); });

    function showBanner(message) { banner.textContent = message; banner.classList.add('show'); }
    function hideBanner() { banner.classList.remove('show'); }

    /* ---------- what is sent to static-admin.php (same keys the question functions there read) ---------- */
    function collect() {
        const type = typeDD.getValue();
        const picked = sectionByNo(sectionDD.getValue());
        const title = val('section_title').trim();
        const q = {
            type: type,
            text: val('text').trim(),
            status: statusDD.getValue(),
            section: picked ? picked.name : title
        };
        if (picked && title && title !== picked.name) q.section_rename = title;      // the Admin retitled the chosen section

        if (type === 'yesno') {
            q.no_note = val('no_note').trim();
            q.favorable = favorableDD.getValue();
        } else if (type === 'yesno_select') {
            q.followup_label = val('followup_label').trim();
            q.followup_when = followupWhenDD.getValue();
            q.followup_options = lines(val('followup_options')).join('\n');
        } else if (type === 'scale') {
            q.max = Number(val('max').trim());
            q.label_left = val('label_left').trim();
            q.label_right = val('label_right').trim();
        } else if (type === 'date') {
            q.hint = val('date_hint').trim();
        } else if (type === 'text') {
            q.hint = val('text_hint').trim();
            q.placeholder = val('placeholder').trim();
            q.min_chars = Number(val('min_chars').trim() || 0);
            q.max_chars = Number(val('max_chars').trim());
        } else if (type === 'consent') {
            q.body = val('body').trim();
            q.checkbox = val('checkbox').trim();
        }
        if (hasLink()) {
            q.link_text = val('link_text').trim();
            q.link_url = val('link_url').trim();
        }

        // screening rubric
        q.knockout = koOn();
        if (q.knockout) {
            if (type === 'scale') {
                q.pass_answer = Number(val('pass_min').trim());
            } else {
                q.pass_answer = passDD.getValue();
                if (type === 'yesno_select' && q.pass_answer === followupWhenDD.getValue()) {
                    q.pass_follow = lines(val('followup_options')).filter(o => acceptedFollow.has(o)).join('\n');
                }
            }
        }
        return q;
    }

    /* ---------- start values ---------- */
    function put(name, value) {
        if (form.elements[name] && value !== undefined && value !== null) form.elements[name].value = String(value);
    }

    form.elements.max.value = '5';
    form.elements.min_chars.value = '0';
    form.elements.max_chars.value = '500';
    form.elements.pass_min.value = '3';

    if (isEdit) {
        put('no_note', Q.no_note);
        favorableDD.setValue(Q.favorable === 'No' ? 'No' : 'Yes', true);
        if (Q.followup) {
            put('followup_label', Q.followup.label);
            followupWhenDD.setValue(Q.followup.when === 'No' ? 'No' : 'Yes', true);
            put('followup_options', (Q.followup.options || []).join('\n'));
        }
        if (Q.type === 'scale') {
            put('max', Q.max);
            put('label_left', (Q.labels || [])[0] || '');
            put('label_right', (Q.labels || [])[1] || '');
        }
        if (Q.type === 'date') put('date_hint', Q.hint || '');
        if (Q.type === 'text') {
            put('text_hint', Q.hint || '');
            put('placeholder', Q.placeholder || '');
            put('min_chars', Q.min_chars !== undefined ? Q.min_chars : 0);
            put('max_chars', Q.max_chars !== undefined ? Q.max_chars : 500);
        }
        if (Q.type === 'consent') {
            put('body', (Q.body || []).join('\n\n'));
            put('checkbox', Q.checkbox || '');
        }
        if (Q.link) {
            put('link_text', Q.link.text || '');
            put('link_url', Q.link.url || '');
        }
        if (Q.knockout) {
            koCheck.checked = true;
            if (Q.type === 'scale') put('pass_min', Q.pass_answer);
            else passDD.setValue(Q.pass_answer === 'No' ? 'No' : 'Yes', true);
            acceptedFollow = new Set(Array.isArray(Q.pass_follow) ? Q.pass_follow : []);
        }
        const own = sectionByNo(sectionDD.getValue());
        if (own) titleInput.value = own.name;
    }
    textCount.textContent = String(textInput.value.length);
    updateSectionBox();
    showTypeBox();
    const startSnapshot = JSON.stringify([collect(), val('section_title')]);

    /* ---------- submit ---------- */
    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        if (saving) return;
        hideBanner();

        // validate everything that is on screen, focus the first problem
        let firstBad = null;
        activeFields().forEach(name => {
            touched.add(name);
            if (!validateField(name, true) && !firstBad) firstBad = name;
        });
        if (firstBad) {
            const bad = form.querySelectorAll('.field.has-error').length;
            ADMIN.toast('Please fix the ' + bad + ' highlighted field' + (bad === 1 ? '' : 's') + ' before saving.', { error: true, duration: 4000 });
            focusField(firstBad);
            return;
        }

        setSaving(true);
        try {
            const question = collect();
            const res = isEdit
                ? await ADMIN.request('question_update', { id: Q.id, question: question })
                : await ADMIN.request('question_add', { question: question });

            const saved = res.result || {};
            let message = isEdit ? 'Question saved.' : 'Question added.';
            if (question.section_rename) message += ' Section renamed.';
            ADMIN.flash(message, { id: saved.id || Q.id || '' });      // the list shows the message and the saved row glows once
            leaving = true;
            window.location.href = 'question-list.php';
        } catch (err) {
            showServerError(err.message || 'Something went wrong. Please try again.');
            setSaving(false);
        }
    });

    function focusField(name) {
        const input = form.elements[name];
        if (input && input.focus) { input.focus({ preventScroll: false }); return; }
        const dropdown = { section: sectionDD, type: typeDD, status: statusDD, pass_answer: passDD }[name];
        if (dropdown) dropdown.btn.focus();
    }

    // The server refused something: put the message under the field it is about
    const SERVER_FIELDS = [
        [/passing rating/i, 'pass_min'],
        [/passing answer/i, 'pass_answer'],
        [/type of question/i, 'type'],
        [/write the question|question is too long/i, 'text'],
        [/another section|section (title|name)|existing section/i, 'section_title'],
        [/choose a section/i, 'section'],
        [/label above the list/i, 'followup_label'],
        [/choices/i, 'followup_options'],
        [/rating scale/i, 'max'],
        [/shortest answer/i, 'min_chars'],
        [/longest answer/i, 'max_chars'],
        [/statement/i, 'body'],
        [/checkbox/i, 'checkbox'],
        [/web address|\{link\}/i, 'link_url'],
        [/reminder/i, 'no_note'],
        [/stay active|at least one/i, 'status']
    ];

    function showServerError(message) {
        const hit = SERVER_FIELDS.find(pair => pair[0].test(message));
        if (hit && fieldOf(hit[1])) {
            setError(hit[1], message, true);
            focusField(hit[1]);
        } else {
            showBanner(message);
        }
        ADMIN.toast(message, { error: true, duration: 6000 });
        formCard.classList.remove('shake');
        void formCard.offsetWidth;
        formCard.classList.add('shake');
    }

    function setSaving(state) {
        saving = state;
        saveBtn.disabled = state;
        saveBtn.classList.toggle('saving', state);
        saveBtn.querySelector('.btn-label').textContent = state ? 'SAVING...' : (isEdit ? 'SAVE CHANGES' : 'SAVE');
        cancelBtn.disabled = state;
    }

    /* ---------- Cancel / Back (asks first when something was typed) ---------- */
    const isDirty = () => !leaving && JSON.stringify([collect(), val('section_title')]) !== startSnapshot;

    function leave() { window.location.href = 'question-list.php'; }

    function tryLeave() {
        if (isDirty()) discardModal.classList.add('active');
        else leave();
    }

    cancelBtn.addEventListener('click', tryLeave);
    backLink.addEventListener('click', (e) => {
        if (isDirty()) { e.preventDefault(); discardModal.classList.add('active'); }
    });

    document.getElementById('keepEditing').addEventListener('click', () => discardModal.classList.remove('active'));
    document.getElementById('confirmDiscard').addEventListener('click', () => {
        discardModal.classList.remove('active');
        leaving = true;
        leave();
    });
    discardModal.addEventListener('click', (e) => { if (e.target === discardModal) discardModal.classList.remove('active'); });

    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && discardModal.classList.contains('active')) discardModal.classList.remove('active');
    });
});
}

/* ===== PAGE : archiveadmin.js (runs only on: archiveadmin) ===== */
if (document.documentElement.getAttribute('data-page') === 'archiveadmin') {
/* ==========================================================================
   Admin Archive: deleted HR accounts + questionnaires, kept for 30 days.
   Search, Type filter (HR Account / Questionnaire), Time left filter, pages, Restore, Delete forever.
   Shared helpers (window.ADMIN: request, toast, confirm, icons, badges) live in admin-common.js.
   BACKEND: window.ADMIN_ARCHIVE.archive = { questions: [...], accounts: [...] }, each row with deleted + days_left
   ========================================================================== */
document.addEventListener('DOMContentLoaded', () => {
    const cfg = window.ADMIN_ARCHIVE || { days: 30, archive: {} };
    const SOON = 7, URGENT = 3, PER_PAGE = 8;
    const $ = (id) => document.getElementById(id);
    const searchInput = $('searchInput'), searchClear = $('searchClear'), tableBody = $('tableBody');
    const emptyState = $('emptyState'), emptyTitle = $('emptyTitle'), emptyText = $('emptyText');
    const clearFilters = $('clearFilters'), showingText = $('showingText'), pagination = $('pagination');

    const state = { q: '', type: 'all', left: 'all', page: 1 };
    let items = [];
    let busy = false;

    function el(tag, cls, text) {
        const n = document.createElement(tag);
        if (cls) n.className = cls;
        if (text !== undefined && text !== null) n.textContent = text;
        return n;
    }

    // One flat list: accounts + questions, the ones that will be deleted first on top
    const plain = (q) => String(q.text || '').replace('{link}', (q.link && q.link.text) || 'link');
    function load(archive) {
        const accs = (archive.accounts || []).map(a => ({ kind: 'account', id: a.id, title: a.name, sub: a.email, deleted: a.deleted, left: Number(a.days_left) }));
        const qs = (archive.questions || []).map(q => ({ kind: 'question', id: q.id, title: plain(q), sub: 'Sec ' + q.section_no + ' \u00B7 ' + q.section, deleted: q.deleted, left: Number(q.days_left) }));
        items = accs.concat(qs).sort((a, b) => a.left - b.left || String(b.deleted).localeCompare(String(a.deleted)));
    }
    load(cfg.archive);

    const leftText = (d) => (d <= 0 ? 'Deleting today' : d === 1 ? '1 day left' : d + ' days left');
    const urgency = (d) => (d <= URGENT ? 'urgent' : d <= SOON ? 'soon' : 'ok');
    const kindLabel = (k) => (k === 'account' ? 'HR Account' : 'Questionnaire');
    const short = (t, n) => (t.length > n ? t.slice(0, n - 1).trimEnd() + '\u2026' : t);

    // ---------- custom dropdown (same one as the other admin lists) ----------
    const dropdowns = [];
    function createDropdown(root, onChange) {
        const btn = root.querySelector('.dropdown-btn'), menu = root.querySelector('.dropdown-menu');
        const valueEl = root.querySelector('.dropdown-value'), btnDot = btn.querySelector('.dot');
        const options = Array.from(menu.querySelectorAll('.dropdown-option'));
        let value = 'all', focusIdx = -1;
        options.forEach(o => o.querySelector('.dot').style.setProperty('--dot', o.dataset.dot));
        const isOpen = () => root.classList.contains('open');
        const highlight = (i) => { options.forEach((o, k) => o.classList.toggle('is-focused', k === i)); focusIdx = i; };
        function setValue(v, silent) {
            const opt = options.find(o => o.dataset.value === v) || options[0];
            value = opt.dataset.value;
            options.forEach(o => o.setAttribute('aria-selected', String(o === opt)));
            valueEl.textContent = opt.querySelector('.opt-label').textContent;
            btnDot.style.setProperty('--dot', opt.dataset.dot);
            root.classList.toggle('is-active', value !== 'all');
            if (!silent) onChange(value);
        }
        function open() { dropdowns.forEach(d => d.close()); root.classList.add('open'); btn.setAttribute('aria-expanded', 'true'); highlight(Math.max(0, options.findIndex(o => o.dataset.value === value))); }
        function close() { root.classList.remove('open'); btn.setAttribute('aria-expanded', 'false'); highlight(-1); }
        function choose(i) { if (i < 0) return; setValue(options[i].dataset.value); close(); btn.focus(); }
        btn.addEventListener('click', () => (isOpen() ? close() : open()));
        btn.addEventListener('keydown', (e) => {
            if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
                e.preventDefault();
                if (!isOpen()) { open(); return; }
                highlight((focusIdx + (e.key === 'ArrowDown' ? 1 : -1) + options.length) % options.length);
            } else if ((e.key === 'Enter' || e.key === ' ') && isOpen()) { e.preventDefault(); choose(focusIdx); }
            else if (e.key === 'Escape' && isOpen()) { e.preventDefault(); close(); }
            else if (e.key === 'Tab' && isOpen()) close();
        });
        options.forEach((o, i) => {
            o.addEventListener('mousedown', (e) => e.preventDefault());
            o.addEventListener('click', () => choose(i));
            o.addEventListener('mouseenter', () => highlight(i));
        });
        const api = { root, options, setValue, close };
        dropdowns.push(api);
        return api;
    }
    document.addEventListener('click', (e) => dropdowns.forEach(d => { if (!d.root.contains(e.target)) d.close(); }));
    const typeDD = createDropdown($('typeDropdown'), (v) => { state.type = v; state.page = 1; render(); });
    const leftDD = createDropdown($('leftDropdown'), (v) => { state.left = v; state.page = 1; render(); });

    // ---------- filtering (each filter's numbers follow the other filter and the search) ----------
    const matchQ = (it) => {
        const q = state.q.trim().toLowerCase();
        return !q || (it.title + ' ' + it.sub + ' ' + kindLabel(it.kind)).toLowerCase().includes(q);
    };
    const matchType = (it, v) => v === 'all' || it.kind === v;
    const matchLeft = (it, v) => v === 'all' || (v === 'soon' ? it.left <= SOON : it.left > SOON);
    const getFiltered = () => items.filter(it => matchQ(it) && matchType(it, state.type) && matchLeft(it, state.left));

    function updateCounts() {
        typeDD.options.forEach(o => { o.querySelector('.opt-count').textContent = items.filter(it => matchQ(it) && matchLeft(it, state.left) && matchType(it, o.dataset.value)).length; });
        leftDD.options.forEach(o => { o.querySelector('.opt-count').textContent = items.filter(it => matchQ(it) && matchType(it, state.type) && matchLeft(it, o.dataset.value)).length; });
    }

    // ---------- rendering ----------
    function buildRow(it, number) {
        const tr = el('tr');
        const tdType = el('td');
        tdType.appendChild(el('span', 'type-tag is-' + it.kind, kindLabel(it.kind)));

        const tdName = el('td', 'cell-name');
        tdName.title = it.title + ' \u2014 ' + it.sub;
        tdName.append(el('strong', null, short(it.title, 90)), el('small', null, it.sub));

        const tdLeft = el('td');
        tdLeft.appendChild(el('span', 'left-pill left-' + urgency(it.left), leftText(it.left)));

        const tdAct = el('td', 'col-action'), box = el('div', 'row-actions');
        const restore = el('button', 'icon-btn is-restore');
        restore.type = 'button'; restore.title = 'Restore'; restore.setAttribute('aria-label', 'Restore ' + kindLabel(it.kind));
        restore.innerHTML = ADMIN.icons.restore;
        restore.addEventListener('click', () => restoreItem(it, restore));
        const del = el('button', 'icon-btn is-delete');
        del.type = 'button'; del.title = 'Delete forever'; del.setAttribute('aria-label', 'Delete forever');
        del.innerHTML = ADMIN.icons.trash;
        del.addEventListener('click', () => purgeItem(it, del));
        box.append(restore, del);
        tdAct.appendChild(box);

        tr.append(el('td', 'col-num', String(number)), tdType, tdName, el('td', null, ADMIN.formatDate(it.deleted, false)), tdLeft, tdAct);
        return tr;
    }

    function render() {
        updateCounts();
        const filtered = getFiltered(), total = filtered.length;
        const pages = Math.max(1, Math.ceil(total / PER_PAGE));
        state.page = Math.min(Math.max(1, state.page), pages);
        const start = (state.page - 1) * PER_PAGE, rows = filtered.slice(start, start + PER_PAGE);

        tableBody.textContent = '';
        rows.forEach((it, i) => tableBody.appendChild(buildRow(it, start + i + 1)));

        emptyState.hidden = total !== 0;
        if (total === 0) {
            const none = items.length === 0;
            emptyTitle.textContent = none ? 'The Archive is empty' : 'Nothing found';
            emptyText.textContent = none ? 'Deleted items stay here for ' + cfg.days + ' days before they are permanently deleted.' : 'Try a different search or clear the filters.';
            clearFilters.hidden = none;
        }
        showingText.textContent = total ? ADMIN.showing(total, start, rows.length) : (items.length ? 'Nothing found' : 'The Archive is empty');
        ADMIN.pager(pagination, state.page, pages, (n) => { state.page = n; render(); });
        searchClear.hidden = state.q === '';
        syncUrl();
    }

    // ---------- Restore / Delete forever ----------
    async function act(action, it, btn, okMessage) {
        busy = true; btn.disabled = true;
        try {
            const res = await ADMIN.request(action, { id: it.id });
            load(res.data.archive);
            render();
            ADMIN.toast(okMessage);
        } catch (err) {
            btn.disabled = false;
            ADMIN.toast(err.message || 'Something went wrong. Please try again.', { error: true, duration: 6000 });
        } finally { busy = false; }
    }

    // Restore: the row turns into a chip and flies back to where it belongs in the sidebar
    // (HR Accounts / Questions), the same animation as the HR Archive.
    async function restoreItem(it, btn) {
        if (busy) return;
        const isAccount = it.kind === 'account';
        const target = isAccount ? '.sidebar-nav a[href="hr-list.php"]' : '.sidebar-nav a[href="question-list.php"]';
        const tr = btn.closest('tr');
        busy = true; btn.disabled = true;
        try {
            const request = ADMIN.request(it.kind + '_restore', { id: it.id });
            const [res] = await Promise.all([request, ADMIN.flyToNav(tr ? [tr] : [], () => short(it.title, 28), target)]);
            load(res.data.archive);
            render();
            ADMIN.bumpNav(target);
            ADMIN.toast(isAccount ? 'HR account restored.' : 'Question restored.');
        } catch (err) {
            if (tr) tr.classList.remove('row-leave');
            btn.disabled = false;
            ADMIN.toast(err.message || 'Something went wrong. Please try again.', { error: true, duration: 6000 });
        } finally { busy = false; }
    }

    async function purgeItem(it, btn) {
        if (busy) return;
        const yes = await ADMIN.confirm({
            title: 'Delete permanently?',
            message: '\u201C' + short(it.title, 90) + '\u201D will be deleted forever. This cannot be undone.',
            confirmLabel: 'Delete forever',
            danger: true,
            icon: ADMIN.icons.trash
        });
        if (yes) act(it.kind + '_purge', it, btn, 'Deleted permanently.');
    }

    // ---------- URL (a refresh keeps the search and the filters) ----------
    function syncUrl() {
        const p = new URLSearchParams();
        if (state.q.trim()) p.set('q', state.q.trim());
        if (state.type !== 'all') p.set('type', state.type);
        if (state.left !== 'all') p.set('left', state.left);
        if (state.page > 1) p.set('page', state.page);
        const qs = p.toString();
        window.history.replaceState({}, document.title, window.location.pathname + (qs ? '?' + qs : ''));
    }
    (function readUrl() {
        const p = new URLSearchParams(window.location.search);
        if (['account', 'question'].includes(p.get('type'))) state.type = p.get('type');
        if (['soon', 'later'].includes(p.get('left'))) state.left = p.get('left');
        state.q = p.get('q') || '';
        state.page = parseInt(p.get('page'), 10) || 1;
        typeDD.setValue(state.type, true);
        leftDD.setValue(state.left, true);
        searchInput.value = state.q;
    })();

    // ---------- events ----------
    let timer = null;
    searchInput.addEventListener('input', () => {
        clearTimeout(timer);
        timer = setTimeout(() => { state.q = searchInput.value; state.page = 1; render(); }, 120);
        searchClear.hidden = searchInput.value === '';
    });
    searchInput.addEventListener('keydown', (e) => { if (e.key === 'Escape' && searchInput.value !== '') { e.preventDefault(); searchClear.click(); } });
    searchClear.addEventListener('click', () => { searchInput.value = ''; state.q = ''; state.page = 1; render(); searchInput.focus(); });
    clearFilters.addEventListener('click', () => {
        Object.assign(state, { q: '', type: 'all', left: 'all', page: 1 });
        searchInput.value = '';
        typeDD.setValue('all', true); leftDD.setValue('all', true);
        render();
    });

    render();
});
}

/* ===== PAGE : hrlist.js (runs only on: hrlist) ===== */
if (document.documentElement.getAttribute('data-page') === 'hrlist') {
/* ==========================================================================
   hrlist.js: the HR Accounts page (admin).
   Same behaviour as the HR Screening List (assets/js/screeninglist.js): live search, the custom
   status dropdown, rows that fit the window, page buttons, rows that slide in.
   Shared helpers (window.ADMIN: requests, toast, confirm box, flyToArchive, badges), the sidebar,
   logout and the entrance animation live in admin-common.js (load admin-common.js BEFORE this file).
   ========================================================================== */
document.addEventListener('DOMContentLoaded', () => {
    const config = window.ADMIN_HR || { accounts: [] };
    let accounts = Array.isArray(config.accounts) ? config.accounts.slice() : [];

    // New HR accounts the Admin has not looked at yet: they get a red dot on THIS visit.
    // Opening this page is what "views" them, so the sidebar badge goes away right now.
    const newIds = new Set(ADMIN.hr.ids());
    ADMIN.hr.clear();

    // After an Edit the form sends the Admin back here with a message; the saved row glows once
    const flashed = ADMIN.showFlash();
    const flashId = flashed && flashed.id ? Number(flashed.id) : 0;

    const searchInput = document.getElementById('searchInput');
    const searchClear = document.getElementById('searchClear');
    const tableScroll = document.getElementById('tableScroll');
    const tableBody = document.getElementById('tableBody');
    const emptyState = document.getElementById('emptyState');
    const emptyTitle = document.getElementById('emptyTitle');
    const emptyText = document.getElementById('emptyText');
    const clearFilters = document.getElementById('clearFilters');
    const emptyAdd = document.getElementById('emptyAdd');
    const showingText = document.getElementById('showingText');
    const pagination = document.getElementById('pagination');

    const state = { q: '', status: 'all', page: 1 };
    let perPage = 8;                  // fitPerPage() adjusts it to the window height
    let visible = [];                 // rows on the current page
    let animateRows = false;          // the first render comes with the card entrance
    let busy = false;                 // a delete / undo request is running
    let flashPending = 0;             // the row that glows once (the one just saved / restored)

    // The glow stays "armed" for 2 seconds, so a re-render right after the first one (re-fit) does not lose it
    function armFlash(id) {
        flashPending = Number(id) || 0;
        if (flashPending) setTimeout(() => { flashPending = 0; }, 2000);
    }
    armFlash(flashId);

    // ---------- helpers ----------
    // Build elements with textContent only, so names / emails can never inject HTML
    function el(tag, className, text) {
        const node = document.createElement(tag);
        if (className) node.className = className;
        if (text !== undefined && text !== null) node.textContent = text;
        return node;
    }

    const plural = (n, word) => n + ' ' + word + (n === 1 ? '' : 's');

    // ======================================================================
    // Custom dropdown (keyboard + mouse), the same one as the HR Screening List
    // ======================================================================
    const dropdowns = [];

    function createDropdown(root, onChange) {
        const btn = root.querySelector('.dropdown-btn');
        const menu = root.querySelector('.dropdown-menu');
        const valueEl = root.querySelector('.dropdown-value');
        const btnDot = btn.querySelector('.dot');
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
            root.classList.toggle('is-active', value !== 'all');
            if (!silent) onChange(value);
        }

        function open() {
            dropdowns.forEach(d => d.close());
            root.classList.add('open');
            btn.setAttribute('aria-expanded', 'true');
            highlight(Math.max(0, options.findIndex(o => o.dataset.value === value)));
        }

        function close() {
            root.classList.remove('open');
            btn.setAttribute('aria-expanded', 'false');
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
                e.preventDefault();
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

    // numbers next to each option ("Active 1")
    function updateCounts() {
        statusDD.options.forEach(o => {
            const v = o.dataset.value;
            o.querySelector('.opt-count').textContent =
                v === 'all' ? accounts.length : accounts.filter(a => a.status === v).length;
        });
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

    // Window changed size: re-fit, keeping the first visible account in view
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
        return accounts.filter(a => {
            if (state.status !== 'all' && a.status !== state.status) return false;
            if (q && !(a.name + ' ' + a.email + ' ' + a.role).toLowerCase().includes(q)) return false;
            return true;
        });
    }

    const filtersActive = () => state.status !== 'all' || state.q.trim() !== '';

    // ---------- rendering ----------
    function render() {
        const filtered = getFiltered();
        const total = filtered.length;
        const pages = Math.max(1, Math.ceil(total / perPage));
        state.page = Math.min(Math.max(1, state.page), pages);

        const start = (state.page - 1) * perPage;
        visible = filtered.slice(start, start + perPage);

        tableBody.textContent = '';
        visible.forEach((a, index) => tableBody.appendChild(buildRow(a, index, start + index + 1)));
        tableScroll.scrollTop = 0;

        // empty state: no accounts at all, or nothing matches the search / filter
        emptyState.hidden = total !== 0;
        if (total === 0) {
            const none = accounts.length === 0;
            emptyTitle.textContent = none ? 'No HR accounts yet' : 'No HR accounts found';
            emptyText.textContent = none ? 'Create the first account for your HR screening staff.' : 'Try a different search or clear the filter.';
            clearFilters.hidden = none;
            emptyAdd.hidden = !none;
        }

        renderShowing(total, start);
        renderPagination(pages, total);
        searchClear.hidden = state.q === '';
        syncUrl();
    }

    function buildRow(a, index, number) {
        const tr = document.createElement('tr');
        tr.dataset.id = a.id;
        if (animateRows) {
            tr.classList.add('row-in');
            tr.style.setProperty('--r', index);
        }

        const tdNum = el('td', 'col-num', String(number));

        // name (a red dot + NEW tag while the Admin has not looked at a new account yet)
        const tdName = el('td', 'cell-name');
        tdName.title = a.name;
        if (newIds.has(Number(a.id))) {
            tr.classList.add('is-new');
            const dot = el('span', 'new-dot');
            dot.title = 'New HR account';
            tdName.appendChild(dot);
        }
        tdName.appendChild(el('strong', null, a.name));
        if (newIds.has(Number(a.id))) tdName.appendChild(el('span', 'new-tag', 'NEW'));

        const tdEmail = el('td', null, a.email);
        tdEmail.title = a.email;

        const tdRole = el('td', null, a.role || 'HR Staff');

        const tdStatus = el('td');
        tdStatus.appendChild(el('span', 'status-pill ' + (a.status === 'Active' ? 'is-active' : 'is-inactive'), a.status));

        // action: edit (opens the same form as Add HR) + delete (moves to the Archive)
        const tdAction = el('td', 'col-action');
        const box = el('div', 'row-actions');

        const edit = el('a', 'icon-btn is-edit');
        edit.href = 'add-hr.php?id=' + encodeURIComponent(a.id);
        edit.title = 'Edit ' + a.name;
        edit.setAttribute('aria-label', 'Edit ' + a.name);
        edit.innerHTML = ADMIN.icons.edit;

        const del = el('button', 'icon-btn is-delete');
        del.type = 'button';
        del.title = 'Delete ' + a.name;
        del.setAttribute('aria-label', 'Delete ' + a.name);
        del.innerHTML = ADMIN.icons.trash;
        del.addEventListener('click', () => deleteAccount(a, tr, del));

        box.append(edit, del);
        tdAction.appendChild(box);

        tr.append(tdNum, tdName, tdEmail, tdRole, tdStatus, tdAction);

        if (flashPending && Number(a.id) === flashPending) tr.classList.add('row-flash');
        return tr;
    }

    function renderShowing(total, start) {
        if (total === 0) {
            showingText.textContent = accounts.length === 0 ? 'No HR accounts yet' : 'No HR accounts found';
            return;
        }
        showingText.textContent = 'Showing ' + (start + 1) + '\u2013' + (start + visible.length) + ' of ' + total;
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
    // Delete (moves to the Archive, like the HR Screening List) + Undo
    // ======================================================================
    async function deleteAccount(a, tr, btn) {
        if (busy) return;
        const yes = await ADMIN.confirm({
            title: 'Delete this HR account?',
            message: a.name + ' will be moved to the Archive and can no longer log in. You can restore the account within 30 days.',
            confirmLabel: 'Delete',
            icon: ADMIN.icons.trash
        });
        if (!yes) return;

        busy = true;
        btn.disabled = true;
        try {
            const res = await ADMIN.request('account_archive', { id: a.id });
            await ADMIN.flyToArchive([tr], () => a.name);       // the row flies to the Archive item in the sidebar
            accounts = res.data.accounts;
            newIds.delete(Number(a.id));
            ADMIN.bumpArchive();
            updateCounts();
            render();
            ADMIN.toast(a.name + ' was moved to the Archive.', {
                actionLabel: 'Undo',
                duration: 6000,
                onAction: () => undo(a.id)
            });
        } catch (err) {
            btn.disabled = false;
            ADMIN.toast(err.message || 'Something went wrong. Please try again.', { error: true, duration: 6000 });
        } finally {
            busy = false;
        }
    }

    async function undo(id) {
        if (busy) return;
        busy = true;
        try {
            const res = await ADMIN.request('account_restore', { id: id });
            accounts = res.data.accounts;
            armFlash(id);
            animateRows = true;
            updateCounts();
            render();
            ADMIN.toast('HR account restored.', { duration: 4000 });
        } catch (err) {
            ADMIN.toast(err.message || 'Could not restore the account.', { error: true, duration: 6000 });
        } finally {
            busy = false;
        }
    }

    // ---------- URL (so a refresh keeps the search and the filter) ----------
    function syncUrl() {
        const p = new URLSearchParams();
        if (state.q.trim()) p.set('q', state.q.trim());
        if (state.status !== 'all') p.set('status', state.status);
        if (state.page > 1) p.set('page', state.page);
        const qs = p.toString();
        window.history.replaceState({}, document.title, window.location.pathname + (qs ? '?' + qs : ''));
    }

    function readUrl() {
        const p = new URLSearchParams(window.location.search);
        const status = String(p.get('status') || '').trim().toLowerCase();
        const map = { active: 'Active', inactive: 'Inactive' };
        if (map[status]) state.status = map[status];
        state.q = p.get('q') || '';
        state.page = parseInt(p.get('page'), 10) || 1;
        statusDD.setValue(state.status, true);
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

    clearFilters.addEventListener('click', () => {
        state.q = '';
        state.page = 1;
        state.status = 'all';
        searchInput.value = '';
        statusDD.setValue('all', true);
        render();
    });

    let resizeTimer = null;
    window.addEventListener('resize', () => {
        clearTimeout(resizeTimer);
        resizeTimer = setTimeout(refit, 150);
    });

    // ---------- start ----------
    updateCounts();
    perPage = fitPerPage();
    readUrl();
    render();
    refit();                // now that a real row exists, measure again for an exact fit
    animateRows = true;     // later renders (search, filter, pages) animate the rows in
});
}

/* ===== PAGE : questionlist.js (runs only on: questionlist) ===== */
if (document.documentElement.getAttribute('data-page') === 'questionlist') {
/* ==========================================================================
   Questionnaires (admin): search, section + status filters, rows that fit the window, page buttons, delete + undo.
   Same behaviour as the HR Accounts page (assets/js/hrlist.js). Shared helpers (window.ADMIN: requests, toast,
   confirm box, flyToArchive, badges), the sidebar and logout live in admin-common.js (loaded BEFORE this script).
   This page's markup + data come from admin/question-list.php (window.ADMIN_Q).
   BACKEND: window.ADMIN_Q.questions = SELECT id, section_no, section, text, status, created_at FROM questions
            WHERE archived_at IS NULL ORDER BY section_no, position
   ========================================================================== */
document.addEventListener('DOMContentLoaded', () => {
    const config = window.ADMIN_Q || { questions: [] };
    let questions = Array.isArray(config.questions) ? config.questions.slice() : [];

    // After Create / Edit the form sends the Admin back here with a message; the saved row glows once
    const flashed = ADMIN.showFlash();
    const flashId = flashed && flashed.id ? String(flashed.id) : '';

    const searchInput = document.getElementById('searchInput');
    const searchClear = document.getElementById('searchClear');
    const tableScroll = document.getElementById('tableScroll');
    const tableBody = document.getElementById('tableBody');
    const emptyState = document.getElementById('emptyState');
    const emptyTitle = document.getElementById('emptyTitle');
    const emptyText = document.getElementById('emptyText');
    const clearFilters = document.getElementById('clearFilters');
    const emptyAdd = document.getElementById('emptyAdd');
    const showingText = document.getElementById('showingText');
    const pagination = document.getElementById('pagination');

    const state = { q: '', status: 'all', section: 'all', page: 1 };
    let perPage = 8;                  // fitPerPage() adjusts it to the window height
    let visible = [];                 // rows on the current page
    let animateRows = false;          // the first render comes with the card entrance
    let busy = false;                 // a delete / undo request is running
    let flashPending = '';            // the row that glows once (the one just saved / restored)

    function armFlash(id) {
        flashPending = String(id || '');
        if (flashPending) setTimeout(() => { flashPending = ''; }, 2000);
    }
    armFlash(flashId);

    // ---------- helpers ----------
    // Build elements with textContent only, so questions can never inject HTML
    function el(tag, className, text) {
        const node = document.createElement(tag);
        if (className) node.className = className;
        if (text !== undefined && text !== null) node.textContent = text;
        return node;
    }

    // The question as the applicant reads it ({link} replaced by its clickable words)
    const plain = (q) => String(q.text || '').replace('{link}', (q.link && q.link.text) || 'link');
    const short = (text, n) => (text.length > n ? text.slice(0, n - 1).trimEnd() + '\u2026' : text);

    // One colour per section (the same ones as the Questionnaires Statistics on the Dashboard)
    const palette = ['#8f8aea', '#74cfb9', '#f8a09a', '#f6c453', '#7ab8f0', '#c58ae0'];
    const colorOf = (no) => palette[(Number(no) - 1 + palette.length) % palette.length];

    // ======================================================================
    // Custom dropdown (keyboard + mouse), the same one as the HR Accounts list
    // ======================================================================
    const dropdowns = [];

    function createDropdown(root, onChange) {
        const btn = root.querySelector('.dropdown-btn');
        const menu = root.querySelector('.dropdown-menu');
        const valueEl = root.querySelector('.dropdown-value');
        const btnDot = btn.querySelector('.dot');
        let options = [];
        let value = 'all';
        let focusIdx = -1;

        // (re)read the options from the menu, e.g. after the Section list was rebuilt
        function refresh() {
            options = Array.from(menu.querySelectorAll('.dropdown-option'));
            options.forEach((o, i) => {
                o.style.setProperty('--k', i);
                o.querySelector('.dot').style.setProperty('--dot', o.dataset.dot);
                o.addEventListener('mousedown', (e) => e.preventDefault());   // keep focus on the button
                o.addEventListener('click', () => choose(i));
                o.addEventListener('mouseenter', () => highlight(i));
            });
            if (!options.some(o => o.dataset.value === value)) value = 'all';
            setValue(value, true);
        }

        const isOpen = () => root.classList.contains('open');

        function highlight(i) {
            options.forEach((o, k) => o.classList.toggle('is-focused', k === i));
            focusIdx = i;
        }

        function setValue(v, silent) {
            const opt = options.find(o => o.dataset.value === v) || options[0];
            if (!opt) return;                       // the Section list is still empty (it is filled right after)
            value = opt.dataset.value;
            options.forEach(o => o.setAttribute('aria-selected', String(o === opt)));
            valueEl.textContent = opt.querySelector('.opt-label').textContent;
            btnDot.style.setProperty('--dot', opt.dataset.dot);
            root.classList.toggle('is-active', value !== 'all');
            if (!silent) onChange(value);
        }

        function open() {
            dropdowns.forEach(d => d.close());
            root.classList.add('open');
            btn.setAttribute('aria-expanded', 'true');
            highlight(Math.max(0, options.findIndex(o => o.dataset.value === value)));
        }

        function close() {
            root.classList.remove('open');
            btn.setAttribute('aria-expanded', 'false');
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
                e.preventDefault();
                close();
            } else if (e.key === 'Tab' && isOpen()) {
                close();
            }
        });

        refresh();

        const api = { root, get options() { return options; }, setValue, refresh, close, getValue: () => value };
        dropdowns.push(api);
        return api;
    }

    document.addEventListener('click', (e) => {
        dropdowns.forEach(d => { if (!d.root.contains(e.target)) d.close(); });
    });

    const statusDD = createDropdown(document.getElementById('statusDropdown'), (v) => {
        state.status = v; state.page = 1; render();
    });
    const sectionDD = createDropdown(document.getElementById('sectionDropdown'), (v) => {
        state.section = v; state.page = 1; render();
    });

    const CHECK = '<svg class="opt-check" viewBox="0 0 24 24" aria-hidden="true"><path d="M9 16.2 4.8 12l-1.4 1.4L9 19 21 7l-1.4-1.4z"/></svg>';

    // The Section list is built from the questions, so a section the Admin adds (or empties) shows up by itself
    function buildSectionOptions() {
        const menu = document.querySelector('#sectionDropdown .dropdown-menu');
        const seen = new Map();
        questions.forEach(q => { if (!seen.has(String(q.section_no))) seen.set(String(q.section_no), q.section); });
        const list = [['all', 'All Sections', '#c5c9ce']];
        [...seen.entries()].sort((a, b) => Number(a[0]) - Number(b[0]))
            .forEach(([no, name]) => list.push([no, 'Sec ' + no + ' \u00B7 ' + name, colorOf(no)]));

        menu.textContent = '';
        list.forEach(([value, label, dot]) => {
            const li = el('li', 'dropdown-option');
            li.setAttribute('role', 'option');
            li.dataset.value = value;
            li.dataset.dot = dot;
            li.setAttribute('aria-selected', 'false');
            li.title = label;
            li.append(el('span', 'dot'), el('span', 'opt-label', label), el('span', 'opt-count', '0'));
            li.insertAdjacentHTML('beforeend', CHECK);
            menu.appendChild(li);
        });
        sectionDD.refresh();
        if (state.section !== 'all' && !seen.has(state.section)) state.section = 'all';   // that section has no questions any more
        sectionDD.setValue(state.section, true);
    }

    // numbers next to each option ("Active 3"); each filter counts what the OTHER filter leaves
    function updateCounts() {
        statusDD.options.forEach(o => {
            const v = o.dataset.value;
            o.querySelector('.opt-count').textContent = questions.filter(q =>
                (state.section === 'all' || String(q.section_no) === state.section) && (v === 'all' || q.status === v)).length;
        });
        sectionDD.options.forEach(o => {
            const v = o.dataset.value;
            o.querySelector('.opt-count').textContent = questions.filter(q =>
                (state.status === 'all' || q.status === state.status) && (v === 'all' || String(q.section_no) === v)).length;
        });
    }

    // ---------- rows per page: as many as fit, so the card has no empty band ----------
    const MIN_ROWS = 4;
    const MAX_ROWS = 15;

    function fitPerPage() {
        tableScroll.style.removeProperty('--row-h');
        const firstRow = tableBody.querySelector('tr');
        const rowHeight = firstRow ? Math.ceil(firstRow.getBoundingClientRect().height) : 53;
        const head = tableScroll.querySelector('thead');
        const room = tableScroll.clientHeight - (head ? head.offsetHeight : 46);
        if (!rowHeight || room <= 0) return perPage;
        const rows = Math.min(MAX_ROWS, Math.max(MIN_ROWS, Math.floor((room - 4) / rowHeight)));
        tableScroll.style.setProperty('--row-h', Math.max(rowHeight, Math.floor((room - 2) / rows)) + 'px');
        return rows;
    }

    // Window changed size: re-fit, keeping the first visible question in view
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
        return questions.filter(item => {
            if (state.status !== 'all' && item.status !== state.status) return false;
            if (state.section !== 'all' && String(item.section_no) !== state.section) return false;
            if (q && !(plain(item) + ' ' + item.section + ' sec ' + item.section_no + ' ' + item.id).toLowerCase().includes(q)) return false;
            return true;
        });
    }

    // ---------- rendering ----------
    function render() {
        updateCounts();
        const filtered = getFiltered();
        const total = filtered.length;
        const pages = Math.max(1, Math.ceil(total / perPage));
        state.page = Math.min(Math.max(1, state.page), pages);

        const start = (state.page - 1) * perPage;
        visible = filtered.slice(start, start + perPage);

        tableBody.textContent = '';
        visible.forEach((q, index) => tableBody.appendChild(buildRow(q, index, start + index + 1)));
        tableScroll.scrollTop = 0;

        emptyState.hidden = total !== 0;
        if (total === 0) {
            const none = questions.length === 0;
            emptyTitle.textContent = none ? 'No questionnaires yet' : 'No questionnaires found';
            emptyText.textContent = none ? 'Create the first question the applicants will answer.' : 'Try a different search or clear the filter.';
            clearFilters.hidden = none;
            emptyAdd.hidden = !none;
        }

        renderShowing(total, start);
        renderPagination(pages, total);
        searchClear.hidden = state.q === '';
        syncUrl();
    }

    // the passing answer of a knock-out question, written out (for the tag's tooltip)
    function koPassText(q) {
        if (q.type === 'scale') return 'at least ' + q.pass_answer + ' of ' + (q.max || 5);
        let t = String(q.pass_answer || 'Yes');
        if (q.type === 'yesno_select' && Array.isArray(q.pass_follow) && q.pass_follow.length) t += ' (' + q.pass_follow.join(' or ') + ')';
        return t;
    }

    function buildRow(q, index, number) {
        const tr = document.createElement('tr');
        tr.dataset.id = q.id;
        if (animateRows) {
            tr.classList.add('row-in');
            tr.style.setProperty('--r', index);
        }

        const tdNum = el('td', 'col-num', String(number));

        const tdSec = el('td', 'cell-sec');
        tdSec.title = 'Section ' + q.section_no + ' \u00B7 ' + q.section;
        const dot = el('span', 'sec-dot');
        dot.style.setProperty('--c', colorOf(q.section_no));
        tdSec.append(dot, document.createTextNode('Sec ' + q.section_no));

        const tdTitle = el('td', 'cell-name');
        tdTitle.title = plain(q);
        tdTitle.appendChild(el('strong', null, plain(q)));
        if (q.knockout) {
            const ko = el('span', 'ko-tag', 'Knock-out');
            ko.title = 'Knock-out question. Passing answer: ' + koPassText(q);
            tdTitle.appendChild(ko);
        }

        const tdDate = el('td', null, ADMIN.formatDate(q.created, false));

        const tdStatus = el('td');
        tdStatus.appendChild(el('span', 'status-pill ' + (q.status === 'Active' ? 'is-active' : 'is-inactive'), q.status));

        // action: edit (opens the same form as Create Questions) + delete (moves to the Archive)
        const tdAction = el('td', 'col-action');
        const box = el('div', 'row-actions');

        const edit = el('a', 'icon-btn is-edit');
        edit.href = 'add-question.php?id=' + encodeURIComponent(q.id);
        edit.title = 'Edit question ' + number;
        edit.setAttribute('aria-label', 'Edit question ' + number);
        edit.innerHTML = ADMIN.icons.edit;

        const del = el('button', 'icon-btn is-delete');
        del.type = 'button';
        del.title = 'Delete question ' + number;
        del.setAttribute('aria-label', 'Delete question ' + number);
        del.innerHTML = ADMIN.icons.trash;
        del.addEventListener('click', () => deleteQuestion(q, tr, del));

        box.append(edit, del);
        tdAction.appendChild(box);

        tr.append(tdNum, tdSec, tdTitle, tdDate, tdStatus, tdAction);

        if (flashPending && q.id === flashPending) tr.classList.add('row-flash');
        return tr;
    }

    function renderShowing(total, start) {
        if (total === 0) {
            showingText.textContent = questions.length === 0 ? 'No questionnaires yet' : 'No questionnaires found';
            return;
        }
        showingText.textContent = 'Showing ' + (start + 1) + '\u2013' + (start + visible.length) + ' of ' + total;
    }

    function pageList(current, pages) {
        if (pages <= 7) return Array.from({ length: pages }, (_, i) => i + 1);
        const keep = new Set([1, pages, current - 1, current, current + 1]);
        const nums = [...keep].filter(n => n >= 1 && n <= pages).sort((a, b) => a - b);
        const out = [];
        nums.forEach((n, i) => {
            if (i > 0) {
                const gap = n - nums[i - 1];
                if (gap === 2) out.push(n - 1);
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
    // Delete (moves to the Archive, like the HR Accounts list) + Undo
    // ======================================================================
    async function deleteQuestion(q, tr, btn) {
        if (busy) return;
        const yes = await ADMIN.confirm({
            title: 'Delete this question?',
            message: '\u201C' + short(plain(q), 90) + '\u201D will be moved to the Archive and the applicants will no longer see it. You can restore it within 30 days.',
            confirmLabel: 'Delete',
            icon: ADMIN.icons.trash
        });
        if (!yes) return;

        busy = true;
        btn.disabled = true;
        try {
            const res = await ADMIN.request('question_archive', { id: q.id });
            await ADMIN.flyToArchive([tr], () => short(plain(q), 28));       // the row flies to the Archive item in the sidebar
            questions = res.data.questions;
            ADMIN.bumpArchive();
            buildSectionOptions();
            render();
            ADMIN.toast('The question was moved to the Archive.', {
                actionLabel: 'Undo',
                duration: 6000,
                onAction: () => undo(q.id)
            });
        } catch (err) {
            btn.disabled = false;
            ADMIN.toast(err.message || 'Something went wrong. Please try again.', { error: true, duration: 6000 });
        } finally {
            busy = false;
        }
    }

    async function undo(id) {
        if (busy) return;
        busy = true;
        try {
            const res = await ADMIN.request('question_restore', { id: id });
            questions = res.data.questions;
            armFlash(id);
            animateRows = true;
            buildSectionOptions();
            render();
            ADMIN.toast('Question restored.', { duration: 4000 });
        } catch (err) {
            ADMIN.toast(err.message || 'Could not restore the question.', { error: true, duration: 6000 });
        } finally {
            busy = false;
        }
    }

    // ---------- URL (so a refresh keeps the search and the filter) ----------
    function syncUrl() {
        const p = new URLSearchParams();
        if (state.q.trim()) p.set('q', state.q.trim());
        if (state.status !== 'all') p.set('status', state.status);
        if (state.section !== 'all') p.set('section', state.section);
        if (state.page > 1) p.set('page', state.page);
        const qs = p.toString();
        window.history.replaceState({}, document.title, window.location.pathname + (qs ? '?' + qs : ''));
    }

    function readUrl() {
        const p = new URLSearchParams(window.location.search);
        const status = String(p.get('status') || '').trim().toLowerCase();
        const map = { active: 'Active', inactive: 'Inactive' };
        if (map[status]) state.status = map[status];
        const section = String(p.get('section') || '').trim();
        if (/^\d+$/.test(section) && questions.some(q => String(q.section_no) === section)) state.section = section;
        state.q = p.get('q') || '';
        state.page = parseInt(p.get('page'), 10) || 1;
        statusDD.setValue(state.status, true);
        sectionDD.setValue(state.section, true);
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

    clearFilters.addEventListener('click', () => {
        state.q = '';
        state.page = 1;
        state.status = 'all';
        state.section = 'all';
        searchInput.value = '';
        statusDD.setValue('all', true);
        sectionDD.setValue('all', true);
        render();
    });

    let resizeTimer = null;
    window.addEventListener('resize', () => {
        clearTimeout(resizeTimer);
        resizeTimer = setTimeout(refit, 150);
    });

    // ---------- start ----------
    buildSectionOptions();
    perPage = fitPerPage();
    readUrl();
    // coming back from the form: open the page where the saved question is
    if (flashId) {
        const at = getFiltered().findIndex(q => q.id === flashId);
        if (at >= 0) state.page = Math.floor(at / perPage) + 1;
    }
    render();
    refit();                // now that a real row exists, measure again for an exact fit
    animateRows = true;     // later renders (search, filter, pages) animate the rows in
});
}

/* ===== PAGE : settingsadmin.js (runs only on: settingsadmin) ===== */
if (document.documentElement.getAttribute('data-page') === 'settingsadmin') {
/* ==========================================================================
   Settings page behaviour.
   Entrance, sidebar, logout and the sidebar badges live in admin-common.js
   (load admin-common.js BEFORE this file). The saved values are read and written
   through ADMIN.settings (see "Settings" in admin-common.js), which is also what the
   other admin pages read, so a saved change shows up across the whole portal.

   Preview rule: the theme and the animations switch show their effect at once so the Admin can see
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
        notifyActivity: $('optActivity'),
        notifyArchive: $('optArchive'),
        animations: $('optAnimations')
    };
    const themeRadios = Array.from(form.querySelectorAll('input[name="theme"]'));

    const fieldOf = (name) => form.querySelector('.set-field[data-field="' + name + '"]');
    let saving = false;

    /* ---------- the saved state ---------- */
    // Name + email: what the Admin saved before, otherwise what the login session gave
    // (window.ADMIN_ACCOUNT is printed by settings-admin.php).
    const account = window.ADMIN_ACCOUNT || { name: '', email: '' };
    function readSaved() {
        const profile = ADMIN.settings.profile();
        const s = ADMIN.settings.get();
        return {
            name: String(profile.name || account.name || '').trim(),
            email: String(profile.email || account.email || '').trim(),
            theme: ['light', 'dark', 'system'].includes(s.theme) ? s.theme : 'light',
            notifications: s.notifications !== false,
            notifyActivity: s.notifyActivity !== false,
            notifyArchive: s.notifyArchive !== false,
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
            notifyActivity: toggles.notifyActivity.checked,
            notifyArchive: toggles.notifyArchive.checked,
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
        toggles.notifyActivity.disabled = !on;
        toggles.notifyArchive.disabled = !on;
    }

    // Shows the chosen look right now (not saved yet)
    function preview() {
        const s = collect();
        ADMIN.settings.apply(Object.assign(ADMIN.settings.get(), { theme: s.theme, animations: s.animations }), true);
    }

    /* ---------- validation (same wording as the HR Settings) ---------- */
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
    toggles.notifyActivity.addEventListener('change', refreshButtons);
    toggles.notifyArchive.addEventListener('change', refreshButtons);
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
           const res = await fetch('settings-admin.php', { method: 'POST', body: JSON.stringify(state), ... });
           const data = await res.json();                 // { status: 'success' }
           if (data.status !== 'success') throw new Error(data.message);
       Until then the values are kept in this browser (ADMIN.settings / ADMIN.settings.saveProfile). */
    async function persist(state) {
        await new Promise(resolve => setTimeout(resolve, 650));
        const okSettings = ADMIN.settings.save({
            theme: state.theme,
            animations: state.animations,
            notifications: state.notifications,
            notifyActivity: state.notifyActivity,
            notifyArchive: state.notifyArchive
        });
        const okProfile = ADMIN.settings.saveProfile({ name: state.name, email: state.email });
        if (!okSettings || !okProfile) throw new Error('Could not save. Please check your browser storage settings.');
    }

    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        if (saving || !isDirty()) return;

        let firstBad = null;
        ['name', 'email'].forEach(n => { if (!validate(n, true) && !firstBad) firstBad = n; });
        if (firstBad) {
            ADMIN.toast('Fix the highlighted field before saving.', { error: true, duration: 4000 });
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
            ADMIN.toast('Settings saved.', { duration: 3500 });
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
            ADMIN.toast(err.message || 'Something went wrong. Please try again.', { error: true, duration: 6000 });
        }
    });

    /* ---------- CANCEL: back to what is saved ---------- */
    cancelBtn.addEventListener('click', () => {
        if (saving || !isDirty()) return;
        fill(saved);
        ['name', 'email'].forEach(n => setError(fieldOf(n), ''));
        touched.clear();
        ADMIN.settings.apply(ADMIN.settings.get(), true);      // the saved look
        refreshButtons();
        ADMIN.toast('Changes discarded.', { duration: 2500 });
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
           const res = await fetch('settings-admin.php', { method: 'POST', body: JSON.stringify({ action: 'password', current, next }) });
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
        ADMIN.toast('Password updated.', { duration: 3500 });
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
        if (e.key !== 'admin_settings' && e.key !== 'admin_profile') return;
        if (isDirty() || saving) return;               // never overwrite what is being typed
        saved = readSaved();
        fill(saved);
        refreshButtons();
    });
});
}

/* ===== PAGE : stats-pdf.js (runs only on: dashboardadmin) ===== */
if (document.documentElement.getAttribute('data-page') === 'dashboardadmin') {
/* ==========================================================================
   Questionnaires Statistics -> PDF (Admin Dashboard "Download PDF" button).
   No library and no internet needed: this file writes the PDF itself.

   StatsPdf.build(stats, { generated: Date, by: 'Admin' })  ->  Blob (application/pdf)
   StatsPdf.download(blob, 'file-name.pdf')

   `stats` is the same object the Dashboard chart uses (ADMIN_DASH.stats):
       { submissions, sections: [{ no, name, count }],
         questions: [{ id, section_no, section, text, type, status, answered, top, top_count, share, options, note }] }

   The report: a title band, three summary boxes (questions / sections / submissions), the most answered
   question, a table of the sections, then every section with all of its questions (a bar for the most chosen
   answer and the share of every answer). A footer with the page number is on every page.
   ========================================================================== */
(function () {
    'use strict';

    const PAGE_W = 595.28, PAGE_H = 841.89;       // A4 portrait, in points
    const M = 40, CW = PAGE_W - M * 2;            // side margin, content width
    const BOTTOM = 52;                            // space kept free at the bottom (footer)
    const INDENT = 46;                            // question text starts after the Q badge

    const RED = [163, 8, 30], INK = [31, 31, 35], SOFT = [91, 95, 102], FAINT = [139, 144, 152];
    const TRACK = [232, 234, 237], PANEL = [243, 244, 246], WHITE = [255, 255, 255];

    // one colour per section, the same as the chart on the Dashboard
    const PALETTE = ['#8f8aea', '#74cfb9', '#f8a09a', '#f6c453', '#7ab8f0', '#c58ae0'];
    const rgb = (hex) => [1, 3, 5].map(i => parseInt(hex.substr(i, 2), 16));
    const colorOf = (no) => rgb(PALETTE[((Number(no) - 1) % PALETTE.length + PALETTE.length) % PALETTE.length]);
    const mix = (c, k) => c.map(v => Math.round(v + (255 - v) * k));           // lighter version of a colour

    const TYPES = {
        yesno: 'Yes / No', yesno_select: 'Yes / No + a list', scale: 'Rating scale',
        date: 'Date', text: 'Written answer', consent: 'Statement + checkbox'
    };

    /* ---------- text helpers ---------- */
    // The PDF uses the standard Helvetica fonts (WinAnsi), so every character is turned into one byte
    const WIN = { 0x2013: 0x96, 0x2014: 0x97, 0x2018: 0x91, 0x2019: 0x92, 0x201C: 0x93, 0x201D: 0x94, 0x2022: 0x95, 0x2026: 0x85, 0x20AC: 0x80 };
    function latin1(str) {
        let out = '';
        for (const ch of String(str == null ? '' : str)) {
            let c = ch.codePointAt(0);
            if (WIN[c]) c = WIN[c];
            else if (c < 32) c = 32;
            else if (c > 255 || (c >= 0x7F && c <= 0x9F)) c = 63;               // "?"
            out += String.fromCharCode(c);
        }
        return out;
    }
    const esc = (s) => latin1(s).replace(/([\\()])/g, '\\$1');

    let ctx = null;
    try { ctx = document.createElement('canvas').getContext('2d'); } catch (e) { ctx = null; }
    // width of a text in points (Arial and Helvetica have the same letter widths)
    function tw(str, size, bold) {
        str = String(str);
        if (ctx) {
            ctx.font = (bold ? 'bold ' : '') + '100px Helvetica, Arial, sans-serif';
            return ctx.measureText(str).width * size / 100 * 1.01;
        }
        return str.length * size * (bold ? 0.57 : 0.53);
    }

    function fit(str, size, bold, maxW) {                                       // cut with "..." to fit one line
        str = String(str);
        if (tw(str, size, bold) <= maxW) return str;
        while (str.length > 1 && tw(str + '...', size, bold) > maxW) str = str.slice(0, -1);
        return str.trim() + '...';
    }

    function wrap(str, size, bold, maxW, maxLines) {
        const words = String(str).replace(/\s+/g, ' ').trim().split(' ');
        const lines = [];
        let line = '';
        for (let w of words) {
            while (tw(w, size, bold) > maxW) {                                  // one very long word: break it
                let k = w.length;
                while (k > 1 && tw(w.slice(0, k), size, bold) > maxW) k--;
                if (line) { lines.push(line); line = ''; }
                lines.push(w.slice(0, k));
                w = w.slice(k);
            }
            const test = line ? line + ' ' + w : w;
            if (tw(test, size, bold) <= maxW) line = test;
            else { lines.push(line); line = w; }
        }
        if (line) lines.push(line);
        if (maxLines && lines.length > maxLines) {
            lines.length = maxLines;
            lines[maxLines - 1] = fit(lines[maxLines - 1] + ' ...', size, bold, maxW);
        }
        return lines.length ? lines : [''];
    }

    const pct = (v) => (Math.round(Number(v) * 10) / 10) + '%';
    const num = (n) => n.toFixed(2).replace(/\.?0+$/, '');
    const col = (c) => c.map(v => num(v / 255)).join(' ');

    /* ---------- a tiny page writer ---------- */
    function Doc(title) {
        this.title = title;
        this.pages = [];
        this.y = M;
        this.newPage();
    }

    Doc.prototype.newPage = function () {
        this.cur = [];
        this.pages.push(this.cur);
        this.y = M;
        if (this.pages.length > 1) {                                           // small running header on the next pages
            this.text(this.title, M, 24, 8, { color: FAINT });
            this.line(M, 38, PAGE_W - M, 38, TRACK, 0.8);
            this.y = 52;
        }
    };

    // keeps a block together: starts a new page when it does not fit
    Doc.prototype.ensure = function (h) {
        if (this.y + h > PAGE_H - BOTTOM) this.newPage();
    };

    Doc.prototype.rect = function (x, top, w, h, c) {
        this.cur.push(col(c) + ' rg ' + num(x) + ' ' + num(PAGE_H - top - h) + ' ' + num(w) + ' ' + num(h) + ' re f');
    };

    Doc.prototype.line = function (x1, y1, x2, y2, c, width) {
        this.cur.push(col(c) + ' RG ' + num(width || 0.8) + ' w ' + num(x1) + ' ' + num(PAGE_H - y1) + ' m ' + num(x2) + ' ' + num(PAGE_H - y2) + ' l S');
    };

    // x is the left edge (align 'right': the right edge, 'center': the middle); top is the top of the text line
    Doc.prototype.text = function (str, x, top, size, o) {
        o = o || {};
        str = String(str);
        const w = tw(str, size, o.bold);
        if (o.align === 'right') x -= w;
        else if (o.align === 'center') x -= w / 2;
        this.cur.push('BT /' + (o.bold ? 'F2' : 'F1') + ' ' + num(size) + ' Tf ' + col(o.color || INK) + ' rg ' +
            num(x) + ' ' + num(PAGE_H - top - size * 0.82) + ' Td (' + esc(str) + ') Tj ET');
    };

    Doc.prototype.finish = function () {
        const total = this.pages.length;
        this.pages.forEach((p, i) => {
            this.cur = p;
            this.line(M, PAGE_H - 38, PAGE_W - M, PAGE_H - 38, TRACK, 0.8);
            this.text('Expertise  \u2022  Admin  \u2022  Questionnaires Statistics', M, PAGE_H - 30, 8, { color: FAINT });
            this.text('Page ' + (i + 1) + ' of ' + total, PAGE_W - M, PAGE_H - 30, 8, { color: FAINT, align: 'right' });
        });

        // objects: 1 catalog, 2 pages, 3 Helvetica, 4 Helvetica-Bold, 5 info, then (page, content) pairs
        const objs = [];
        objs[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        const kids = this.pages.map((_, i) => (6 + i * 2) + ' 0 R').join(' ');
        objs[2] = '<< /Type /Pages /Kids [' + kids + '] /Count ' + total + ' >>';
        objs[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
        objs[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';
        objs[5] = '<< /Title (' + esc(this.title) + ') /Producer (Expertise) >>';
        this.pages.forEach((p, i) => {
            const content = p.join('\n');
            objs[6 + i * 2] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 ' + num(PAGE_W) + ' ' + num(PAGE_H) + '] ' +
                '/Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents ' + (7 + i * 2) + ' 0 R >>';
            objs[7 + i * 2] = '<< /Length ' + content.length + ' >>\nstream\n' + content + '\nendstream';
        });

        let out = '%PDF-1.4\n%\xE2\xE3\xCF\xD3\n';
        const offsets = [];
        for (let n = 1; n < objs.length; n++) {
            offsets[n] = out.length;
            out += n + ' 0 obj\n' + objs[n] + '\nendobj\n';
        }
        const xref = out.length;
        out += 'xref\n0 ' + objs.length + '\n0000000000 65535 f \n';
        for (let n = 1; n < objs.length; n++) out += String(offsets[n]).padStart(10, '0') + ' 00000 n \n';
        out += 'trailer\n<< /Size ' + objs.length + ' /Root 1 0 R /Info 5 0 R >>\nstartxref\n' + xref + '\n%%EOF';

        const bytes = new Uint8Array(out.length);                              // every character is one byte
        for (let i = 0; i < out.length; i++) bytes[i] = out.charCodeAt(i) & 255;
        return bytes;
    };

    /* ---------- the report ---------- */
    const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
    function stamp(d) {
        let h = d.getHours();
        const ap = h >= 12 ? 'PM' : 'AM';
        h = h % 12 || 12;
        return MONTHS[d.getMonth()] + ' ' + d.getDate() + ', ' + d.getFullYear() + '  ' + h + ':' + String(d.getMinutes()).padStart(2, '0') + ' ' + ap;
    }

    function build(stats, opts) {
        opts = opts || {};
        stats = stats || {};
        const questions = Array.isArray(stats.questions) ? stats.questions : [];
        const sections = Array.isArray(stats.sections) ? stats.sections : [];
        const subs = Number(stats.submissions) || 0;
        const d = new Doc('Questionnaires Statistics');

        /* title band */
        d.rect(0, 0, PAGE_W, 84, RED);
        d.text('Expertise', M, 20, 11, { bold: true, color: [255, 214, 220] });
        d.text('Questionnaires Statistics', M, 36, 21, { bold: true, color: WHITE });
        d.text('Admin report  \u2022  Generated ' + stamp(opts.generated || new Date()) + (opts.by ? '  \u2022  by ' + opts.by : ''), M, 66, 9, { color: [255, 226, 230] });
        d.y = 84 + 22;

        /* three summary boxes */
        const gap = 12, bw = (CW - gap * 2) / 3;
        [['Questions', questions.length, [28, 138, 219]], ['Sections', sections.length, [21, 154, 67]], ['Submitted questionnaires', subs, RED]].forEach((b, i) => {
            const x = M + i * (bw + gap);
            d.rect(x, d.y, bw, 54, PANEL);
            d.rect(x, d.y + 10, 3, 34, b[2]);
            d.text(String(b[1]), x + 16, d.y + 10, 22, { bold: true });
            d.text(b[0], x + 16, d.y + 38, 8.5, { color: SOFT, bold: true });
        });
        d.y += 54 + 16;

        /* the most answered question */
        let most = null;
        questions.forEach(q => { if (!most || q.answered > most.answered) most = q; });
        const mostLines = most && most.answered > 0 ? wrap(String(most.id).toUpperCase() + '  \u2022  ' + most.text, 10, true, CW - 28, 2) : [];
        const boxH = 26 + (mostLines.length ? mostLines.length * 13 + 16 : 16);
        d.rect(M, d.y, CW, boxH, mix(RED, 0.9));
        d.rect(M, d.y, 3, boxH, RED);
        d.text('MOST ANSWERED QUESTION', M + 14, d.y + 9, 8, { bold: true, color: RED });
        if (mostLines.length) {
            mostLines.forEach((l, i) => d.text(l, M + 14, d.y + 24 + i * 13, 10, { bold: true }));
            const share = subs > 0 ? Math.round(most.answered / subs * 1000) / 10 : 0;
            d.text(most.answered + ' of ' + subs + ' submitted questionnaires answered it (' + pct(share) + ')', M + 14, d.y + 24 + mostLines.length * 13 + 2, 8.5, { color: SOFT });
        } else {
            d.text('No applicant has submitted the questionnaire yet.', M + 14, d.y + 24, 9.5, { color: SOFT });
        }
        d.y += boxH + 20;

        /* sections table */
        d.text('Sections at a glance', M, d.y, 12, { bold: true });
        d.y += 22;
        const cols = { name: M + 10, count: M + 290, ans: M + 360, top: M + 440 };
        d.rect(M, d.y, CW, 20, [235, 236, 238]);
        d.text('Section', cols.name, d.y + 5.5, 8.5, { bold: true });
        d.text('Questions', cols.count, d.y + 5.5, 8.5, { bold: true });
        d.text('Answers', cols.ans, d.y + 5.5, 8.5, { bold: true });
        d.text('Most answered', cols.top, d.y + 5.5, 8.5, { bold: true });
        d.y += 20;
        sections.forEach(s => {
            const qs = questions.filter(q => Number(q.section_no) === Number(s.no));
            let top = null, sum = 0;
            qs.forEach(q => { sum += q.answered; if (!top || q.answered > top.answered) top = q; });
            d.rect(M + 10, d.y + 6, 8, 8, colorOf(s.no));
            d.text(fit('Section ' + s.no + '  \u2022  ' + s.name, 9, false, 255), M + 24, d.y + 5, 9);
            d.text(String(qs.length), cols.count, d.y + 5, 9);
            d.text(String(sum), cols.ans, d.y + 5, 9);
            d.text(top && top.answered > 0 ? String(top.id).toUpperCase() + ' (' + top.answered + ')' : '-', cols.top, d.y + 5, 9);
            d.line(M, d.y + 20, PAGE_W - M, d.y + 20, [240, 241, 243], 0.8);
            d.y += 20;
        });
        d.y += 24;

        /* every section with its questions */
        if (!questions.length) {
            d.text('There are no questions yet.', M, d.y, 10, { color: SOFT });
        }
        sections.forEach(s => {
            const color = colorOf(s.no);
            const qs = questions.filter(q => Number(q.section_no) === Number(s.no));
            if (!qs.length) return;

            const first = blockHeight(qs[0]);
            d.ensure(28 + first);                                              // the heading never sits alone at the bottom
            d.rect(M, d.y, CW, 22, color);
            d.text(fit('Section ' + s.no + '  \u2022  ' + s.name, 10.5, true, CW - 110), M + 10, d.y + 5.5, 10.5, { bold: true });
            d.text(qs.length + ' question' + (qs.length === 1 ? '' : 's'), PAGE_W - M - 10, d.y + 6.5, 8.5, { bold: true, align: 'right', color: [50, 50, 56] });
            d.y += 22 + 8;

            qs.forEach(q => drawQuestion(q, color));
            d.y += 10;
        });

        function meta(q) {
            const parts = [TYPES[q.type] || q.type];
            if (q.status === 'Inactive') parts.push('Inactive (hidden from applicants)');
            if (q.type === 'text') parts.push(q.note || 'Written answers');
            else parts.push(q.answered ? q.answered + ' of ' + subs + ' answered' : 'No answers yet');
            return parts.join('   \u2022   ');
        }

        function blockHeight(q) {
            const lines = wrap(q.text, 10, true, CW - INDENT, 5).length;
            const opts = q.type === 'text' ? 0 : Math.min(6, (q.options || []).length);
            const note = q.note && q.type !== 'text' ? 12 : 0;
            return lines * 13 + 14 + (q.top && q.type !== 'text' ? 14 : 0) + 18 + opts * 12 + note + 14;
        }

        function drawQuestion(q, color) {
            const h = blockHeight(q);
            d.ensure(h);
            const top = d.y;
            const none = q.type !== 'text' && !q.answered;

            // Q badge
            d.rect(M, top, 38, 16, mix(color, 0.45));
            d.text(String(q.id).toUpperCase(), M + 19, top + 4, 8.5, { bold: true, align: 'center' });

            // the question
            let y = top;
            wrap(q.text, 10, true, CW - INDENT, 5).forEach(l => { d.text(l, M + INDENT, y + 1, 10, { bold: true }); y += 13; });
            d.text(fit(meta(q), 8, false, CW - INDENT), M + INDENT, y + 1, 8, { color: SOFT });
            y += 14;

            if (q.top && q.type !== 'text') {
                d.text('Most chosen answer:  ', M + INDENT, y, 8.5, { color: SOFT });
                const lw = tw('Most chosen answer:  ', 8.5, false);
                d.text(fit(q.top, 8.5, true, CW - INDENT - lw - 70) , M + INDENT + lw, y, 8.5, { bold: true });
                d.text('(' + q.top_count + ' of ' + q.answered + ')', PAGE_W - M, y, 8.5, { color: SOFT, align: 'right' });
                y += 14;
            }

            // the bar: 0 to 100 %
            const bx = M + INDENT, bwid = CW - INDENT - 52;
            d.rect(bx, y + 2, bwid, 9, TRACK);
            const share = none ? 0 : Math.max(0, Math.min(100, Number(q.share) || 0));
            if (share > 0) d.rect(bx, y + 2, Math.max(1.5, bwid * share / 100), 9, color);
            [25, 50, 75].forEach(t => d.line(bx + bwid * t / 100, y + 2, bx + bwid * t / 100, y + 11, WHITE, 0.6));
            d.text(none ? 'No data' : pct(share), bx + bwid + 8, y + 1.5, 9, { bold: !none, color: none ? FAINT : INK });
            y += 18;

            // every answer with its share
            if (q.type !== 'text') {
                (q.options || []).slice(0, 6).forEach((o, k) => {
                    d.text(fit(o.label, 8, k === 0, 150), bx + 8, y, 8, { bold: k === 0, color: k === 0 ? INK : SOFT });
                    const ox = bx + 168, ow = 170;
                    d.rect(ox, y + 2, ow, 5, TRACK);
                    if (o.pct > 0) d.rect(ox, y + 2, Math.max(1, ow * o.pct / 100), 5, k === 0 ? color : mix(color, 0.4));
                    d.text(pct(o.pct) + '   \u2022   ' + o.count, ox + ow + 10, y, 8, { color: SOFT });
                    y += 12;
                });
                if (q.note) { d.text(fit(q.note, 8, false, CW - INDENT), bx + 8, y, 8, { color: SOFT }); y += 12; }
            }

            d.line(M, top + h - 6, PAGE_W - M, top + h - 6, [240, 241, 243], 0.8);
            d.y = top + h;
        }

        return new Blob([d.finish()], { type: 'application/pdf' });
    }

    function download(blob, name) {
        const a = document.createElement('a');
        a.href = URL.createObjectURL(blob);
        a.download = name;
        document.body.appendChild(a);
        a.click();
        setTimeout(() => { URL.revokeObjectURL(a.href); a.remove(); }, 1500);
    }

    window.StatsPdf = { build: build, download: download };
})();
}

/* ===== PAGE : dashboardadmin.js (runs only on: dashboardadmin) ===== */
if (document.documentElement.getAttribute('data-page') === 'dashboardadmin') {
/* ==========================================================================
   Dashboard (Admin) behaviour.
   Entrance, sidebar, toast and notifications live in admin-common.js (loaded BEFORE this file).
   ADMIN_DASH (printed by dashboard-admin.php) = {
       stats:    { submissions, sections: [{ no, name, count }],
                   questions: [{ id, section_no, section, text, type, status, answered, top, top_count, share, options, note, expected }] },
       activity: [{ at, activity, details, by }]      the latest 10, newest first
   }
   ========================================================================== */
document.addEventListener('DOMContentLoaded', () => {
    const data = window.ADMIN_DASH || { stats: { sections: [], questions: [], submissions: 0 }, activity: [] };
    const esc = ADMIN.esc;

    /* ---------- 0. Scroll hand-off: at the end of a scrollable card, the wheel scrolls the page ---------- */
    const pageScroll = document.querySelector('.main-content');
    function handOff(box) {
        if (!box || !pageScroll) return;
        box.addEventListener('wheel', (e) => {
            if (e.ctrlKey || Math.abs(e.deltaX) > Math.abs(e.deltaY)) return;      // pinch-zoom / sideways scrolling stays in the card
            const dy = e.deltaMode === 1 ? e.deltaY * 16 : e.deltaY;               // lines -> pixels
            const canScroll = box.scrollHeight - box.clientHeight > 1;
            const atTop = box.scrollTop <= 0;
            const atEnd = box.scrollTop + box.clientHeight >= box.scrollHeight - 1;
            if (!canScroll || (dy > 0 && atEnd) || (dy < 0 && atTop)) {
                pageScroll.scrollTop += dy;
                e.preventDefault();
            }
        }, { passive: false });
    }
    handOff(document.getElementById('statsScroll'));
    handOff(document.querySelector('.activity-card .table-responsive'));

    /* ---------- 1. Summary boxes: numbers count up, the activity box jumps to the list ---------- */
    const base = ADMIN.arrivedViaTransition ? 950 : 550;
    document.querySelectorAll('.card-value[data-count]').forEach((el, i) => {
        ADMIN.countUp(el, el.dataset.count, base + i * 120, 800);
    });

    const activityCard = document.getElementById('activityCard');
    const activityBox = document.getElementById('recentActivities');
    if (activityCard && activityBox) {
        activityCard.addEventListener('click', (e) => {
            e.preventDefault();
            jumpToActivities();
        });
    }

    function jumpToActivities() {
        activityBox.scrollIntoView({ behavior: ADMIN.noMotion() ? 'auto' : 'smooth', block: 'center' });
        activityBox.classList.remove('flash');
        void activityBox.offsetWidth;                 // restart the glow
        activityBox.classList.add('flash');
        setTimeout(() => activityBox.classList.remove('flash'), 1300);
    }

    /* ---------- 2. Recent Activities (latest 10) + the "new" marks ---------- */
    // What HR did since the Admin last opened the Dashboard (read BEFORE it is marked as seen)
    const fresh = new Set(ADMIN.notify.unseen());

    const body = document.getElementById('activityBody');
    const serverActivity = data.activity.slice();
    const STATUS_LABEL = { pending: 'Needs approval', approved: 'Approved', rejected: 'Rejected' };

    // BACKEND: the list (latest 10) should come from the server; HRREQ.activity() is the browser-side stand-in for HR changes
    function allActivity() {
        return serverActivity.concat(HRREQ.activity())
            .sort((x, y) => String(y.at).localeCompare(String(x.at)))
            .slice(0, 10);
    }

    function renderActivity(list, animate) {
        body.innerHTML = list.length
            ? list.map((row, i) => {
                const fromHr = String(row.by).toLowerCase() !== 'admin';
                // rows written by HR through the front-end stand-in carry an id + read flag; they stay highlighted until the Admin opens them
                const isNew = fromHr && (row.id ? (!row.read && ADMIN.notify.on('activity')) : fresh.has(row.at));
                const who = fromHr ? 'hr' : 'admin';
                const rq = row.ref ? HRREQ.get(Number(row.ref)) : null;
                const chip = rq ? ' <span class="req-chip is-' + esc(rq.status) + '">' + esc(STATUS_LABEL[rq.status] || rq.status) + '</span>' : '';
                let click = '';
                if (row.ref) click = ' data-ref="' + Number(row.ref) + '" tabindex="0" role="button" title="' + (isNew ? 'Click to read it and decide' : 'Click to see what changed') + '"';
                else if (isNew && row.id) click = ' data-read="' + esc(row.id) + '" tabindex="0" role="button" title="Click to mark as read"';
                return '<tr class="' + (animate === false ? '' : 'row-in') + (isNew ? ' is-new' : '') + (rq && rq.status === 'pending' ? ' needs-action' : '') + (row.ref || (isNew && row.id) ? ' is-click' : '') + '" style="--r:' + i + '"' + click + '>' +
                    '<td>' + (isNew ? '<span class="new-dot" title="New since your last visit"></span>' : '') + esc(ADMIN.formatDate(row.at)) + '</td>' +
                    '<td title="' + esc(row.activity) + '"><strong>' + esc(row.activity) + '</strong>' + chip + '</td>' +
                    '<td title="' + esc(row.details) + '">' + esc(row.details) + '</td>' +
                    '<td><span class="role-tag ' + who + '">' + esc(row.by) + '</span></td>' +
                '</tr>';
            }).join('')
            : '<tr><td colspan="4" class="empty-row">No activity yet.</td></tr>';
    }
    renderActivity(allActivity());

    /* ---------- 2b. HR change requests: click a row to see the new email / password and decide ---------- */
    const reqModal = document.getElementById('reqModal');
    const reqTitle = document.getElementById('reqTitle');
    const reqSub = document.getElementById('reqSub');
    const reqBody = document.getElementById('reqBody');
    const reqActions = document.getElementById('reqActions');
    let reqId = 0;
    let reqLastFocus = null;

    const EYE = '<svg class="eye-on" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 4.5C7 4.5 2.73 7.61 1 12c1.73 4.39 6 7.5 11 7.5s9.27-3.11 11-7.5c-1.73-4.39-6-7.5-11-7.5zM12 17c-2.76 0-5-2.24-5-5s2.24-5 5-5 5 2.24 5 5-2.24 5-5 5zm0-8c-1.66 0-3 1.34-3 3s1.34 3 3 3 3-1.34 3-3-1.34-3-3-3z"/></svg>' +
        '<svg class="eye-off" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 7c2.76 0 5 2.24 5 5 0 .65-.13 1.26-.36 1.83l2.92 2.92c1.51-1.26 2.7-2.89 3.43-4.75-1.73-4.39-6-7.5-11-7.5-1.4 0-2.74.25-3.98.7l2.16 2.16C10.74 7.13 11.35 7 12 7zM2 4.27l2.28 2.28.46.46C3.08 8.3 1.78 10.02 1 12c1.73 4.39 6 7.5 11 7.5 1.55 0 3.03-.3 4.38-.84l.42.42L19.73 22 21 20.73 3.27 3 2 4.27zM7.53 9.8l1.55 1.55c-.05.21-.08.43-.08.65 0 1.66 1.34 3 3 3 .22 0 .44-.03.65-.08l1.55 1.55c-.67.33-1.41.53-2.2.53-2.76 0-5-2.24-5-5 0-.79.2-1.53.53-2.2z"/></svg>';

    // a password shown as dots with an eye
    function secretLine(label, value) {
        if (!value) return '<div class="req-line"><span class="req-key">' + esc(label) + '</span><span class="req-val is-muted">Not available</span></div>';
        return '<div class="req-line"><span class="req-key">' + esc(label) + '</span>' +
            '<span class="req-val req-secret" data-secret="' + esc(value) + '"><code>' + '•'.repeat(Math.min(12, String(value).length)) + '</code>' +
            '<button type="button" class="req-eye" aria-pressed="false" aria-label="Show password">' + EYE + '</button></span></div>';
    }

    function drawRequest(r) {
        reqTitle.textContent = r.status === 'pending' ? 'Approval needed' : 'Change request';
        reqSub.innerHTML = '<strong>' + esc(r.hr_name) + '</strong> (HR) asked for the changes below · ' + esc(ADMIN.formatDate(r.created));

        let html = '<div class="req-status is-' + esc(r.status) + '">' + esc(STATUS_LABEL[r.status] || r.status) + '</div>';
        if (r.new_email) {
            html += '<div class="req-group"><h4>Email</h4>' +
                '<div class="req-line"><span class="req-key">Current</span><span class="req-val">' + esc(r.old_email) + '</span></div>' +
                '<div class="req-line is-new"><span class="req-key">New</span><span class="req-val">' + esc(r.new_email) + '</span></div></div>';
        }
        if (r.new_password) {
            html += '<div class="req-group"><h4>Password</h4>' +
                secretLine('Current', r.current_password) +
                secretLine('New', r.new_password) + '</div>';
        }
        if (r.status !== 'pending' && r.decided) {
            html += '<p class="req-decided">' + esc(STATUS_LABEL[r.status]) + ' · ' + esc(ADMIN.formatDate(r.decided)) + '</p>';
        }
        reqBody.innerHTML = html;

        reqBody.querySelectorAll('.req-eye').forEach(btn => btn.addEventListener('click', () => {
            const box = btn.closest('.req-secret');
            const show = btn.getAttribute('aria-pressed') !== 'true';
            box.querySelector('code').textContent = show ? box.dataset.secret : '•'.repeat(Math.min(12, box.dataset.secret.length));
            btn.setAttribute('aria-pressed', String(show));
            btn.setAttribute('aria-label', (show ? 'Hide' : 'Show') + ' password');
        }));

        reqActions.innerHTML = r.status === 'pending'
            ? '<button type="button" class="modal-btn is-cancel" data-do="rejected">Reject</button>' +
              '<button type="button" class="modal-btn is-confirm" data-do="approved">Approve</button>'
            : '<button type="button" class="modal-btn is-cancel" data-do="close">Close</button>';
    }

    // BACKEND: GET the request from the server instead of HRREQ.get()
    function openRequest(id) {
        const r = HRREQ.get(id);
        if (!r) { ADMIN.toast('That request was not found.', { error: true, duration: 4000 }); return; }
        if (HRREQ.markRequestRead(id)) refreshActivityUI();       // opening it counts as reading it: the highlight goes
        reqId = id;
        reqLastFocus = document.activeElement;
        drawRequest(r);
        reqModal.classList.add('active');
        setTimeout(() => { const b = reqActions.querySelector('button:last-child'); if (b) b.focus(); }, 120);
    }

    function closeRequest() {
        reqModal.classList.remove('active');
        if (reqLastFocus && reqLastFocus.focus) reqLastFocus.focus();
    }

    // BACKEND: POST approve / reject to the server instead of HRREQ.decide()
    function decide(status) {
        const r = HRREQ.decide(reqId, status);
        reqModal.classList.remove('active');
        if (!r) { ADMIN.toast('This request was already handled.', { error: true, duration: 4000 }); return; }
        refreshActivityUI();
        ADMIN.toast(status === 'approved' ? 'Change approved. The HR account was updated.' : 'Change rejected. The HR account stays as it was.', { duration: 4500 });
    }

    // a row is opened (request) or marked as read (name change) by click / Enter / Space
    function activateRow(tr) {
        if (tr.dataset.ref) openRequest(Number(tr.dataset.ref));
        else if (tr.dataset.read && HRREQ.markAdminRead(tr.dataset.read)) refreshActivityUI();
    }
    body.addEventListener('click', (e) => {
        const tr = e.target.closest('tr[data-ref], tr[data-read]');
        if (tr) activateRow(tr);
    });
    body.addEventListener('keydown', (e) => {
        if (e.key !== 'Enter' && e.key !== ' ') return;
        const tr = e.target.closest('tr[data-ref], tr[data-read]');
        if (!tr) return;
        e.preventDefault();
        activateRow(tr);
    });
    const markAllBtn = document.getElementById('markAllRead');
    if (markAllBtn) markAllBtn.addEventListener('click', () => { if (HRREQ.markAdminRead()) refreshActivityUI(); });
    reqActions.addEventListener('click', (e) => {
        const btn = e.target.closest('button[data-do]');
        if (!btn) return;
        if (btn.dataset.do === 'close') closeRequest();
        else decide(btn.dataset.do);
    });
    reqModal.addEventListener('click', (e) => { if (e.target === reqModal) closeRequest(); });
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && reqModal.classList.contains('active')) closeRequest();
    });
    // a new HR request arrives while this page is open
    window.addEventListener('storage', (e) => {
        if (e.key !== 'ebsmi_activity_extra' && e.key !== 'ebsmi_change_requests') return;
        const before = lastUnread;
        refreshActivityUI();
        if (lastUnread > before) {
            const waiting = HRREQ.list().filter(r => r.status === 'pending').length;
            ADMIN.toast('HR sent a change.' + (waiting ? ' ' + waiting + ' waiting for your approval.' : ''), { actionLabel: 'View', duration: 6000, onAction: jumpToActivities });
        }
    });

    // the list fades out at the bottom edge while there are more rows to scroll to (same as the HR Dashboard)
    const listScroll = document.querySelector('.activity-card .table-responsive');
    function updateListFade() {
        if (!listScroll) return;
        listScroll.classList.toggle('has-more', listScroll.scrollHeight - listScroll.scrollTop - listScroll.clientHeight > 4);
    }
    if (listScroll) {
        listScroll.addEventListener('scroll', updateListFade, { passive: true });
        window.addEventListener('resize', updateListFade);
        updateListFade();
        setTimeout(updateListFade, 900);                 // again once the entrance animation has settled
    }

    // The "N new" chip counts what is still highlighted: the server's rows since the last visit (this visit only)
    // plus the HR changes nobody opened yet (those stay until the Admin opens or decides them)
    const chip = document.getElementById('newChip');
    const serverNew = data.activity.filter(r => fresh.has(r.at) && String(r.by).toLowerCase() !== 'admin').length;
    let lastUnread = 0;
    function paintChip() {
        const hr = ADMIN.notify.unreadChanges();
        const n = serverNew + hr;
        chip.textContent = n + ' new';
        chip.hidden = n <= 0;
        if (markAllBtn) markAllBtn.hidden = hr <= 0;
        lastUnread = hr;
        return n;
    }
    // redraw the list, the chip and the sidebar badge after something was read, decided or received
    function refreshActivityUI() {
        renderActivity(allActivity(), false);
        updateListFade();
        paintChip();
        ADMIN.notify.refresh();
    }

    const newCount = paintChip();
    if (newCount > 0) {
        const waiting = HRREQ.list().filter(r => r.status === 'pending').length;
        setTimeout(() => ADMIN.toast(newCount + ' new HR ' + (newCount === 1 ? 'update' : 'updates') + '.' +
            (waiting ? ' ' + waiting + ' waiting for your approval.' : ''), {
            actionLabel: 'View',
            duration: 6000,
            onAction: jumpToActivities
        }), ADMIN.arrivedViaTransition ? 1500 : 900);
    }
    // Opening the Dashboard counts as "seen" for the server's rows. HR changes stay highlighted until opened / decided.
    ADMIN.notify.markSeen();

    /* ---------- 3. Questionnaires Statistics: one bar per question, the answer most applicants chose ---------- */
    const stats = data.stats;
    const list = document.getElementById('statsList');
    const filterBox = document.getElementById('statsFilter');
    const axis = document.querySelector('.chart-axis');

    const palette = ['#8f8aea', '#74cfb9', '#f8a09a', '#f6c453', '#7ab8f0', '#c58ae0'];   // one colour per section (purple, teal, salmon like the mockup)
    const colorOf = (no) => palette[(Number(no) - 1 + palette.length) % palette.length];
    const pct = (v) => (Math.round(v * 10) / 10) + '%';
    let section = 'all';
    let open = null;

    function drawFilter() {
        if (stats.sections.length < 2) { filterBox.hidden = true; return; }
        const chipHtml = (value, label, color, count) =>
            '<button type="button" class="stats-chip' + (section === value ? ' is-on' : '') + '" data-section="' + value + '"' +
            (color ? ' style="--c:' + color + '"' : '') + '>' + (color ? '<i></i>' : '') + esc(label) + '<b>' + count + '</b></button>';
        filterBox.innerHTML = chipHtml('all', 'All', '', stats.questions.length) + stats.sections.map(s =>
            chipHtml(String(s.no), 'Section ' + s.no, colorOf(s.no), s.count)).join('');
    }

    function draw(animate) {
        const rows = stats.questions.filter(q => section === 'all' || String(q.section_no) === section);
        axis.hidden = !rows.length;
        if (!rows.length) {
            list.innerHTML = '<p class="chart-empty">No questions yet. Create one in Questions.</p>';
            return;
        }

        list.innerHTML = rows.map((q, i) => {
            const color = colorOf(q.section_no);
            const isText = q.type === 'text';
            const none = q.answered === 0 && !isText;

            let answer;
            if (isText) answer = '<span class="q-answer is-muted">Written answers</span>';
            else if (none) answer = '<span class="q-answer is-muted">No answers yet</span>';
            else {
                const good = q.expected ? (q.top === q.expected ? ' is-good' : ' is-warn') : '';
                answer = '<span class="q-answer' + good + '" title="Most chosen answer">Top: ' + esc(q.top) + '</span>';
            }

            const count = isText ? esc(q.note) : (q.answered ? q.top_count + ' of ' + q.answered : '0 answered');
            const w = none ? 0 : Math.max(0, Math.min(100, q.share));
            const val = none
                ? '<span class="q-val is-none" style="--w:0%">No data</span>'
                : '<span class="q-val' + (w >= 20 ? ' is-inside' : '') + '" style="--w:' + w + '%">' + pct(q.share) + '</span>';

            const detail = q.options.length
                ? q.options.map((o, k) => '<div class="q-opt' + (k === 0 ? ' is-top' : '') + '"><span class="q-opt-name">' + esc(o.label) + '</span>' +
                    '<span class="q-opt-bar"><i style="--w:' + o.pct + '%"></i></span>' +
                    '<span class="q-opt-val">' + pct(o.pct) + ' · ' + o.count + '</span></div>').join('')
                : '<div class="q-opt"><span class="q-opt-name">' + (isText ? esc(q.note) : 'Nobody answered this question yet.') + '</span></div>';

            return '<div class="q-item' + (q.status === 'Inactive' ? ' is-off' : '') + (open === q.id ? ' is-open' : '') + '" data-id="' + esc(q.id) + '" style="--c:' + color + ';--i:' + Math.min(i, 12) + '"' + (animate ? '' : ' data-still') + '>' +
                '<div class="q-row" role="button" tabindex="0" aria-expanded="' + (open === q.id ? 'true' : 'false') + '">' +
                    '<div class="q-main">' +
                        '<span class="q-badge">' + esc(q.id.toUpperCase()) + '</span>' +
                        '<div class="q-text"><span class="q-title" title="' + esc(q.text) + '">' + esc(q.text) + '</span>' +
                            '<div class="q-meta">' + answer + (q.status === 'Inactive' ? '<em class="q-tag">Inactive</em>' : '') +
                            '<small>Section ' + esc(q.section_no) + ' · ' + esc(q.section) + (none ? '' : ' · ' + count) + '</small></div></div>' +
                    '</div>' +
                    '<div class="q-plot" aria-label="' + (none ? 'No answers yet' : pct(q.share) + ' chose the top answer') + '"><span class="q-bar" style="--w:' + w + '%"></span>' + val + '</div>' +
                '</div>' +
                '<div class="q-detail"><div>' + detail + (q.note && !isText ? '<p class="q-note">' + esc(q.note) + '</p>' : '') + '</div></div>' +
            '</div>';
        }).join('');

        // the bars grow from 0 once the rows are on the page
        list.classList.remove('is-grown');
        requestAnimationFrame(() => requestAnimationFrame(() => list.classList.add('is-grown')));
    }

    /* ---------- Download PDF: the whole Questionnaires Statistics as a report (made in the browser, see stats-pdf.js) ---------- */
    const pdfBtn = document.getElementById('statsPdfBtn');
    if (pdfBtn) {
        pdfBtn.addEventListener('click', () => {
            if (!window.StatsPdf) {
                ADMIN.toast('The PDF maker is missing. Copy assets/js/stats-pdf.js into your project.', { error: true, duration: 5000 });
                return;
            }
            pdfBtn.disabled = true;
            pdfBtn.classList.add('is-busy');
            // a short moment so the button can show it is working
            setTimeout(() => {
                try {
                    const now = new Date();
                    const day = now.getFullYear() + '-' + String(now.getMonth() + 1).padStart(2, '0') + '-' + String(now.getDate()).padStart(2, '0');
                    const blob = window.StatsPdf.build(stats, { generated: now, by: (document.getElementById('greeting') || {}).title ? document.getElementById('greeting').title.replace(/^Hello,\s*|!$/g, '') : 'Admin' });
                    window.StatsPdf.download(blob, 'Questionnaire-Statistics-' + day + '.pdf');
                    ADMIN.toast('PDF downloaded.', { duration: 3000 });
                } catch (err) {
                    ADMIN.toast('Could not make the PDF. Please try again.', { error: true, duration: 4000 });
                } finally {
                    pdfBtn.disabled = false;
                    pdfBtn.classList.remove('is-busy');
                }
            }, 350);
        });
    }

    filterBox.addEventListener('click', (e) => {
        const b = e.target.closest('[data-section]');
        if (!b) return;
        section = b.dataset.section;
        open = null;
        drawFilter();
        draw(false);
    });

    function toggleRow(item) {
        const id = item.dataset.id;
        open = open === id ? null : id;
        list.querySelectorAll('.q-item').forEach(el => {
            const on = el.dataset.id === open;
            el.classList.toggle('is-open', on);
            el.querySelector('.q-row').setAttribute('aria-expanded', on ? 'true' : 'false');
        });
    }

    list.addEventListener('click', (e) => {
        const item = e.target.closest('.q-item');
        if (item && e.target.closest('.q-row')) toggleRow(item);
    });
    list.addEventListener('keydown', (e) => {
        if (e.key !== 'Enter' && e.key !== ' ') return;
        const item = e.target.closest('.q-item');
        if (item && e.target.classList.contains('q-row')) { e.preventDefault(); toggleRow(item); }
    });

    drawFilter();
    draw(true);
});
}

/* ==========================================================================
   HRREQ: front-end stand-in for "HR changes personal info -> Admin is notified -> Admin decides -> HR is told".
   It keeps everything in this browser (localStorage) so the HR pages and the Admin pages can be tried
   together. BACKEND: replace every HRREQ call with a request to the server; the data shapes below are
   what the PHP should return.
     request  = { id, hr_id, hr_name, old_email, new_email|null, new_password|null, current_password|null,
                  status: 'pending'|'approved'|'rejected', created, decided|null, applied }
     activity = { id, at, activity, details, by: 'HR'|'Admin', ref|null, hr_id|null, read }
   "read" on an activity row means:
     by 'HR'    -> the ADMIN has opened it   (until then it is highlighted on the Admin side)
     by 'Admin' -> the HR it is about has read it   (until then it is highlighted on the HR side)
   ========================================================================== */
window.HRREQ = (function () {
    const KEY = 'ebsmi_change_requests', ACT = 'ebsmi_activity_extra', PW = 'ebsmi_mock_passwords';
    const read = (k, f) => { try { const v = JSON.parse(localStorage.getItem(k)); return v == null ? f : v; } catch (e) { return f; } };
    const write = (k, v) => { try { localStorage.setItem(k, JSON.stringify(v)); return true; } catch (e) { return false; } };
    const pad = (n) => String(n).padStart(2, '0');
    function now() {
        const d = new Date();
        return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()) + ' ' + pad(d.getHours()) + ':' + pad(d.getMinutes()) + ':' + pad(d.getSeconds());
    }
    let seq = 0;
    const uid = () => 'a' + Date.now().toString(36) + (seq++).toString(36);
    const isAdminRow = (a) => String(a.by).toLowerCase() === 'admin';
    const partsOf = (r) => [r.new_email ? 'email' : '', r.new_password ? 'password' : ''].filter(Boolean).join(' & ');

    // FRONT-END PREVIEW: sample rows so the design can be seen before the backend exists (set DEMO to false to switch off)
    const DEMO = true;
    if (DEMO && !read('ebsmi_demo_seeded', false)) {
        const ago = (min) => { const d = new Date(Date.now() - min * 60000); return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()) + ' ' + pad(d.getHours()) + ':' + pad(d.getMinutes()) + ':' + pad(d.getSeconds()); };
        write(KEY, [
            { id: 1, hr_id: 9001, hr_name: 'Maria Santos', old_email: 'maria.santos@expertise.com', new_email: 'maria.s@gmail.com',
              new_password: 'NewPass123', current_password: 'OldPass123', status: 'pending', created: ago(18), decided: null, applied: false },
            { id: 2, hr_id: 9002, hr_name: 'John Reyes', old_email: 'john.reyes@expertise.com', new_email: 'j.reyes@gmail.com',
              new_password: null, current_password: null, status: 'approved', created: ago(190), decided: ago(150), applied: true }
        ]);
        write(ACT, [
            { id: 'seed1', at: ago(18),  activity: 'HR Change Request',  details: 'Maria Santos (HR) · email & password · waiting for approval', by: 'HR',    ref: 1,    hr_id: 9001, read: false },
            { id: 'seed2', at: ago(60),  activity: 'HR Name Changed',    details: 'Mariaa Santos → Maria Santos',                                 by: 'HR',    ref: null, hr_id: 9001, read: false },
            { id: 'seed3', at: ago(150), activity: 'HR Change Approved', details: 'John Reyes (HR) · email',                                      by: 'Admin', ref: 2,    hr_id: 9002, read: false },
            { id: 'seed4', at: ago(190), activity: 'HR Change Request',  details: 'John Reyes (HR) · email · waiting for approval',               by: 'HR',    ref: 2,    hr_id: 9002, read: true }
        ]);
        write('ebsmi_demo_seeded', true);
    }

    // Rows saved by an older version of this file have no id / read / hr_id: they count as already read
    (function migrate() {
        const a = read(ACT, []);
        let changed = false;
        a.forEach(r => {
            if (r.id) return;
            const rq = r.ref ? read(KEY, []).find(x => x.id === Number(r.ref)) : null;
            r.id = uid(); r.read = true; r.hr_id = rq ? rq.hr_id : null; changed = true;
        });
        if (changed) write(ACT, a);
    })();

    // What the Admin's answer means for HR, in plain words (used by the bell, the Settings banner ...)
    function describe(r, status) {
        const what = r && r.new_email && r.new_password ? 'Email & password' : r && r.new_email ? 'Email' : 'Password';
        const ok = status === 'approved';
        const detail = [];
        if (ok) {
            if (r && r.new_email) detail.push('Your new email ' + r.new_email + ' is now active.');
            if (r && r.new_password) detail.push('Your new password is now active. Use it the next time you log in.');
            if (!detail.length) detail.push('The admin approved your change.');
        } else {
            detail.push('The admin did not approve it, so nothing was changed. You can send a new request from Settings.');
        }
        return { title: what + ' change ' + (ok ? 'approved' : 'rejected'), detail: detail.join(' ') };
    }

    const api = {
        now: now,
        list: () => read(KEY, []),
        get: (id) => api.list().find(r => r.id === id) || null,
        pendingFor: (hrId) => api.list().find(r => r.hr_id === hrId && r.status === 'pending') || null,
        unappliedFor: (hrId) => api.list().find(r => r.hr_id === hrId && r.status !== 'pending' && !r.applied) || null,
        activity: () => read(ACT, []),
        log(activity, details, by, ref, hrId) {
            const a = read(ACT, []);
            const row = { id: uid(), at: now(), activity: activity, details: details, by: by, ref: ref || null, hr_id: hrId || null, read: false };
            a.unshift(row);
            write(ACT, a.slice(0, 50));
            return row;
        },
        // HR sends a change; a second change while one is waiting is added to the same request
        submit(hr, changes) {
            const all = api.list();
            let r = all.find(x => x.hr_id === hr.id && x.status === 'pending');
            if (!r) {
                r = { id: Date.now(), hr_id: hr.id, hr_name: hr.name, old_email: hr.email, new_email: null, new_password: null,
                      current_password: null, status: 'pending', created: now(), decided: null, applied: false };
                all.push(r);
            }
            if (changes.new_email) r.new_email = changes.new_email;
            if (changes.new_password) { r.new_password = changes.new_password; r.current_password = changes.current_password || null; }
            write(KEY, all);
            api.log('HR Change Request', r.hr_name + ' (HR) · ' + partsOf(r) + ' · waiting for approval', 'HR', r.id, r.hr_id);
            return r;
        },
        // Admin decides. The HR gets a new (unread) notice; the HR's own request row counts as read for the Admin.
        decide(id, status) {
            const all = api.list();
            const r = all.find(x => x.id === id && x.status === 'pending');
            if (!r) return null;
            r.status = status;
            r.decided = now();
            write(KEY, all);
            if (status === 'approved' && r.new_password) {
                const m = read(PW, {}); m[r.hr_id] = r.new_password; write(PW, m);
            }
            api.markRequestRead(r.id);
            api.log(status === 'approved' ? 'HR Change Approved' : 'HR Change Rejected', r.hr_name + ' (HR) · ' + partsOf(r), 'Admin', r.id, r.hr_id);
            return r;
        },
        markApplied(id) {
            const all = api.list();
            const r = all.find(x => x.id === id);
            if (r) { r.applied = true; write(KEY, all); }
        },
        // the password the Admin sees in Edit HR Account (null = "Not available yet")
        password: (hrId) => read(PW, {})[hrId] || null,

        /* ---- ADMIN side: what HR did that the Admin has not opened yet ---- */
        adminUnread: () => read(ACT, []).filter(a => !isAdminRow(a) && !a.read),
        // one row (by its id) or, with no id, every row
        markAdminRead(id) {
            const a = read(ACT, []);
            let changed = false;
            a.forEach(r => { if (!isAdminRow(r) && !r.read && (id == null || r.id === id)) { r.read = true; changed = true; } });
            if (changed) write(ACT, a);
            return changed;
        },
        // opening a request (or deciding it) reads every row that belongs to it
        markRequestRead(refId) {
            const a = read(ACT, []);
            let changed = false;
            a.forEach(r => { if (!isAdminRow(r) && !r.read && Number(r.ref) === Number(refId)) { r.read = true; changed = true; } });
            if (changed) write(ACT, a);
            return changed;
        },

        /* ---- HR side: the Admin's answers to this HR's requests ---- */
        // newest first: { id, at, ref, status: 'approved'|'rejected', title, detail, read }
        hrNotices(hrId) {
            return read(ACT, [])
                .filter(a => isAdminRow(a) && a.ref && Number(a.hr_id) === Number(hrId) && /^HR Change (Approved|Rejected)$/.test(a.activity))
                .map(a => {
                    const status = /Approved$/.test(a.activity) ? 'approved' : 'rejected';
                    const d = describe(api.get(Number(a.ref)), status);
                    return { id: a.id, at: a.at, ref: a.ref, status: status, title: d.title, detail: d.detail, read: !!a.read };
                })
                .sort((x, y) => String(y.at).localeCompare(String(x.at)));
        },
        hrUnread: (hrId) => api.hrNotices(hrId).filter(n => !n.read),
        // one notice (by its id) or, with no id, all of this HR's notices
        markHrRead(hrId, id) {
            const a = read(ACT, []);
            let changed = false;
            a.forEach(r => {
                if (isAdminRow(r) && !r.read && Number(r.hr_id) === Number(hrId) && (id == null || r.id === id)) { r.read = true; changed = true; }
            });
            if (changed) write(ACT, a);
            return changed;
        }
    };
    return api;
})();