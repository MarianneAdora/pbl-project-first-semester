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

    function restoreItem(it, btn) {
        if (busy) return;
        act(it.kind + '_restore', it, btn, it.kind === 'account' ? 'HR account restored.' : 'Question restored.');
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