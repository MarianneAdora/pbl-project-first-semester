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
        edit.href = 'addhr.php?id=' + encodeURIComponent(a.id);
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