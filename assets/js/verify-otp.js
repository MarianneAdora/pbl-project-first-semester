/* Verify OTP page.
   The entrance animation and the normal page change come from applicant-common.js.

   Animation rules (so nothing fights):
   - each box sits in a .otp__cell that only does the entrance
   - the input itself uses  translate  (hover / focus / wave)  and  scale  (pop / clear)
   - the row  .otp__boxes  does the shake                                                  */
document.addEventListener("DOMContentLoaded", function () {
    const form = document.getElementById("otpForm");
    const boxesWrap = document.getElementById("otpBoxes");
    const boxes = Array.from(document.querySelectorAll(".otp__box"));
    const verifyBtn = document.getElementById("verifyBtn");
    const resendBtn = document.getElementById("resendBtn");
    const verifyLabel = verifyBtn.querySelector(".otp__submit-label");
    const verifyText = verifyLabel.textContent;
    const resendText = resendBtn.textContent;
    const resendEndsAt = Date.now() + (parseInt(resendBtn.dataset.cooldown, 10) || 0) * 1000;
    const banner = document.getElementById("errorBanner");
    const bannerText = document.getElementById("errorText");
    const timerEl = document.getElementById("timer");
    const timerSuffix = document.getElementById("timerSuffix");
    const timerWrap = timerEl.closest(".resend__timer");
    const LAST = boxes.length - 1;

    let submitting = false;

    /* ---------- helpers ---------- */
    function replay(el, className) {
        el.classList.remove(className);
        void el.offsetWidth;                         // restart the animation
        el.classList.add(className);
    }

    const allFilled = () => boxes.every(b => b.value !== "");

    function setDigit(box, digit) {
        box.value = digit;
        box.dataset.prev = digit;
        box.classList.toggle("is-filled", digit !== "");
    }

    function focusBox(index) {
        boxes[Math.max(0, Math.min(LAST, index))].focus();     // focus handler selects the digit
    }

    // short-lived classes remove themselves when their animation ends
    boxes.forEach(box => box.addEventListener("animationend", e => {
        if (e.animationName === "digitPop" || e.animationName === "digitClear") {
            box.classList.remove("pop", "clear");
        }
    }));
    boxesWrap.addEventListener("animationend", e => {
        if (e.target === boxesWrap && e.animationName === "shake") boxesWrap.classList.remove("shake");
    });

    /* ---------- complete state: glowing button + one soft wave ---------- */
    let wasComplete = false;
    let waveStart = null;
    let waveEnd = null;

    function refreshState() {
        const complete = allFilled();
        verifyBtn.classList.toggle("is-ready", complete);

        if (complete && !wasComplete) {
            clearTimeout(waveStart);
            waveStart = setTimeout(function () {         // let the last digit finish popping first
                if (!allFilled()) return;
                boxes.forEach(b => b.classList.remove("pop", "clear"));
                replay(boxesWrap, "complete");
                clearTimeout(waveEnd);
                waveEnd = setTimeout(() => boxesWrap.classList.remove("complete"), 1000);
            }, 320);
        }
        if (!complete) {
            clearTimeout(waveStart);
            boxesWrap.classList.remove("complete");
        }
        wasComplete = complete;
    }

    /* ---------- errors ---------- */
    // Only one message is on screen at a time: the red error and the green "new code sent" notice
    const notice = document.getElementById("noticeBanner");

    function hideNotice() {
        if (notice) notice.hidden = true;
    }

    function showError(message) {
        hideNotice();
        bannerText.textContent = message;
        banner.hidden = false;
        boxesWrap.classList.add("is-error");
        replay(boxesWrap, "shake");
    }

    // called whenever the visitor starts typing, pasting or erasing: clears whatever message is showing
    function clearError() {
        hideNotice();
        banner.hidden = true;
        boxesWrap.classList.remove("is-error");
    }

    /* ---------- filling boxes (typing, paste, autofill) ---------- */
    // Drops digits into the boxes one after another, starting at `start`
    function fillFrom(start, digits, stagger) {
        const count = Math.min(digits.length, boxes.length - start);
        for (let k = 0; k < count; k++) {
            setTimeout(function () {
                const box = boxes[start + k];
                setDigit(box, digits[k]);
                replay(box, "pop");
                focusBox(start + k + 1);                 // clamps on the last box
                if (k === count - 1) refreshState();
            }, k * stagger);
        }
    }

    boxes.forEach(function (box, i) {
        box.addEventListener("input", function () {
            const digits = box.value.replace(/\D/g, "");
            clearError();

            // a whole code arrived in one box (SMS autofill): spread it out
            if (digits.length > 1) {
                fillFrom(digits.length >= boxes.length ? 0 : i, digits, 55);
                return;
            }

            const hadDigit = (box.dataset.prev || "") !== "";
            setDigit(box, digits);

            if (digits) {
                replay(box, "pop");
                if (i < LAST) focusBox(i + 1);
            } else if (hadDigit) {
                replay(box, "clear");
            }
            refreshState();
        });

        box.addEventListener("keydown", function (e) {
            if (e.key === "Backspace" && !box.value && i > 0) {
                e.preventDefault();
                const prev = boxes[i - 1];
                if (prev.value) {
                    setDigit(prev, "");
                    replay(prev, "clear");
                }
                clearError();
                focusBox(i - 1);
                refreshState();
            } else if (e.key === "ArrowLeft") {
                e.preventDefault();
                focusBox(i - 1);
            } else if (e.key === "ArrowRight") {
                e.preventDefault();
                focusBox(i + 1);
            } else if (e.key === "Home") {
                e.preventDefault();
                focusBox(0);
            } else if (e.key === "End") {
                e.preventDefault();
                focusBox(LAST);
            }
        });

        box.addEventListener("focus", function () {
            box.select();
        });

        // Paste a code into any box: a full code always starts from the first box
        box.addEventListener("paste", function (e) {
            e.preventDefault();
            const pasted = (e.clipboardData || window.clipboardData).getData("text").replace(/\D/g, "");
            if (!pasted) return;
            clearError();
            fillFrom(pasted.length >= boxes.length ? 0 : i, pasted, 55);
        });
    });

    // Start typing once the boxes have risen in (unless the visitor already started)
    setTimeout(function () {
        const started = boxes.some(b => b.value !== "" || b === document.activeElement);
        if (!started) focusBox(0);
    }, 800);

    // The server sent the page back with an error (wrong or expired code): shake the row too
    if (!banner.hidden) {
        setTimeout(function () {
            boxesWrap.classList.add("is-error");
            replay(boxesWrap, "shake");
        }, 700);
    }

    /* ---------- form submit ---------- */
    form.addEventListener("submit", function (e) {
        if (e.submitter && e.submitter.name === "resend") {
            resendBtn.textContent = "Sending...";
            return;                                       // resend skips validation
        }
        if (submitting) { e.preventDefault(); return; }

        const emptyIndex = boxes.findIndex(b => b.value === "");
        if (emptyIndex !== -1) {
            e.preventDefault();
            showError("Please enter the complete 6-digit code.");
            focusBox(emptyIndex);
            return;
        }

        submitting = true;
        verifyBtn.classList.add("is-loading");
        verifyLabel.textContent = "Verifying...";
    });

    /* ---------- countdown (uses the clock, so it stays right in a background tab) ---------- */
    const endsAt = Date.now() + (parseInt(timerEl.dataset.remaining, 10) || 0) * 1000;

    function renderTimer() {
        const remaining = Math.max(0, Math.ceil((endsAt - Date.now()) / 1000));
        const m = Math.floor(remaining / 60);
        const s = remaining % 60;
        timerEl.textContent = String(m).padStart(2, "0") + ":" + String(s).padStart(2, "0");
        timerSuffix.textContent = remaining > 0 ? "remaining" : "expired";
        timerWrap.classList.toggle("is-expired", remaining <= 0);
        return remaining;
    }

    if (renderTimer() > 0) {
        const countdown = setInterval(function () {
            if (renderTimer() <= 0) clearInterval(countdown);
        }, 500);
    }

    /* ---------- "Resend Code" has a 1 minute break after every code that was sent ---------- */
    function renderResend() {
        const left = Math.max(0, Math.ceil((resendEndsAt - Date.now()) / 1000));
        resendBtn.disabled = left > 0;
        resendBtn.textContent = left > 0
            ? resendText + " in " + Math.floor(left / 60) + ":" + String(left % 60).padStart(2, "0")
            : resendText;
        return left;
    }

    if (renderResend() > 0) {
        const resendWait = setInterval(function () {
            if (renderResend() <= 0) clearInterval(resendWait);
        }, 500);
    }

    /* ---------- Back button (browser) resets the loading state ---------- */
    window.addEventListener("pageshow", function () {
        submitting = false;
        verifyBtn.classList.remove("is-loading");
        verifyLabel.textContent = verifyText;
        renderResend();
    });
});