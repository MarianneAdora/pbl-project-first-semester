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
            submitting = false;
            fileCard.classList.remove('is-locked');
            submitBtn.classList.remove('is-loading');
            submitBtn.disabled = false;
            submitBtn.classList.add('is-ready');
            submitLabel.textContent = submitText;
            showMsg((err && err.message) || 'We could not submit your application. Please try again.');
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