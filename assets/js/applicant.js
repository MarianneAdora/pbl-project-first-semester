/* ==========================================================================
   applicant.js : every applicant page's script in ONE file.

   The shared script runs everywhere. Each page script is wrapped in a check of
   <html data-page="..."> so it only runs on the page(s) that used to load it.
   The code inside each section is unchanged.
   ========================================================================== */

/* ===== SHARED : applicant-common.js (runs on every applicant page) ===== */
/* ==========================================================================
   Applicant pages: entrance + normal page change (shared).
   Load this BEFORE the page's own script. Each page also needs, in <head>:
       <script>document.documentElement.classList.add('js');</script>

   Links with  data-leave  fade the page out before opening the next one:
       <a href="verify-otp.php" data-leave>...</a>
   From JS you can call:  Applicant.go('next-page.php')
   ========================================================================== */
(function () {
    var root = document.documentElement;
    var reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    var leaving = false;

    function enter() {
        // elements rise in right away
        requestAnimationFrame(function () { root.classList.add('is-ready'); });
    }

    function go(url) {
        if (leaving) return;
        leaving = true;
        if (reduceMotion) { window.location.href = url; return; }
        root.classList.add('is-leaving');
        setTimeout(function () { window.location.href = url; }, 320);
    }

    // links marked data-leave
    document.addEventListener('click', function (e) {
        var link = e.target.closest ? e.target.closest('a[data-leave]') : null;
        if (!link || e.defaultPrevented) return;
        if (e.button !== 0 || e.ctrlKey || e.metaKey || e.shiftKey || e.altKey) return;
        e.preventDefault();
        go(link.getAttribute('href'));
    });

    // Browser Back (page restored from cache): undo the leaving state
    window.addEventListener('pageshow', function (event) {
        if (!event.persisted) return;
        leaving = false;
        root.classList.remove('is-leaving');
        root.classList.add('is-ready');
    });

    window.Applicant = { go: go };

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', enter);
    else enter();
})();

/* ===== PAGE : application-status.js (runs only on: application-status) ===== */
if (document.documentElement.getAttribute('data-page') === 'application-status') {
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
}

/* ===== PAGE : instructions.js (runs only on: instruction) ===== */
if (document.documentElement.getAttribute('data-page') === 'instruction') {
/* Instructions page. The entrance animation and the normal page change
   come from applicant-common.js (loaded before this file). */
document.addEventListener("DOMContentLoaded", function () {
    var beginBtn = document.getElementById("beginBtn");
    if (!beginBtn) return;

    var label = beginBtn.querySelector(".start__btn-label");
    var originalText = label.textContent;

    // Loading feedback while the page fades out (the link itself is handled by data-leave)
    beginBtn.addEventListener("click", function () {
        if (beginBtn.classList.contains("is-loading")) return;
        beginBtn.classList.add("is-loading");
        label.textContent = "Loading...";
    });

    // Reset if the user comes back with the browser's Back button
    window.addEventListener("pageshow", function () {
        beginBtn.classList.remove("is-loading");
        label.textContent = originalText;
    });
});
}

/* ===== PAGE : opening.js (runs only on: opening) ===== */
if (document.documentElement.getAttribute('data-page') === 'opening') {
/* Opening page. The entrance animation and the normal page change
   come from applicant-common.js (loaded before this file). */
document.addEventListener("DOMContentLoaded", function () {
    var startBtn = document.getElementById("startBtn");
    if (!startBtn) return;

    var label = startBtn.querySelector(".invite__btn-label");
    var originalText = label.textContent;

    // Button feedback while the page fades out (the link itself is handled by data-leave)
    startBtn.addEventListener("click", function () {
        if (startBtn.classList.contains("is-loading")) return;
        startBtn.classList.add("is-loading");
        label.textContent = "Loading...";
    });

    // Reset if the visitor comes back with the browser's Back button
    window.addEventListener("pageshow", function () {
        startBtn.classList.remove("is-loading");
        label.textContent = originalText;
    });
});
}

