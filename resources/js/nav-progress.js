/**
 * The navigation progress line.
 *
 * Shown the moment a same-site link is followed or a form submitted, so a
 * page that waits on a marketplace before it can paint still answers the
 * click at once. Hidden again on pageshow, which also covers the back
 * button restoring a page from the cache with the line still on.
 */
(function () {
    var bar = document.getElementById('x-progress');
    if (!bar) return;

    function on() { bar.classList.add('is-on'); }
    function off() { bar.classList.remove('is-on'); }

    document.addEventListener('click', function (e) {
        if (e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
        var a = e.target && e.target.closest ? e.target.closest('a[href]') : null;
        if (!a || a.target === '_blank' || a.hasAttribute('download') || a.getAttribute('href').charAt(0) === '#') return;
        if (a.origin !== window.location.origin) return;
        if (a.getAttribute('href').indexOf('javascript:') === 0) return;
        on();
    });

    document.addEventListener('submit', function (e) {
        if (e.defaultPrevented) return;
        var form = e.target;
        if (form.target === '_blank') return;
        on();
    });

    // A cancelled navigation (a confirm dialog answered No, a download)
    // never fires pageshow, so the line would stay. Give up after a while.
    var timer = null;
    document.addEventListener('click', function () {
        if (timer) clearTimeout(timer);
        timer = setTimeout(off, 15000);
    });

    window.addEventListener('pageshow', off);
    window.addEventListener('pagehide', off);
})();
