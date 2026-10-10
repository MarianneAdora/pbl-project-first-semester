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
        if (key === 'evaluation') playEvaluation();
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
    var lastEmail = null;          // the email that was just sent (for "View email")

    var ICON = {
        cal: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M19 3h-1V1h-2v2H8V1H6v2H5c-1.11 0-1.99.9-1.99 2L3 19c0 1.1.89 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm0 16H5V8h14v11zM7 10h5v5H7z"/></svg>',
        clock: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M11.99 2C6.47 2 2 6.48 2 12s4.47 10 9.99 10C17.52 22 22 17.52 22 12S17.52 2 11.99 2zM12 20c-4.42 0-8-3.58-8-8s3.58-8 8-8 8 3.58 8 8-3.58 8-8 8zm.5-13H11v6l5.25 3.15.75-1.23-4.5-2.67z"/></svg>',
        chat: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M21 6h-2v9H6v2c0 .55.45 1 1 1h11l4 4V7c0-.55-.45-1-1-1zm-4 6V3c0-.55-.45-1-1-1H3c-.55 0-1 .45-1 1v14l4-4h10c.55 0 1-.45 1-1z"/></svg>',
        ok: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 15l-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z"/></svg>',
        chev: '<svg class="chev" viewBox="0 0 24 24" aria-hidden="true"><path d="M7.41 8.59 12 13.17l4.59-4.58L18 10l-6 6-6-6z"/></svg>'
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
        return '<div class="iv-row">' + icon +
            '<span class="iv-label">' + esc(label) + '</span>' +
            '<span class="iv-value">' + esc(value) + '</span></div>';
    }

    function timeOptions(selected) {
        var html = '<option value="" disabled' + (selected ? '' : ' selected') + '>Select a time</option>';
        (D.slots || []).forEach(function (s) {
            html += '<option value="' + esc(s.value) + '"' + (s.value === selected ? ' selected' : '') + '>' + esc(s.label) + '</option>';
        });
        return html;
    }

    function render() {
        var b = badge();
        var html = '<h3 class="iv-title">Interview Schedule</h3>' +
            '<div class="iv-sub"><span class="iv-sub-text">' + esc(subtitle()) + '</span>' +
            '<span class="iv-badge ' + b.cls + '">' + esc(b.text) + '</span></div>';

        html += '<div class="iv-card">' +
            row(ICON.cal, 'Date', iv.current.date) +
            row(ICON.clock, 'Time', iv.current.time) +
            (mode === 'summary' ? row(ICON.chat, 'Interview Type', iv.type) : '') +
            '</div>';

        if (mode === 'adjust') {
            var startDate = iv.current.raw_date && iv.current.raw_date >= D.today ? iv.current.raw_date : D.today;
            html += '<h3 class="iv-adjust-title">Adjust Schedule</h3>' +
                '<div class="iv-fields">' +
                  '<div class="iv-field"><label for="ivDate">Preferred Date<b>*</b></label>' +
                    '<div class="iv-input" id="ivDateBox">' + ICON.cal +
                      '<input type="date" id="ivDate" min="' + esc(D.today) + '" max="' + esc(D.maxDate) + '" value="' + esc(startDate) + '" required></div></div>' +
                  '<div class="iv-field"><label for="ivTime">Available Time<b>*</b></label>' +
                    '<div class="iv-input" id="ivTimeBox">' + ICON.clock +
                      '<select id="ivTime" required>' + timeOptions('') + '</select>' + ICON.chev + '</div></div>' +
                '</div>' +
                '<p class="iv-error" id="ivError" role="alert"></p>' +
                '<p class="iv-hint">Interviews are held Monday to Friday. The applicant will be emailed the new schedule.</p>' +
                '<div class="iv-adjust-actions">' +
                  '<button type="button" class="iv-btn cancel" data-act="cancel">Cancel</button>' +
                  '<button type="button" class="iv-btn send" data-act="send" id="ivSend">Send New Schedule</button>' +
                '</div>';
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
                        : 'The applicant has been notified of the confirmed schedule.') + '</span>' +
                '</div></div>' +
                '<div class="iv-actions">' +
                  (lastEmail ? '<button type="button" class="iv-btn neutral" data-act="email">View Email</button>' : '') +
                  '<button type="button" class="iv-btn neutral" data-act="resched">Re-schedule</button>' +
                '</div></div>';
        }

        root.innerHTML = html;
    }

    function setMode(next, from, focusDate) {
        mode = next;
        adjustFrom = from || '';
        render();
        if (focusDate) {
            var d = $('ivDate');
            if (d) d.focus({ preventScroll: true });
        }
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
        $('approveText').textContent =
            iv.current.date + ' at ' + iv.current.time + ' (' + iv.type + '). ' +
            'An email saying this schedule is confirmed will be sent to ' + D.email + ' right away.';
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
    function fieldError(box, message) {
        var err = $('ivError');
        if (err) err.textContent = message || '';
        ['ivDateBox', 'ivTimeBox'].forEach(function (id) {
            var el = $(id);
            if (el) el.classList.toggle('has-error', !!message && el === box);
        });
    }

    // "2026-10-17" -> Saturday / Sunday? (no timezone maths, so the day never shifts)
    function isWeekend(value) {
        var p = String(value).split('-');
        var day = new Date(Number(p[0]), Number(p[1]) - 1, Number(p[2])).getDay();
        return day === 0 || day === 6;
    }

    function sendNewSchedule() {
        if (busy) return;
        var date = $('ivDate').value;
        var time = $('ivTime').value;

        if (!date) { fieldError($('ivDateBox'), 'Please choose a date.'); $('ivDate').focus(); return; }
        if (date < D.today) { fieldError($('ivDateBox'), 'Please choose today or a later date.'); $('ivDate').focus(); return; }
        if (date > D.maxDate) { fieldError($('ivDateBox'), 'Please choose a date within the next 60 days.'); $('ivDate').focus(); return; }
        if (isWeekend(date)) { fieldError($('ivDateBox'), 'Interviews are held Monday to Friday. Please choose a weekday.'); $('ivDate').focus(); return; }
        if (!time) { fieldError($('ivTimeBox'), 'Please choose a time.'); $('ivTime').focus(); return; }
        if (date === iv.current.raw_date && time === iv.current.raw_time) {
            fieldError($('ivTimeBox'), 'That is the same schedule as before. Choose a different date or time.');
            return;
        }
        fieldError(null, '');

        busy = true;
        var send = $('ivSend');
        var cancel = root.querySelector('[data-act="cancel"]');
        send.disabled = true;
        cancel.disabled = true;
        send.textContent = 'Sending...';

        post({ action: 'interview_reschedule', date: date, time: time }).then(function (data) {
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
            send.textContent = 'Send New Schedule';
            fieldError(null, err.message);
        });
    }

    /* ---------- buttons (one listener for the whole panel) ---------- */
    root.addEventListener('click', function (e) {
        var btn = e.target.closest('[data-act]');
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

    // typing / picking clears the message
    root.addEventListener('input', function (e) {
        if (e.target && (e.target.id === 'ivDate' || e.target.id === 'ivTime')) fieldError(null, '');
    });
    root.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' && e.target && e.target.id === 'ivDate') { e.preventDefault(); sendNewSchedule(); }
    });

    render();
})();
