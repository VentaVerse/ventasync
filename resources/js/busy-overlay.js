(function () {
    function overlay() {
        return document.querySelector('[data-busy-overlay]');
    }

    function show(title) {
        var el = overlay();
        if (!el) return;

        var titleEl = el.querySelector('[data-busy-title]');
        if (titleEl && title) titleEl.textContent = title;

        el.classList.add('active');
    }

    function hide() {
        var el = overlay();
        if (el) el.classList.remove('active');
    }

    document.addEventListener('submit', function (e) {
        if (e.defaultPrevented) return;

        var form = e.target;
        var submitter = e.submitter || null;
        var title = (submitter && submitter.getAttribute('data-busy'))
            || (form.getAttribute && form.getAttribute('data-busy'));
        if (!title) return;

        var target = (submitter && submitter.getAttribute('formtarget')) || form.target;
        if (target === '_blank') return;

        show(title);
    });

    document.addEventListener('busy:show', function (e) {
        show((e.detail && e.detail.title) || '');
    });

    window.addEventListener('pageshow', hide);
})();
