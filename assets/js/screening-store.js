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