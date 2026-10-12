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
    /* ---- Settings (admin/settingsadmin.php) -----------------------------------------------------
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
    const SERVER_API = 'static-admin.php';

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

    const lastCount = { dashboardBadge: -1, archiveBadge: -1 };
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
        const a = unseenActivity().length;
        const e = notifyOn('archive') ? expiring : 0;
        paintBadge('dashboardBadge', a, a + ' new HR ' + (a === 1 ? 'activity' : 'activities') + ' since you last looked');
        paintBadge('archiveBadge', e, e + ' archived ' + (e === 1 ? 'item' : 'items') + ' will be deleted for good within ' + (notify.days || 7) + ' days');
        document.dispatchEvent(new CustomEvent('admin:badges', { detail: { activity: a, expiring: e } }));
        return { activity: a, expiring: e };
    }

    function bumpArchive() {
        const link = document.getElementById('archiveLink');
        if (!link) return;
        link.classList.remove('bump');
        void link.offsetWidth;
        link.classList.add('bump');
        setTimeout(() => link.classList.remove('bump'), 700);
    }

    /* ---- Row flies to the Archive (same animation as the HR Screening List) ---------------------
         ADMIN.flyToArchive(rows, label)  rows = <tr> elements, label(tr) = the text on the flying chip
       Resolves when every chip has landed. Without motion the rows simply fade (class row-leave). */
    function flyToArchive(rows, label) {
        const link = document.getElementById('archiveLink');
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

    /* ---- Start ------------------------------------------------------------------------------ */
    applySettings();                                   // the <head> script already did this; harmless backup
    document.addEventListener('DOMContentLoaded', () => {
        applyProfile();
        refreshBadges();
    });

    window.addEventListener('storage', (e) => {
        if (e.key === SETTINGS_KEY) { applySettings(null, true); refreshBadges(); }   // Settings saved in another tab
        if (e.key === PROFILE_KEY) applyProfile();
        if (e.key === SEEN_KEY) refreshBadges();
    });

    return {
        request, esc, formatDate, toast, confirm: confirmBox, icons, countUp, noMotion,
        menu: openMenu, closeMenu, pager, showing, flash, showFlash,
        flyToArchive, bumpArchive,
        notify: { unseen: unseenActivity, markSeen: markActivitySeen, setExpiring: setExpiring, refresh: refreshBadges, on: notifyOn },
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
