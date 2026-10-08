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
   Screening.submit({answers, video})     sends everything (returns a Promise)
   Screening.isSubmitted() / clear()
   ========================================================================== */
(function () {
    var KEY_ANSWERS = 'expertise.screening.answers';
    var KEY_INDEX = 'expertise.screening.index';
    var KEY_OWNER = 'expertise.screening.owner';
    var KEY_STARTED = 'expertise.screening.started';
    var KEY_DONE = 'expertise.screening.done';

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
        remove(KEY_ANSWERS); remove(KEY_INDEX); remove(KEY_STARTED); remove(KEY_DONE);
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
        return post({ action: 'submit', answers: payload.answers, video: payload.video }).then(function (data) {
            write(KEY_DONE, token || '1');
            return data;
        });
    }

    function isSubmitted() { return read(KEY_DONE) === (token || '1'); }

    function clear() { remove(KEY_ANSWERS); remove(KEY_INDEX); }

    window.Screening = {
        load: load, save: save, getIndex: getIndex, setIndex: setIndex, today: today,
        validate: validate, firstIncomplete: firstIncomplete,
        startOnce: startOnce, submit: submit, isSubmitted: isSubmitted, clear: clear
    };
})();