(function () {
    var MARK_MS = 2600;

    function pointAt(target) {
        if (!target) return;

        target.classList.remove('is-wanted');
        void target.offsetWidth;
        target.classList.add('is-wanted');
        window.setTimeout(function () {
            target.classList.remove('is-wanted');
        }, MARK_MS);

        try {
            target.scrollIntoView({ behavior: 'smooth', block: 'center' });
        } catch (e) {
            target.scrollIntoView();
        }

        var control = target.matches('input, select, textarea')
            ? target
            : target.querySelector('input:not([type="hidden"]):not([disabled]), select:not([disabled]), textarea:not([disabled]), button:not([disabled])');
        if (control) {
            window.setTimeout(function () {
                try {
                    control.focus({ preventScroll: true });
                } catch (e) {
                    control.focus();
                }
            }, 260);
        }
    }

    document.addEventListener('click', function (e) {
        var link = e.target.closest ? e.target.closest('[data-gap-jump]') : null;
        if (!link) return;

        var href = link.getAttribute('href') || '';
        if (href.charAt(0) !== '#' || href.length < 2) return;

        var target = document.getElementById(href.slice(1));
        if (!target) return;

        e.preventDefault();
        pointAt(target);
    });
})();