/* ===== PAGE : screening-store.js (runs only on: questionnaire, upload-video) ===== */
if (['questionnaire', 'upload-video'].indexOf(document.documentElement.getAttribute('data-page')) !== -1) {
/* ==========================================================================
   Screening store (shared by questionnaire.js and upload-video.js).
   Load it AFTER applicant-common.js and BEFORE the page's own script.

   It keeps the applicant's answers in the browser (sessionStorage) while they move between the
   questionnaire and the video page, checks them, and sends the final result to the server.
   The page prints  window.SCREENING  (questions, endpoint, live, session ...) before this file.

   Screening.load() / save(answers)       the answers  { q1: 'Yes', q7: 'Yes', 'q7:follow': '2 weeks' ... }
   Screening.getIndex() / setIndex(n)     which question the applicant is on
   Screening.today()                      today as YYYY-MM-DD (for the date picker)
   Screening.validate(q, answers)         '' when the answer is fine, otherwise the message to show
   Screening.firstIncomplete(qs, answers) index of the first unanswered question, or -1
   Screening.startOnce()                  tells the HR lists this applicant is now "In Progress"
   Screening.lap(id)                      time spent per question (call with the question id when it is shown, null when leaving)
   Screening.timing() / deviceId()        the times and a random id kept in this browser (both are sent with the submit)
   Screening.submit({answers, video})     sends everything (returns a Promise)
   Screening.isSubmitted() / clear()
   ========================================================================== */
(function () {
    var KEY_ANSWERS = 'expertise.screening.answers';
    var KEY_INDEX = 'expertise.screening.index';
    var KEY_OWNER = 'expertise.screening.owner';
    var KEY_STARTED = 'expertise.screening.started';
    var KEY_DONE = 'expertise.screening.done';
    var KEY_TIMING = 'expertise.screening.timing';
    var KEY_DEVICE = 'expertise.device';          // kept in localStorage: it stays the same for this browser

    function cfg() { return window.SCREENING || {}; }

    /* ---------- storage that never throws (private mode, blocked storage) ---------- */
    var memory = {};
    function read(key) {
        try { var v = window.sessionStorage.getItem(key); return v === null ? (memory[key] || null) : v; }
        catch (e) { return memory[key] || null; }
    }
    function write(key, value) {
        memory[key] = value;
        try { window.sessionStorage.setItem(key, value); } catch (e) { /* kept in memory only */ }
    }
    function remove(key) {
        delete memory[key];
        try { window.sessionStorage.removeItem(key); } catch (e) { /* nothing to do */ }
    }

    // Another applicant on the same browser tab (a new server session) must not see old answers
    var token = String(cfg().session || '');
    if (token && read(KEY_OWNER) !== token) {
        remove(KEY_ANSWERS); remove(KEY_INDEX); remove(KEY_STARTED); remove(KEY_DONE); remove(KEY_TIMING);
        write(KEY_OWNER, token);
    }

    function load() {
        try {
            var data = JSON.parse(read(KEY_ANSWERS) || '{}');
            return data && typeof data === 'object' ? data : {};
        } catch (e) { return {}; }
    }

    function save(answers) { write(KEY_ANSWERS, JSON.stringify(answers || {})); }

    function getIndex() { return parseInt(read(KEY_INDEX) || '0', 10) || 0; }
    function setIndex(n) { write(KEY_INDEX, String(Math.max(0, n | 0))); }

    /* ---------- time per question (for the screening evaluation: very fast answers look like a bot) ---------- */
    var lapId = null;
    var lapAt = 0;

    function timing() {
        try {
            var data = JSON.parse(read(KEY_TIMING) || '{}');
            return data && typeof data === 'object' ? data : {};
        } catch (e) { return {}; }
    }

    function lap(id) {
        var now = Date.now();
        if (lapId) {
            var t = timing();
            t[lapId] = (t[lapId] || 0) + Math.min(now - lapAt, 600000);    // an idle tab counts for 10 minutes at most
            write(KEY_TIMING, JSON.stringify(t));
        }
        lapId = id || null;
        lapAt = now;
    }

    /* ---------- a random id for this browser (not the phone model): lets the evaluation see one device used by many applicants ---------- */
    function deviceId() {
        try {
            var id = window.localStorage.getItem(KEY_DEVICE);
            if (!id) {
                var bytes = new Uint8Array(16);
                (window.crypto || window.msCrypto).getRandomValues(bytes);
                id = Array.prototype.map.call(bytes, function (b) { return ('0' + b.toString(16)).slice(-2); }).join('');
                window.localStorage.setItem(KEY_DEVICE, id);
            }
            return id;
        } catch (e) { return ''; }
    }

    // an old follow-up answer must not travel with a different Yes / No (Yes + notice period, then changed to No)
    function cleanAnswers(answers) {
        var out = {};
        Object.keys(answers || {}).forEach(function (k) { out[k] = answers[k]; });
        (cfg().questions || []).forEach(function (q) {
            // Consent questions (data protection statement) are stored as boolean by the checkbox,
            // but the server expects Yes/No strings. Convert them here so the answer is persisted
            // correctly to data_protection_agreed_at in the response table.
            if (q.type === 'consent') {
                out[q.id] = (out[q.id] === true) ? 'Yes' : 'No';
            }
            if (q.followup && out[q.id] !== q.followup.when) delete out[q.id + ':follow'];
        });
        return out;
    }

    function today() {
        var d = new Date();
        function p(n) { return (n < 10 ? '0' : '') + n; }
        return d.getFullYear() + '-' + p(d.getMonth() + 1) + '-' + p(d.getDate());
    }

    /* ---------- checking one answer (same rules as static_validate_answer in static-applicant.php) ---------- */
    function validate(q, answers) {
        var v = answers[q.id];
        switch (q.type) {
            case 'scale':
                var n = Number(v);
                return n >= 1 && n <= (q.max || 5) ? '' : 'Please choose a rating.';

            case 'date':
                if (!v || !/^\d{4}-\d{2}-\d{2}$/.test(v)) return 'Please choose a date.';
                return v >= today() ? '' : 'Please choose today or a later date.';

            case 'text':
                var len = String(v || '').trim().length;
                if (!len) return 'Please write your answer.';
                return len >= (q.min_chars || 1) ? '' : 'Please write a little more (2-3 sentences).';

            case 'consent':
                return v === true ? '' : 'Please tick the box to continue.';

            default:                                   // yesno + yesno_select
                if (v !== 'Yes' && v !== 'No') return 'Please choose Yes or No.';
                if (q.followup && v === q.followup.when && !answers[q.id + ':follow']) return 'Please choose one from the list.';
                return '';
        }
    }

    function firstIncomplete(questions, answers) {
        for (var i = 0; i < questions.length; i++) {
            if (validate(questions[i], answers)) return i;
        }
        return -1;
    }

    /* ---------- talking to the server (hr/static-applicant.php now, the backend later) ---------- */
    function post(payload) {
        return fetch(cfg().endpoint, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
            body: JSON.stringify(payload)
        }).then(function (res) {
            return res.json().catch(function () { return {}; }).then(function (data) {
                if (!res.ok || data.status !== 'success') {
                    throw new Error(data.message || 'We could not reach the server. Please try again.');
                }
                return data;
            });
        });
    }

    // the first time the questionnaire is opened: Pending -> In Progress in the HR lists
    function startOnce() {
        if (!cfg().live || !cfg().endpoint || read(KEY_STARTED)) return;
        write(KEY_STARTED, '1');
        post({ action: 'start' }).catch(function () { remove(KEY_STARTED); });   // try again next time
    }

    function submit(payload) {
        if (!cfg().live || !cfg().endpoint) {                          // design/testing mode: nothing is sent
            return new Promise(function (resolve) { setTimeout(resolve, 700); });
        }
        return post({
            action: 'submit',
            answers: cleanAnswers(payload.answers),
            video: payload.video,
            timing: timing(),
            device: deviceId()
        }).then(function (data) {
            write(KEY_DONE, token || '1');
            return data;
        });
    }

    function isSubmitted() { return read(KEY_DONE) === (token || '1'); }

    function clear() { remove(KEY_ANSWERS); remove(KEY_INDEX); remove(KEY_TIMING); }

    window.Screening = {
        load: load, save: save, getIndex: getIndex, setIndex: setIndex, today: today,
        validate: validate, firstIncomplete: firstIncomplete,
        lap: lap, timing: timing, deviceId: deviceId,
        startOnce: startOnce, submit: submit, isSubmitted: isSubmitted, clear: clear
    };
})();
}

