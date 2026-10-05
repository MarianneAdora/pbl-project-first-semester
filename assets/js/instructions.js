/* Instructions page. The entrance animation and the normal page change
   come from applicant-common.js (loaded before this file). */
document.addEventListener("DOMContentLoaded", function () {
    var beginBtn = document.getElementById("beginBtn");
    if (!beginBtn) return;

    var label = beginBtn.querySelector(".start__btn-label");
    var originalText = label.textContent;

    // Loading feedback while the page fades out (the link itself is handled by data-leave)
    beginBtn.addEventListener("click", function () {
        if (beginBtn.classList.contains("is-loading")) return;
        beginBtn.classList.add("is-loading");
        label.textContent = "Loading...";
    });

    // Reset if the user comes back with the browser's Back button
    window.addEventListener("pageshow", function () {
        beginBtn.classList.remove("is-loading");
        label.textContent = originalText;
    });
});