/* ==========================================================================
   Settings page behaviour.
   Entrance, sidebar, logout and the sidebar badges live in admin-common.js
   (load admin-common.js BEFORE this file). The saved values are read and written
   through ADMIN.settings (see "Settings" in admin-common.js), which is also what the
   other admin pages read, so a saved change shows up across the whole portal.

   Preview rule: the theme and the animations switch show their effect at once so the Admin can see
   what they pick, but nothing is kept until SAVE. CANCEL puts the saved look back.
   ========================================================================== */
document.addEventListener('DOMContentLoaded', () => {
    const $ = (id) => document.getElementById(id);

    const form = $('settingsForm');
    const nameInput = $('setName');
    const emailInput = $('setEmail');
    const saveBtn = $('saveBtn');
    const cancelBtn = $('cancelBtn');
    const prefCard = $('prefCard');
    const subGroup = $('subGroup');

    const toggles = {
        notifications: $('optNotifications'),
        notifyActivity: $('optActivity'),
        notifyArchive: $('optArchive'),
        animations: $('optAnimations')
    };
    const themeRadios = Array.from(form.querySelectorAll('input[name="theme"]'));

    const fieldOf = (name) => form.querySelector('.set-field[data-field="' + name + '"]');
    let saving = false;

    /* ---------- the saved state ---------- */
    // Name + email: what the Admin saved before, otherwise what the login session gave
    // (window.ADMIN_ACCOUNT is printed by settingsadmin.php).
    const account = window.ADMIN_ACCOUNT || { name: '', email: '' };
    function readSaved() {
        const profile = ADMIN.settings.profile();
        const s = ADMIN.settings.get();
        return {
            name: String(profile.name || account.name || '').trim(),
            email: String(profile.email || account.email || '').trim(),
            theme: ['light', 'dark', 'system'].includes(s.theme) ? s.theme : 'light',
            notifications: s.notifications !== false,
            notifyActivity: s.notifyActivity !== false,
            notifyArchive: s.notifyArchive !== false,
            animations: s.animations !== false
        };
    }
    let saved = readSaved();

    /* ---------- form <-> state ---------- */
    function collect() {
        const checked = themeRadios.find(r => r.checked);
        return {
            name: nameInput.value.trim(),
            email: emailInput.value.trim(),
            theme: checked ? checked.value : 'light',
            notifications: toggles.notifications.checked,
            notifyActivity: toggles.notifyActivity.checked,
            notifyArchive: toggles.notifyArchive.checked,
            animations: toggles.animations.checked
        };
    }

    function fill(state) {
        nameInput.value = state.name;
        emailInput.value = state.email;
        themeRadios.forEach(r => { r.checked = r.value === state.theme; });
        Object.keys(toggles).forEach(k => { toggles[k].checked = !!state[k]; });
        syncSubGroup();
    }

    const isDirty = () => JSON.stringify(collect()) !== JSON.stringify(saved);

    function refreshButtons() {
        const dirty = isDirty();
        prefCard.classList.toggle('is-dirty', dirty);
        if (saving) return;
        saveBtn.disabled = !dirty;
        cancelBtn.disabled = !dirty;
    }

    // Notifications master switch: the two kinds fold away (and cannot be tabbed to) while it is off
    function syncSubGroup() {
        const on = toggles.notifications.checked;
        subGroup.classList.toggle('is-off', !on);
        subGroup.setAttribute('aria-hidden', on ? 'false' : 'true');
        toggles.notifyActivity.disabled = !on;
        toggles.notifyArchive.disabled = !on;
    }

    // Shows the chosen look right now (not saved yet)
    function preview() {
        const s = collect();
        ADMIN.settings.apply(Object.assign(ADMIN.settings.get(), { theme: s.theme, animations: s.animations }), true);
    }

    /* ---------- validation (same wording as the HR Settings) ---------- */
    const rules = {
        name(v) {
            const t = v.trim();
            if (!t) return 'Please enter your name.';
            if (/\d/.test(t)) return 'The name cannot contain numbers.';
            if (!/^[\p{L}\p{M}'.\- ]+$/u.test(t)) return 'Use letters only (spaces, apostrophes, periods and hyphens are fine).';
            if (t.replace(/[^\p{L}]/gu, '').length < 2) return 'The name is too short (at least 2 letters).';
            return '';
        },
        email(v) {
            const t = v.trim();
            if (!t) return 'Please enter your email address.';
            if (/\s/.test(t)) return 'The email cannot contain spaces.';
            if (!t.includes('@')) return 'The email must include an @ (example: name@gmail.com).';
            if (!/^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(t)) return 'Enter a valid email address (example: name@gmail.com).';
            return '';
        }
    };

    function setError(field, message, shake) {
        if (!field) return;
        field.classList.toggle('has-error', !!message);
        field.querySelector('.set-error').textContent = message || '';
        const input = field.querySelector('input');
        if (input) input.setAttribute('aria-invalid', message ? 'true' : 'false');
        if (message && shake) {
            field.classList.remove('shake');
            void field.offsetWidth;
            field.classList.add('shake');
        }
    }

    function validate(name, shake) {
        const input = name === 'name' ? nameInput : emailInput;
        const message = rules[name](input.value);
        setError(fieldOf(name), message, shake);
        return !message;
    }

    const touched = new Set();
    [['name', nameInput], ['email', emailInput]].forEach(([name, input]) => {
        input.addEventListener('input', () => {
            touched.add(name);
            validate(name, false);
            refreshButtons();
        });
        input.addEventListener('blur', () => { if (touched.has(name)) validate(name, false); });
    });

    themeRadios.forEach(r => r.addEventListener('change', () => { preview(); refreshButtons(); }));
    toggles.notifications.addEventListener('change', () => { syncSubGroup(); refreshButtons(); });
    toggles.notifyActivity.addEventListener('change', refreshButtons);
    toggles.notifyArchive.addEventListener('change', refreshButtons);
    toggles.animations.addEventListener('change', () => { preview(); refreshButtons(); });

    /* ---------- SAVE ---------- */
    function setSaving(state) {
        saving = state;
        saveBtn.classList.toggle('saving', state);
        saveBtn.querySelector('.btn-label').textContent = state ? 'SAVING...' : 'SAVE';
        saveBtn.disabled = state;
        cancelBtn.disabled = state;
    }

    /* BACKEND: replace the body with a real request, for example
           const res = await fetch('settingsadmin.php', { method: 'POST', body: JSON.stringify(state), ... });
           const data = await res.json();                 // { status: 'success' }
           if (data.status !== 'success') throw new Error(data.message);
       Until then the values are kept in this browser (ADMIN.settings / ADMIN.settings.saveProfile). */
    async function persist(state) {
        await new Promise(resolve => setTimeout(resolve, 650));
        const okSettings = ADMIN.settings.save({
            theme: state.theme,
            animations: state.animations,
            notifications: state.notifications,
            notifyActivity: state.notifyActivity,
            notifyArchive: state.notifyArchive
        });
        const okProfile = ADMIN.settings.saveProfile({ name: state.name, email: state.email });
        if (!okSettings || !okProfile) throw new Error('Could not save. Please check your browser storage settings.');
    }

    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        if (saving || !isDirty()) return;

        let firstBad = null;
        ['name', 'email'].forEach(n => { if (!validate(n, true) && !firstBad) firstBad = n; });
        if (firstBad) {
            ADMIN.toast('Fix the highlighted field before saving.', { error: true, duration: 4000 });
            (firstBad === 'name' ? nameInput : emailInput).focus();
            return;
        }

        const state = collect();
        setSaving(true);
        try {
            await persist(state);
            saved = readSaved();
            saveBtn.classList.add('saved');
            saveBtn.querySelector('.btn-label').textContent = 'SAVED';
            ADMIN.toast('Settings saved.', { duration: 3500 });
            setSaving(false);
            saveBtn.disabled = true;               // nothing left to save
            cancelBtn.disabled = true;
            prefCard.classList.remove('is-dirty');
            setTimeout(() => {
                saveBtn.classList.remove('saved');
                saveBtn.querySelector('.btn-label').textContent = 'SAVE';
                refreshButtons();
            }, 1600);
        } catch (err) {
            setSaving(false);
            refreshButtons();
            ADMIN.toast(err.message || 'Something went wrong. Please try again.', { error: true, duration: 6000 });
        }
    });

    /* ---------- CANCEL: back to what is saved ---------- */
    cancelBtn.addEventListener('click', () => {
        if (saving || !isDirty()) return;
        fill(saved);
        ['name', 'email'].forEach(n => setError(fieldOf(n), ''));
        touched.clear();
        ADMIN.settings.apply(ADMIN.settings.get(), true);      // the saved look
        refreshButtons();
        ADMIN.toast('Changes discarded.', { duration: 2500 });
    });

    /* ---------- Update password ---------- */
    const pwModal = $('pwModal');
    const pwForm = $('pwForm');
    const pwOpen = $('pwOpen');
    const pwCurrent = $('pwCurrent');
    const pwNew = $('pwNew');
    const pwConfirm = $('pwConfirm');
    const pwSubmit = $('pwSubmit');
    const meter = $('pwMeter');
    const meterLabel = $('pwMeterLabel');
    const ruleItems = {
        len: pwModal.querySelector('[data-rule="len"]'),
        case: pwModal.querySelector('[data-rule="case"]'),
        num: pwModal.querySelector('[data-rule="num"]')
    };
    let pwBusy = false;
    let lastFocus = null;

    function pwChecks(v) {
        return {
            len: v.length >= 8,
            case: /[a-z]/.test(v) && /[A-Z]/.test(v),
            num: /\d/.test(v)
        };
    }

    function pwScore(v) {
        const c = pwChecks(v);
        let score = [c.len, c.case, c.num].filter(Boolean).length;
        if (score === 3 && (v.length >= 12 || /[^A-Za-z0-9]/.test(v))) score = 4;
        return v ? Math.max(1, score) : 0;
    }

    function paintStrength() {
        const v = pwNew.value;
        const c = pwChecks(v);
        Object.keys(ruleItems).forEach(k => ruleItems[k].classList.toggle('ok', c[k]));
        const score = pwScore(v);
        meter.dataset.level = String(score);
        meterLabel.textContent = ['', 'Weak', 'Okay', 'Good', 'Strong'][score];
    }

    const pwField = (name) => pwModal.querySelector('.set-field[data-field="' + name + '"]');

    function pwValidate(shake) {
        const checks = pwChecks(pwNew.value);
        const problems = {
            pwCurrent: pwCurrent.value ? '' : 'Enter your current password.',
            pwNew: !pwNew.value ? 'Enter a new password.'
                : !(checks.len && checks.case && checks.num) ? 'The new password does not meet all the requirements.'
                : pwNew.value === pwCurrent.value ? 'The new password must be different from the current one.' : '',
            pwConfirm: !pwConfirm.value ? 'Type the new password again.'
                : pwConfirm.value !== pwNew.value ? 'The passwords do not match.' : ''
        };
        let first = null;
        Object.keys(problems).forEach(k => {
            setError(pwField(k), problems[k], shake);
            if (problems[k] && !first) first = k;
        });
        return first;
    }

    function openPw() {
        lastFocus = document.activeElement;
        pwForm.reset();
        pwModal.querySelectorAll('.set-field').forEach(f => setError(f, ''));
        pwModal.querySelectorAll('.eye').forEach(b => { b.setAttribute('aria-pressed', 'false'); b.previousElementSibling.type = 'password'; });
        paintStrength();
        pwModal.classList.add('active');
        setTimeout(() => pwCurrent.focus(), 120);
    }

    function closePw() {
        if (pwBusy) return;
        pwModal.classList.remove('active');
        if (lastFocus && lastFocus.focus) lastFocus.focus();
    }

    pwOpen.addEventListener('click', openPw);
    $('pwCancel').addEventListener('click', closePw);
    pwModal.addEventListener('click', (e) => { if (e.target === pwModal) closePw(); });

    pwModal.querySelectorAll('.eye').forEach(btn => btn.addEventListener('click', () => {
        const input = btn.previousElementSibling;
        const show = input.type === 'password';
        input.type = show ? 'text' : 'password';
        btn.setAttribute('aria-pressed', show ? 'true' : 'false');
        btn.setAttribute('aria-label', (show ? 'Hide ' : 'Show ') + btn.dataset.what);
    }));

    pwNew.addEventListener('input', () => {
        paintStrength();
        if (pwConfirm.value) setError(pwField('pwConfirm'), pwConfirm.value === pwNew.value ? '' : 'The passwords do not match.');
    });
    pwConfirm.addEventListener('input', () => setError(pwField('pwConfirm'), pwConfirm.value === pwNew.value ? '' : 'The passwords do not match.'));
    pwCurrent.addEventListener('input', () => setError(pwField('pwCurrent'), ''));

    /* BACKEND: check the current password and store the new one, for example
           const res = await fetch('settingsadmin.php', { method: 'POST', body: JSON.stringify({ action: 'password', current, next }) });
       and show data.message when the current password is wrong (use setError(pwField('pwCurrent'), data.message, true)).
       The browser cannot check the current password on its own, so for now it only has to be filled in. */
    pwForm.addEventListener('submit', async (e) => {
        e.preventDefault();
        if (pwBusy) return;
        const bad = pwValidate(true);
        if (bad) { $(bad).focus(); return; }

        pwBusy = true;
        pwSubmit.disabled = true;
        pwSubmit.textContent = 'Updating...';
        await new Promise(resolve => setTimeout(resolve, 800));
        pwBusy = false;
        pwSubmit.disabled = false;
        pwSubmit.textContent = 'Update Password';
        closePw();
        ADMIN.toast('Password updated.', { duration: 3500 });
        const mask = $('pwMask').closest('.set-input');
        mask.classList.remove('pulse');
        void mask.offsetWidth;
        mask.classList.add('pulse');
    });

    // Tab stays inside the open dialog
    pwModal.addEventListener('keydown', (e) => {
        if (e.key !== 'Tab') return;
        const items = Array.from(pwModal.querySelectorAll('input, button')).filter(el => !el.disabled && el.offsetParent !== null);
        if (!items.length) return;
        const first = items[0], last = items[items.length - 1];
        if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
        else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
    });

    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && pwModal.classList.contains('active') && !$('logoutModal').classList.contains('active')) closePw();
    });

    /* ---------- start ---------- */
    fill(saved);
    refreshButtons();

    // Another tab saved Settings: show its values here
    window.addEventListener('storage', (e) => {
        if (e.key !== 'admin_settings' && e.key !== 'admin_profile') return;
        if (isDirty() || saving) return;               // never overwrite what is being typed
        saved = readSaved();
        fill(saved);
        refreshButtons();
    });
});