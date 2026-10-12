/* ==========================================================================
   addhr.js: Create HR Account / Edit HR Account (admin).
   Same behaviour as the HR Add Applicant page (assets/js/addapplicants.js): rules that say WHAT is
   wrong, messages that appear while typing, a shake for a refused field, a spinner while saving,
   a "created" modal and then the success panel.
   Shared helpers (window.ADMIN: requests, toast, notifications), the sidebar, logout and the
   entrance animation live in admin-common.js (load admin-common.js BEFORE this file).
   ========================================================================== */
document.addEventListener('DOMContentLoaded', () => {
    const config = window.ADMIN_HR_FORM || { mode: 'create', account: null };
    const isEdit = config.mode === 'edit' && !!config.account;

    const form = document.getElementById('hrForm');
    const formCard = document.getElementById('formCard');
    const successCard = document.getElementById('successCard');
    const banner = document.getElementById('formBanner');
    const saveBtn = document.getElementById('saveBtn');
    const cancelBtn = document.getElementById('cancelBtn');
    const backLink = document.getElementById('backLink');
    const sendEmail = document.getElementById('sendEmail');

    const createdModal = document.getElementById('createdModal');
    const discardModal = document.getElementById('discardModal');

    let saving = false;
    let lastAccount = null;
    let lastEmailed = false;

    const fieldOf = (name) => form.querySelector('.field[data-field="' + name + '"]');

    /* ---------- custom dropdowns (Role, Status): the same one as the HR filters ---------- */
    const dropdowns = [];

    function createDropdown(root) {
        const btn = root.querySelector('.dropdown-btn');
        const menu = root.querySelector('.dropdown-menu');
        const valueEl = root.querySelector('.dropdown-value');
        const btnDot = btn.querySelector('.dot');
        const field = root.closest('.field');
        const options = Array.from(menu.querySelectorAll('.dropdown-option'));
        let value = options[0].dataset.value;
        let focusIdx = -1;

        options.forEach(o => o.querySelector('.dot').style.setProperty('--dot', o.dataset.dot));

        const isOpen = () => root.classList.contains('open');

        function highlight(i) {
            options.forEach((o, k) => o.classList.toggle('is-focused', k === i));
            focusIdx = i;
        }

        function setValue(v) {
            const opt = options.find(o => o.dataset.value === v) || options[0];
            value = opt.dataset.value;
            options.forEach(o => o.setAttribute('aria-selected', String(o === opt)));
            valueEl.textContent = opt.querySelector('.opt-label').textContent;
            btnDot.style.setProperty('--dot', opt.dataset.dot);
        }

        function open() {
            dropdowns.forEach(d => d.close());
            root.classList.add('open');
            if (field) field.classList.add('is-open');
            btn.setAttribute('aria-expanded', 'true');
            highlight(Math.max(0, options.findIndex(o => o.dataset.value === value)));
        }

        function close() {
            root.classList.remove('open');
            if (field) field.classList.remove('is-open');
            btn.setAttribute('aria-expanded', 'false');
            highlight(-1);
        }

        function choose(i) {
            if (i < 0) return;
            setValue(options[i].dataset.value);
            close();
            btn.focus();
        }

        btn.addEventListener('click', () => (isOpen() ? close() : open()));

        btn.addEventListener('keydown', (e) => {
            if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
                e.preventDefault();
                if (!isOpen()) { open(); return; }
                const step = e.key === 'ArrowDown' ? 1 : -1;
                highlight((focusIdx + step + options.length) % options.length);
            } else if ((e.key === 'Enter' || e.key === ' ') && isOpen()) {
                e.preventDefault();
                choose(focusIdx);
            } else if (e.key === 'Escape' && isOpen()) {
                e.preventDefault();
                close();
            } else if (e.key === 'Tab' && isOpen()) {
                close();
            }
        });

        options.forEach((o, i) => {
            o.addEventListener('mousedown', (e) => e.preventDefault());   // keep focus on the button
            o.addEventListener('click', () => choose(i));
            o.addEventListener('mouseenter', () => highlight(i));
        });

        const api = { root, close, setValue, getValue: () => value };
        dropdowns.push(api);
        return api;
    }

    document.addEventListener('click', (e) => {
        dropdowns.forEach(d => { if (!d.root.contains(e.target)) d.close(); });
    });

    const roleDD = createDropdown(document.getElementById('roleDropdown'));
    const statusDD = createDropdown(document.getElementById('statusDropdown'));

    /* ---------- the eye: show / hide the password ---------- */
    form.querySelectorAll('.eye-toggle').forEach(btn => {
        btn.addEventListener('click', () => {
            const input = document.getElementById(btn.dataset.target);
            const show = input.type === 'password';
            input.type = show ? 'text' : 'password';
            btn.setAttribute('aria-pressed', String(show));
            btn.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
            input.focus();
        });
    });

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
            if (t.toLowerCase() === 'admin@expertise.com') return 'That email belongs to the Admin account.';
            return '';
        },
        password(v) {
            if (!v) return isEdit ? '' : 'Please enter a password.';       // Edit: empty = keep the current password
            if (/\s/.test(v)) return 'The password cannot contain spaces.';
            if (v.length < 8) return 'The password is too short (at least 8 characters).';
            if (!/[A-Za-z]/.test(v)) return 'Add at least one letter to the password.';
            if (!/\d/.test(v)) return 'Add at least one number to the password.';
            return '';
        },
        password_confirm(v) {
            const pass = form.elements.password.value;
            if (!pass && !v) return '';                                    // Edit with no new password
            if (!v) return 'Please confirm the password.';
            if (v !== pass) return 'The passwords do not match.';
            return '';
        }
    };

    function setError(name, message, shake) {
        const field = fieldOf(name);
        if (!field) return;
        const input = form.elements[name];
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
        const message = rules[name](form.elements[name].value);
        setError(name, message, shake);
        return !message;
    }

    // Typing: once a box has been used, it is re-checked on every keystroke, so the message appears the
    // moment something is wrong and goes away the moment it is fixed. Leaving a used box also checks it.
    const FIELDS = ['name', 'email', 'password', 'password_confirm'];
    const touched = new Set();

    FIELDS.forEach(name => {
        const input = form.elements[name];
        input.setAttribute('aria-describedby', name + 'Error');
        fieldOf(name).querySelector('.field-error').id = name + 'Error';

        input.addEventListener('input', () => {
            touched.add(name);
            hideBanner();
            validateField(name, false);
            // changing the password re-checks the confirmation (and the other way round)
            if (name === 'password' && touched.has('password_confirm')) validateField('password_confirm', false);
        });
        input.addEventListener('blur', () => { if (touched.has(name)) validateField(name, false); });
    });

    function showBanner(message) {
        banner.textContent = message;
        banner.classList.add('show');
    }

    function hideBanner() {
        banner.classList.remove('show');
    }

    /* ---------- start values ---------- */
    if (isEdit) {
        roleDD.setValue(config.account.role || 'HR Staff');
        statusDD.setValue(config.account.status || 'Active');
    } else {
        roleDD.setValue('HR Staff');
        statusDD.setValue('Active');
    }

    /* ---------- submit ---------- */
    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        if (saving) return;
        hideBanner();

        // validate everything, focus the first problem
        let firstBad = null;
        FIELDS.forEach(name => {
            if (!validateField(name, true) && !firstBad) firstBad = name;
        });
        if (firstBad) {
            const bad = form.querySelectorAll('.field.has-error').length;
            ADMIN.toast('Please fix the ' + bad + ' highlighted field' + (bad === 1 ? '' : 's') + ' before saving.', { error: true, duration: 4000 });
            form.elements[firstBad].focus({ preventScroll: false });
            return;
        }

        setSaving(true);
        try {
            const account = {
                name: form.elements.name.value.trim(),
                email: form.elements.email.value.trim(),
                password: form.elements.password.value,
                password_confirm: form.elements.password_confirm.value,
                role: roleDD.getValue(),
                status: statusDD.getValue(),
                send_email: sendEmail.checked
            };

            const res = isEdit
                ? await ADMIN.request('account_update', { id: config.account.id, account: account })
                : await ADMIN.request('account_add', { account: account });

            const saved = res.result || account;

            lastAccount = saved;
            lastEmailed = !!saved.emailed;

            if (isEdit) {
                // "Edited Successfully!" modal; DONE goes back to the list, where the saved row glows
                openCreatedModal(saved);
                return;
            }

            ADMIN.hr.note(saved.id);              // red badge on the HR Accounts sidebar item, gone once the list is opened
            openCreatedModal(saved);
        } catch (err) {
            showServerError(err.message || 'Something went wrong. Please try again.');
        } finally {
            setSaving(false);
        }
    });

    // The server refused something: put the message under the field it is about
    function showServerError(message) {
        const lower = message.toLowerCase();
        let target = null;
        if (lower.includes('match')) target = 'password_confirm';
        else if (lower.includes('password')) target = 'password';
        else if (lower.includes('email')) target = 'email';
        else if (lower.includes('name')) target = 'name';

        if (target) {
            setError(target, message, true);
            form.elements[target].focus();
        } else {
            showBanner(message);
        }
        ADMIN.toast(message, { error: true, duration: 6000 });
        formCard.classList.remove('shake');
        void formCard.offsetWidth;
        formCard.classList.add('shake');
    }

    function setSaving(state) {
        saving = state;
        saveBtn.disabled = state;
        saveBtn.classList.toggle('saving', state);
        saveBtn.querySelector('.btn-label').textContent = state ? 'SAVING...' : (isEdit ? 'SAVE CHANGES' : 'SAVE');
        cancelBtn.disabled = state;
    }

    /* ---------- "created" modal -> success panel ---------- */
    function openCreatedModal(account) {
        document.getElementById('createdTitle').textContent = isEdit ? 'Edited Successfully!' : 'New HR Account created Successfully!';
        document.getElementById('createdEmail').textContent = account.email;
        document.getElementById('createdChip').hidden = !lastEmailed;
        document.getElementById('createdLead').textContent = lastEmailed
            ? (isEdit ? 'The new login credentials will be sent to:' : 'The login credentials will be sent to:')
            : (isEdit ? 'The changes were saved. No login email was sent.' : 'No login email was sent. Share the login details with the new HR yourself.');
        createdModal.classList.add('active');
        document.getElementById('createdDone').focus();
    }

    function finishCreated() {
        if (!createdModal.classList.contains('active')) return;
        createdModal.classList.remove('active');
        if (!lastAccount) return;

        if (isEdit) {
            ADMIN.flash('HR account saved.', { id: lastAccount.id || config.account.id });
            window.location.href = 'hrlist.php';
            return;
        }

        document.getElementById('detName').textContent = lastAccount.name;
        document.getElementById('detEmail').textContent = lastAccount.email;
        document.getElementById('detRole').textContent = lastAccount.role || 'HR Staff';
        document.getElementById('successLead').textContent = lastEmailed
            ? 'The new HR account has been added to the system and the login credentials have been sent via email.'
            : 'The new HR account has been added to the system.';

        formCard.hidden = true;
        successCard.hidden = false;
        window.scrollTo(0, 0);
        document.querySelector('.main-content').scrollTop = 0;
    }

    document.getElementById('createdDone').addEventListener('click', finishCreated);
    document.getElementById('createdClose').addEventListener('click', finishCreated);
    createdModal.addEventListener('click', (e) => { if (e.target === createdModal) finishCreated(); });

    document.getElementById('addAnother').addEventListener('click', () => {
        form.reset();
        roleDD.setValue('HR Staff');
        statusDD.setValue('Active');
        sendEmail.checked = true;
        form.querySelectorAll('.eye-toggle').forEach(b => {
            document.getElementById(b.dataset.target).type = 'password';
            b.setAttribute('aria-pressed', 'false');
            b.setAttribute('aria-label', 'Show password');
        });
        FIELDS.forEach(n => setError(n, ''));
        touched.clear();
        hideBanner();
        lastAccount = null;
        successCard.hidden = true;
        formCard.hidden = false;
        // replay the small rise-in so the swap does not feel abrupt
        formCard.style.animation = 'none';
        void formCard.offsetWidth;
        formCard.style.animation = '';
        form.elements.name.focus();
    });

    /* ---------- Cancel / Back (asks first when something was typed) ---------- */
    const isDirty = () => {
        if (isEdit) {
            const a = config.account;
            return form.elements.name.value.trim() !== a.name
                || form.elements.email.value.trim() !== a.email
                || statusDD.getValue() !== a.status
                || form.elements.password.value !== ''
                || form.elements.password_confirm.value !== ''
                || !sendEmail.checked;
        }
        return ['name', 'email', 'password', 'password_confirm'].some(n => form.elements[n].value.trim() !== '');
    };

    function leave() { window.location.href = 'hrlist.php'; }

    function tryLeave() {
        if (isDirty() && !formCard.hidden) discardModal.classList.add('active');
        else leave();
    }

    cancelBtn.addEventListener('click', tryLeave);
    backLink.addEventListener('click', (e) => {
        if (isDirty() && !formCard.hidden) { e.preventDefault(); discardModal.classList.add('active'); }
    });

    document.getElementById('keepEditing').addEventListener('click', () => discardModal.classList.remove('active'));
    document.getElementById('confirmDiscard').addEventListener('click', () => {
        discardModal.classList.remove('active');
        leave();
    });
    discardModal.addEventListener('click', (e) => { if (e.target === discardModal) discardModal.classList.remove('active'); });

    document.addEventListener('keydown', (e) => {
        if (e.key !== 'Escape') return;
        if (createdModal.classList.contains('active')) finishCreated();
        else if (discardModal.classList.contains('active')) discardModal.classList.remove('active');
    });
});