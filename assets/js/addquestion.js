/* ==========================================================================
   Create / Edit Questionnaire (admin): the form behind "Create Questions" and the pencil in the Questionnaires list.
   Same behaviour as Create HR Account (assets/js/addhr.js): rules that say WHAT is wrong, messages while typing,
   a shake for a refused field, a spinner while saving, "are you sure" when leaving with unsaved work.
   Sections:  - pick one from the list -> the box next to it becomes "Rename Section" (the title changes for every
                question of that section)
              - leave the list empty and type a title in "New Section" -> it becomes the next section number
   Shared helpers (window.ADMIN: requests, toast, flash), the sidebar and logout live in admin-common.js.
   ========================================================================== */
document.addEventListener('DOMContentLoaded', () => {
    const config = window.ADMIN_QFORM || { mode: 'create', question: null, sections: [], nextNo: 1 };
    const isEdit = config.mode === 'edit' && !!config.question;
    const Q = config.question || {};

    const form = document.getElementById('qForm');
    const formCard = document.getElementById('formCard');
    const banner = document.getElementById('formBanner');
    const saveBtn = document.getElementById('saveBtn');
    const cancelBtn = document.getElementById('cancelBtn');
    const backLink = document.getElementById('backLink');
    const discardModal = document.getElementById('discardModal');
    const titleInput = document.getElementById('sectionTitle');
    const titleLabel = document.getElementById('sectionTitleLabel');
    const sectionHint = document.getElementById('sectionHint');
    const textInput = document.getElementById('text');
    const textCount = document.getElementById('textCount');

    let saving = false;
    let leaving = false;

    const fieldOf = (name) => form.querySelector('.field[data-field="' + name + '"]');
    const val = (name) => (form.elements[name] ? form.elements[name].value : '');

    const sections = (config.sections || []).map(s => ({ no: String(s.no), name: String(s.name) }));
    const sectionByNo = (no) => sections.find(s => s.no === String(no)) || null;

    /* ---------- custom dropdowns (Section, Type, Status + two small ones): the same one as the HR forms ---------- */
    const dropdowns = [];

    function createDropdown(root, onChange) {
        const btn = root.querySelector('.dropdown-btn');
        const menu = root.querySelector('.dropdown-menu');
        const valueEl = root.querySelector('.dropdown-value');
        const btnDot = btn.querySelector('.dot');
        const field = root.closest('.field');
        const options = Array.from(menu.querySelectorAll('.dropdown-option'));
        const start = options.find(o => o.getAttribute('aria-selected') === 'true') || options[0];
        let value = start.dataset.value;
        let focusIdx = -1;

        options.forEach(o => o.querySelector('.dot').style.setProperty('--dot', o.dataset.dot));

        const isOpen = () => root.classList.contains('open');

        function highlight(i) {
            options.forEach((o, k) => o.classList.toggle('is-focused', k === i));
            focusIdx = i;
        }

        function setValue(v, silent) {
            const opt = options.find(o => o.dataset.value === v) || options[0];
            value = opt.dataset.value;
            options.forEach(o => o.setAttribute('aria-selected', String(o === opt)));
            valueEl.textContent = opt.querySelector('.opt-label').textContent;
            btnDot.style.setProperty('--dot', opt.dataset.dot);
            root.classList.toggle('is-placeholder', value === '');
            if (!silent && onChange) onChange(value);
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

        setValue(value, true);
        const api = { root, btn, close, setValue, getValue: () => value };
        dropdowns.push(api);
        return api;
    }

    document.addEventListener('click', (e) => {
        dropdowns.forEach(d => { if (!d.root.contains(e.target)) d.close(); });
    });

    const dd = (id, onChange) => createDropdown(document.getElementById(id), onChange);
    const sectionDD = dd('sectionDropdown', () => { onSectionPicked(); if (touched.has('section')) validateField('section', false); });
    const typeDD = dd('typeDropdown', () => { showTypeBox(); hideBanner(); validateField('type', false); });
    const statusDD = dd('statusDropdown', () => hideBanner());
    const favorableDD = dd('favorableDropdown');
    const followupWhenDD = dd('followupWhenDropdown');

    /* ---------- Section: pick one (and rename it) or type a new one ---------- */
    function matchSectionByTitle(title, exceptNo) {
        const t = title.trim().toLowerCase();
        return t ? sections.find(s => s.name.toLowerCase() === t && s.no !== String(exceptNo || '')) || null : null;
    }

    function updateSectionBox() {
        const picked = sectionByNo(sectionDD.getValue());
        const title = titleInput.value.trim();
        if (picked) {
            titleLabel.textContent = 'Rename Section';
            titleInput.placeholder = 'Type a new title for Section ' + picked.no;
            sectionHint.textContent = title && title !== picked.name
                ? 'Section ' + picked.no + ' will be renamed to \u201C' + title + '\u201D for every question in it.'
                : 'Change the title to rename Section ' + picked.no + ' (it updates every question in it).';
        } else {
            titleLabel.textContent = 'New Section';
            titleInput.placeholder = 'Type New Section';
            const same = matchSectionByTitle(title);
            sectionHint.textContent = same
                ? '\u201C' + same.name + '\u201D already exists (Section ' + same.no + '). The question goes into it.'
                : 'Type only the title. It is added automatically as Section ' + config.nextNo + '.';
        }
    }

    // The Admin picked another section (or cleared it): the box shows that section's title to rename, or is empty for a new one
    function onSectionPicked() {
        const picked = sectionByNo(sectionDD.getValue());
        titleInput.value = picked ? picked.name : '';
        setError('section_title', '');
        updateSectionBox();
    }

    /* ---------- Type: only the matching extra box is shown ---------- */
    const TYPE_FIELDS = {
        yesno: ['no_note'],
        yesno_select: ['followup_label', 'followup_options'],
        scale: ['max', 'label_left', 'label_right'],
        date: ['date_hint'],
        text: ['text_hint', 'placeholder', 'min_chars', 'max_chars'],
        consent: ['body', 'checkbox']
    };

    const hasLink = () => textInput.value.indexOf('{link}') !== -1;

    function showTypeBox() {
        const type = typeDD.getValue();
        form.querySelectorAll('.sub-fields[data-for]').forEach(box => {
            const f = box.dataset.for;
            box.hidden = f === 'link' ? !hasLink() : f !== type;
        });
    }

    function activeFields() {
        const list = ['text', 'section', 'section_title', 'type'].concat(TYPE_FIELDS[typeDD.getValue()] || []);
        if (hasLink()) list.push('link_text', 'link_url');
        return list;
    }

    /* ---------- validation ---------- */
    // Each rule returns the error text (or '' when the value is fine). The messages say WHAT is wrong.
    const isWhole = (t) => /^\d+$/.test(t.trim());
    const lines = (t) => t.split(/\r?\n/).map(s => s.trim()).filter(Boolean);

    const rules = {
        text(v) {
            const t = v.trim();
            if (!t) return 'Please write the question.';
            if (t.length > 400) return 'The question is too long (400 characters at most).';
            return '';
        },
        section() {
            if (!sectionDD.getValue() && !titleInput.value.trim()) return 'Choose a section, or type the title of a new one.';
            return '';
        },
        section_title(v) {
            const t = v.trim();
            if (t.length > 60) return 'The section title is too long (60 characters at most).';
            const picked = sectionByNo(sectionDD.getValue());
            if (picked && t && matchSectionByTitle(t, picked.no)) return 'Another section already uses that title.';
            return '';
        },
        type() { return typeDD.getValue() ? '' : 'Please choose the type of question.'; },
        no_note(v) { return v.trim().length > 200 ? 'The reminder is too long (200 characters at most).' : ''; },
        followup_label(v) {
            const t = v.trim();
            if (!t) return 'Please write the label above the list (example: Your notice period).';
            return t.length > 80 ? 'The label is too long (80 characters at most).' : '';
        },
        followup_options(v) {
            const n = lines(v).length;
            if (n < 2) return 'Add at least two choices, one per line.';
            if (n > 12) return 'The list can have 12 choices at most.';
            return '';
        },
        max(v) {
            const t = v.trim();
            if (!isWhole(t) || Number(t) < 3 || Number(t) > 10) return 'The rating scale must go from 3 up to 10.';
            return '';
        },
        label_left(v) { return v.trim().length > 40 ? 'Keep the label short (40 characters at most).' : ''; },
        label_right(v) { return v.trim().length > 40 ? 'Keep the label short (40 characters at most).' : ''; },
        date_hint(v) { return v.trim().length > 200 ? 'The hint is too long (200 characters at most).' : ''; },
        text_hint(v) { return v.trim().length > 200 ? 'The hint is too long (200 characters at most).' : ''; },
        placeholder(v) { return v.trim().length > 200 ? 'This text is too long (200 characters at most).' : ''; },
        min_chars(v) {
            const t = v.trim();
            if (t && !isWhole(t)) return 'Use a whole number (0 or more).';
            if (t && isWhole(val('max_chars')) && Number(t) > Number(val('max_chars').trim())) return 'The shortest answer cannot be longer than the longest answer.';
            return '';
        },
        max_chars(v) {
            const t = v.trim();
            if (!isWhole(t) || Number(t) < 10 || Number(t) > 2000) return 'The longest answer must be between 10 and 2000 characters.';
            return '';
        },
        body(v) { return v.trim() ? '' : 'Please write the statement the applicant has to read.'; },
        checkbox(v) {
            const t = v.trim();
            if (!t) return 'Please write the text next to the checkbox (example: I agree).';
            return t.length > 200 ? 'The text is too long (200 characters at most).' : '';
        },
        link_text(v) {
            const t = v.trim();
            if (!t) return 'Write the clickable words of the link.';
            return t.length > 60 ? 'The clickable words are too long (60 characters at most).' : '';
        },
        link_url(v) {
            const t = v.trim();
            let ok = /^https?:\/\//i.test(t);
            if (ok) { try { new URL(t); } catch (err) { ok = false; } }
            return ok ? '' : 'Enter a web address that starts with http:// or https://.';
        }
    };

    function setError(name, message, shake) {
        const field = fieldOf(name);
        if (!field) return;
        const input = form.elements[name];
        field.classList.toggle('has-error', !!message);
        const box = field.querySelector('.field-error');
        if (box) box.textContent = message || '';
        if (input && input.setAttribute) input.setAttribute('aria-invalid', message ? 'true' : 'false');
        if (message && shake) {
            field.classList.remove('shake');
            void field.offsetWidth;
            field.classList.add('shake');
        }
    }

    function validateField(name, shake) {
        if (!rules[name]) return true;
        const message = rules[name](val(name));
        setError(name, message, shake);
        return !message;
    }

    // Typing: once a box has been used, it is re-checked on every keystroke, so the message appears the moment
    // something is wrong and goes away the moment it is fixed. Leaving a used box also checks it.
    const touched = new Set();

    Object.keys(rules).forEach(name => {
        const input = form.elements[name];
        if (!input || !input.addEventListener) return;
        input.addEventListener('input', () => {
            touched.add(name);
            hideBanner();
            validateField(name, false);
        });
        input.addEventListener('blur', () => { if (touched.has(name)) validateField(name, false); });
    });

    // the question: counter + the {link} box
    function onQuestionInput() {
        textCount.textContent = String(textInput.value.length);
        showTypeBox();
    }
    textInput.addEventListener('input', onQuestionInput);

    // the section title: hint, and the section rule (empty list + empty title) is re-checked too
    titleInput.addEventListener('input', () => {
        updateSectionBox();
        touched.add('section_title');
        if (touched.has('section')) validateField('section', false);
    });

    // changing the longest answer re-checks the shortest one
    form.elements.max_chars.addEventListener('input', () => { if (touched.has('min_chars')) validateField('min_chars', false); });

    function showBanner(message) { banner.textContent = message; banner.classList.add('show'); }
    function hideBanner() { banner.classList.remove('show'); }

    /* ---------- what is sent to static-admin.php (same keys the question functions there read) ---------- */
    function collect() {
        const type = typeDD.getValue();
        const picked = sectionByNo(sectionDD.getValue());
        const title = val('section_title').trim();
        const q = {
            type: type,
            text: val('text').trim(),
            status: statusDD.getValue(),
            section: picked ? picked.name : title
        };
        if (picked && title && title !== picked.name) q.section_rename = title;      // the Admin retitled the chosen section

        if (type === 'yesno') {
            q.no_note = val('no_note').trim();
            q.favorable = favorableDD.getValue();
        } else if (type === 'yesno_select') {
            q.followup_label = val('followup_label').trim();
            q.followup_when = followupWhenDD.getValue();
            q.followup_options = lines(val('followup_options')).join('\n');
        } else if (type === 'scale') {
            q.max = Number(val('max').trim());
            q.label_left = val('label_left').trim();
            q.label_right = val('label_right').trim();
        } else if (type === 'date') {
            q.hint = val('date_hint').trim();
        } else if (type === 'text') {
            q.hint = val('text_hint').trim();
            q.placeholder = val('placeholder').trim();
            q.min_chars = Number(val('min_chars').trim() || 0);
            q.max_chars = Number(val('max_chars').trim());
        } else if (type === 'consent') {
            q.body = val('body').trim();
            q.checkbox = val('checkbox').trim();
        }
        if (hasLink()) {
            q.link_text = val('link_text').trim();
            q.link_url = val('link_url').trim();
        }
        return q;
    }

    /* ---------- start values ---------- */
    function put(name, value) {
        if (form.elements[name] && value !== undefined && value !== null) form.elements[name].value = String(value);
    }

    form.elements.max.value = '5';
    form.elements.min_chars.value = '0';
    form.elements.max_chars.value = '500';

    if (isEdit) {
        put('no_note', Q.no_note);
        favorableDD.setValue(Q.favorable === 'No' ? 'No' : 'Yes', true);
        if (Q.followup) {
            put('followup_label', Q.followup.label);
            followupWhenDD.setValue(Q.followup.when === 'No' ? 'No' : 'Yes', true);
            put('followup_options', (Q.followup.options || []).join('\n'));
        }
        if (Q.type === 'scale') {
            put('max', Q.max);
            put('label_left', (Q.labels || [])[0] || '');
            put('label_right', (Q.labels || [])[1] || '');
        }
        if (Q.type === 'date') put('date_hint', Q.hint || '');
        if (Q.type === 'text') {
            put('text_hint', Q.hint || '');
            put('placeholder', Q.placeholder || '');
            put('min_chars', Q.min_chars !== undefined ? Q.min_chars : 0);
            put('max_chars', Q.max_chars !== undefined ? Q.max_chars : 500);
        }
        if (Q.type === 'consent') {
            put('body', (Q.body || []).join('\n\n'));
            put('checkbox', Q.checkbox || '');
        }
        if (Q.link) {
            put('link_text', Q.link.text || '');
            put('link_url', Q.link.url || '');
        }
        const own = sectionByNo(sectionDD.getValue());
        if (own) titleInput.value = own.name;
    }
    textCount.textContent = String(textInput.value.length);
    updateSectionBox();
    showTypeBox();
    const startSnapshot = JSON.stringify([collect(), val('section_title')]);

    /* ---------- submit ---------- */
    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        if (saving) return;
        hideBanner();

        // validate everything that is on screen, focus the first problem
        let firstBad = null;
        activeFields().forEach(name => {
            touched.add(name);
            if (!validateField(name, true) && !firstBad) firstBad = name;
        });
        if (firstBad) {
            const bad = form.querySelectorAll('.field.has-error').length;
            ADMIN.toast('Please fix the ' + bad + ' highlighted field' + (bad === 1 ? '' : 's') + ' before saving.', { error: true, duration: 4000 });
            focusField(firstBad);
            return;
        }

        setSaving(true);
        try {
            const question = collect();
            const res = isEdit
                ? await ADMIN.request('question_update', { id: Q.id, question: question })
                : await ADMIN.request('question_add', { question: question });

            const saved = res.result || {};
            let message = isEdit ? 'Question saved.' : 'Question added.';
            if (question.section_rename) message += ' Section renamed.';
            ADMIN.flash(message, { id: saved.id || Q.id || '' });      // the list shows the message and the saved row glows once
            leaving = true;
            window.location.href = 'questionlist.php';
        } catch (err) {
            showServerError(err.message || 'Something went wrong. Please try again.');
            setSaving(false);
        }
    });

    function focusField(name) {
        const input = form.elements[name];
        if (input && input.focus) { input.focus({ preventScroll: false }); return; }
        const dropdown = { section: sectionDD, type: typeDD, status: statusDD }[name];
        if (dropdown) dropdown.btn.focus();
    }

    // The server refused something: put the message under the field it is about
    const SERVER_FIELDS = [
        [/type of question/i, 'type'],
        [/write the question|question is too long/i, 'text'],
        [/another section|section (title|name)|existing section/i, 'section_title'],
        [/choose a section/i, 'section'],
        [/label above the list/i, 'followup_label'],
        [/choices/i, 'followup_options'],
        [/rating scale/i, 'max'],
        [/shortest answer/i, 'min_chars'],
        [/longest answer/i, 'max_chars'],
        [/statement/i, 'body'],
        [/checkbox/i, 'checkbox'],
        [/web address|\{link\}/i, 'link_url'],
        [/reminder/i, 'no_note'],
        [/stay active|at least one/i, 'status']
    ];

    function showServerError(message) {
        const hit = SERVER_FIELDS.find(pair => pair[0].test(message));
        if (hit && fieldOf(hit[1])) {
            setError(hit[1], message, true);
            focusField(hit[1]);
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

    /* ---------- Cancel / Back (asks first when something was typed) ---------- */
    const isDirty = () => !leaving && JSON.stringify([collect(), val('section_title')]) !== startSnapshot;

    function leave() { window.location.href = 'questionlist.php'; }

    function tryLeave() {
        if (isDirty()) discardModal.classList.add('active');
        else leave();
    }

    cancelBtn.addEventListener('click', tryLeave);
    backLink.addEventListener('click', (e) => {
        if (isDirty()) { e.preventDefault(); discardModal.classList.add('active'); }
    });

    document.getElementById('keepEditing').addEventListener('click', () => discardModal.classList.remove('active'));
    document.getElementById('confirmDiscard').addEventListener('click', () => {
        discardModal.classList.remove('active');
        leaving = true;
        leave();
    });
    discardModal.addEventListener('click', (e) => { if (e.target === discardModal) discardModal.classList.remove('active'); });

    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && discardModal.classList.contains('active')) discardModal.classList.remove('active');
    });
});