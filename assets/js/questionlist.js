/* ==========================================================================
   Questionnaires (admin): search, section + status filters, rows that fit the window, page buttons, delete + undo.
   Same behaviour as the HR Accounts page (assets/js/hrlist.js). Shared helpers (window.ADMIN: requests, toast,
   confirm box, flyToArchive, badges), the sidebar and logout live in admin-common.js (loaded BEFORE this script).
   This page's markup + data come from admin/questionlist.php (window.ADMIN_Q).
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

        const tdDate = el('td', null, ADMIN.formatDate(q.created, false));

        const tdStatus = el('td');
        tdStatus.appendChild(el('span', 'status-pill ' + (q.status === 'Active' ? 'is-active' : 'is-inactive'), q.status));

        // action: edit (opens the same form as Create Questions) + delete (moves to the Archive)
        const tdAction = el('td', 'col-action');
        const box = el('div', 'row-actions');

        const edit = el('a', 'icon-btn is-edit');
        edit.href = 'addquestion.php?id=' + encodeURIComponent(q.id);
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