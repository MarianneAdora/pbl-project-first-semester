/* ==========================================================================
   Dashboard (Admin) behaviour.
   Entrance, sidebar, toast and notifications live in admin-common.js (loaded BEFORE this file).
   ADMIN_DASH (printed by dashboardadmin.php) = {
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
    body.innerHTML = data.activity.length
        ? data.activity.map((row, i) => {
            const isNew = fresh.has(row.at) && String(row.by).toLowerCase() !== 'admin';
            const who = String(row.by).toLowerCase() === 'admin' ? 'admin' : 'hr';
            return '<tr class="row-in' + (isNew ? ' is-new' : '') + '" style="--r:' + i + '">' +
                '<td>' + (isNew ? '<span class="new-dot" title="New since your last visit"></span>' : '') + esc(ADMIN.formatDate(row.at)) + '</td>' +
                '<td title="' + esc(row.activity) + '"><strong>' + esc(row.activity) + '</strong></td>' +
                '<td title="' + esc(row.details) + '">' + esc(row.details) + '</td>' +
                '<td><span class="role-tag ' + who + '">' + esc(row.by) + '</span></td>' +
            '</tr>';
        }).join('')
        : '<tr><td colspan="4" class="empty-row">No activity yet.</td></tr>';

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

    // Opening the Dashboard counts as "seen" (the sidebar badge clears), like opening an applicant on the HR side
    const newCount = data.activity.filter(r => fresh.has(r.at) && String(r.by).toLowerCase() !== 'admin').length;
    const chip = document.getElementById('newChip');
    if (newCount > 0) {
        chip.textContent = newCount + ' new';
        chip.hidden = false;
        setTimeout(() => ADMIN.toast(newCount + ' new HR ' + (newCount === 1 ? 'activity' : 'activities') + ' since your last visit.', {
            actionLabel: 'View',
            duration: 6000,
            onAction: jumpToActivities
        }), ADMIN.arrivedViaTransition ? 1500 : 900);
    }
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