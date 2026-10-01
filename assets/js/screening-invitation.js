document.addEventListener("DOMContentLoaded", function () {
    // Turn on the entrance animation (page stays fully visible if JS fails)
    document.documentElement.classList.add("js-ready");

    // Button feedback: show a loading state, then let the link navigate
    var startBtn = document.getElementById("startBtn");
    if (!startBtn) return;

    startBtn.addEventListener("click", function () {
        if (startBtn.classList.contains("is-loading")) return;

        startBtn.classList.add("is-loading");
        startBtn.querySelector(".invite__btn-label").textContent = "Loading...";
    });
});
