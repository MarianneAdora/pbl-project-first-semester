/* ==========================================================================
   Questionnaire page.
   The entrance animation and the normal page change come from applicant-common.js; answers,
   validation and the progress pings come from screening-store.js (both loaded before this file).

   The questions are NOT written here: they come from window.SCREENING.questions, which
   questionnaire.php prints from static-data.php (later: the database). To change a question,
   its type or its choices, change the data, not this file.

   Question types:  yesno | yesno_select (Yes/No + a follow-up list) | scale (circles) | date (calendar) | text | consent
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

    /* ---------- dropdown (a styled list instead of the browser's own select) ----------
       options: ['A', 'B']   value: '' or one of them   onChange(value)
       Keyboard: Enter / Space / arrows open it, arrows move, Enter picks, Esc closes.              */
    var CHEVRON_DOWN = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 9l6 6 6-6"/></svg>';
    var dropdownCount = 0;

    function buildDropdown(opts) {
        var id = 'dd' + (++dropdownCount);
        var value = opts.value || '';
        var activeIndex = -1;

        var root = h('div', 'dropdown');
        var btn = h('button', 'dropdown__btn');
        btn.type = 'button';
        btn.id = id + 'Btn';
        btn.setAttribute('aria-haspopup', 'listbox');
        btn.setAttribute('aria-expanded', 'false');
        btn.setAttribute('aria-controls', id + 'List');
        if (opts.labelId) btn.setAttribute('aria-labelledby', opts.labelId + ' ' + btn.id);
        var current = h('span', 'dropdown__value');
        var chevron = h('span', 'dropdown__chevron');
        chevron.innerHTML = CHEVRON_DOWN;
        btn.append(current, chevron);

        var list = h('ul', 'dropdown__list');
        list.id = id + 'List';
        list.setAttribute('role', 'listbox');
        list.tabIndex = -1;
        list.hidden = true;

        var items = (opts.options || []).map(function (text, n) {
            var li = h('li', 'dropdown__item');
            li.id = id + 'Opt' + n;
            li.setAttribute('role', 'option');
            li.dataset.value = text;
            li.style.setProperty('--n', n);
            li.innerHTML = '<span class="dropdown__text"></span><span class="dropdown__tick">' + TICK + '</span>';
            li.querySelector('.dropdown__text').textContent = text;
            li.addEventListener('mousemove', function () { setActive(n, false); });
            li.addEventListener('click', function () { pick(n); });
            list.appendChild(li);
            return li;
        });

        root.append(btn, list);

        function paint() {
            current.textContent = value || opts.placeholder || 'Choose one';
            root.classList.toggle('has-value', !!value);
            items.forEach(function (li) {
                var on = li.dataset.value === value;
                li.setAttribute('aria-selected', String(on));
                li.classList.toggle('is-selected', on);
            });
        }

        function setActive(n, scroll) {
            activeIndex = Math.max(0, Math.min(items.length - 1, n));
            items.forEach(function (li, k) { li.classList.toggle('is-active', k === activeIndex); });
            btn.setAttribute('aria-activedescendant', items[activeIndex] ? items[activeIndex].id : '');
            if (scroll && items[activeIndex]) items[activeIndex].scrollIntoView({ block: 'nearest' });
        }

        function isOpen() { return !list.hidden; }

        function open() {
            if (isOpen() || !items.length) return;
            // not enough room below: the list opens upwards
            var room = window.innerHeight - btn.getBoundingClientRect().bottom;
            root.classList.toggle('is-up', room < list.scrollHeight + 40 && btn.getBoundingClientRect().top > room);
            list.hidden = false;
            root.classList.add('is-open');
            btn.setAttribute('aria-expanded', 'true');
            var sel = items.findIndex(function (li) { return li.dataset.value === value; });
            setActive(sel === -1 ? 0 : sel, true);
            replay(list, 'dropdown__list--in');
        }

        function close(refocus) {
            if (!isOpen()) return;
            list.hidden = true;
            root.classList.remove('is-open');
            btn.setAttribute('aria-expanded', 'false');
            btn.removeAttribute('aria-activedescendant');
            if (refocus) btn.focus({ preventScroll: true });
        }

        function pick(n) {
            var chosen = items[n].dataset.value;
            var changedNow = chosen !== value;
            value = chosen;
            paint();
            close(true);
            replay(btn, 'picked');
            if (changedNow) opts.onChange(value);
        }

        btn.addEventListener('click', function () { isOpen() ? close(false) : open(); });
        btn.addEventListener('keydown', function (e) {
            var k = e.key;
            if (!isOpen()) {
                if (k === 'ArrowDown' || k === 'ArrowUp') { e.preventDefault(); open(); }
                return;
            }
            if (k === 'ArrowDown') { e.preventDefault(); setActive(activeIndex + 1, true); }
            else if (k === 'ArrowUp') { e.preventDefault(); setActive(activeIndex - 1, true); }
            else if (k === 'Home') { e.preventDefault(); setActive(0, true); }
            else if (k === 'End') { e.preventDefault(); setActive(items.length - 1, true); }
            else if (k === 'Enter' || k === ' ') { e.preventDefault(); pick(activeIndex); }
            else if (k === 'Escape') { e.preventDefault(); e.stopPropagation(); close(true); }
            else if (k === 'Tab') { close(false); }
        });
        // clicking anywhere else closes it
        document.addEventListener('pointerdown', function (e) {
            if (isOpen() && !root.contains(e.target)) close(false);
        });

        paint();
        return { el: root, close: function () { close(false); }, focus: function () { btn.focus({ preventScroll: true }); } };
    }

    function buildYesNo(q, box) {
        var group = h('div', 'choices');
        group.setAttribute('role', 'radiogroup');
        group.setAttribute('aria-label', 'Your answer');

        var follow = null;
        var note = null;

        function sync() {
            var v = answers[q.id];
            if (follow) {
                var open = !!(q.followup && v === q.followup.when);
                follow.wrap.classList.toggle('open', open);
                clearTimeout(follow.timer);
                if (open) {
                    // the list may only hang out of the box once the box has finished unfolding
                    follow.timer = setTimeout(function () { follow.wrap.classList.add('settled'); }, reduceMotion ? 0 : 480);
                } else {
                    follow.wrap.classList.remove('settled');
                    follow.dropdown.close();
                }
            }
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
            var label = h('span', 'followup__label', q.followup.label);
            label.id = 'followLabel';
            var dd = buildDropdown({
                labelId: 'followLabel',
                placeholder: 'Choose one',
                options: q.followup.options || [],
                value: answers[q.id + ':follow'] || '',
                onChange: function (value) {
                    answers[q.id + ':follow'] = value;
                    changed();
                }
            });
            inner.append(label, dd.el);
            wrap.appendChild(inner);
            box.appendChild(wrap);
            follow = { wrap: wrap, dropdown: dd };
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

    /* ---------- rating: circles joined by a line ----------
       hover   -> preview (the line and the circles up to the pointer light up)
       click   -> the line grows to the chosen circle, the ones before it turn red, the chosen one pops
       keys    -> 1..max choose, arrows move                                                        */
    function buildScale(q, box) {
        var max = q.max || 5;
        var group = h('div', 'scale');
        group.style.setProperty('--cols', max);
        group.setAttribute('role', 'radiogroup');
        group.setAttribute('aria-label', 'Rating from 1 to ' + max);

        var rail = h('span', 'scale__rail');
        rail.setAttribute('aria-hidden', 'true');
        rail.innerHTML = '<i class="pv"></i><i class="fill"></i>';
        group.appendChild(rail);

        var dots = [];
        var readout = h('span', 'scale__readout');
        readout.setAttribute('aria-live', 'polite');

        function frac(n) { return max > 1 ? Math.max(0, (n - 1) / (max - 1)) : 0; }

        function paint(animateReadout) {
            var v = Number(answers[q.id]) || 0;
            group.style.setProperty('--fill', frac(v).toFixed(4));
            dots.forEach(function (d, i) {
                d.classList.toggle('is-on', i + 1 === v);
                d.classList.toggle('is-reached', v > 0 && i + 1 < v);
                d.setAttribute('aria-checked', String(i + 1 === v));
            });
            setRoving(dots);
            readout.classList.toggle('has-value', v > 0);
            readout.textContent = v > 0 ? v + ' / ' + max : '';
            if (v > 0 && animateReadout) replay(readout, 'tick');
        }

        function preview(n) {
            group.style.setProperty('--pv', frac(n).toFixed(4));
            dots.forEach(function (d, i) { d.classList.toggle('is-preview', n > 0 && i + 1 <= n && i + 1 !== (Number(answers[q.id]) || 0)); });
        }

        for (var i = 1; i <= max; i++) {
            (function (n) {
                var d = h('button', 'scale__dot', String(n));
                d.type = 'button';
                d.setAttribute('role', 'radio');
                d.setAttribute('aria-label', n + ' out of ' + max);
                d.style.setProperty('--n', n - 1);
                d.addEventListener('click', function () {
                    answers[q.id] = n;
                    preview(0);
                    paint(true);
                    replay(d, 'picked');
                    changed();
                });
                d.addEventListener('pointerenter', function () { preview(n); });
                d.addEventListener('focus', function () { preview(n); });
                d.addEventListener('blur', function () { preview(0); });
                dots.push(d);
                group.appendChild(d);
            })(i);
        }
        group.addEventListener('pointerleave', function () { preview(0); });
        paint(false);
        radioKeys(group, dots);
        box.appendChild(group);

        var labels = h('div', 'scale__labels');
        var l = (q.labels || []);
        labels.append(h('span', null, l[0] || ''), readout, h('span', null, l[1] || ''));
        box.appendChild(labels);

        return { focus: function () { (dots.filter(function (b) { return b.tabIndex === 0; })[0] || dots[0]).focus({ preventScroll: true }); } };
    }

    /* ---------- date: a calendar + quick picks + a summary of the chosen day ---------- */
    var MONTHS = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
    var WEEKDAYS = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
    var CHEVRON = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M9 6l6 6-6 6"/></svg>';

    function ymd(d) {
        function p(n) { return (n < 10 ? '0' : '') + n; }
        return d.getFullYear() + '-' + p(d.getMonth() + 1) + '-' + p(d.getDate());
    }
    function fromYmd(s) {
        var p = String(s).split('-');
        return new Date(Number(p[0]), Number(p[1]) - 1, Number(p[2]));
    }
    function addDays(d, n) { var x = new Date(d.getFullYear(), d.getMonth(), d.getDate()); x.setDate(x.getDate() + n); return x; }

    function buildDate(q, box) {
        var todayStr = Screening.today();
        var today = fromYmd(todayStr);
        var picked = answers[q.id] && answers[q.id] >= todayStr ? answers[q.id] : '';
        if (!picked && answers[q.id]) { delete answers[q.id]; save(); }   // a date in the past is no longer valid
        var view = fromYmd(picked || todayStr);
        view.setDate(1);

        var wrap = h('div', 'datepick');

        /* --- the calendar --- */
        var cal = h('div', 'cal');
        cal.setAttribute('role', 'group');
        cal.setAttribute('aria-label', q.hint || 'Choose a date');
        var head = h('div', 'cal__head');
        var prev = h('button', 'cal__nav cal__nav--prev');
        prev.type = 'button';
        prev.setAttribute('aria-label', 'Previous month');
        prev.innerHTML = CHEVRON;
        var next = h('button', 'cal__nav');
        next.type = 'button';
        next.setAttribute('aria-label', 'Next month');
        next.innerHTML = CHEVRON;
        var title = h('p', 'cal__title');
        title.setAttribute('aria-live', 'polite');
        head.append(prev, title, next);
        cal.appendChild(head);

        var dow = h('div', 'cal__dow');
        ['Su', 'Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa'].forEach(function (d) { dow.appendChild(h('span', null, d)); });
        cal.appendChild(dow);
        var grid = h('div', 'cal__grid');
        grid.setAttribute('role', 'grid');
        cal.appendChild(grid);

        /* --- the right side: quick picks + the chosen day --- */
        var side = h('div', 'dateside');
        side.appendChild(h('p', 'dateside__label', 'Quick picks'));
        var chips = h('div', 'chips');
        var nextMonday = addDays(today, ((8 - today.getDay()) % 7) || 7);
        var oneMonth = new Date(today.getFullYear(), today.getMonth() + 1, today.getDate());
        [['Today', today], ['Next Monday', nextMonday], ['In 2 weeks', addDays(today, 14)], ['In 1 month', oneMonth]].forEach(function (c) {
            var chip = h('button', 'chip', c[0]);
            chip.type = 'button';
            chip.dataset.date = ymd(c[1]);
            chip.addEventListener('click', function () { choose(ymd(c[1]), true); });
            chips.appendChild(chip);
        });
        side.appendChild(chips);

        var sum = h('div', 'daysum');
        var sumDay = h('span', 'daysum__day');
        var sumLine = h('span', 'daysum__line');
        var sumNote = h('span', 'daysum__note');
        var sumText = h('span', 'daysum__text');
        sumText.append(sumLine, sumNote);
        sum.append(sumDay, sumText);
        side.appendChild(sum);

        wrap.append(cal, side);
        box.appendChild(wrap);

        function paintSummary(animate) {
            if (!picked) {
                sum.classList.remove('is-set');
                sumDay.textContent = '--';
                sumLine.textContent = 'No date chosen yet';
                sumNote.textContent = 'Pick a day on the calendar.';
                return;
            }
            var d = fromYmd(picked);
            var diff = Math.round((d - today) / 86400000);
            sum.classList.add('is-set');
            sumDay.textContent = String(d.getDate());
            sumLine.textContent = WEEKDAYS[d.getDay()] + ', ' + MONTHS[d.getMonth()] + ' ' + d.getFullYear();
            sumNote.textContent = diff === 0 ? 'Today' : diff === 1 ? 'Tomorrow' : 'In ' + diff + ' days';
            if (animate) replay(sum, 'bump');
        }

        function draw(dir) {
            title.textContent = MONTHS[view.getMonth()] + ' ' + view.getFullYear();
            prev.disabled = view.getFullYear() === today.getFullYear() && view.getMonth() <= today.getMonth();

            grid.textContent = '';
            var first = new Date(view.getFullYear(), view.getMonth(), 1);
            var days = new Date(view.getFullYear(), view.getMonth() + 1, 0).getDate();
            for (var i = 0; i < first.getDay(); i++) grid.appendChild(h('span', 'cal__blank'));
            for (var n = 1; n <= days; n++) {
                (function (day) {
                    var date = new Date(view.getFullYear(), view.getMonth(), day);
                    var key = ymd(date);
                    var b = h('button', 'cal__day', String(day));
                    b.type = 'button';
                    b.dataset.date = key;
                    b.setAttribute('aria-label', WEEKDAYS[date.getDay()] + ', ' + MONTHS[date.getMonth()] + ' ' + day + ', ' + date.getFullYear());
                    b.style.setProperty('--n', day);
                    if (key < todayStr) b.disabled = true;
                    if (key === todayStr) b.classList.add('is-today');
                    if (key === picked) { b.classList.add('is-selected'); b.setAttribute('aria-pressed', 'true'); }
                    b.tabIndex = (picked ? key === picked : key === todayStr) ? 0 : -1;
                    b.addEventListener('click', function () { choose(key, false); });
                    grid.appendChild(b);
                })(n);
            }
            // a month with no picked / today button: the first free day takes the tab stop
            if (!grid.querySelector('.cal__day[tabindex="0"]')) {
                var firstFree = grid.querySelector('.cal__day:not(:disabled)');
                if (firstFree) firstFree.tabIndex = 0;
            }
            chips.querySelectorAll('.chip').forEach(function (c) { c.classList.toggle('is-on', c.dataset.date === picked); });
            if (dir) replay(grid, dir > 0 ? 'cal__grid--next' : 'cal__grid--prev');
        }

        function choose(key, fromChip) {
            picked = key;
            answers[q.id] = key;
            var d = fromYmd(key);
            var target = new Date(d.getFullYear(), d.getMonth(), 1);
            var dir = target > view ? 1 : target < view ? -1 : 0;   // which way the month slides
            view = target;
            draw(dir);
            paintSummary(true);
            var btn = grid.querySelector('.cal__day[data-date="' + key + '"]');
            if (btn) { replay(btn, 'picked'); if (!fromChip) btn.focus({ preventScroll: true }); }
            changed();
        }

        function go(delta) {
            view = new Date(view.getFullYear(), view.getMonth() + delta, 1);
            draw(delta);
        }
        prev.addEventListener('click', function () { if (!prev.disabled) go(-1); });
        next.addEventListener('click', function () { go(1); });

        // arrow keys move between days (a week up / down), Home / End jump to the start / end of the month
        grid.addEventListener('keydown', function (e) {
            var step = { ArrowLeft: -1, ArrowRight: 1, ArrowUp: -7, ArrowDown: 7 }[e.key];
            var cur = document.activeElement;
            if (!cur || !cur.dataset || !cur.dataset.date) return;
            if (step === undefined) return;
            e.preventDefault();
            var target = addDays(fromYmd(cur.dataset.date), step);
            if (ymd(target) < todayStr) return;
            if (target.getMonth() !== view.getMonth() || target.getFullYear() !== view.getFullYear()) {
                view = new Date(target.getFullYear(), target.getMonth(), 1);
                draw(step > 0 ? 1 : -1);
            }
            var btn = grid.querySelector('.cal__day[data-date="' + ymd(target) + '"]');
            if (btn) { grid.querySelectorAll('.cal__day').forEach(function (x) { x.tabIndex = -1; }); btn.tabIndex = 0; btn.focus({ preventScroll: true }); }
        });

        draw(0);
        paintSummary(false);
        return { focus: function () { var t = grid.querySelector('.cal__day[tabindex="0"]'); if (t) t.focus({ preventScroll: true }); } };
    }

    function buildText(q, box) {
        var max = q.max_chars || 500;
        if (q.hint) box.appendChild(h('p', 'q__hint', q.hint));

        var wrap = h('div', 'textbox');
        var area = h('textarea', 'textbox__input');
        area.id = 'answerInput';
        area.maxLength = max;
        area.rows = 4;
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

    var SHIELD = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3l7 3v5c0 4.5-3 8.2-7 10-4-1.8-7-5.5-7-10V6z"/><path d="M9 12l2.2 2.2L15.5 10"/></svg>';

    function buildConsent(q, box) {
        var wrap = h('div', 'consent');

        /* the notice: icon + title, the text (scrolls), a line that fills while it is read */
        var card = h('div', 'consent__card');
        var head = h('div', 'consent__head');
        var shield = h('span', 'consent__shield');
        shield.innerHTML = SHIELD;
        var heading = h('div');
        heading.append(h('p', 'consent__title', 'Data Privacy Notice'), h('p', 'consent__sub', 'Please read this before you continue'));
        var read = h('span', 'consent__read');
        read.setAttribute('aria-hidden', 'true');
        head.append(shield, heading, read);

        var scroller = h('div', 'consent__scroll');
        var body = h('div', 'consent__body');
        body.tabIndex = 0;                                   // so the text can be scrolled with the keyboard
        body.setAttribute('role', 'region');
        body.setAttribute('aria-label', 'Data protection statement');
        (q.body || []).forEach(function (p) { body.appendChild(h('p', null, p)); });
        var fade = h('div', 'consent__fade', 'Scroll for more');
        fade.setAttribute('aria-hidden', 'true');
        scroller.append(body, fade);
        card.append(head, scroller);
        wrap.appendChild(card);

        function measure() {
            var room = body.scrollHeight - body.clientHeight;
            var pos = room > 2 ? body.scrollTop / room : 1;
            card.style.setProperty('--read', Math.min(1, Math.max(0, pos)).toFixed(3));
            read.style.setProperty('--read', Math.min(1, Math.max(0, pos)).toFixed(3));
            scroller.classList.toggle('no-scroll', room <= 2);
            scroller.classList.toggle('is-end', room > 2 && body.scrollTop >= room - 4);
        }
        body.addEventListener('scroll', measure, { passive: true });
        window.addEventListener('resize', measure);
        setTimeout(measure, 60);

        /* the big "I agree" tile */
        var label = h('label', 'agree');
        var input = h('input', 'agree__input');
        input.type = 'checkbox';
        input.checked = answers[q.id] === true;
        var mark = h('span', 'agree__box');
        mark.innerHTML = TICK;
        var badge = h('span', 'agree__badge');

        function paintAgree() {
            label.classList.toggle('is-on', input.checked);
            badge.textContent = input.checked ? 'Agreed' : 'Required';
        }
        input.addEventListener('change', function () {
            answers[q.id] = input.checked;
            paintAgree();
            if (input.checked) replay(mark, 'picked');
            changed();
        });
        paintAgree();
        label.append(input, mark, h('span', 'agree__text', q.checkbox || 'I agree'), badge);
        wrap.appendChild(label);

        box.appendChild(wrap);
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
        Screening.lap(questions[index].id);           // time spent on each question (for the screening evaluation)
        var outgoing = stage.querySelector('.q');

        if (!outgoing || dir === 0 || reduceMotion) {
            stage.textContent = '';
            stage.appendChild(incoming);
            if (dir !== 0) incoming._focus();
            return;
        }

        // one calm step: the old question leaves, then the new one comes in (they never overlap)
        busy = true;
        outgoing.classList.add(dir > 0 ? 'q--out-left' : 'q--out-right');
        setTimeout(function () {
            stage.textContent = '';
            incoming.classList.add(dir > 0 ? 'q--in-right' : 'q--in-left');
            stage.appendChild(incoming);
            setTimeout(function () {
                incoming.classList.remove('q--in-right', 'q--in-left');
                busy = false;
                incoming._focus();
            }, 400);
        }, 230);
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
        Screening.lap(null);
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
        var typing = t && (t.tagName === 'TEXTAREA' || t.tagName === 'INPUT' || t.tagName === 'SELECT' || (t.closest && t.closest('.dropdown')));
        if (typing) return;
        var q = questions[index];
        if ((q.type === 'yesno' || q.type === 'yesno_select') && (e.key === 'y' || e.key === 'Y' || e.key === 'n' || e.key === 'N')) {
            var want = e.key.toLowerCase() === 'y' ? 'Yes' : 'No';
            var btn = stage.querySelector('.choice[data-value="' + want + '"]');
            if (btn) { btn.focus({ preventScroll: true }); btn.click(); }
        }
        // rating: press 1 .. 5 to choose
        if (q.type === 'scale' && /^[0-9]$/.test(e.key)) {
            var dot = stage.querySelectorAll('.scale__dot')[Number(e.key) - 1];
            if (dot) { dot.focus({ preventScroll: true }); dot.click(); }
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