/* ==========================================================================
   screeninglist.js  (everything for the Screening List page in ONE file)
   Part 1: archive helpers + toast   Part 2: sidebar / logout / entrance   Part 3: the list
   ========================================================================== */

/* ==========================================================================
   Shared helpers for the Archive feature: window.HR
   - archiveRequest('archive' | 'restore', ids)  -> talks to archive_action.php
   - setArchiveBadge(n) / bumpArchive()          -> sidebar Archive item
   - toast(message, { actionLabel, onAction, duration, error })
   ========================================================================== */
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

    function setArchiveBadge(count, animate) {
        const badge = document.getElementById('archiveBadge');
        if (!badge) return;
        badge.textContent = String(count);
        badge.hidden = count <= 0;
        badge.classList.remove('pop');
        if (animate !== false && count > 0) {
            void badge.offsetWidth;           // restart the animation
            badge.classList.add('pop');
        }
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

    document.addEventListener('DOMContentLoaded', () => setArchiveBadge(readArchived().length, false));

    return { archiveRequest, readArchived, setArchiveBadge, bumpArchive, toast };
})();


/* ---------- PART 2: sidebar, logout, entrance animation ---------- */
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
    const params = new URLSearchParams(window.location.search);
    const arrivedViaTransition = params.get('transition') === 'out' && pageOverlay;

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

    // 2. Sidebar collapse / expand
    if (sidebarToggle && sidebar) {
        sidebarToggle.addEventListener('click', () => {
            sidebar.classList.toggle('collapsed');
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
            if (e.key === 'Escape') logoutModal.classList.remove('active');
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
    window.addEventListener('pageshow', (event) => {
        if (!event.persisted || !pageOverlay) return;
        root.classList.remove('is-leaving', 'is-transitioning');
        pageOverlay.classList.remove('sweep-in', 'sweep-out');
        if (confirmLogout) confirmLogout.disabled = false;
    });
});


/* ---------- PART 3: Screening List ---------- */
document.addEventListener('DOMContentLoaded', () => {
    const config = window.SCREENING || { perPage: 6, applicants: [] };
    let applicants = Array.isArray(config.applicants) ? config.applicants.slice() : [];
    // applicants already moved to the Archive are not listed
    const archivedNow = new Set(HR.readArchived());
    applicants = applicants.filter(a => !archivedNow.has(a.id));
    // config.perPage is the starting value; fitPerPage() then adjusts it to the window height
    let perPage = Math.max(1, parseInt(config.perPage, 10) || 6);

    const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    const toolbar = document.getElementById('toolbar');
    const searchInput = document.getElementById('searchInput');
    const searchClear = document.getElementById('searchClear');
    const selectAllBtn = document.getElementById('selectAllBtn');
    const selectActions = document.getElementById('selectActions');
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
    let settleTimer = null;

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

    function updateSelectionUi() {
        const n = selected.size;
        deleteSelected.disabled = busy || n === 0;
        deleteCount.textContent = n ? '(' + n + ')' : '';
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

    function setSelectMode(on) {
        if (selectMode === on) return;
        selectMode = on;
        table.classList.toggle('select-mode', on);
        toolbar.classList.toggle('is-selecting', on);
        // once the buttons have finished sliding in, stop clipping them (so hover pops are never cut)
        clearTimeout(settleTimer);
        toolbar.classList.remove('is-settled');
        if (on) settleTimer = setTimeout(() => toolbar.classList.add('is-settled'), 500);
        selectAllBtn.inert = on;              // hidden controls stay out of the tab order
        selectActions.inert = !on;
        selectAll.tabIndex = on ? 0 : -1;
        tableBody.querySelectorAll('.row-check').forEach(b => { b.tabIndex = on ? 0 : -1; });
        if (!on) selected.clear();
        syncRowChecks();
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
        setSelectMode(false);            // clears the selection too
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
        const resultMap = { 'passed': 'Passed', 'failed': 'Failed', 'rejected': 'Failed', 'none': 'none' };

        if (statusMap[status]) state.status = statusMap[status];
        // The dashboard pie chart links with ?status=Passed / ?status=Failed
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
        resizeTimer = setTimeout(refit, 150);
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
    updateCounts();
    perPage = fitPerPage();
    readUrl();
    render();
    refit();                // now that a real row exists, measure again for an exact fit
    animateRows = true;     // later renders (search, filters, pages) animate the rows in
});