/* ===== PAGE : questionnaire.js (runs only on: questionnaire) ===== */
if (document.documentElement.getAttribute('data-page') === 'questionnaire') {
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

        // last question: validate every answer before going to the video page
        var missing = Screening.firstIncomplete(questions, answers);
        if (missing !== -1) {
            var badQ = questions[missing];
            showError('Question ' + (missing + 1) + ': ' + Screening.validate(badQ, answers));
            index = missing;
            show(0);
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
}

/* ===== PAGE : upload-video.js (runs only on: upload-video) ===== */
if (document.documentElement.getAttribute('data-page') === 'upload-video') {
/* ==========================================================================
   Video page.
   The entrance animation and the normal page change come from applicant-common.js; answers and
   the final submit come from screening-store.js (both loaded before this file).

   Two ways to give a video:
     - upload one (browse or drag and drop): MP4 / MOV, max 100 MB, max 10 seconds
     - record one with the camera, right here: it stops by itself at 10 seconds

   The rules (seconds, MB, formats) are NOT written here: they come from window.SCREENING.video,
   printed by upload-video.php from static-data.php (later: the database / settings).

   BACKEND: the real upload is already written (realUpload below). It sends the video to
   api/upload-file.php (Cloudinary) as the form field "video" and expects JSON back:
       { "status": "success", "url": "https://res.cloudinary.com/..." }
       { "status": "error",   "message": "..." }
   Until then upload-video.php prints  simulateUpload: true  and the progress bar is pretend.
   ========================================================================== */
document.addEventListener('DOMContentLoaded', function () {
    var cfg = window.SCREENING || {};
    var rules = cfg.video || { max_seconds: 10, max_mb: 50, formats: ['mp4', 'mov'] };
    var MAX_SEC = Number(rules.max_seconds) || 10;
    var MAX_BYTES = (Number(rules.max_mb) || 50) * 1024 * 1024;
    var FORMATS = (rules.formats || ['mp4', 'mov']).map(function (f) { return String(f).toLowerCase(); });
    var FORMATS_TEXT = FORMATS.map(function (f) { return f.toUpperCase(); }).join(' or ');
    var SECONDS_SLACK = 0.6;            // a clip of 10.4 s is still "10 seconds"

    var reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    /* ---------- the applicant must have finished the questionnaire ---------- */
    if (cfg.live) {
        var missing = Screening.firstIncomplete(cfg.questions || [], Screening.load());
        if (Screening.isSubmitted()) { window.location.replace(cfg.nextPage || 'application-status.php'); return; }
        if (missing !== -1) {
            Screening.setIndex(missing);
            window.location.replace(cfg.backPage || 'questionnaire.php');
            return;
        }
    }

    /* ---------- elements ---------- */
    function $(id) { return document.getElementById(id); }
    var dropzone = $('dropzone');
    var pickPanel = $('pickPanel');
    var input = $('videoInput');
    var browseBtn = $('browseBtn');
    var recordBtn = $('recordBtn');
    var dropTitle = $('dropTitle');
    var vmsg = $('vmsg');
    var vError = $('vError');
    var vErrorText = $('vErrorText');
    var fileCard = $('fileCard');
    var thumbBtn = $('thumbBtn');
    var thumbImg = $('thumbImg');
    var fileName = $('fileName');
    var fileSize = $('fileSize');
    var uploadBar = $('uploadBar');
    var filePct = $('filePct');
    var fileState = $('fileState');
    var removeBtn = $('removeBtn');
    var reselectBtn = $('reselectBtn');         // no longer in the page (one video only); kept so older markup still works
    var submitBtn = $('submitBtn');
    var submitLabel = submitBtn.querySelector('.submit__label');
    var submitText = submitLabel.textContent;

    /* ---------- helpers ---------- */
    function replay(el, className) {
        el.classList.remove(className);
        void el.offsetWidth;                // restart the animation
        el.classList.add(className);
    }

    function wait(ms) { return new Promise(function (r) { setTimeout(r, ms); }); }

    function prettySize(bytes) {
        return bytes < 1024 * 1024
            ? Math.max(1, Math.round(bytes / 1024)) + ' KB'
            : (bytes / 1024 / 1024).toFixed(1) + ' MB';
    }

    function ext(name) {
        var i = String(name).lastIndexOf('.');
        return i < 0 ? '' : String(name).slice(i + 1).toLowerCase();
    }

    function clock(seconds) {
        var s = Math.max(0, Math.floor(seconds));
        return Math.floor(s / 60) + ':' + (s % 60 < 10 ? '0' : '') + (s % 60);
    }

    /* ---------- the message (one at a time) ---------- */
    function showMsg(text) {
        vErrorText.textContent = text;
        vmsg.classList.add('open');
        replay(vError, 'is-in');
    }

    function hideMsg() {
        vmsg.classList.remove('open');
    }

    /* ---------- the chosen video ---------- */
    var state = { file: null, seconds: null, source: 'upload', status: 'idle', url: '', previewUrl: '' };
    var job = null;                         // the running upload (so Remove / Re-select can stop it)
    var submitting = false;

    function checkFile(file, recorded) {
        if (!recorded) {
            var e = ext(file.name);
            if (FORMATS.indexOf(e) === -1) {
                return (e ? '.' + e.toUpperCase() + ' files are not allowed. ' : '') + 'Upload an ' + FORMATS_TEXT + ' video.';
            }
        }
        if (file.size === 0) return 'This file is empty. Choose a different video.';
        if (file.size > MAX_BYTES) return 'Your video is ' + prettySize(file.size) + '. It must be ' + (MAX_BYTES / 1024 / 1024) + ' MB or smaller.';
        return '';
    }

    // Reads the length and a still picture from the video itself. Both can be unknown (some
    // phone formats cannot be read by the browser); then the server checks it instead.
    function inspect(file) {
        return new Promise(function (resolve) {
            var url = URL.createObjectURL(file);
            var v = document.createElement('video');
            var out = { seconds: null, poster: null };
            var done = false;

            function finish() {
                if (done) return;
                done = true;
                URL.revokeObjectURL(url);
                resolve(out);
            }

            v.preload = 'metadata';
            v.muted = true;
            v.playsInline = true;
            v.onerror = finish;
            v.onloadedmetadata = function () {
                if (isFinite(v.duration) && v.duration > 0) out.seconds = v.duration;
                try { v.currentTime = Math.min(0.4, (out.seconds || 1) / 2); } catch (e) { finish(); }
            };
            v.onseeked = function () {
                try {
                    var c = document.createElement('canvas');
                    c.width = 192;
                    c.height = Math.max(1, Math.round(192 * (v.videoHeight || 108) / (v.videoWidth || 192)));
                    c.getContext('2d').drawImage(v, 0, 0, c.width, c.height);
                    out.poster = c.toDataURL('image/jpeg', 0.7);
                } catch (e) { /* no picture: the play icon stays */ }
                finish();
            };
            setTimeout(finish, 6000);       // never wait forever
            v.src = url;
        });
    }

    // ONE video only. While a video is there, nothing else can be added (browse, drop or record):
    // the applicant has to press Remove first.
    var FULL_TEXT = 'You can only upload one video. Remove your current video first if you want to add a different one.';
    var dropTitleText = dropTitle.textContent;

    function syncFull() {
        var full = !!state.file;
        dropzone.classList.toggle('is-full', full);
        input.disabled = full;
        browseBtn.setAttribute('aria-disabled', String(full));
        browseBtn.tabIndex = full ? -1 : 0;
        recordBtn.disabled = full;
        dropTitle.textContent = full ? 'Your video is added' : dropTitleText;
    }

    async function choose(file, opts) {
        opts = opts || {};
        if (submitting) return;
        hideMsg();
        if (state.file) { showMsg(FULL_TEXT); return; }

        var problem = checkFile(file, opts.recorded);
        if (problem) { showMsg(problem); return; }

        dropzone.classList.add('is-checking');
        var info = await inspect(file);
        dropzone.classList.remove('is-checking');

        var seconds = opts.seconds != null ? opts.seconds : info.seconds;
        if (seconds != null && seconds > MAX_SEC + SECONDS_SLACK) {
            showMsg('Your video is ' + Math.round(seconds) + ' seconds long. It should be ' + MAX_SEC + ' seconds or shorter.');
            return;
        }

        // accepted (there is no earlier video: that was checked at the top)
        stopUpload();
        if (state.previewUrl) URL.revokeObjectURL(state.previewUrl);
        state = {
            file: file,
            seconds: seconds,
            source: opts.recorded ? 'recorded' : 'upload',
            status: 'idle',
            url: '',
            previewUrl: URL.createObjectURL(file)
        };

        fileName.textContent = file.name;
        fileSize.textContent = '[' + prettySize(file.size) + (seconds != null ? ' \u00B7 ' + Math.max(1, Math.round(seconds)) + ' s' : '') + ']';
        fileName.title = file.name;
        if (info.poster) { thumbImg.src = info.poster; thumbImg.hidden = false; } else { thumbImg.hidden = true; thumbImg.removeAttribute('src'); }

        fileCard.classList.remove('is-leaving', 'is-locked');
        fileCard.hidden = false;
        replay(fileCard, 'is-in');
        syncFull();
        startUpload();
    }

    /* ---------- uploading ---------- */
    function setProgress(p) {
        var pct = Math.max(0, Math.min(100, Math.round(p)));
        uploadBar.style.setProperty('--p', pct + '%');
        uploadBar.setAttribute('aria-valuenow', String(pct));
        filePct.textContent = pct + '%';
    }

    function setStatus(status, text) {
        state.status = status;
        fileCard.dataset.status = status;
        fileState.textContent = text || '';
        submitBtn.disabled = status !== 'ready';
        submitBtn.classList.toggle('is-ready', status === 'ready');
        var old = fileCard.querySelector('.retry');
        if (old) old.remove();
        if (status === 'error') {
            var retry = document.createElement('button');
            retry.type = 'button';
            retry.className = 'link link--red retry';
            retry.textContent = 'Try again';
            retry.addEventListener('click', function () { hideMsg(); startUpload(); });
            fileCard.querySelector('.filecard__actions').appendChild(retry);
        }
    }

    // pretend upload for testing: the bar fills in about a second or two
    function simulateUpload(file, onProgress) {
        var cancelled = false;
        var promise = new Promise(function (resolve, reject) {
            var total = Math.max(1200, Math.min(3800, file.size / 4000));
            var t0 = performance.now();
            (function step(now) {
                if (cancelled) { reject({ cancelled: true }); return; }
                var t = Math.min(1, ((now || performance.now()) - t0) / total);
                onProgress(100 * (1 - Math.pow(1 - t, 2.2)));
                if (t < 1) requestAnimationFrame(step);
                else resolve({ url: '' });
            })();
        });
        return { promise: promise, cancel: function () { cancelled = true; } };
    }

    // the real upload (see the BACKEND note at the top of this file)
    function realUpload(file, onProgress) {
        var xhr = new XMLHttpRequest();
        var promise = new Promise(function (resolve, reject) {
            xhr.open('POST', cfg.uploadUrl);
            xhr.withCredentials = true;
            xhr.upload.onprogress = function (e) { if (e.lengthComputable) onProgress(e.loaded / e.total * 100); };
            xhr.onload = function () {
                var data;
                try { data = JSON.parse(xhr.responseText); } catch (e) { reject(new Error('The server did not answer correctly. Please try again.')); return; }
                if (xhr.status >= 200 && xhr.status < 300 && data.status === 'success') resolve({ url: data.url || '' });
                else reject(new Error(data.message || 'The upload did not finish. Please try again.'));
            };
            xhr.onerror = function () { reject(new Error('Could not reach the server. Please check your connection and try again.')); };
            xhr.onabort = function () { reject({ cancelled: true }); };
            var fd = new FormData();
            fd.append('video', file, file.name);
            xhr.send(fd);
        });
        return { promise: promise, cancel: function () { xhr.abort(); } };
    }

    function startUpload() {
        stopUpload();
        setProgress(0);
        setStatus('uploading', 'Uploading...');
        var mine = cfg.simulateUpload === false ? realUpload(state.file, setProgress) : simulateUpload(state.file, setProgress);
        job = mine;
        mine.promise.then(function (res) {
            if (job !== mine) return;
            job = null;
            state.url = res.url || '';
            setProgress(100);
            setStatus('ready', 'Uploaded');
        }, function (err) {
            if (job !== mine || (err && err.cancelled)) return;
            job = null;
            setStatus('error', 'Upload failed');
            showMsg((err && err.message) || 'The upload did not finish. Please try again.');
        });
    }

    function stopUpload() {
        if (job) { var j = job; job = null; j.cancel(); }
    }

    function removeVideo() {
        if (submitting) return;
        stopUpload();
        hideMsg();
        if (state.previewUrl) URL.revokeObjectURL(state.previewUrl);
        state = { file: null, seconds: null, source: 'upload', status: 'idle', url: '', previewUrl: '' };
        syncFull();
        submitBtn.disabled = true;
        submitBtn.classList.remove('is-ready');
        fileCard.classList.add('is-leaving');
        setTimeout(function () {
            if (!state.file) { fileCard.hidden = true; fileCard.classList.remove('is-leaving'); }
        }, reduceMotion ? 0 : 320);
    }

    removeBtn.addEventListener('click', removeVideo);

    /* ---------- picking a file: browse, drag and drop ---------- */
    input.addEventListener('change', function () {
        var files = input.files;
        var file = files && files[0];
        input.value = '';                    // so the same file can be picked again
        if (files && files.length > 1) { showMsg('Please choose one video only.'); return; }
        if (file) choose(file);
    });

    [browseBtn, reselectBtn].filter(Boolean).forEach(function (label) {
        label.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                if (state.file) { showMsg(FULL_TEXT); return; }
                if (!submitting) input.click();
            }
        });
        label.addEventListener('click', function (e) {
            if (submitting) { e.preventDefault(); return; }
            if (state.file) { e.preventDefault(); showMsg(FULL_TEXT); }     // one video only
        });
    });

    var dragDepth = 0;
    dropzone.addEventListener('dragenter', function (e) {
        e.preventDefault();
        dragDepth++;
        dropzone.classList.add('is-drag');
    });
    dropzone.addEventListener('dragover', function (e) { e.preventDefault(); });
    dropzone.addEventListener('dragleave', function () {
        dragDepth = Math.max(0, dragDepth - 1);
        if (!dragDepth) dropzone.classList.remove('is-drag');
    });
    dropzone.addEventListener('drop', function (e) {
        e.preventDefault();
        dragDepth = 0;
        dropzone.classList.remove('is-drag');
        if (!recorder.hidden) return;        // the camera is open: ignore drops
        var files = e.dataTransfer && e.dataTransfer.files;
        if (!files || !files.length) return;
        if (files.length > 1) { showMsg('Please drop one video only.'); return; }
        choose(files[0]);
    });
    // a file dropped outside the box must not open in the browser and take the page away
    ['dragover', 'drop'].forEach(function (t) { window.addEventListener(t, function (e) { e.preventDefault(); }); });

    /* ==========================================================================
       Record with the camera
       ========================================================================== */
    var recorder = $('recorder');
    var camVideo = $('camVideo');
    var camCount = $('camCount');
    var camRec = $('camRec');
    var camTime = $('camTime');
    var camBar = $('camBar');
    var recTitle = $('recTitle');
    var recText = $('recText');
    var recView = $('recView');
    var btn = {
        start: $('recStart'), stop: $('recStop'), use: $('recUse'),
        retake: $('recRetake'), cancel: $('recCancel')
    };

    var rec = { stream: null, mr: null, chunks: [], raf: 0, startedAt: 0, seconds: 0, mime: '', blob: null, blobUrl: '', token: 0 };

    var canRecord = !!(navigator.mediaDevices && navigator.mediaDevices.getUserMedia && window.MediaRecorder);
    if (canRecord) recordBtn.hidden = false;

    // MP4 first (it is on the supported list); WebM where the browser cannot make MP4
    function pickMime() {
        var list = ['video/mp4;codecs=avc1,mp4a.40.2', 'video/mp4', 'video/webm;codecs=vp9,opus', 'video/webm;codecs=vp8,opus', 'video/webm'];
        for (var i = 0; i < list.length; i++) {
            if (MediaRecorder.isTypeSupported && MediaRecorder.isTypeSupported(list[i])) return list[i];
        }
        return '';
    }

    function cameraMessage(err) {
        var n = err && err.name;
        if (n === 'NotAllowedError' || n === 'SecurityError') return 'Camera access was blocked. Allow the camera and microphone in your browser, or upload a video instead.';
        if (n === 'NotFoundError' || n === 'DevicesNotFoundError') return 'No camera was found on this device. You can upload a video instead.';
        if (n === 'NotReadableError' || n === 'TrackStartError') return 'Your camera is being used by another app. Close it and try again, or upload a video instead.';
        return 'The camera could not be started. You can upload a video instead.';
    }

    function stopStream() {
        if (rec.stream) rec.stream.getTracks().forEach(function (t) { t.stop(); });
        rec.stream = null;
    }

    function dropBlob() {
        if (rec.blobUrl) URL.revokeObjectURL(rec.blobUrl);
        rec.blob = null;
        rec.blobUrl = '';
    }

    // live | counting | recording | review
    function setRecState(s) {
        recorder.dataset.state = s;
        btn.start.hidden = s !== 'live';
        btn.stop.hidden = s !== 'recording';
        btn.use.hidden = s !== 'review';
        btn.retake.hidden = s !== 'review';
        btn.cancel.hidden = false;
        camRec.hidden = s !== 'recording';
        recView.classList.toggle('is-mirror', s !== 'review');
        recView.classList.toggle('is-recording', s === 'recording');

        if (s === 'live') {
            recTitle.textContent = 'Check your camera';
            recText.textContent = 'Face the camera in good light. Your video is ' + MAX_SEC + ' seconds long.';
        } else if (s === 'counting') {
            recTitle.textContent = 'Get ready...';
            recText.textContent = 'Recording starts in a moment.';
        } else if (s === 'recording') {
            recTitle.textContent = 'Recording';
            recText.textContent = 'It stops by itself at ' + MAX_SEC + ' seconds, or press Stop.';
        } else if (s === 'review') {
            recTitle.textContent = 'Happy with it?';
            recText.textContent = 'Play it back. Use it, or retake it.';
        }
    }

    async function openCamera() {
        var token = ++rec.token;
        var stream;
        try {
            stream = await navigator.mediaDevices.getUserMedia({
                video: { facingMode: 'user', width: { ideal: 1280 }, height: { ideal: 720 } },
                audio: true
            });
        } catch (err) {
            if (token === rec.token) showMsg(cameraMessage(err));
            return false;
        }
        if (token !== rec.token) { stream.getTracks().forEach(function (t) { t.stop(); }); return false; }   // cancelled while asking
        rec.stream = stream;
        camVideo.controls = false;
        camVideo.removeAttribute('src');
        camVideo.srcObject = stream;
        camVideo.muted = true;
        camVideo.play().catch(function () { /* autoplay is allowed after a click */ });
        return true;
    }

    async function openRecorder() {
        if (submitting) return;
        hideMsg();
        if (state.file) { showMsg(FULL_TEXT); return; }
        if (!await openCamera()) return;
        pickPanel.hidden = true;
        recorder.hidden = false;
        dropzone.classList.add('is-recording-ui');
        camBar.style.setProperty('--p', '0%');
        camTime.textContent = '0:00';
        setRecState('live');
        btn.start.focus({ preventScroll: true });
    }

    function closeRecorder() {
        rec.token++;
        cancelAnimationFrame(rec.raf);
        if (rec.mr && rec.mr.state !== 'inactive') { rec.mr.onstop = null; try { rec.mr.stop(); } catch (e) { /* already stopped */ } }
        rec.mr = null;
        stopStream();
        dropBlob();
        camVideo.pause();
        camVideo.removeAttribute('src');
        camVideo.srcObject = null;
        recorder.hidden = true;
        pickPanel.hidden = false;
        dropzone.classList.remove('is-recording-ui');
        camCount.textContent = '';
    }

    async function countdown() {
        var token = rec.token;
        setRecState('counting');
        for (var n = 3; n >= 1; n--) {
            camCount.textContent = String(n);
            replay(camCount, 'pulse');
            await wait(reduceMotion ? 700 : 1000);
            if (token !== rec.token) return;           // cancelled during the count
        }
        camCount.textContent = '';
        startRecorder();
    }

    function startRecorder() {
        var mime = pickMime();
        try {
            rec.mr = mime ? new MediaRecorder(rec.stream, { mimeType: mime }) : new MediaRecorder(rec.stream);
        } catch (e) {
            closeRecorder();
            showMsg('Recording is not supported in this browser. Please upload a video instead.');
            return;
        }
        rec.mime = rec.mr.mimeType || mime || 'video/webm';
        rec.chunks = [];
        rec.mr.ondataavailable = function (e) { if (e.data && e.data.size) rec.chunks.push(e.data); };
        rec.mr.onstop = onRecorded;
        rec.mr.start();
        rec.startedAt = performance.now();
        setRecState('recording');
        tick();
    }

    function tick() {
        var s = (performance.now() - rec.startedAt) / 1000;
        camTime.textContent = clock(s) + ' / ' + clock(MAX_SEC);
        camBar.style.setProperty('--p', Math.min(100, s / MAX_SEC * 100) + '%');
        if (s >= MAX_SEC) { stopRecording(); return; }
        rec.raf = requestAnimationFrame(tick);
    }

    function stopRecording() {
        cancelAnimationFrame(rec.raf);
        rec.seconds = Math.min(MAX_SEC, (performance.now() - rec.startedAt) / 1000);
        if (rec.mr && rec.mr.state !== 'inactive') rec.mr.stop();
    }

    function onRecorded() {
        rec.blob = new Blob(rec.chunks, { type: rec.mime });
        rec.blobUrl = URL.createObjectURL(rec.blob);
        stopStream();                                  // the camera light goes off
        camVideo.srcObject = null;
        camVideo.src = rec.blobUrl;
        camVideo.muted = false;
        camVideo.controls = true;
        camVideo.play().catch(function () { /* the controls are there */ });
        camBar.style.setProperty('--p', '100%');
        setRecState('review');
        btn.use.focus({ preventScroll: true });
    }

    recordBtn.addEventListener('click', openRecorder);
    btn.start.addEventListener('click', countdown);
    btn.stop.addEventListener('click', stopRecording);
    btn.cancel.addEventListener('click', closeRecorder);

    btn.retake.addEventListener('click', async function () {
        dropBlob();
        camVideo.pause();
        camBar.style.setProperty('--p', '0%');
        camTime.textContent = '0:00';
        if (await openCamera()) setRecState('live');
        else closeRecorder();
    });

    btn.use.addEventListener('click', function () {
        if (!rec.blob) return;
        var type = rec.mime || 'video/webm';
        var name = 'video_response.' + (type.indexOf('mp4') !== -1 ? 'mp4' : 'webm');
        var file = new File([rec.blob], name, { type: type });
        var seconds = rec.seconds;
        closeRecorder();
        choose(file, { recorded: true, seconds: seconds });
    });

    window.addEventListener('pagehide', stopStream);

    /* ==========================================================================
       Preview
       ========================================================================== */
    var modal = $('previewModal');
    var pv = $('previewVideo');
    var closeBtn = $('previewClose');

    function openPreview() {
        if (!state.previewUrl) return;
        pv.src = state.previewUrl;
        modal.hidden = false;
        replay(modal, 'is-in');
        pv.play().catch(function () { /* the controls are there */ });
        closeBtn.focus({ preventScroll: true });
    }

    function closePreview() {
        pv.pause();
        pv.removeAttribute('src');
        pv.load();
        modal.hidden = true;
        thumbBtn.focus({ preventScroll: true });
    }

    thumbBtn.addEventListener('click', openPreview);
    closeBtn.addEventListener('click', closePreview);
    modal.addEventListener('click', function (e) { if (e.target === modal) closePreview(); });
    document.addEventListener('keydown', function (e) {
        if (e.key !== 'Escape') return;
        if (!modal.hidden) closePreview();
        else if (!recorder.hidden && recorder.dataset.state !== 'recording') closeRecorder();
    });

    /* ==========================================================================
       Submit
       ========================================================================== */
    // SHA-256 of the chosen file: lets the evaluation see the same video sent by two different applicants
    async function fileHash(file) {
        try {
            if (!window.crypto || !window.crypto.subtle) return '';
            var digest = await window.crypto.subtle.digest('SHA-256', await file.arrayBuffer());
            return Array.prototype.map.call(new Uint8Array(digest), function (b) { return ('0' + b.toString(16)).slice(-2); }).join('');
        } catch (e) { return ''; }                 // no fingerprint is not a problem, the video is still sent
    }

    submitBtn.addEventListener('click', async function () {
        if (state.status !== 'ready' || submitting) return;
        submitting = true;
        hideMsg();
        submitBtn.disabled = true;
        submitBtn.classList.remove('is-ready');
        submitBtn.classList.add('is-loading');
        submitLabel.textContent = 'Submitting...';
        fileCard.classList.add('is-locked');

        try {
            await Screening.submit({
                answers: Screening.load(),
                video: { name: state.file.name, size: state.file.size, seconds: state.seconds, url: state.url, source: state.source, hash: await fileHash(state.file) }
            });
            Screening.clear();
            Applicant.go(cfg.nextPage || 'application-status.php');
        } catch (err) {
            var msg = (err && err.message) || '';
            // The submission may already have been saved on the server but the
            // response never reached the browser (e.g. network dropped). If the
            // backend says it was already submitted, treat it as success and move on.
            if (/already submitted/i.test(msg)) {
                Screening.clear();
                Applicant.go(cfg.nextPage || 'application-status.php');
                return;
            }
            submitting = false;
            fileCard.classList.remove('is-locked');
            submitBtn.classList.remove('is-loading');
            submitBtn.disabled = false;
            submitBtn.classList.add('is-ready');
            submitLabel.textContent = submitText;
            showMsg(msg || 'We could not submit your application. Please try again.');
        }
    });

    // Browser Back (page restored from cache): undo the loading state, or move on if it was already sent
    window.addEventListener('pageshow', function (e) {
        if (e.persisted && Screening.isSubmitted() && cfg.live) { window.location.replace(cfg.nextPage || 'application-status.php'); return; }
        if (!submitting) {
            submitBtn.classList.remove('is-loading');
            submitLabel.textContent = submitText;
        }
    });
});
}

