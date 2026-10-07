/* ==========================================================================
   Questionnaire page.
   The entrance animation and the normal page change come from applicant-common.js; answers,
   validation and the progress pings come from screening-store.js (both loaded before this file).

   The questions are NOT written here: they come from window.SCREENING.questions, which
   questionnaire.php prints from static-data.php (later: the database). To change a question,
   its type or its choices, change the data, not this file.

   Question types:  yesno | yesno_select (Yes/No + a follow-up list) | scale | date | text | consent
   ========================================================================== */
document.addEventListener('DOMContentLoaded', function () {
    var cfg = window.SCREENING || { questions: [] };
    var questions = cfg.questions || [];
    var total = questions.length;
    if (!total) return;

    var stage = document.getElementById('qStage');
    var counterNow = document.getElementById('counterNow');
    var progress = document.getElementById('progress');
    var progressFill = document.getElementById('progressFill');
    var errorBox = document.getElementById('qError');
    var errorText = document.getElementById('qErrorText');
    var form = document.getElementById('qForm');
    var prevBtn = document.getElementById('prevBtn');
    var nextBtn = document.getElementById('nextBtn');
    var nextLabel = nextBtn.querySelector('.qbtn__label');
    var nextText = nextLabel.textContent;

    var reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    var answers = Screening.load();
    var index = Math.max(0, Math.min(total - 1, Screening.getIndex()));
    var busy = false;                       // a question is sliding in/out: ignore extra clicks

    /* ---------- small helpers ---------- */
    function h(tag, className, text) {
        var node = document.createElement(tag);
        if (className) node.className = className;
        if (text !== undefined && text !== null) node.textContent = text;
        return node;
    }

    function replay(el, className) {
        el.classList.remove(className);
        void el.offsetWidth;                // restart the animation
        el.classList.add(className);
    }

    var TICK = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12.5l4.5 4.5L19 7.5"/></svg>';
    var EXTERNAL = '<svg class="q__link-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M14 4h6v6M20 4l-9 9M18 14v5a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1h5"/></svg>';

    function save() { Screening.save(answers); }

    /* ---------- the message under the answer (one at a time) ---------- */
    function showError(message, answerBox) {
        errorText.textContent = message;
        errorBox.hidden = false;
        replay(errorBox, 'is-in');
        if (answerBox) replay(answerBox, 'shake');
    }

    function hideError() {
        if (!errorBox.hidden) errorBox.hidden = true;
    }

    // every change to an answer: save it and take the message away
    function changed() {
        save();
        hideError();
    }

    /* ---------- building one question ---------- */
    function questionText(el, q) {
        var parts = String(q.text).split('{link}');
        if (parts.length === 2 && q.link && q.link.url) {
            // the link text and address come from the data (static-data.php), they are not written here
            var a = h('a', 'q__link');
            a.href = q.link.url;
            a.target = '_blank';
            a.rel = 'noopener noreferrer';
            a.appendChild(document.createTextNode(q.link.text || 'link'));
            a.insertAdjacentHTML('beforeend', EXTERNAL);
            el.appendChild(document.createTextNode(parts[0]));
            el.appendChild(a);
            el.appendChild(document.createTextNode(parts[1]));
        } else {
            el.textContent = String(q.text).replace('{link}', q.link ? q.link.text : '');
        }
    }

    // roving focus for radio-like groups: arrows move AND select, like real radio buttons
    function radioKeys(group, items) {
        group.addEventListener('keydown', function (e) {
            var keys = { ArrowRight: 1, ArrowDown: 1, ArrowLeft: -1, ArrowUp: -1 };
            if (!(e.key in keys)) return;
            var i = items.indexOf(document.activeElement);
            if (i === -1) return;
            e.preventDefault();
            var next = items[(i + keys[e.key] + items.length) % items.length];
            next.focus();
            next.click();
        });
    }

    function setRoving(items) {
        var checked = items.filter(function (b) { return b.getAttribute('aria-checked') === 'true'; })[0];
        items.forEach(function (b) { b.tabIndex = (checked ? b === checked : b === items[0]) ? 0 : -1; });
    }

    function buildYesNo(q, box) {
        var group = h('div', 'choices');
        group.setAttribute('role', 'radiogroup');
        group.setAttribute('aria-label', 'Your answer');

        var follow = null;
        var note = null;

        function sync() {
            var v = answers[q.id];
            if (follow) follow.wrap.classList.toggle('open', !!(q.followup && v === q.followup.when));
            if (note) note.classList.toggle('open', !!(q.no_note && v === 'No'));
        }

        var buttons = ['Yes', 'No'].map(function (label, n) {
            var b = h('button', 'choice');
            b.type = 'button';
            b.setAttribute('role', 'radio');
            b.setAttribute('aria-checked', String(answers[q.id] === label));
            b.dataset.value = label;
            b.style.setProperty('--n', n);
            b.innerHTML = '<span class="choice__icon">' + TICK + '</span><span class="choice__label"></span>';
            b.querySelector('.choice__label').textContent = label;
            b.addEventListener('click', function () {
                answers[q.id] = label;
                buttons.forEach(function (x) { x.setAttribute('aria-checked', String(x === b)); });
                setRoving(buttons);
                replay(b, 'picked');
                sync();
                changed();
            });
            group.appendChild(b);
            return b;
        });
        setRoving(buttons);
        radioKeys(group, buttons);
        box.appendChild(group);

        // Yes/No + a list that opens when the answer is the one the follow-up belongs to (e.g. "Yes" -> notice period)
        if (q.followup) {
            var wrap = h('div', 'followup');
            var inner = h('div', 'followup__inner');
            var label = h('label', 'followup__label', q.followup.label);
            label.htmlFor = 'followSelect';
            var select = h('select', 'select');
            select.id = 'followSelect';
            var blank = h('option', null, 'Choose one');
            blank.value = '';
            select.appendChild(blank);
            (q.followup.options || []).forEach(function (opt) {
                var o = h('option', null, opt);
                o.value = opt;
                select.appendChild(o);
            });
            select.value = answers[q.id + ':follow'] || '';
            select.addEventListener('change', function () {
                answers[q.id + ':follow'] = select.value;
                changed();
            });
            inner.append(label, select);
            wrap.appendChild(inner);
            box.appendChild(wrap);
            follow = { wrap: wrap, select: select };
        }

        // optional gentle reminder when the answer is "No" (text comes from the data)
        if (q.no_note) {
            note = h('div', 'q__note');
            note.appendChild(h('p', null, q.no_note));
            box.appendChild(note);
        }

        sync();
        return { focus: function () { (buttons.filter(function (b) { return b.tabIndex === 0; })[0] || buttons[0]).focus({ preventScroll: true }); } };
    }

    function buildScale(q, box) {
        var max = q.max || 5;
        var group = h('div', 'scale');
        group.setAttribute('role', 'radiogroup');
        group.setAttribute('aria-label', 'Rating from 1 to ' + max);

        function paint() {
            var v = Number(answers[q.id]) || 0;
            dots.forEach(function (d, i) {
                d.classList.toggle('is-on', i + 1 <= v);
                d.setAttribute('aria-checked', String(i + 1 === v));
            });
            setRoving(dots);
        }

        var dots = [];
        for (var i = 1; i <= max; i++) {
            (function (n) {
                var d = h('button', 'scale__dot', String(n));
                d.type = 'button';
                d.setAttribute('role', 'radio');
                d.style.setProperty('--n', n - 1);
                d.addEventListener('click', function () {
                    var before = Number(answers[q.id]) || 0;
                    answers[q.id] = n;
                    // the dots fill from the left (or empty from the right) one after another
                    dots.forEach(function (x, k) { x.style.setProperty('--d', Math.abs(k + 1 - (before || 0)) ); });
                    paint();
                    replay(d, 'picked');
                    changed();
                });
                dots.push(d);
                group.appendChild(d);
            })(i);
        }
        paint();
        radioKeys(group, dots);
        box.appendChild(group);

        var labels = h('div', 'scale__labels');
        var l = (q.labels || []);
        labels.append(h('span', null, l[0] || ''), h('span', null, l[1] || ''));
        box.appendChild(labels);

        return { focus: function () { (dots.filter(function (b) { return b.tabIndex === 0; })[0] || dots[0]).focus({ preventScroll: true }); } };
    }

    function buildDate(q, box) {
        var field = h('div', 'datefield');
        var label = h('label', 'datefield__label', q.hint || 'Choose a date');
        label.htmlFor = 'answerInput';
        var input = h('input', 'input');
        input.type = 'date';
        input.id = 'answerInput';
        input.min = Screening.today();
        input.value = answers[q.id] || '';
        input.addEventListener('input', function () {
            answers[q.id] = input.value;
            changed();
        });
        field.append(label, input);
        box.appendChild(field);
        return { focus: function () { input.focus({ preventScroll: true }); } };
    }

    function buildText(q, box) {
        var max = q.max_chars || 500;
        if (q.hint) box.appendChild(h('p', 'q__hint', q.hint));

        var wrap = h('div', 'textbox');
        var area = h('textarea', 'textbox__input');
        area.id = 'answerInput';
        area.maxLength = max;
        area.rows = 6;
        area.placeholder = q.placeholder || '';
        area.value = answers[q.id] || '';
        area.setAttribute('aria-label', 'Your answer');
        var count = h('span', 'textbox__count');

        function paintCount() {
            count.textContent = area.value.length + '/' + max;
            count.classList.toggle('at-limit', area.value.length >= max);
        }
        area.addEventListener('input', function () {
            answers[q.id] = area.value;
            paintCount();
            changed();
        });
        paintCount();
        wrap.append(area, count);
        box.appendChild(wrap);
        return { focus: function () { area.focus({ preventScroll: true }); } };
    }

    function buildConsent(q, box) {
        var body = h('div', 'consent__body');
        body.tabIndex = 0;                                   // so the text can be scrolled with the keyboard
        body.setAttribute('role', 'region');
        body.setAttribute('aria-label', 'Data protection statement');
        (q.body || []).forEach(function (p) { body.appendChild(h('p', null, p)); });
        box.appendChild(body);

        var label = h('label', 'check');
        var input = h('input');
        input.type = 'checkbox';
        input.className = 'check__input';
        input.checked = answers[q.id] === true;
        var mark = h('span', 'check__box');
        mark.innerHTML = TICK;
        label.append(input, mark, h('span', 'check__text', q.checkbox || 'I agree'));
        input.addEventListener('change', function () {
            answers[q.id] = input.checked;
            if (input.checked) replay(mark, 'picked');
            changed();
        });
        box.appendChild(label);
        return { focus: function () { input.focus({ preventScroll: true }); } };
    }

    function build(q, first) {
        var el = h('section', 'q' + (first ? ' q--first' : ''));
        el.dataset.id = q.id;

        el.appendChild(h('p', 'q__section', 'Section ' + q.section_no + ' \u00B7 ' + q.section));
        var title = h('h1', 'q__text');
        questionText(title, q);
        el.appendChild(title);

        var box = h('div', 'q__answer');
        var api;
        if (q.type === 'scale') api = buildScale(q, box);
        else if (q.type === 'date') api = buildDate(q, box);
        else if (q.type === 'text') api = buildText(q, box);
        else if (q.type === 'consent') api = buildConsent(q, box);
        else api = buildYesNo(q, box);                      // yesno + yesno_select
        el.appendChild(box);

        el._box = box;
        el._focus = api.focus;
        return el;
    }

    /* ---------- showing a question (with the slide) ---------- */
    function paintChrome() {
        counterNow.textContent = String(index + 1);
        replay(counterNow, 'tick');
        progressFill.style.setProperty('--p', ((index + 1) / total).toFixed(4));
        progress.setAttribute('aria-valuenow', String(index + 1));

        var last = index === total - 1;
        nextText = last ? 'Continue to Video' : 'Next';
        nextLabel.textContent = nextText;
        prevBtn.querySelector('.qbtn__label').textContent = 'Previous';
    }

    // dir: 1 = forward (new one comes from the right), -1 = back, 0 = first draw
    function show(dir) {
        Screening.setIndex(index);
        hideError();
        paintChrome();

        var incoming = build(questions[index], dir === 0);
        var outgoing = stage.querySelector('.q');

        if (!outgoing || dir === 0 || reduceMotion) {
            stage.textContent = '';
            stage.appendChild(incoming);
            if (dir !== 0) incoming._focus();
            return;
        }

        busy = true;
        outgoing.classList.add(dir > 0 ? 'q--out-left' : 'q--out-right');
        incoming.classList.add(dir > 0 ? 'q--in-right' : 'q--in-left');
        stage.appendChild(incoming);
        setTimeout(function () {
            outgoing.remove();
            incoming.classList.remove('q--in-right', 'q--in-left');
            busy = false;
            incoming._focus();
        }, 460);
    }

    /* ---------- Next / Previous ---------- */
    form.addEventListener('submit', function (e) {
        e.preventDefault();
        if (busy || nextBtn.classList.contains('is-loading')) return;

        var q = questions[index];
        var problem = Screening.validate(q, answers);
        if (problem) {
            showError(problem, stage.querySelector('.q__answer'));
            return;
        }
        save();

        if (index < total - 1) {
            index++;
            show(1);
            return;
        }

        // last question: on to the video
        nextBtn.classList.add('is-loading');
        nextLabel.textContent = 'Loading...';
        Applicant.go(cfg.nextPage || 'upload-video.php');
    });

    prevBtn.addEventListener('click', function () {
        if (busy || nextBtn.classList.contains('is-loading')) return;
        if (index === 0) {
            Applicant.go(cfg.backPage || 'instruction.php');
            return;
        }
        index--;
        show(-1);
    });

    // Keyboard shortcuts (only when the applicant is not typing):  Y / N answer a Yes-No question
    document.addEventListener('keydown', function (e) {
        if (e.ctrlKey || e.metaKey || e.altKey) return;
        var t = e.target;
        var typing = t && (t.tagName === 'TEXTAREA' || t.tagName === 'INPUT' || t.tagName === 'SELECT');
        if (typing) return;
        var q = questions[index];
        if ((q.type === 'yesno' || q.type === 'yesno_select') && (e.key === 'y' || e.key === 'Y' || e.key === 'n' || e.key === 'N')) {
            var want = e.key.toLowerCase() === 'y' ? 'Yes' : 'No';
            var btn = stage.querySelector('.choice[data-value="' + want + '"]');
            if (btn) { btn.focus({ preventScroll: true }); btn.click(); }
        }
    });

    // Browser Back (page restored from cache): undo the loading state
    window.addEventListener('pageshow', function () {
        nextBtn.classList.remove('is-loading');
        nextLabel.textContent = nextText;
    });

    /* ---------- start ---------- */
    Screening.startOnce();          // tells the HR lists this applicant is now "In Progress"
    show(0);
});