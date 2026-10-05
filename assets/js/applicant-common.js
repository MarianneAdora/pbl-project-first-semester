/* ==========================================================================
   Applicant pages: entrance + normal page change (shared).
   Load this BEFORE the page's own script. Each page also needs, in <head>:
       <script>document.documentElement.classList.add('js');</script>

   Links with  data-leave  fade the page out before opening the next one:
       <a href="verify-otp.php" data-leave>...</a>
   From JS you can call:  Applicant.go('next-page.php')
   ========================================================================== */
(function () {
    var root = document.documentElement;
    var reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    var leaving = false;

    function enter() {
        // elements rise in right away
        requestAnimationFrame(function () { root.classList.add('is-ready'); });
    }

    function go(url) {
        if (leaving) return;
        leaving = true;
        if (reduceMotion) { window.location.href = url; return; }
        root.classList.add('is-leaving');
        setTimeout(function () { window.location.href = url; }, 320);
    }

    // links marked data-leave
    document.addEventListener('click', function (e) {
        var link = e.target.closest ? e.target.closest('a[data-leave]') : null;
        if (!link || e.defaultPrevented) return;
        if (e.button !== 0 || e.ctrlKey || e.metaKey || e.shiftKey || e.altKey) return;
        e.preventDefault();
        go(link.getAttribute('href'));
    });

    // Browser Back (page restored from cache): undo the leaving state
    window.addEventListener('pageshow', function (event) {
        if (!event.persisted) return;
        leaving = false;
        root.classList.remove('is-leaving');
        root.classList.add('is-ready');
    });

    window.Applicant = { go: go };

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', enter);
    else enter();
})();