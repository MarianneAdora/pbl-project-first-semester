document.addEventListener("DOMContentLoaded", function () {
    // Enable the entrance animation (page stays fully visible if JS fails)
    document.documentElement.classList.add("js-ready");

    var beginBtn = document.getElementById("beginBtn");
    if (!beginBtn) return;

    var label = beginBtn.querySelector(".start__btn-label");
    var originalText = label.textContent;

    // Loading feedback, then the link navigates as normal
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
