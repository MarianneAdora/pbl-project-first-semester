(function () {
    const rules = {
        name: /[^\p{L}\p{M}'.\- ]/gu,
        digits: /[^0-9]/g
    };

    function sanitize(value, rule) {
        if (rule === 'phone') {
            let result = '';
            for (const character of value) {
                if (/[0-9]/.test(character)) {
                    result += character;
                } else if (character === '+' && result === '') {
                    result += character;
                } else if ((character === ' ' || character === '-') && /^\+?\d/.test(result)) {
                    result += character;
                }
            }
            return result;
        }

        return rules[rule] ? value.replace(rules[rule], '') : value;
    }

    function inputFor(target) {
        return target instanceof Element ? target.closest('input[data-input-rule]') : null;
    }

    function sanitizeInput(input) {
        const rule = input.dataset.inputRule;
        const original = input.value;
        const caret = input.selectionStart;
        const filtered = sanitize(original, rule);
        if (filtered === original) return;

        input.value = filtered;
        if (caret !== null) {
            input.setSelectionRange(sanitize(original.slice(0, caret), rule).length, sanitize(original.slice(0, caret), rule).length);
        }
    }

    document.addEventListener('beforeinput', function (event) {
        const input = inputFor(event.target);
        if (!input || event.inputType !== 'insertText' || event.data === null) return;

        const start = input.selectionStart;
        const end = input.selectionEnd;
        const candidate = input.value.slice(0, start) + event.data + input.value.slice(end);
        if (sanitize(candidate, input.dataset.inputRule) !== candidate) event.preventDefault();
    });

    document.addEventListener('input', function (event) {
        const input = inputFor(event.target);
        if (input && !event.isComposing) sanitizeInput(input);
    });

    document.addEventListener('compositionend', function (event) {
        const input = inputFor(event.target);
        if (input) sanitizeInput(input);
    });

    document.querySelectorAll('input[data-input-rule]').forEach(sanitizeInput);
})();
