(function () {
    function init() {
        var strip = document.querySelector('.cl-strip');
        if (!strip) return;

        function publish() {
            var h = Math.round(strip.getBoundingClientRect().height);
            if (h > 0) {
                document.documentElement.style.setProperty('--cl-strip-h', h + 'px');
            }
        }

        publish();

        if (typeof ResizeObserver === 'function') {
            new ResizeObserver(publish).observe(strip);
        } else {
            window.addEventListener('resize', publish);
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
