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