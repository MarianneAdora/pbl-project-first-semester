/* ==========================================================================
   Add Applicant behaviour.
   Entrance, sidebar, logout and the sidebar badges live in hr-common.js
   (load hr-common.js BEFORE this file).
   ========================================================================== */
document.addEventListener('DOMContentLoaded', () => {
    const form = document.getElementById('applicantForm');
    const formCard = document.getElementById('formCard');
    const successCard = document.getElementById('successCard');
    const sendBtn = document.getElementById('sendBtn');
    const cancelBtn = document.getElementById('cancelBtn');

    const fileInput = document.getElementById('cv');
    const fileBox = document.getElementById('fileBox');
    const fileName = document.getElementById('fileName');
    const fileRemove = document.getElementById('fileRemove');
    const FILE_HINT = fileName.textContent;

    const sentModal = document.getElementById('sentModal');
    const discardModal = document.getElementById('discardModal');

    const MAX_CV = 5 * 1024 * 1024;
    const CV_TYPES = ['pdf', 'doc', 'docx'];
    let sending = false;
    let lastApplicant = null;

    const fieldOf = (name) => form.querySelector(`.field[data-field="${name}"]`);

    /* ---------- validation ---------- */
    // Each rule returns the error text (or '' when the value is fine). The messages say WHAT is wrong.
    const rules = {
        name(v) {
            const t = v.trim();
            if (!t) return 'Please enter the full name.';
            if (/\d/.test(t)) return 'The name cannot contain numbers.';
            if (!/^[\p{L}\p{M}'.\- ]+$/u.test(t)) return 'Use letters only (spaces, apostrophes, periods and hyphens are fine).';
            if (t.replace(/[^\p{L}]/gu, '').length < 2) return 'The name is too short (at least 2 letters).';
            if (t.length > 100) return 'The name is too long (100 characters maximum).';
            return '';
        },
        email(v) {
            const t = v.trim();
            if (!t) return 'Please enter the email address.';
            if (/\s/.test(t)) return 'The email cannot contain spaces.';
            if (!t.includes('@')) return 'The email must include an @ (example: name@gmail.com).';
            if (t.split('@').length > 2) return 'The email can only have one @.';
            const [local, domain] = t.split('@');
            if (!local) return 'Add the part before the @ (example: name@gmail.com).';
            if (!domain) return 'Add the part after the @ (example: name@gmail.com).';
            if (!/\.[^.]{2,}$/.test(domain) || domain.startsWith('.') || domain.includes('..')) {
                return 'The email needs a valid domain (example: gmail.com).';
            }
            if (!/^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(t)) return 'Enter a valid email address (example: name@gmail.com).';
            return '';
        },
        contact(v) {
            const t = v.trim();
            if (!t) return 'Please enter the contact number.';
            if (/[A-Za-z]/.test(t)) return 'The contact number cannot contain letters.';
            if (!/^\+?[0-9][0-9\s-]*$/.test(t)) return 'Use digits only (you may start with + and use spaces or dashes).';
            const digits = t.replace(/\D/g, '').length;
            if (digits < 7) return 'The number is too short (at least 7 digits).';
            if (digits > 15) return 'The number is too long (15 digits maximum).';
            return '';
        },
        cv() {
            const f = fileInput.files[0];
            if (!f) return 'Please upload the applicant\'s CV.';
            const ext = f.name.includes('.') ? f.name.split('.').pop().toLowerCase() : '';
            if (!CV_TYPES.includes(ext)) return (ext ? '.' + ext + ' files are not allowed. ' : '') + 'Upload a PDF, DOC or DOCX file.';
            if (f.size > MAX_CV) return 'The CV is ' + prettySize(f.size) + '. It must be 5 MB or smaller.';
            if (f.size === 0) return 'This file is empty. Choose a different CV.';
            return '';
        }
    };

    const NOTE_MAX = 500;
    const noteInput = form.elements.note;
    const noteCount = document.getElementById('noteCount');

    function setError(name, message, shake) {
        const field = fieldOf(name);
        if (!field) return;
        const input = name === 'cv' ? fileInput : form.elements[name];
        field.classList.toggle('has-error', !!message);
        field.querySelector('.field-error').textContent = message || '';
        if (input) input.setAttribute('aria-invalid', message ? 'true' : 'false');
        if (message && shake) {
            field.classList.remove('shake');
            void field.offsetWidth;
            field.classList.add('shake');
        }
    }

    function validateField(name, shake) {
        const value = name === 'cv' ? '' : form.elements[name].value;
        const message = rules[name](value);
        setError(name, message, shake);
        return !message;
    }

    // Typing: once a box has been used, it is re-checked on every keystroke, so the message
    // appears the moment something is wrong and goes away the moment it is fixed.
    // Leaving a box that was used (even if now empty) also checks it.
    const touched = new Set();
    ['name', 'email', 'contact'].forEach(name => {
        const input = form.elements[name];
        input.setAttribute('aria-describedby', name + 'Error');
        fieldOf(name).querySelector('.field-error').id = name + 'Error';

        input.addEventListener('input', () => {
            touched.add(name);
            validateField(name, false);
        });
        input.addEventListener('blur', () => { if (touched.has(name)) validateField(name, false); });
    });

    // Note is optional, but it still tells you when you hit the limit
    function updateNote() {
        const len = noteInput.value.length;
        noteCount.textContent = len + '/' + NOTE_MAX;
        noteCount.classList.toggle('at-limit', len >= NOTE_MAX);
        setError('note', len >= NOTE_MAX ? 'You reached the ' + NOTE_MAX + '-character limit.' : '');
    }
    noteInput.addEventListener('input', updateNote);
    updateNote();

    /* ---------- CV picker (click or drag a file onto the box) ---------- */
    function prettySize(bytes) {
        return bytes < 1024 * 1024
            ? Math.max(1, Math.round(bytes / 1024)) + ' KB'
            : (bytes / 1024 / 1024).toFixed(1) + ' MB';
    }

    function showFile() {
        const f = fileInput.files[0];
        fileBox.classList.toggle('has-file', !!f);
        fileRemove.hidden = !f;
        fileName.textContent = f ? `${f.name} (${prettySize(f.size)})` : FILE_HINT;
        fileName.title = f ? f.name : '';
    }

    function clearFile() {
        fileInput.value = '';
        showFile();
    }

    fileInput.addEventListener('change', () => {
        showFile();
        if (!fileInput.files[0]) { setError('cv', ''); return; }
        const ok = validateField('cv', true);          // wrong type / too big: show why
        if (!ok) {
            // drop the unusable file so the box is empty again, but keep the message visible
            const msg = rules.cv();
            fileInput.value = '';
            showFile();
            setError('cv', msg, false);
        }
    });

    fileRemove.addEventListener('click', () => { clearFile(); setError('cv', ''); });

    ['dragenter', 'dragover'].forEach(type => fileBox.addEventListener(type, (e) => {
        e.preventDefault();
        fileBox.classList.add('drag');
    }));
    ['dragleave', 'drop'].forEach(type => fileBox.addEventListener(type, (e) => {
        e.preventDefault();
        fileBox.classList.remove('drag');
    }));
    fileBox.addEventListener('drop', (e) => {
        const f = e.dataTransfer && e.dataTransfer.files[0];
        if (!f) return;
        const dt = new DataTransfer();
        dt.items.add(f);
        fileInput.files = dt.files;
        fileInput.dispatchEvent(new Event('change'));
    });

    /* ---------- submit ---------- */
    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        if (sending) return;

        // validate everything, focus the first problem
        let firstBad = null;
        ['name', 'email', 'contact', 'cv'].forEach(name => {
            if (!validateField(name, true) && !firstBad) firstBad = name;
        });
        if (firstBad) {
            const bad = form.querySelectorAll('.field.has-error').length;
            HR.toast('Please fix the ' + bad + ' highlighted field' + (bad === 1 ? '' : 's') + ' before sending.', { error: true, duration: 4000 });
            const target = firstBad === 'cv' ? fileBox.querySelector('.choose-btn') : form.elements[firstBad];
            if (target && target.focus) target.focus({ preventScroll: false });
            return;
        }

        setSending(true);
        try {
            const data = await sendInvitation(new FormData(form));
            lastApplicant = data.applicant;
            HR.noteNewApplicant(data.applicant.id);      // red badge on Applicants > Screening List
            openSentModal(data.applicant);
        } catch (err) {
            HR.toast(err.message || 'Something went wrong. Please try again.', { error: true, duration: 8000 });
        } finally {
            setSending(false);
        }
    });

    /* ---------- sending (front end only) ----------
       There is no server yet, so this just waits a moment and pretends the email was sent.
       BACKEND: replace the body with a real request, for example:

           const res = await fetch('addapplicants.php', { method: 'POST', body: formData });
           const data = await res.json();                 // { status: 'success', applicant: { id, name, email, contact } }
           if (data.status !== 'success') throw new Error(data.message);
           return data;

       The email itself is designed in email.html. When the server sends it, it replaces:
           {{FIRST_NAME}}  first word of the applicant's name
           {{LINK}}        the applicant's personal link:  .../applicant/opening.php?token=...
           {{YEAR}}        current year
       and, before redirecting from that link to opening.php, sets the session values
       listed at the top of opening.php (applicant_name, applicant_email). */
    /* Real request: saves the applicant through static-data.php (it is the endpoint too),
       so the Screening List, Dashboard cards, chart and Recent Applicants all show the new person.
       BACKEND: when the database is ready, the backend answers this same POST; this stays the same. */
    async function sendInvitation(formData) {
        formData.append('action', 'add');
        const res = await fetch('static-data.php', { method: 'POST', body: formData, credentials: 'same-origin' });
        let data;
        try { data = await res.json(); }
        catch (e) { throw new Error('The server did not answer correctly. Please try again.'); }
        if (data.status !== 'success') throw new Error(data.message || 'Something went wrong. Please try again.');
        return data;
    }

    // The original pretend version, kept for testing without the server
    async function sendInvitationFake(formData) {
        await new Promise(resolve => setTimeout(resolve, 1200));
        return {
            status: 'success',
            applicant: {
                id: Date.now() % 1000000000,           // placeholder id until the database gives one
                name: formData.get('name').trim(),
                email: formData.get('email').trim(),
                contact: formData.get('contact').trim()
            }
        };
    }

    function setSending(state) {
        sending = state;
        sendBtn.disabled = state;
        sendBtn.classList.toggle('sending', state);
        sendBtn.querySelector('.btn-label').textContent = state ? 'SENDING...' : 'SEND';
        cancelBtn.disabled = state;
    }

    /* ---------- "Invitation Sent" modal -> success panel ---------- */
    function openSentModal(applicant) {
        document.getElementById('sentEmail').textContent = applicant.email;
        sentModal.classList.add('active');
        document.getElementById('sentDone').focus();
    }

    function finishSent() {
        if (!sentModal.classList.contains('active')) return;
        sentModal.classList.remove('active');
        if (!lastApplicant) return;

        document.getElementById('detName').textContent = lastApplicant.name;
        document.getElementById('detEmail').textContent = lastApplicant.email;
        document.getElementById('detContact').textContent = lastApplicant.contact;

        formCard.hidden = true;
        successCard.hidden = false;
        window.scrollTo(0, 0);
        document.querySelector('.main-content').scrollTop = 0;
    }

    document.getElementById('sentDone').addEventListener('click', finishSent);
    document.getElementById('sentClose').addEventListener('click', finishSent);
    sentModal.addEventListener('click', (e) => { if (e.target === sentModal) finishSent(); });

    document.getElementById('addAnother').addEventListener('click', () => {
        form.reset();
        clearFile();
        ['name', 'email', 'contact', 'cv'].forEach(n => setError(n, ''));
        touched.clear();
        updateNote();
        successCard.hidden = true;
        formCard.hidden = false;
        // replay the small rise-in so the swap does not feel abrupt
        formCard.style.animation = 'none';
        void formCard.offsetWidth;
        formCard.style.animation = '';
        form.elements.name.focus();
    });

    /* ---------- Cancel (asks first when something was typed) ---------- */
    const isDirty = () => ['name', 'email', 'contact', 'note'].some(n => form.elements[n].value.trim() !== '')
        || fileInput.files.length > 0;

    cancelBtn.addEventListener('click', () => {
        if (isDirty()) discardModal.classList.add('active');
        else window.location.href = 'screeninglist.php';
    });

    document.getElementById('keepEditing').addEventListener('click', () => discardModal.classList.remove('active'));
    document.getElementById('confirmDiscard').addEventListener('click', () => {
        discardModal.classList.remove('active');
        window.location.href = 'screeninglist.php';
    });
    discardModal.addEventListener('click', (e) => { if (e.target === discardModal) discardModal.classList.remove('active'); });

    document.addEventListener('keydown', (e) => {
        if (e.key !== 'Escape') return;
        if (sentModal.classList.contains('active')) finishSent();
        else if (discardModal.classList.contains('active')) discardModal.classList.remove('active');
    });
});