/* ===== PAGE : verify-otp.js (runs only on: verify-otp) ===== */
if (document.documentElement.getAttribute('data-page') === 'verify-otp') {
/* Verify OTP page.
   The entrance animation and the normal page change come from applicant-common.js.

   Animation rules (so nothing fights):
   - each box sits in a .otp__cell that only does the entrance
   - the input itself uses  translate  (hover / focus / wave)  and  scale  (pop / clear)
   - the row  .otp__boxes  does the shake                                                  */
document.addEventListener("DOMContentLoaded", function () {
    const form = document.getElementById("otpForm");

    /* ---------- locked page (too many wrong codes): only a countdown, then the page reloads with a fresh code ---------- */
    const lockTimer = document.getElementById("lockTimer");
    if (!form) {
        if (lockTimer) {
            const lockEndsAt = Date.now() + (parseInt(lockTimer.dataset.remaining, 10) || 0) * 1000;
            const tickLock = function () {
                const left = Math.max(0, Math.ceil((lockEndsAt - Date.now()) / 1000));
                lockTimer.textContent = String(Math.floor(left / 60)).padStart(2, "0") + ":" + String(left % 60).padStart(2, "0");
                if (left <= 0) { clearInterval(lockLoop); location.reload(); }   // the server issues a new code and resets the attempts
            };
            const lockLoop = setInterval(tickLock, 500);
            tickLock();
        }
        return;
    }
    const boxesWrap = document.getElementById("otpBoxes");
    const boxes = Array.from(document.querySelectorAll(".otp__box"));
    const verifyBtn = document.getElementById("verifyBtn");
    const resendBtn = document.getElementById("resendBtn");
    const verifyLabel = verifyBtn.querySelector(".otp__submit-label");
    const verifyText = verifyLabel.textContent;
    const resendText = resendBtn.textContent;
    const resendEndsAt = Date.now() + (parseInt(resendBtn.dataset.cooldown, 10) || 0) * 1000;
    const banner = document.getElementById("errorBanner");
    const bannerText = document.getElementById("errorText");
    const timerEl = document.getElementById("timer");
    const timerSuffix = document.getElementById("timerSuffix");
    const timerWrap = timerEl.closest(".resend__timer");
    const LAST = boxes.length - 1;

    let submitting = false;

    /* ---------- helpers ---------- */
    function replay(el, className) {
        el.classList.remove(className);
        void el.offsetWidth;                         // restart the animation
        el.classList.add(className);
    }

    const allFilled = () => boxes.every(b => b.value !== "");

    function setDigit(box, digit) {
        box.value = digit;
        box.dataset.prev = digit;
        box.classList.toggle("is-filled", digit !== "");
    }

    function focusBox(index) {
        boxes[Math.max(0, Math.min(LAST, index))].focus();     // focus handler selects the digit
    }

    // short-lived classes remove themselves when their animation ends
    boxes.forEach(box => box.addEventListener("animationend", e => {
        if (e.animationName === "digitPop" || e.animationName === "digitClear") {
            box.classList.remove("pop", "clear");
        }
    }));
    boxesWrap.addEventListener("animationend", e => {
        if (e.target === boxesWrap && e.animationName === "shake") boxesWrap.classList.remove("shake");
    });

    /* ---------- complete state: glowing button + one soft wave ---------- */
    let wasComplete = false;
    let waveStart = null;
    let waveEnd = null;

    function refreshState() {
        const complete = allFilled();
        verifyBtn.classList.toggle("is-ready", complete);

        if (complete && !wasComplete) {
            clearTimeout(waveStart);
            waveStart = setTimeout(function () {         // let the last digit finish popping first
                if (!allFilled()) return;
                boxes.forEach(b => b.classList.remove("pop", "clear"));
                replay(boxesWrap, "complete");
                clearTimeout(waveEnd);
                waveEnd = setTimeout(() => boxesWrap.classList.remove("complete"), 1000);
            }, 320);
        }
        if (!complete) {
            clearTimeout(waveStart);
            boxesWrap.classList.remove("complete");
        }
        wasComplete = complete;
    }

    /* ---------- errors ---------- */
    // Only one message is on screen at a time: the red error and the green "new code sent" notice
    const notice = document.getElementById("noticeBanner");

    function hideNotice() {
        if (notice) notice.hidden = true;
    }

    function showError(message) {
        hideNotice();
        bannerText.textContent = message;
        banner.hidden = false;
        boxesWrap.classList.add("is-error");
        replay(boxesWrap, "shake");
    }

    // called whenever the visitor starts typing, pasting or erasing: clears whatever message is showing
    function clearError() {
        hideNotice();
        banner.hidden = true;
        boxesWrap.classList.remove("is-error");
    }

    /* ---------- filling boxes (typing, paste, autofill) ---------- */
    // Drops digits into the boxes one after another, starting at `start`
    function fillFrom(start, digits, stagger) {
        const count = Math.min(digits.length, boxes.length - start);
        for (let k = 0; k < count; k++) {
            setTimeout(function () {
                const box = boxes[start + k];
                setDigit(box, digits[k]);
                replay(box, "pop");
                focusBox(start + k + 1);                 // clamps on the last box
                if (k === count - 1) refreshState();
            }, k * stagger);
        }
    }

    boxes.forEach(function (box, i) {
        box.addEventListener("input", function () {
            const digits = box.value.replace(/\D/g, "");
            clearError();

            // a whole code arrived in one box (SMS autofill): spread it out
            if (digits.length > 1) {
                fillFrom(digits.length >= boxes.length ? 0 : i, digits, 55);
                return;
            }

            const hadDigit = (box.dataset.prev || "") !== "";
            setDigit(box, digits);

            if (digits) {
                replay(box, "pop");
                if (i < LAST) focusBox(i + 1);
            } else if (hadDigit) {
                replay(box, "clear");
            }
            refreshState();
        });

        box.addEventListener("keydown", function (e) {
            if (e.key === "Backspace" && !box.value && i > 0) {
                e.preventDefault();
                const prev = boxes[i - 1];
                if (prev.value) {
                    setDigit(prev, "");
                    replay(prev, "clear");
                }
                clearError();
                focusBox(i - 1);
                refreshState();
            } else if (e.key === "ArrowLeft") {
                e.preventDefault();
                focusBox(i - 1);
            } else if (e.key === "ArrowRight") {
                e.preventDefault();
                focusBox(i + 1);
            } else if (e.key === "Home") {
                e.preventDefault();
                focusBox(0);
            } else if (e.key === "End") {
                e.preventDefault();
                focusBox(LAST);
            }
        });

        box.addEventListener("focus", function () {
            box.select();
        });

        // Paste a code into any box: a full code always starts from the first box
        box.addEventListener("paste", function (e) {
            e.preventDefault();
            const pasted = (e.clipboardData || window.clipboardData).getData("text").replace(/\D/g, "");
            if (!pasted) return;
            clearError();
            fillFrom(pasted.length >= boxes.length ? 0 : i, pasted, 55);
        });
    });

    // Start typing once the boxes have risen in (unless the visitor already started)
    setTimeout(function () {
        const started = boxes.some(b => b.value !== "" || b === document.activeElement);
        if (!started) focusBox(0);
    }, 800);

    // The server sent the page back with an error (wrong or expired code): shake the row too
    if (!banner.hidden) {
        setTimeout(function () {
            boxesWrap.classList.add("is-error");
            replay(boxesWrap, "shake");
        }, 700);
    }

    /* ---------- form submit ---------- */
    form.addEventListener("submit", function (e) {
        if (e.submitter && e.submitter.name === "resend") {
            resendBtn.textContent = "Sending...";
            return;                                       // resend skips validation
        }
        if (submitting) { e.preventDefault(); return; }

        const emptyIndex = boxes.findIndex(b => b.value === "");
        if (emptyIndex !== -1) {
            e.preventDefault();
            showError("Please enter the complete 6-digit code.");
            focusBox(emptyIndex);
            return;
        }

        submitting = true;
        verifyBtn.classList.add("is-loading");
        verifyLabel.textContent = "Verifying...";
    });

    /* ---------- countdown (uses the clock, so it stays right in a background tab) ---------- */
    const endsAt = Date.now() + (parseInt(timerEl.dataset.remaining, 10) || 0) * 1000;

    function renderTimer() {
        const remaining = Math.max(0, Math.ceil((endsAt - Date.now()) / 1000));
        const m = Math.floor(remaining / 60);
        const s = remaining % 60;
        timerEl.textContent = String(m).padStart(2, "0") + ":" + String(s).padStart(2, "0");
        timerSuffix.textContent = remaining > 0 ? "remaining" : "expired";
        timerWrap.classList.toggle("is-expired", remaining <= 0);
        return remaining;
    }

    if (renderTimer() > 0) {
        const countdown = setInterval(function () {
            if (renderTimer() <= 0) clearInterval(countdown);
        }, 500);
    }

    /* ---------- "Resend Code" has a 1 minute break after every code that was sent ---------- */
    function renderResend() {
        const left = Math.max(0, Math.ceil((resendEndsAt - Date.now()) / 1000));
        resendBtn.disabled = left > 0;
        resendBtn.textContent = left > 0
            ? resendText + " in " + Math.floor(left / 60) + ":" + String(left % 60).padStart(2, "0")
            : resendText;
        return left;
    }

    if (renderResend() > 0) {
        const resendWait = setInterval(function () {
            if (renderResend() <= 0) clearInterval(resendWait);
        }, 500);
    }

    /* ---------- Back button (browser) resets the loading state ---------- */
    window.addEventListener("pageshow", function () {
        submitting = false;
        verifyBtn.classList.remove("is-loading");
        verifyLabel.textContent = verifyText;
        renderResend();
    });
});
}
