/* ==========================================================================
   Applicant Details (hr/details.php)
   Load AFTER hr-common.js (it uses HR.toast). The page prints  window.DETAILS  before this file:
     id, name, email      the applicant
     endpoint             hr/static-details.php now, the backend later (same two POST actions)
     today, maxDate, slots  for the "Adjust Schedule" form (weekdays only, same slots as the applicant's picker)
     tab, locked          the open tab, and the message for every locked tab
     interview            the schedule (null when the tab is not open for this applicant)
     videoUrl             the video file (empty until the upload storage is connected)

   Part 1  tabs (locked tabs explain why they are locked)
   Part 2  video
   Part 3  interview schedule: Available -> email, Not available -> new schedule -> email
           (the date + time pickers are the same as on the applicant result page)
   ========================================================================== */
(function () {
    'use strict';

    var D = window.DETAILS || {};

    function $(id) { return document.getElementById(id); }
    function esc(value) {
        return String(value == null ? '' : value).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }
    function toast(message, opts) {
        if (window.HR && typeof window.HR.toast === 'function') return window.HR.toast(message, opts || {});
        return null;
    }

    /* ======================================================================
       PART 1: tabs
       ====================================================================== */
    var tabs = Array.prototype.slice.call(document.querySelectorAll('.tab'));
    var panels = Array.prototype.slice.call(document.querySelectorAll('.panel'));

    // One red line under the active tab that SLIDES to the next tab (instead of each tab drawing its own)
    var tabBar = $('tabs');
    var ink = null;
    if (tabBar) {
        ink = document.createElement('span');
        ink.className = 'tab-ink no-anim';
        ink.setAttribute('aria-hidden', 'true');
        tabBar.appendChild(ink);
    }
    function moveInk(instant) {
        if (!ink) return;
        var cur = tabs.filter(function (t) { return t.classList.contains('is-active'); })[0];
        if (!cur) return;
        var w = cur.offsetWidth * 0.64;
        ink.classList.toggle('no-anim', !!instant);
        ink.style.setProperty('--ink-w', w + 'px');
        ink.style.setProperty('--ink-x', (cur.offsetLeft + (cur.offsetWidth - w) / 2) + 'px');
        if (instant) { void ink.offsetWidth; ink.classList.remove('no-anim'); }
    }

    // The bars and the ring fill up each time the AI Evaluation tab opens
    function playEvaluation() {
        var panel = $('panel-evaluation');
        if (!panel) return;
        panel.classList.remove('is-in');
        void panel.offsetWidth;
        window.requestAnimationFrame(function () {
            window.requestAnimationFrame(function () { panel.classList.add('is-in'); });
        });
    }

    function activate(key, silent) {
        var btn = tabs.filter(function (t) { return t.dataset.tab === key; })[0];
        var panel = $('panel-' + key);
        if (!btn || !panel) return;

        if (btn.classList.contains('is-locked')) return;

        tabs.forEach(function (t) {
            var on = t === btn;
            t.classList.toggle('is-active', on);
            t.setAttribute('aria-selected', on ? 'true' : 'false');
            t.tabIndex = on ? 0 : -1;
        });
        panels.forEach(function (p) {
            var on = p === panel;
            p.hidden = !on;
            p.classList.toggle('is-active', on);
        });

        if (!silent) {                                  // keep the tab in the address (reload / share)
            try {
                var url = new URL(window.location.href);
                if (key === 'overview') url.searchParams.delete('tab'); else url.searchParams.set('tab', key);
                window.history.replaceState(null, '', url.toString());
            } catch (e) { /* old browser: the tab still changes */ }
        }
        moveInk(false);
        if (!silent) {                                  // every tab opens from its very top, never from the old scroll spot
            var sc = $('details');
            if (sc && sc.scrollTop > 0) {
                sc.style.scrollBehavior = 'auto';       // jump at once (a smooth glide would be cut short by the header changing size)
                sc.scrollTop = 0;
                sc.style.scrollBehavior = '';
                wrapReset();
            }
        }
        if (key === 'evaluation') playEvaluation();
    }

    // back at the top: the header opens up again straight away
    function wrapReset() {
        var wrap = $('detailsSticky');
        if (wrap) { wrap.classList.remove('is-compact'); wrap.classList.remove('is-stuck'); }
    }

    function explainLocked(btn) {
        toast((D.locked && D.locked[btn.dataset.tab]) || 'This section is not available yet.', { duration: 4500 });
        btn.classList.remove('shake');
        void btn.offsetWidth;
        btn.classList.add('shake');
        setTimeout(function () { btn.classList.remove('shake'); }, 450);
    }

    tabs.forEach(function (btn, i) {
        btn.addEventListener('click', function () {
            if (btn.classList.contains('is-locked')) { explainLocked(btn); return; }
            activate(btn.dataset.tab);
        });
        btn.addEventListener('keydown', function (e) {
            var next = null;
            if (e.key === 'ArrowRight') next = tabs[(i + 1) % tabs.length];
            else if (e.key === 'ArrowLeft') next = tabs[(i - 1 + tabs.length) % tabs.length];
            else if (e.key === 'Home') next = tabs[0];
            else if (e.key === 'End') next = tabs[tabs.length - 1];
            if (!next) return;
            e.preventDefault();
            next.focus();
            if (!next.classList.contains('is-locked')) activate(next.dataset.tab);
        });
    });

    moveInk(true);
    window.addEventListener('resize', function () { moveInk(true); });
    if (document.fonts && document.fonts.ready) document.fonts.ready.then(function () { moveInk(true); });

    // The top part (title, applicant card, tabs) stays put while the page scrolls; once the page has
    // scrolled it shrinks to a slim bar so the content keeps most of the screen.
    (function () {
        var wrap = $('detailsSticky');
        var scroller = $('details');                   // <main class="main-content details"> is what scrolls
        if (!wrap || !scroller) return;
        var compact = false;
        var queued = false;
        var lockUntil = 0;                              // after a change, wait for the animation to finish before deciding again

        function update() {
            queued = false;
            var y = scroller.scrollTop;
            var room = scroller.scrollHeight - scroller.clientHeight;
            wrap.classList.toggle('is-stuck', y > 4);

            if (Date.now() < lockUntil) return;
            // two thresholds (80 down / 10 up) so it never flickers at the edge; short pages never shrink
            var next = compact;
            if (!compact && y > 80 && room > 260) next = true;
            else if (compact && y < 10) next = false;
            if (next === compact) return;

            compact = next;
            wrap.classList.toggle('is-compact', compact);
            lockUntil = Date.now() + 480;
            window.setTimeout(update, 500);            // look again once the header has settled
        }

        scroller.addEventListener('scroll', function () {
            if (!queued) { queued = true; window.requestAnimationFrame(update); }
        }, { passive: true });
        update();
    })();

    if (D.tab === 'evaluation') playEvaluation();

    // "Back" returns to the exact page / filters of the list you came from
    var back = $('backLink');
    if (back) {
        back.addEventListener('click', function (e) {
            if (document.referrer && /\/(screeninglist|dashboardhr)\.php/i.test(document.referrer) && window.history.length > 1) {
                e.preventDefault();
                window.history.back();
            }
        });
    }

    /* ======================================================================
       PART 2: video
       ====================================================================== */
    var playBtn = $('videoPlayBtn');
    if (playBtn) {
        playBtn.addEventListener('click', function () {
            var video = $('videoEl');
            if (video) {
                video.scrollIntoView({ behavior: 'smooth', block: 'center' });
                var p = video.play();
                if (p && typeof p.catch === 'function') p.catch(function () { /* the person can press play */ });
                return;
            }
            var empty = $('videoEmpty');
            if (empty) {
                empty.classList.add('is-pulse');
                setTimeout(function () { empty.classList.remove('is-pulse'); }, 900);
            }
            toast('The video file is not stored yet, so it can not be played here.', { duration: 4500 });
        });
    }

    /* ======================================================================
       PART 3: interview schedule
       ====================================================================== */
    var root = $('interviewRoot');
    if (!root || !D.interview) return;

    var iv = D.interview;
    var mode = 'summary';          // 'summary' | 'adjust'
    var adjustFrom = '';           // 'reject' (Not available) | 'resched' (Re-schedule button)
    var busy = false;
    var firstPaint = true;         // the whole tab animates in once; later changes only animate what changed
    var lastEmail = D.lastEmail || null;     // the email that was sent (for "View email"; kept by the server)
    var pick = { date: '', time: '' };       // what HR chose in "Adjust Schedule" (time is 24-hour "13:00")

    var MONTHS = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
    var DOW = ['Su', 'Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa'];

    var ICON = {
        cal: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M19 3h-1V1h-2v2H8V1H6v2H5c-1.11 0-1.99.9-1.99 2L3 19c0 1.1.89 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm0 16H5V8h14v11zM7 10h5v5H7z"/></svg>',
        clock: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M11.99 2C6.47 2 2 6.48 2 12s4.47 10 9.99 10C17.52 22 22 17.52 22 12S17.52 2 11.99 2zM12 20c-4.42 0-8-3.58-8-8s3.58-8 8-8 8 3.58 8 8-3.58 8-8 8zm.5-13H11v6l5.25 3.15.75-1.23-4.5-2.67z"/></svg>',
        chat: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M21 6h-2v9H6v2c0 .55.45 1 1 1h11l4 4V7c0-.55-.45-1-1-1zm-4 6V3c0-.55-.45-1-1-1H3c-.55 0-1 .45-1 1v14l4-4h10c.55 0 1-.45 1-1z"/></svg>',
        ok: '<svg class="iv-ok-svg" viewBox="0 0 80 80" aria-hidden="true"><circle class="ok-circle" cx="40" cy="40" r="36"/><path class="ok-tick" d="M24 41l11 11 21-23"/></svg>',
        // the picker icons (same drawings as the applicant's page)
        pCal: '<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3.5" y="5" width="17" height="15" rx="2.5"/><path d="M3.5 10h17M8 3v4M16 3v4"/><path d="M8 14h.01M12 14h.01M16 14h.01M8 17.5h.01M12 17.5h.01"/></svg>',
        pClock: '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 7v5.5l3.5 2"/></svg>',
        pChev: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 9l6 6 6-6"/></svg>',
        pNext: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M9 6l6 6-6 6"/></svg>',
        pTick: '<svg class="picker__check" viewBox="0 0 24 24" aria-hidden="true"><path d="M9 16.2 4.8 12l-1.4 1.4L9 19 21 7l-1.4-1.4z"/></svg>',
        tick: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12.5l4.5 4.5L19 7.5"/></svg>'
    };

    /* ---------- talking to the server (hr/static-details.php now, the backend later) ---------- */
    function post(payload) {
        return fetch(D.endpoint, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
            body: JSON.stringify(Object.assign({ id: D.id }, payload))
        }).then(function (res) {
            return res.json().catch(function () { return {}; }).then(function (data) {
                if (!res.ok || data.status !== 'success') {
                    throw new Error(data.message || 'We could not reach the server. Please try again.');
                }
                return data;
            });
        });
    }

    /* ---------- small helpers ---------- */
    function two(n) { return (n < 10 ? '0' : '') + n; }
    function ymd(y, m, d) { return y + '-' + two(m + 1) + '-' + two(d); }          // m = 0..11

    // "2026-09-27" -> "September 27, 2026" (no timezone maths, so the day never shifts)
    function pretty(value) {
        var p = String(value).split('-');
        return MONTHS[Number(p[1]) - 1] + ' ' + Number(p[2]) + ', ' + p[0];
    }

    function weekend(value) {
        var p = String(value).split('-');
        var day = new Date(Number(p[0]), Number(p[1]) - 1, Number(p[2])).getDay();
        return day === 0 || day === 6;
    }

    function slotLabel(value) {
        var s = (D.slots || []).filter(function (x) { return x.value === value; })[0];
        return s ? s.label : '';
    }

    // returns '' when the date is fine, otherwise the message (same rules as the server)
    function dateProblem(value) {
        if (!/^\d{4}-\d{2}-\d{2}$/.test(value)) return 'Please choose a date.';
        if (value < D.today) return 'Please choose today or a later date.';
        if (value > D.maxDate) return 'Please choose a date within the next 60 days.';
        if (weekend(value)) return 'Interviews are held Monday to Friday. Please choose a weekday.';
        return '';
    }

    /* ---------- drawing ---------- */
    function badge() {
        if (mode === 'adjust' && adjustFrom === 'reject') return { cls: 'is-no', text: 'Not Available' };
        if (iv.status === 'confirmed') return { cls: 'is-confirmed', text: 'Confirmed' };
        if (iv.status === 'rescheduled') return { cls: 'is-rescheduled', text: 'Rescheduled' };
        return { cls: 'is-awaiting', text: 'Awaiting HR Confirmation' };
    }

    function subtitle() {
        if (mode === 'adjust' && adjustFrom === 'resched') return 'Current Schedule';
        if (iv.status === 'rescheduled') return 'New Schedule (set by HR)';
        return 'Applicant\u2019s Preferred Schedule';
    }

    function row(icon, label, value) {
        return '<div class="iv-row"><span class="iv-ico">' + icon + '</span>' +
            '<div class="iv-text"><span class="iv-label">' + esc(label) + '</span>' +
            '<span class="iv-value">' + esc(value) + '</span></div></div>';
    }

    // Chosen > HR answers > Applicant is emailed
    function tracker() {
        var answered = iv.status !== 'awaiting';
        var steps = [
            { s: 'done', title: 'Schedule chosen', text: 'Applicant picked ' + iv.preferred.date },
            { s: answered ? 'done' : 'current',
              title: answered ? (iv.status === 'confirmed' ? 'HR confirmed' : 'HR set a new schedule') : 'HR response',
              text: answered ? 'Answered' : 'Waiting for you' },
            { s: answered ? 'done' : 'todo', title: 'Applicant emailed', text: answered ? D.email : 'After you answer' }
        ];
        return '<ol class="iv-track" aria-label="Interview progress">' + steps.map(function (st, i) {
            var next = steps[i + 1];
            var line = next ? '<span class="iv-step-line' + (next.s === 'done' ? ' is-full' : (next.s === 'current' ? ' is-half' : '')) + '" aria-hidden="true"></span>' : '';
            return '<li class="iv-step is-' + st.s + '" style="--n:' + i + '"' + (st.s === 'current' ? ' aria-current="step"' : '') + '>' +
                '<div class="iv-step-top"><span class="iv-step-dot">' + (st.s === 'done' ? ICON.tick : '<b>' + (i + 1) + '</b>') + '</span>' + line + '</div>' +
                '<div class="iv-step-text"><strong>' + esc(st.title) + '</strong><small>' + esc(st.text) + '</small></div></li>';
        }).join('') + '</ol>';
    }

    function adjustForm() {
        var slots = (D.slots || []).map(function (s, k) {
            return '<li class="picker__option" role="option" aria-selected="false" data-value="' + esc(s.value) + '" style="--k:' + k + '">' +
                '<span class="picker__optlabel">' + esc(s.label) + '</span>' + ICON.pTick + '</li>';
        }).join('');

        return '<div class="iv-adjust">' +
            '<h3 class="iv-adjust-title">Adjust Schedule</h3>' +
            '<div class="iv-fields">' +
              '<div class="iv-field">' +
                '<span class="iv-flabel" id="ivDateLabel">Preferred Date<b>*</b></span>' +
                '<div class="picker" id="ivDatePicker">' +
                  '<button type="button" class="picker__btn" id="ivDateBtn" aria-haspopup="dialog" aria-expanded="false" aria-labelledby="ivDateLabel ivDateValue">' +
                    '<span class="picker__icon">' + ICON.pCal + '</span>' +
                    '<span class="picker__value is-placeholder" id="ivDateValue">Select a date</span>' +
                    '<span class="picker__chevron">' + ICON.pChev + '</span></button>' +
                  '<div class="picker__pop pcal" id="ivDatePop" role="dialog" aria-label="Choose a date"></div>' +
                '</div></div>' +
              '<div class="iv-field">' +
                '<span class="iv-flabel" id="ivTimeLabel">Available Time<b>*</b></span>' +
                '<div class="picker" id="ivTimePicker">' +
                  '<button type="button" class="picker__btn" id="ivTimeBtn" aria-haspopup="listbox" aria-expanded="false" aria-labelledby="ivTimeLabel ivTimeValue">' +
                    '<span class="picker__icon">' + ICON.pClock + '</span>' +
                    '<span class="picker__value is-placeholder" id="ivTimeValue">Select a time</span>' +
                    '<span class="picker__chevron">' + ICON.pChev + '</span></button>' +
                  '<ul class="picker__pop picker__menu" id="ivTimeMenu" role="listbox" aria-labelledby="ivTimeLabel" tabindex="-1">' + slots + '</ul>' +
                '</div></div>' +
            '</div>' +
            '<div class="iv-new">' +
              '<span class="iv-new-title">New schedule</span>' +
              '<span class="iv-new-item">' + ICON.cal + '<span id="ivPickedDate">Not chosen yet</span></span>' +
              '<span class="iv-new-item">' + ICON.clock + '<span id="ivPickedTime">Not chosen yet</span></span>' +
            '</div>' +
            '<p class="iv-hint">Interviews are held Monday to Friday. The applicant will be emailed the new schedule.</p>' +
            '<p class="iv-error" id="ivError" role="alert"></p>' +
            '<div class="iv-adjust-actions">' +
              '<button type="button" class="iv-btn cancel" data-act="cancel">Cancel</button>' +
              '<button type="button" class="iv-btn send" data-act="send" id="ivSend" disabled>Send New Schedule</button>' +
            '</div></div>';
    }

    function render() {
        var b = badge();
        var html = '<div class="iv-head"><h3 class="iv-title">Interview Schedule</h3>' +
            '<span class="iv-badge ' + b.cls + '">' + esc(b.text) + '</span></div>' + tracker() +
            '<p class="iv-sub-text">' + esc(subtitle()) + '</p>';

        html += '<div class="iv-card">' +
            row(ICON.cal, 'Date', iv.current.date) +
            row(ICON.clock, 'Time', iv.current.time) +
            (mode === 'summary' ? row(ICON.chat, 'Interview Type', iv.type) : '') +
            '</div>';

        if (mode === 'adjust') {
            html += adjustForm();
        } else if (iv.status === 'awaiting') {
            html += '<div class="iv-foot"><div class="iv-ask">' +
                '<p>Is this schedule available?</p>' +
                '<div class="iv-actions">' +
                  '<button type="button" class="iv-btn yes" data-act="approve">Available</button>' +
                  '<button type="button" class="iv-btn no" data-act="reject">Not Available</button>' +
                '</div></div></div>';
        } else {
            var rescheduled = iv.status === 'rescheduled';
            html += (rescheduled
                    ? '<p class="iv-was">The applicant\u2019s first choice was <s>' + esc(iv.preferred.date) + ' at ' + esc(iv.preferred.time) + '</s>.</p>'
                    : '') +
                '<div class="iv-foot"><div class="iv-ok">' + ICON.ok + '<div>' +
                  '<strong>' + (rescheduled ? 'New Schedule Sent' : 'Schedule Confirmed') + '</strong>' +
                  '<span>' + (rescheduled
                        ? 'The new schedule was emailed to ' + esc(D.email) + '.'
                        : 'The applicant was emailed the confirmed schedule.') + '</span>' +
                '</div></div>' +
                '<div class="iv-actions">' +
                  (lastEmail ? '<button type="button" class="iv-btn neutral" data-act="email">View Email</button>' : '') +
                  '<button type="button" class="iv-btn neutral" data-act="resched">Re-schedule</button>' +
                '</div></div>';
        }

        root.classList.toggle('iv--first', firstPaint);
        firstPaint = false;
        root.innerHTML = html;
    }

    function setMode(next, from, focusDate) {
        mode = next;
        adjustFrom = from || '';
        pick = { date: '', time: '' };
        render();
        if (focusDate) {
            var d = $('ivDateBtn');
            if (d) d.focus({ preventScroll: true });
        }
    }

    /* ---------- the date + time pickers (same behaviour as the applicant's result page) ---------- */
    var viewY = 0, viewM = 0, active = -1;

    function pickerParts(kind) {
        return kind === 'date'
            ? { box: $('ivDatePicker'), btn: $('ivDateBtn') }
            : { box: $('ivTimePicker'), btn: $('ivTimeBtn') };
    }

    function setOpen(kind, open) {
        var p = pickerParts(kind);
        if (!p.box) return;
        p.box.classList.toggle('open', open);
        p.btn.setAttribute('aria-expanded', open ? 'true' : 'false');
    }

    function closePickers(except) {
        if (except !== 'date') setOpen('date', false);
        if (except !== 'time') setOpen('time', false);
    }

    function pickerOpen() { return !!root.querySelector('.picker.open'); }

    function fieldError(which, message) {
        var err = $('ivError');
        if (err) {
            err.textContent = message || '';
            err.classList.remove('is-in');
            if (message) { void err.offsetWidth; err.classList.add('is-in'); }
        }
        var d = $('ivDateBtn'), t = $('ivTimeBtn');
        if (d) d.classList.toggle('has-error', !!message && which === 'date');
        if (t) t.classList.toggle('has-error', !!message && which === 'time');
    }

    function refreshPick(bump) {
        var okDate = pick.date && !dateProblem(pick.date);
        var pd = $('ivPickedDate'), pt = $('ivPickedTime'), send = $('ivSend');
        if (!pd || !pt || !send) return;
        pd.textContent = okDate ? pretty(pick.date) : 'Not chosen yet';
        pt.textContent = pick.time ? slotLabel(pick.time) : 'Not chosen yet';
        pd.classList.toggle('is-set', !!okDate);
        pt.classList.toggle('is-set', !!pick.time);
        send.disabled = busy || !(okDate && pick.time);
        send.classList.toggle('is-ready', !send.disabled);
        if (bump) {
            var el = bump === 'date' ? pd : pt;
            el.classList.remove('bump');
            void el.offsetWidth;
            el.classList.add('bump');
        }
    }

    function drawCalendar(direction) {
        var pop = $('ivDatePop');
        if (!pop) return;
        var minP = D.today.split('-').map(Number);
        var maxP = D.maxDate.split('-').map(Number);
        var first = new Date(viewY, viewM, 1).getDay();
        var days = new Date(viewY, viewM + 1, 0).getDate();
        var here = viewY * 12 + viewM;
        var canPrev = here > minP[0] * 12 + minP[1] - 1;
        var canNext = here < maxP[0] * 12 + maxP[1] - 1;
        var cur = iv.current.raw_date;

        var html = '<div class="pcal__head">' +
            '<button type="button" class="pcal__nav pcal__nav--prev" aria-label="Previous month"' + (canPrev ? '' : ' disabled') + '>' + ICON.pNext + '</button>' +
            '<div class="pcal__title" aria-live="polite">' + MONTHS[viewM] + ' ' + viewY + '</div>' +
            '<button type="button" class="pcal__nav pcal__nav--next" aria-label="Next month"' + (canNext ? '' : ' disabled') + '>' + ICON.pNext + '</button>' +
            '</div><div class="pcal__dow">' + DOW.map(function (d) { return '<span>' + d + '</span>'; }).join('') + '</div>' +
            '<div class="pcal__grid' + (direction ? ' pcal__grid--' + direction : '') + '">';

        for (var i = 0; i < first; i++) html += '<span></span>';
        for (var d = 1; d <= days; d++) {
            var value = ymd(viewY, viewM, d);
            var off = value < D.today || value > D.maxDate || weekend(value);
            var cls = 'pcal__day' + (weekend(value) ? ' is-weekend' : '') + (value === D.today ? ' is-today' : '') +
                (value === cur ? ' is-current' : '') + (value === pick.date ? ' is-selected' : '');
            html += '<button type="button" class="' + cls + '" data-value="' + value + '"' + (off ? ' disabled' : '') +
                (value === cur ? ' title="Current schedule"' : '') + '>' + d + '</button>';
        }
        html += '</div><p class="pcal__hint">Interviews are held Monday to Friday.' +
            '<span class="pcal__legend"><i></i>Current schedule</span></p>';
        pop.innerHTML = html;
    }

    function toggleDate() {
        var p = pickerParts('date');
        var open = !p.box.classList.contains('open');
        closePickers('date');
        if (open) {
            var start = pick.date || (iv.current.raw_date >= D.today ? iv.current.raw_date : D.today);
            var parts = start.split('-').map(Number);
            viewY = parts[0]; viewM = parts[1] - 1;
            drawCalendar(null);
        }
        setOpen('date', open);
    }

    function timeOptionsList() { return Array.prototype.slice.call(root.querySelectorAll('.picker__option')); }

    function setActive(i) {
        var opts = timeOptionsList();
        if (!opts.length) return;
        active = Math.max(0, Math.min(opts.length - 1, i));
        opts.forEach(function (o, k) { o.classList.toggle('is-focused', k === active); });
        opts[active].scrollIntoView({ block: 'nearest' });
    }

    function openTime() {
        closePickers('time');
        setOpen('time', true);
        var opts = timeOptionsList();
        var sel = opts.filter(function (o) { return o.getAttribute('aria-selected') === 'true'; })[0];
        setActive(sel ? opts.indexOf(sel) : 0);
        var menu = $('ivTimeMenu');
        if (menu) menu.focus({ preventScroll: true });
    }

    function chooseTime(option) {
        timeOptionsList().forEach(function (o) { o.setAttribute('aria-selected', o === option ? 'true' : 'false'); });
        pick.time = option.dataset.value;
        var v = $('ivTimeValue');
        v.textContent = slotLabel(pick.time);
        v.classList.remove('is-placeholder');
        fieldError(null, '');
        refreshPick('time');
        closePickers(null);
        $('ivTimeBtn').focus();
    }

    function chooseDay(day) {
        pick.date = day.dataset.value;
        var v = $('ivDateValue');
        v.textContent = pretty(pick.date);
        v.classList.remove('is-placeholder');
        fieldError(null, '');
        drawCalendar(null);
        var chosen = $('ivDatePop').querySelector('.is-selected');
        if (chosen) chosen.classList.add('picked');
        refreshPick('date');
        setTimeout(function () { closePickers(null); var b = $('ivDateBtn'); if (b) b.focus(); }, 160);
    }

    /* ---------- the two modals ---------- */
    var approveModal = $('approveModal');
    var approveConfirm = $('approveConfirm');
    var approveCancel = $('approveCancel');
    var emailModal = $('emailModal');

    function openModal(m, focusEl) {
        if (!m) return;
        m.classList.add('active');
        m.setAttribute('aria-hidden', 'false');
        if (focusEl) setTimeout(function () { focusEl.focus(); }, 60);
    }
    function closeModal(m) {
        if (!m) return;
        m.classList.remove('active');
        m.setAttribute('aria-hidden', 'true');
    }

    function openApprove() {
        $('approveWhen').innerHTML =
            '<span class="ap-when"><b>' + esc(iv.current.date) + '</b><span>at</span><b>' + esc(iv.current.time) + '</b></span>' +
            '<span class="ap-type">' + esc(iv.type) + '</span>';
        $('approveText').innerHTML =
            'An email saying this schedule is confirmed, with a link to the applicant\u2019s status page, will be sent to <b>' + esc(D.email) + '</b> right away.';
        approveConfirm.disabled = false;
        approveConfirm.textContent = 'Confirm & Send Email';
        openModal(approveModal, approveConfirm);
    }

    function showEmail(email) {
        if (!email || !emailModal) return;
        $('emailTo').textContent = email.to;
        $('emailSubject').textContent = email.subject;
        $('emailFrame').srcdoc = email.html;
        openModal(emailModal, $('emailClose'));
    }

    [approveModal, emailModal].forEach(function (m) {
        if (!m) return;
        m.addEventListener('click', function (e) { if (e.target === m && !busy) closeModal(m); });
    });
    if (approveCancel) approveCancel.addEventListener('click', function () { if (!busy) closeModal(approveModal); });
    if ($('emailClose')) $('emailClose').addEventListener('click', function () { closeModal(emailModal); });

    document.addEventListener('keydown', function (e) {
        if (e.key !== 'Escape' || busy) return;
        if (emailModal && emailModal.classList.contains('active')) closeModal(emailModal);
        else if (approveModal && approveModal.classList.contains('active')) closeModal(approveModal);
        else if (pickerOpen()) {
            var wasDate = $('ivDatePicker') && $('ivDatePicker').classList.contains('open');
            closePickers(null);
            var b = $(wasDate ? 'ivDateBtn' : 'ivTimeBtn');
            if (b) b.focus();
        }
    });

    // a click outside closes the open picker (the path is read from the event itself, because the
    // calendar redraws its buttons on a click)
    document.addEventListener('click', function (e) {
        if (!pickerOpen()) return;
        var path = e.composedPath ? e.composedPath() : [];
        var inside = path.some(function (n) { return n && n.classList && n.classList.contains('picker'); });
        if (!inside) closePickers(null);
    });

    /* ---------- Available: confirm, then the email goes out ---------- */
    approveConfirm.addEventListener('click', function () {
        if (busy) return;
        busy = true;
        approveConfirm.disabled = true;
        approveCancel.disabled = true;
        approveConfirm.textContent = 'Sending...';

        post({ action: 'interview_approve' }).then(function (data) {
            iv = data.interview;
            lastEmail = data.email;
            closeModal(approveModal);
            setMode('summary');
            toast('Schedule confirmed. An email was sent to ' + D.email + '.', {
                actionLabel: 'View email',
                onAction: function () { showEmail(lastEmail); },
                duration: 8000
            });
        }).catch(function (err) {
            toast(err.message, { error: true, duration: 6000 });
            approveConfirm.disabled = false;
            approveConfirm.textContent = 'Confirm & Send Email';
        }).then(function () {
            busy = false;
            approveCancel.disabled = false;
        });
    });

    /* ---------- Not available / Re-schedule: a new date and time, then the email ---------- */
    function sendNewSchedule() {
        if (busy) return;
        var problem = dateProblem(pick.date);
        if (problem) { fieldError('date', problem); $('ivDateBtn').focus(); return; }
        if (!pick.time) { fieldError('time', 'Please choose a time.'); $('ivTimeBtn').focus(); return; }
        if (pick.date === iv.current.raw_date && pick.time === iv.current.raw_time) {
            fieldError('time', 'That is the same schedule as before. Choose a different date or time.');
            return;
        }
        fieldError(null, '');

        busy = true;
        var send = $('ivSend');
        var cancel = root.querySelector('[data-act="cancel"]');
        send.disabled = true;
        cancel.disabled = true;
        send.classList.remove('is-ready');
        send.classList.add('is-loading');
        send.textContent = 'Sending...';

        post({ action: 'interview_reschedule', date: pick.date, time: pick.time }).then(function (data) {
            iv = data.interview;
            lastEmail = data.email;
            busy = false;
            setMode('summary');
            toast('New schedule sent to ' + D.email + '.', {
                actionLabel: 'View email',
                onAction: function () { showEmail(lastEmail); },
                duration: 8000
            });
        }).catch(function (err) {
            busy = false;
            send.disabled = false;
            cancel.disabled = false;
            send.classList.remove('is-loading');
            send.classList.add('is-ready');
            send.textContent = 'Send New Schedule';
            fieldError(null, err.message);
        });
    }

    /* ---------- clicks + keys (one listener each for the whole panel) ---------- */
    root.addEventListener('click', function (e) {
        var t = e.target;

        // calendar: month arrows and days
        var nav = t.closest('.pcal__nav');
        if (nav) {
            if (nav.disabled) return;
            var step = nav.classList.contains('pcal__nav--next') ? 1 : -1;
            viewM += step;
            if (viewM > 11) { viewM = 0; viewY++; }
            if (viewM < 0) { viewM = 11; viewY--; }
            drawCalendar(step > 0 ? 'next' : 'prev');
            return;
        }
        var day = t.closest('.pcal__day');
        if (day) { if (!day.disabled) chooseDay(day); return; }

        // time list
        var option = t.closest('.picker__option');
        if (option) { chooseTime(option); return; }

        // the two picker buttons
        var pbtn = t.closest('.picker__btn');
        if (pbtn) {
            if (pbtn.id === 'ivDateBtn') toggleDate();
            else if ($('ivTimePicker').classList.contains('open')) closePickers(null);
            else openTime();
            return;
        }

        var btn = t.closest('[data-act]');
        if (!btn || busy) return;
        switch (btn.getAttribute('data-act')) {
            case 'approve': openApprove(); break;
            case 'reject':  setMode('adjust', 'reject', true); break;
            case 'resched': setMode('adjust', 'resched', true); break;
            case 'cancel':  setMode('summary'); break;
            case 'send':    sendNewSchedule(); break;
            case 'email':   showEmail(lastEmail); break;
        }
    });

    root.addEventListener('mousemove', function (e) {
        var option = e.target.closest ? e.target.closest('.picker__option') : null;
        if (option) setActive(timeOptionsList().indexOf(option));
    });

    root.addEventListener('keydown', function (e) {
        var id = e.target && e.target.id;
        if (id === 'ivTimeBtn' && (e.key === 'ArrowDown' || e.key === 'ArrowUp')) { e.preventDefault(); openTime(); return; }
        if (id !== 'ivTimeMenu') return;
        var opts = timeOptionsList();
        if (e.key === 'ArrowDown') { e.preventDefault(); setActive(active + 1); }
        else if (e.key === 'ArrowUp') { e.preventDefault(); setActive(active - 1); }
        else if (e.key === 'Home') { e.preventDefault(); setActive(0); }
        else if (e.key === 'End') { e.preventDefault(); setActive(opts.length - 1); }
        else if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); if (active >= 0) chooseTime(opts[active]); }
        else if (e.key === 'Tab') { closePickers(null); }
    });

    render();
})();