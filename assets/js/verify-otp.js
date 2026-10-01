document.addEventListener("DOMContentLoaded", function () {
    // Enable the entrance animation (page stays fully visible if JS fails)
    document.documentElement.classList.add("js-ready");

    var form = document.getElementById("otpForm");
    var boxesWrap = document.getElementById("otpBoxes");
    var boxes = Array.prototype.slice.call(document.querySelectorAll(".otp__box"));
    var verifyBtn = document.getElementById("verifyBtn");
    var verifyLabel = verifyBtn.querySelector(".otp__submit-label");
    var verifyText = verifyLabel.textContent;
    var banner = document.getElementById("errorBanner");
    var bannerText = document.getElementById("errorText");
    var timerEl = document.getElementById("timer");
    var timerSuffix = document.getElementById("timerSuffix");

    /* ---------- Helpers ---------- */
    function showError(message) {
        bannerText.textContent = message;
        banner.hidden = false;
        boxesWrap.classList.add("is-error");

        // restart the shake animation
        boxesWrap.classList.remove("shake");
        void boxesWrap.offsetWidth;
        boxesWrap.classList.add("shake");
    }

    function clearError() {
        banner.hidden = true;
        boxesWrap.classList.remove("is-error");
    }

    function markFilled(box) {
        box.classList.toggle("is-filled", box.value !== "");
    }

    function focusBox(index) {
        if (boxes[index]) {
            boxes[index].focus();
            boxes[index].select();
        }
    }

    /* ---------- OTP boxes ---------- */
    boxes.forEach(function (box, i) {
        box.addEventListener("input", function () {
            // keep digits only
            box.value = box.value.replace(/\D/g, "").slice(-1);
            markFilled(box);
            clearError();
            if (box.value && i < boxes.length - 1) focusBox(i + 1);
        });

        box.addEventListener("keydown", function (e) {
            if (e.key === "Backspace" && !box.value && i > 0) {
                e.preventDefault();
                boxes[i - 1].value = "";
                markFilled(boxes[i - 1]);
                focusBox(i - 1);
            } else if (e.key === "ArrowLeft" && i > 0) {
                e.preventDefault();
                focusBox(i - 1);
            } else if (e.key === "ArrowRight" && i < boxes.length - 1) {
                e.preventDefault();
                focusBox(i + 1);
            }
        });

        box.addEventListener("focus", function () {
            box.select();
        });

        // Paste a full code into any box
        box.addEventListener("paste", function (e) {
            e.preventDefault();
            var pasted = (e.clipboardData || window.clipboardData).getData("text").replace(/\D/g, "");
            if (!pasted) return;

            for (var k = 0; k < pasted.length && i + k < boxes.length; k++) {
                boxes[i + k].value = pasted[k];
                markFilled(boxes[i + k]);
            }
            clearError();
            focusBox(Math.min(i + pasted.length, boxes.length - 1));
        });
    });

    // Start typing right away
    focusBox(0);

    /* ---------- Form submit ---------- */
    form.addEventListener("submit", function (e) {
        // Resend button: skip validation
        if (e.submitter && e.submitter.name === "resend") return;

        var emptyIndex = boxes.findIndex(function (b) { return b.value === ""; });
        if (emptyIndex !== -1) {
            e.preventDefault();
            showError("Please enter the complete 6-digit code.");
            focusBox(emptyIndex);
            return;
        }

        verifyBtn.classList.add("is-loading");
        verifyLabel.textContent = "Verifying...";
    });

    /* ---------- Countdown ---------- */
    var remaining = parseInt(timerEl.getAttribute("data-remaining"), 10) || 0;

    function renderTimer() {
        var m = Math.floor(remaining / 60);
        var s = remaining % 60;
        timerEl.textContent = (m < 10 ? "0" : "") + m + ":" + (s < 10 ? "0" : "") + s;
        timerSuffix.textContent = remaining > 0 ? "remaining" : "expired";
    }

    renderTimer();

    if (remaining > 0) {
        var countdown = setInterval(function () {
            remaining--;
            renderTimer();
            if (remaining <= 0) clearInterval(countdown);
        }, 1000);
    }

    /* ---------- Back button (browser) resets the loading state ---------- */
    window.addEventListener("pageshow", function () {
        verifyBtn.classList.remove("is-loading");
        verifyLabel.textContent = verifyText;
    });
});
