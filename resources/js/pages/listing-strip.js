/* The listing strip's height, published so the page can scroll around it.
 *
 * The strip is pinned, and its height is content rather than a constant: a
 * fourth verb, a longer status word or a channel with more figures wraps it
 * taller. Anything that jumps to a section under it - the readiness gap
 * links, which exist precisely so an operator can reach what is blocking a
 * push - has to know how tall it actually is, or the heading it lands on
 * sits behind the bar (critique 2026-09-08).
 *
 * One custom property on the root, kept honest by a ResizeObserver.
 * channel-listing-console.css reads it in scroll-margin-top.
 */
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
