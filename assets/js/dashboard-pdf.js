/* ===== PAGE : dashboard-pdf.js (runs only on dashboards) ===== */
if (['dashboardadmin', 'dashboardhr'].includes(document.documentElement.getAttribute('data-page'))) {
/* ==========================================================================
   Dashboard statistics -> PDF.
   No library and no internet needed: this file writes the PDF itself.
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
            this.text('Expertise  \u2022  Dashboard Statistics', M, PAGE_H - 30, 8, { color: FAINT });
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

    function addTitleBand(d, title, role, opts) {
        d.rect(0, 0, PAGE_W, 84, RED);
        d.text('Expertise', M, 20, 11, { bold: true, color: [255, 214, 220] });
        d.text(title, M, 36, 21, { bold: true, color: WHITE });
        const meta = role + ' report  \u2022  Generated ' + stamp(opts.generated || new Date()) +
            (opts.scope ? '  \u2022  ' + opts.scope : '') + (opts.by ? '  \u2022  by ' + opts.by : '');
        d.text(fit(meta, 9, false, CW), M, 66, 9, { color: [255, 226, 230] });
        d.y = 84 + 22;
    }

    function drawSummaryCards(d, cards) {
        const gap = 8, bw = (CW - gap * (cards.length - 1)) / cards.length;
        cards.forEach((card, i) => {
            const x = M + i * (bw + gap);
            d.rect(x, d.y, bw, 54, PANEL);
            d.rect(x, d.y + 10, 3, 34, card.color);
            d.text(String(card.value), x + 12, d.y + 10, 20, { bold: true });
            d.text(fit(card.label, 7.5, true, bw - 22), x + 12, d.y + 38, 7.5, { color: SOFT, bold: true });
        });
        d.y += 54 + 16;
    }

    function drawRows(d, title, rows, subtitle, color) {
        const accent = color || RED;
        const pale = mix(accent, 0.88);
        const items = rows.length ? rows : [['No data available', '—']];
        const drawHeading = (continued) => {
            d.ensure(82);
            d.rect(M, d.y, CW, 24, pale);
            d.rect(M, d.y, 3, 24, accent);
            d.text(title + (continued ? ' (continued)' : ''), M + 12, d.y + 6, 11, { bold: true, color: accent });
            d.y += 28;
            if (subtitle) {
                d.text(fit(subtitle, 8, false, CW), M + 2, d.y, 8, { color: SOFT });
                d.y += 13;
            }
            d.rect(M, d.y, CW, 18, mix(accent, 0.92));
            d.text('Metric', M + 8, d.y + 4.5, 8, { bold: true, color: accent });
            d.text('Value', PAGE_W - M - 8, d.y + 4.5, 8, { bold: true, color: accent, align: 'right' });
            d.y += 18;
        };
        drawHeading(false);
        items.forEach(row => {
            const hasRatio = row[2] !== undefined && row[2] !== null && row[2] !== '';
            const rowHeight = hasRatio ? 30 : 22;
            const pageCount = d.pages.length;
            d.ensure(rowHeight);
            if (d.pages.length !== pageCount) drawHeading(true);
            const rowColor = row[3] || accent;
            d.rect(M, d.y + 2, 2, rowHeight - 4, mix(rowColor, 0.18));
            d.text(fit(row[0], 8.5, false, CW - 180), M + 8, d.y + 4, 8.5);
            d.text(fit(row[1], 8.5, true, 165), PAGE_W - M - 8, d.y + 4, 8.5, { bold: true, color: rowColor, align: 'right' });
            if (hasRatio) {
                const bx = M + 8, by = d.y + 20, bw = 150;
                const ratio = Math.max(0, Math.min(100, Number(row[2]) || 0));
                d.rect(bx, by, bw, 4, TRACK);
                if (ratio > 0) d.rect(bx, by, Math.max(1, bw * ratio / 100), 4, rowColor);
            }
            d.line(M, d.y + rowHeight - 1, PAGE_W - M, d.y + rowHeight - 1, mix(accent, 0.84), 0.6);
            d.y += rowHeight;
        });
        d.y += 16;
    }

    function build(report, opts) {
        report = report || {};
        if (report.type === 'hr') return buildHr(report, opts);
        if (report.type !== 'admin') throw new Error('Unsupported dashboard PDF report.');
        return buildAdmin(report, opts);
    }

    function selectRecords(title, groups) {
        return new Promise((resolve) => {
            const options = groups.reduce((all, group) => all.concat(group.options), []);
            if (!options.length) {
                resolve(null);
                return;
            }

            const dialog = document.createElement('dialog');
            dialog.className = 'dashboard-report-filter';
            dialog.setAttribute('aria-labelledby', 'dashboardReportFilterTitle');

            const content = document.createElement('div');
            content.className = 'dashboard-report-filter-content';

            const heading = document.createElement('h2');
            heading.id = 'dashboardReportFilterTitle';
            heading.textContent = title;
            content.appendChild(heading);

            const description = document.createElement('p');
            description.id = 'dashboardReportFilterDescription';
            description.className = 'dashboard-report-filter-description';
            description.textContent = 'Choose which dashboard records to include in the PDF.';
            dialog.setAttribute('aria-describedby', description.id);
            content.appendChild(description);

            const fields = document.createElement('div');
            fields.className = 'dashboard-report-filter-groups';
            const checkboxes = [];
            groups.forEach((group) => {
                if (!group.options.length) return;
                const fieldset = document.createElement('fieldset');
                fieldset.className = 'dashboard-report-filter-group';
                const legend = document.createElement('legend');
                legend.textContent = group.label;
                fieldset.appendChild(legend);

                group.options.forEach((option) => {
                    const label = document.createElement('label');
                    label.className = 'dashboard-report-filter-option';
                    const checkbox = document.createElement('input');
                    checkbox.type = 'checkbox';
                    checkbox.value = option.id;
                    checkbox.id = 'dashboardReportFilterOption' + checkboxes.length;
                    const text = document.createElement('span');
                    text.textContent = option.label;
                    label.append(checkbox, text);
                    fieldset.appendChild(label);
                    checkboxes.push(checkbox);
                });
                fields.appendChild(fieldset);
            });
            content.appendChild(fields);

            const actions = document.createElement('div');
            actions.className = 'dashboard-report-filter-actions';
            const cancel = document.createElement('button');
            cancel.type = 'button';
            cancel.className = 'is-cancel';
            cancel.textContent = 'Cancel';
            const all = document.createElement('button');
            all.type = 'button';
            all.className = 'is-all';
            all.textContent = 'Download All';
            const selected = document.createElement('button');
            selected.type = 'button';
            selected.className = 'is-selected';
            selected.textContent = 'Download Selected';
            selected.disabled = true;
            actions.append(cancel, all, selected);
            content.appendChild(actions);
            dialog.appendChild(content);
            document.body.appendChild(dialog);

            let finished = false;
            const finish = (result) => {
                if (finished) return;
                finished = true;
                if (dialog.open) dialog.close();
                dialog.remove();
                resolve(result);
            };
            const checkedOptions = () => checkboxes.filter((checkbox) => checkbox.checked);

            checkboxes.forEach((checkbox) => {
                checkbox.addEventListener('change', () => {
                    selected.disabled = checkedOptions().length === 0;
                });
            });
            cancel.addEventListener('click', () => finish(null));
            all.addEventListener('click', () => finish({ include: options.map((option) => option.id), includeAll: true }));
            selected.addEventListener('click', () => {
                const chosen = checkedOptions().map((checkbox) => checkbox.value);
                if (!chosen.length) return;
                finish({ include: chosen, includeAll: chosen.length === options.length });
            });
            dialog.addEventListener('cancel', (event) => {
                event.preventDefault();
                finish(null);
            });
            dialog.addEventListener('click', (event) => {
                if (event.target === dialog) finish(null);
            });
            dialog.showModal();
            cancel.focus();
        });
    }

    function buildAdmin(report, opts) {
        opts = opts || {};
        const stats = report.stats || {};
        const overview = report.overview || {};
        const failure = report.failure || {};
        const risk = report.risk || {};
        const volume = report.volume || {};
        const allRecords = !Array.isArray(report.include) || report.includeAll === true;
        const included = (id) => allRecords || report.include.indexOf(id) !== -1;
        const questions = (Array.isArray(stats.questions) ? stats.questions : [])
            .filter((question) => included('section:' + question.section_no));
        const sections = (Array.isArray(stats.sections) ? stats.sections : [])
            .filter((section) => included('section:' + section.no));
        const subs = Number(stats.submissions) || 0;
        const d = new Doc('Admin Dashboard Statistics');

        /* title band */
        addTitleBand(d, 'Admin Dashboard Statistics', 'Admin', Object.assign({}, opts, { scope: overview.scope || opts.scope }));

        if (allRecords) {
            drawSummaryCards(d, [
                { label: 'Active HR accounts', value: Number(overview.hr_active) || 0, color: [28, 138, 219] },
                { label: 'Questionnaires', value: overview.questions == null ? questions.length : (Number(overview.questions) || 0), color: [21, 154, 67] },
                { label: 'Sections', value: overview.sections == null ? sections.length : (Number(overview.sections) || 0), color: [246, 196, 83] },
                { label: 'Submitted questionnaires', value: subs, color: RED }
            ]);
        }

        if (included('failure')) {
            const failureRows = [['Knock-out questions', String(Number(failure.knockouts) || 0)],
                ['Submitted questionnaires', String(Number(failure.submissions) || 0)]];
            (Array.isArray(failure.rows) ? failure.rows : []).forEach(row => {
                const rate = Number(row.rate) || 0;
                failureRows.push([row.label || row.id || 'Question', (Number(row.failed) || 0) + ' failed / ' +
                    (Number(row.submissions) || 0) + ' submitted (' + pct(rate) + ')', rate, RED]);
            });
            drawRows(d, 'Question-Level Failure Rate', failureRows, 'Applicants who did not meet each active knock-out question.', RED);
        }

        if (included('risk')) {
            const riskRows = [
                ['Risk flag evaluations', String(Number(risk.evaluations) || 0)],
                ['Total flags raised', String(Number(risk.total) || 0)]
            ];
            (Array.isArray(risk.rows) ? risk.rows : []).forEach(row => {
                const rowColor = row.color ? rgb(row.color) : [120, 87, 195];
                riskRows.push([row.label || 'Other factors', (Number(row.count) || 0) + ' flags (' + pct(Number(row.share) || 0) + ')', Number(row.share) || 0, rowColor]);
            });
            drawRows(d, 'Risk Flag Breakdown by Factor', riskRows, 'Counts and share of the risk flags recorded.', [120, 87, 195]);
        }

        if (included('volume')) {
            const volumeRows = [];
            ['week', 'month'].forEach(period => {
                const group = volume[period] || {};
                const labels = Array.isArray(group.labels) ? group.labels : [];
                const ranges = Array.isArray(group.ranges) ? group.ranges : [];
                const values = Array.isArray(group.values) ? group.values : [];
                const max = Math.max(0, ...values.map(value => Number(value) || 0));
                const rowColor = period === 'week' ? [28, 138, 219] : [21, 154, 67];
                labels.forEach((label, i) => {
                    const count = Number(values[i]) || 0;
                    volumeRows.push([(period === 'week' ? 'Week ' : 'Month ') + label + (ranges[i] ? ' (' + ranges[i] + ')' : ''),
                        count + ' applicants', max > 0 ? count / max * 100 : 0, rowColor]);
                });
            });
            drawRows(d, 'Applicant Volume Over Time', volumeRows, 'Applications received per week and per month.', [21, 154, 67]);
        }

        /* sections table */
        const cols = { name: M + 10, count: M + 290, ans: M + 360, top: M + 440 };
        const drawSectionsHeader = (continued) => {
            d.ensure(42);
            d.text('Sections at a glance' + (continued ? ' (continued)' : ''), M, d.y, 12, { bold: true });
            d.y += 22;
            d.rect(M, d.y, CW, 20, [235, 236, 238]);
            d.text('Section', cols.name, d.y + 5.5, 8.5, { bold: true });
            d.text('Questions', cols.count, d.y + 5.5, 8.5, { bold: true });
            d.text('Answers', cols.ans, d.y + 5.5, 8.5, { bold: true });
            d.text('Most answered', cols.top, d.y + 5.5, 8.5, { bold: true });
            d.y += 20;
        };
        if (sections.length) {
            drawSectionsHeader(false);
            sections.forEach(s => {
                const pageCount = d.pages.length;
                d.ensure(20);
                if (d.pages.length !== pageCount) drawSectionsHeader(true);
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
        }

        /* every section with its questions */
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

    function buildHr(report, opts) {
        opts = opts || {};
        const stats = report.stats || {};
        const funnel = report.funnel || {};
        const duration = report.duration || {};
        const turnaround = report.turnaround || {};
        const allRecords = !Array.isArray(report.include) || report.includeAll === true;
        const included = (id) => allRecords || report.include.indexOf(id) !== -1;
        const stages = Array.isArray(funnel.stages) ? funnel.stages : [];
        const d = new Doc('HR Dashboard Statistics');

        addTitleBand(d, 'HR Dashboard Statistics', 'HR', opts);
        if (allRecords) {
            drawSummaryCards(d, [
                { label: 'Total applicants', value: Number(stats.total) || 0, color: RED },
                { label: 'Pending', value: Number(stats.pending) || 0, color: [246, 196, 83] },
                { label: 'In progress', value: Number(stats.in_progress) || 0, color: [28, 138, 219] },
                { label: 'Completed', value: Number(stats.completed) || 0, color: [21, 154, 67] }
            ]);
        }

        if (included('funnel')) {
            const funnelColors = ['#1c8adb', '#159a43', '#f0b100', '#f26a1b', '#d1122a'];
            const funnelRows = stages.map((stage, i) => [
                stage.label || 'Stage',
                (Number(stage.count) || 0) + ' (' + (Number(stage.of_applied) || 0) + '% of applicants); ' +
                    (Number(stage.lost) || 0) + ' dropped (' + (Number(stage.lost_pct) || 0) + '%)',
                Number(stage.of_applied) || 0,
                rgb(funnelColors[i % funnelColors.length])
            ]);
            funnelRows.push(['Reached an interview', (Number(funnel.overall_pct) || 0) + '% of applicants', Number(funnel.overall_pct) || 0, [21, 154, 67]]);
            if (funnel.biggest) funnelRows.push(['Biggest drop-off', funnel.biggest.from + ' to ' + funnel.biggest.to + ' (' + (Number(funnel.biggest.lost) || 0) + ')', Number(funnel.biggest.pct) || 0, [242, 106, 27]]);
            drawRows(d, 'Funnel Drop-Off', funnelRows, 'Applicant counts at each screening stage and drop-off from the prior stage.', [28, 138, 219]);
        }

        if (included('results')) {
            const passed = Number(stats.passed) || 0;
            const rejected = Number(stats.failed) || 0;
            const screened = passed + rejected;
            drawRows(d, 'Passed / Rejected', [
                ['Screened applicants', String(screened)],
                ['Passed', passed + (screened ? ' (' + pct(passed / screened * 100) + ')' : ''), screened ? passed / screened * 100 : 0, [21, 154, 67]],
                ['Rejected', rejected + (screened ? ' (' + pct(rejected / screened * 100) + ')' : ''), screened ? rejected / screened * 100 : 0, RED],
                ['Pass rate', (Number(report.pass_rate) || 0) + '%', Number(report.pass_rate) || 0, [21, 154, 67]]
            ], 'Results among applicants who completed screening.', [91, 95, 102]);
        }

        if (included('duration')) {
            const durationRows = [['Completed screenings with timing data', String(Number(duration.n) || 0)]];
            const labels = Array.isArray(duration.labels) ? duration.labels : [];
            const titles = Array.isArray(duration.titles) ? duration.titles : [];
            const counts = Array.isArray(duration.counts) ? duration.counts : [];
            labels.forEach((label, i) => {
                const count = Number(counts[i]) || 0;
                durationRows.push([titles[i] || label, count + ' screenings', Number(duration.n) > 0 ? count / Number(duration.n) * 100 : 0, [76, 151, 210]]);
            });
            durationRows.push(['Median', duration.median || 'No data']);
            durationRows.push(['Fastest', duration.fastest || 'No data']);
            durationRows.push(['Slowest', duration.slowest || 'No data']);
            drawRows(d, 'Time-to-Complete Distribution', durationRows, 'Time from invitation to questionnaire submission; all continued pages are included.', [76, 151, 210]);
        }

        if (included('turnaround')) {
            const avg = turnaround.avg;
            const avgLabel = avg ? (avg.label || ((avg.value || 0) + ' ' + (avg.unit || ''))) : 'No schedules yet';
            const turnaroundColor = turnaround.state === 'ok' ? [21, 154, 67] : (turnaround.state === 'warn' ? [246, 164, 45] : (turnaround.state === 'slow' ? RED : [91, 95, 102]));
            const turnaroundRows = [
                ['Average turnaround', avgLabel, avg ? Number(turnaround.bar_pct) || 0 : null, turnaroundColor],
                ['Target', turnaround.target_label || '3-day'],
                ['Scheduled interviews', String(Number(turnaround.n) || 0)],
                ['Passed, waiting for a schedule', String(Number(turnaround.waiting) || 0)],
                ['Fastest', turnaround.fastest || 'No data'],
                ['Slowest', turnaround.slowest || 'No data']
            ];
            drawRows(d, 'Scheduling Turnaround Time', turnaroundRows, 'Time between passing screening and HR entering the final interview schedule.', turnaroundColor);
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

    function recordDownload(report) {
        const meta = document.querySelector('meta[name="csrf-token"]');
        return fetch('../api/report-download.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
            body: JSON.stringify({ report: report, csrf_token: meta ? meta.content : '' })
        }).then(
            response => response.json().catch(() => { throw new Error('The server did not answer correctly. Please try again.'); }),
            () => { throw new Error('Could not reach the server. Please check your connection and try again.'); }
        ).then(data => {
            if (data.status !== 'success') throw new Error(data.message || 'The activity could not be recorded.');
            return data;
        });
    }

    window.DashboardPdf = { build: build, download: download, recordDownload: recordDownload, selectRecords: selectRecords };
})();
}

