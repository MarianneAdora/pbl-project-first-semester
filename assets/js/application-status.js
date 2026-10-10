/* ==========================================================================
   Application status page.
   The entrance animation and the normal page change come from applicant-common.js.

   1. EVALUATION: the "Evaluating Your Screening" page plays for a few seconds. The comment under the title
      changes while a bar fills, then the tracker completes and the result page replaces it
      (html gets the class "eval-done", CSS swaps the two views). A refresh skips it.
   2. SCHEDULE (only when the applicant passed): pick a weekday + a time, see the chosen schedule, confirm.
      The choice is sent to this same page (application-status.php), which keeps it and shows the
      "Interview Schedule" page.
   ========================================================================== */
document.addEventListener('DOMContentLoaded', function () {
    var root = document.documentElement;
    var reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    /* ---------- 1. the evaluation ---------- */
    var evalView = document.getElementById('evalView');

    if (evalView && !root.classList.contains('eval-done')) {
        var line = document.getElementById('evalLine');
        var bar = document.getElementById('evalBar');
        var fill = bar.querySelector('span');
        var steps = document.querySelectorAll('#evalSteps .step');
        var duration = parseInt(evalView.dataset.duration, 10) || 8000;
        var comments = [];
        try { comments = JSON.parse(evalView.dataset.comments || '[]'); } catch (e) { comments = []; }
        var seenKey = evalView.dataset.seen || '';
        var startedAt = Date.now();
        var shown = 0;
        var finished = false;

        function setLine(text) {
            if (reduceMotion) { line.textContent = text; return; }
            line.classList.add('is-out');                       // the old comment fades up, the new one comes in
            setTimeout(function () {
                line.textContent = text;
                line.classList.remove('is-out');
            }, 260);
        }

        function frame() {
            if (finished) return;
            var t = Math.min(1, (Date.now() - startedAt) / duration);
            var pct = Math.round(t * 100);
            fill.style.width = pct + '%';
            bar.setAttribute('aria-valuenow', String(pct));

            // one comment per equal slice of the time (the last one stays until the end)
            if (comments.length) {
                var want = Math.min(comments.length - 1, Math.floor(t * comments.length));
                if (want !== shown) { shown = want; setLine(comments[want]); }
            }

            if (t >= 1) { finish(); return; }
            requestAnimationFrame(frame);
        }

        function finish() {
            finished = true;
            // the tracker completes, then the result takes over
            if (steps.length === 3) {
                steps[1].classList.remove('is-current');
                steps[1].classList.add('is-done');
                steps[1].querySelector('.step__dot').innerHTML = '<svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true"><path d="M5 12.5l4.5 4.5L19 7.5"/></svg>';
                steps[2].classList.remove('is-upcoming');
                steps[2].classList.add('is-current');
                steps[2].setAttribute('aria-current', 'step');
                steps[1].removeAttribute('aria-current');
            }
            line.textContent = 'Your result is ready.';
            setTimeout(function () {
                try { sessionStorage.setItem('expertise.status.seen', seenKey); } catch (e) { /* nothing to do */ }
                root.classList.add('eval-done');
                window.scrollTo(0, 0);
            }, reduceMotion ? 300 : 900);
        }

        requestAnimationFrame(frame);
    }

    /* ---------- 3. waiting for HR: ask the page every few seconds if HR answered ---------- */
    var ivView = document.querySelector('.view--interview');
    if (ivView && ivView.dataset.state === 'pending') {
        var knownSig = ivView.dataset.sig || '';
        var pollTimer = null;
        var checkHr = function () {
            if (document.hidden) return;
            fetch(window.location.pathname + '?status=1', {
                credentials: 'same-origin', cache: 'no-store', headers: { 'Accept': 'application/json' }
            }).then(function (res) { return res.json(); }).then(function (data) {
                if (data && data.status === 'success' && data.sig && data.sig !== knownSig) {
                    clearInterval(pollTimer);
                    Applicant.go(window.location.pathname);          // HR answered: show the new page
                }
            }).catch(function () { /* offline: try again next time */ });
        };
        pollTimer = setInterval(checkHr, 8000);
        document.addEventListener('visibilitychange', checkHr);
    }

    /* ---------- 2. the interview schedule ---------- */
    var form = document.getElementById('scheduleForm');
    if (!form) return;

    var dateInput = document.getElementById('prefDate');       // hidden: YYYY-MM-DD
    var timeInput = document.getElementById('prefTime');       // hidden: "10:00 AM"
    var pickedDate = document.getElementById('pickedDate');
    var pickedTime = document.getElementById('pickedTime');
    var confirmBtn = document.getElementById('confirmBtn');
    var confirmLabel = document.getElementById('confirmLabel');
    var msg = document.getElementById('schedMsg');
    var confirmText = confirmLabel.textContent;
    var MONTHS = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
    var DOW = ['Su', 'Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa'];
    var CHEVRON = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M9 6l6 6-6 6"/></svg>';
    var sending = false;

    var datePicker = document.getElementById('datePicker');
    var dateBtn = document.getElementById('dateBtn');
    var dateValue = document.getElementById('dateValue');
    var datePop = document.getElementById('datePop');
    var timePicker = document.getElementById('timePicker');
    var timeBtn = document.getElementById('timeBtn');
    var timeValue = document.getElementById('timeValue');
    var timeMenu = document.getElementById('timeMenu');
    var options = Array.prototype.slice.call(timeMenu.querySelectorAll('.picker__option'));

    function showMsg(text) {
        msg.textContent = text;
        msg.hidden = false;
        msg.classList.remove('is-in');
        void msg.offsetWidth;                       // restart the animation
        msg.classList.add('is-in');
    }

    function hideMsg() { msg.hidden = true; }

    function two(n) { return (n < 10 ? '0' : '') + n; }
    function ymd(y, m, d) { return y + '-' + two(m + 1) + '-' + two(d); }          // m = 0..11

    // "2026-09-27" -> "September 27, 2026" (no timezone maths, so the day never shifts)
    function pretty(value) {
        var p = String(value).split('-');
        return MONTHS[Number(p[1]) - 1] + ' ' + Number(p[2]) + ', ' + p[0];
    }

    function weekend(value) {
        var p = value.split('-');
        var d = new Date(Number(p[0]), Number(p[1]) - 1, Number(p[2])).getDay();
        return d === 0 || d === 6;
    }

    // returns '' when the date is fine, otherwise the message
    function dateProblem(value) {
        if (!/^\d{4}-\d{2}-\d{2}$/.test(value)) return 'Please choose a preferred date.';
        if (value < form.dataset.min || value > form.dataset.max) return 'Please choose a date from tomorrow up to 60 days ahead.';
        if (weekend(value)) return 'Interviews are held Monday to Friday. Please choose a weekday.';
        return '';
    }

    function refresh(bump) {
        var okDate = dateInput.value && !dateProblem(dateInput.value);
        pickedDate.textContent = okDate ? pretty(dateInput.value) : 'Not chosen yet';
        pickedTime.textContent = timeInput.value || 'Not chosen yet';
        pickedDate.classList.toggle('is-set', !!okDate);
        pickedTime.classList.toggle('is-set', !!timeInput.value);
        confirmBtn.disabled = sending || !(okDate && timeInput.value);
        confirmBtn.classList.toggle('is-ready', !confirmBtn.disabled);

        if (bump) {
            var el = bump === 'date' ? pickedDate : pickedTime;
            el.classList.remove('bump');
            void el.offsetWidth;
            el.classList.add('bump');
        }
    }

    /* ----- open / close (only one picker is open at a time) ----- */
    function setOpen(picker, btn, open) {
        picker.classList.toggle('open', open);
        btn.setAttribute('aria-expanded', open ? 'true' : 'false');
    }

    function closeAll(except) {
        if (except !== datePicker) setOpen(datePicker, dateBtn, false);
        if (except !== timePicker) setOpen(timePicker, timeBtn, false);
    }

    // click outside closes the open picker. The path is taken from the event itself, because the calendar
    // redraws its buttons on a click (the clicked arrow is no longer in the page when this runs)
    document.addEventListener('click', function (e) {
        var path = e.composedPath ? e.composedPath() : [];
        if (path.indexOf(datePicker) === -1 && path.indexOf(timePicker) === -1) closeAll(null);
    });

    document.addEventListener('keydown', function (e) {
        if (e.key !== 'Escape') return;
        if (datePicker.classList.contains('open')) { closeAll(null); dateBtn.focus(); }
        else if (timePicker.classList.contains('open')) { closeAll(null); timeBtn.focus(); }
    });

    /* ----- the calendar ----- */
    var minParts = form.dataset.min.split('-').map(Number);
    var maxParts = form.dataset.max.split('-').map(Number);
    var viewY = minParts[0];
    var viewM = minParts[1] - 1;
    var todayParts = null;

    (function () {
        var t = new Date();
        todayParts = ymd(t.getFullYear(), t.getMonth(), t.getDate());
    })();

    function monthIndex(y, m) { return y * 12 + m; }

    function drawCalendar(direction) {
        var first = new Date(viewY, viewM, 1).getDay();
        var days = new Date(viewY, viewM + 1, 0).getDate();
        var canPrev = monthIndex(viewY, viewM) > monthIndex(minParts[0], minParts[1] - 1);
        var canNext = monthIndex(viewY, viewM) < monthIndex(maxParts[0], maxParts[1] - 1);
        var html = '<div class="pcal__head">' +
            '<button type="button" class="pcal__nav pcal__nav--prev" aria-label="Previous month"' + (canPrev ? '' : ' disabled') + '>' + CHEVRON + '</button>' +
            '<div class="pcal__title" aria-live="polite">' + MONTHS[viewM] + ' ' + viewY + '</div>' +
            '<button type="button" class="pcal__nav pcal__nav--next" aria-label="Next month"' + (canNext ? '' : ' disabled') + '>' + CHEVRON + '</button>' +
            '</div><div class="pcal__dow">' + DOW.map(function (d) { return '<span>' + d + '</span>'; }).join('') + '</div>' +
            '<div class="pcal__grid' + (direction ? ' pcal__grid--' + direction : '') + '">';

        for (var i = 0; i < first; i++) html += '<span></span>';
        for (var d = 1; d <= days; d++) {
            var value = ymd(viewY, viewM, d);
            var off = value < form.dataset.min || value > form.dataset.max || weekend(value);
            var cls = 'pcal__day' + (weekend(value) ? ' is-weekend' : '') + (value === todayParts ? ' is-today' : '') + (value === dateInput.value ? ' is-selected' : '');
            html += '<button type="button" class="' + cls + '" data-value="' + value + '"' + (off ? ' disabled' : '') + '>' + d + '</button>';
        }
        html += '</div><p class="pcal__hint">Interviews are held Monday to Friday.</p>';
        datePop.innerHTML = html;
    }

    datePop.addEventListener('click', function (e) {
        var nav = e.target.closest('.pcal__nav');
        if (nav && !nav.disabled) {
            var step = nav.classList.contains('pcal__nav--next') ? 1 : -1;
            viewM += step;
            if (viewM > 11) { viewM = 0; viewY++; }
            if (viewM < 0) { viewM = 11; viewY--; }
            drawCalendar(step > 0 ? 'next' : 'prev');
            return;
        }
        var day = e.target.closest('.pcal__day');
        if (!day || day.disabled) return;
        dateInput.value = day.dataset.value;
        dateValue.textContent = pretty(day.dataset.value);
        dateValue.classList.remove('is-placeholder');
        hideMsg();
        drawCalendar(null);
        var chosen = datePop.querySelector('.is-selected');
        if (chosen) chosen.classList.add('picked');
        refresh('date');
        setTimeout(function () { closeAll(null); dateBtn.focus(); }, 160);
    });

    dateBtn.addEventListener('click', function () {
        var open = !datePicker.classList.contains('open');
        closeAll(datePicker);
        if (open) {
            if (dateInput.value) {                      // open on the month that is already chosen
                var p = dateInput.value.split('-').map(Number);
                viewY = p[0]; viewM = p[1] - 1;
            }
            drawCalendar(null);
        }
        setOpen(datePicker, dateBtn, open);
    });

    /* ----- the time list ----- */
    var active = -1;

    function setActive(i) {
        active = Math.max(0, Math.min(options.length - 1, i));
        options.forEach(function (o, k) { o.classList.toggle('is-focused', k === active); });
        options[active].scrollIntoView({ block: 'nearest' });
    }

    function chooseTime(option) {
        options.forEach(function (o) { o.setAttribute('aria-selected', o === option ? 'true' : 'false'); });
        timeInput.value = option.dataset.value;
        timeValue.textContent = option.dataset.value;
        timeValue.classList.remove('is-placeholder');
        hideMsg();
        refresh('time');
        closeAll(null);
        timeBtn.focus();
    }

    function openTime() {
        closeAll(timePicker);
        setOpen(timePicker, timeBtn, true);
        var selected = options.filter(function (o) { return o.getAttribute('aria-selected') === 'true'; })[0];
        setActive(selected ? options.indexOf(selected) : 0);
        timeMenu.focus({ preventScroll: true });
    }

    timeBtn.addEventListener('click', function () {
        if (timePicker.classList.contains('open')) { closeAll(null); } else { openTime(); }
    });

    timeBtn.addEventListener('keydown', function (e) {
        if (e.key === 'ArrowDown' || e.key === 'ArrowUp') { e.preventDefault(); openTime(); }
    });

    timeMenu.addEventListener('click', function (e) {
        var option = e.target.closest('.picker__option');
        if (option) chooseTime(option);
    });

    timeMenu.addEventListener('mousemove', function (e) {
        var option = e.target.closest('.picker__option');
        if (option) setActive(options.indexOf(option));
    });

    timeMenu.addEventListener('keydown', function (e) {
        if (e.key === 'ArrowDown') { e.preventDefault(); setActive(active + 1); }
        else if (e.key === 'ArrowUp') { e.preventDefault(); setActive(active - 1); }
        else if (e.key === 'Home') { e.preventDefault(); setActive(0); }
        else if (e.key === 'End') { e.preventDefault(); setActive(options.length - 1); }
        else if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); if (active >= 0) chooseTime(options[active]); }
        else if (e.key === 'Tab') { closeAll(null); }
    });

    refresh(false);

    form.addEventListener('submit', function (e) {
        e.preventDefault();
        if (sending) return;

        var problem = dateProblem(dateInput.value);
        if (problem) { showMsg(problem); dateBtn.focus(); return; }
        if (!timeInput.value) { showMsg('Please choose an available time.'); timeBtn.focus(); return; }

        sending = true;
        confirmBtn.disabled = true;
        confirmBtn.classList.remove('is-ready');
        confirmBtn.classList.add('is-loading');
        confirmLabel.textContent = 'Confirming...';
        hideMsg();

        fetch(window.location.href, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
            body: JSON.stringify({ date: dateInput.value, time: timeInput.value })
        }).then(function (res) {
            return res.json().catch(function () { return {}; }).then(function (data) {
                if (!res.ok || data.status !== 'success') throw new Error(data.message || 'We could not save your schedule. Please try again.');
            });
        }).then(function () {
            // the page now shows the "Interview Schedule" view (it reads the saved choice)
            Applicant.go(window.location.pathname);
        }).catch(function (err) {
            sending = false;
            confirmBtn.classList.remove('is-loading');
            confirmLabel.textContent = confirmText;
            showMsg((err && err.message) || 'We could not save your schedule. Please try again.');
            refresh(false);
        });
    });

    // Browser Back (page restored from cache): undo the loading state
    window.addEventListener('pageshow', function (e) {
        if (!e.persisted) return;
        sending = false;
        confirmBtn.classList.remove('is-loading');
        confirmLabel.textContent = confirmText;
        refresh(false);
    });
});