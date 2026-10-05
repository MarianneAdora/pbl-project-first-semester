/* Opening page. The entrance animation and the normal page change
   come from applicant-common.js (loaded before this file). */
document.addEventListener("DOMContentLoaded", function () {
    var startBtn = document.getElementById("startBtn");
    if (!startBtn) return;

    var label = startBtn.querySelector(".invite__btn-label");
    var originalText = label.textContent;

    // Button feedback while the page fades out (the link itself is handled by data-leave)
    startBtn.addEventListener("click", function () {
        if (startBtn.classList.contains("is-loading")) return;
        startBtn.classList.add("is-loading");
        label.textContent = "Loading...";
    });

    // Reset if the visitor comes back with the browser's Back button
    window.addEventListener("pageshow", function () {
        startBtn.classList.remove("is-loading");
        label.textContent = originalText;
    });
});