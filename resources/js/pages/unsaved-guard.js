/* "You have not saved that yet."
 *
 * A form marked data-guard-unsaved is snapshotted on load and compared on
 * every change. While it differs, the page's own ways out - Cancel, the
 * back link, anything marked data-guard-leave - carry the house confirm
 * (the [data-confirm] convention, not a native popup), and the browser's
 * own unload guard is armed for the tab-close case, which is the only one
 * an in-page dialog cannot reach.
 *
 * Written for the listing pages (critique 2026-09-08): arranging eleven
 * pictures is a minute of work that leaves no textual record, so it is the
 * one edit on those pages that cannot be reconstructed from memory, and
 * the back link sat two inches above it with no warning at all.
 */
(function () {
    var WORDS = 'Leave without saving? The changes on this page are not saved yet.';

    function snapshot(form) {
        try {
            var out = [];
            new FormData(form).forEach(function (value, key) {
                out.push(key + '=' + value);
            });
            return out.join('&');
        } catch (e) {
            return null;
        }
    }

    function init() {
        var form = document.querySelector('form[data-guard-unsaved]');
        if (!form) return;

        var saved = snapshot(form);
        if (saved === null) return;
        var leaving = false;

        var exits = Array.prototype.slice.call(document.querySelectorAll('[data-guard-leave]'));

        function dirty() {
            return snapshot(form) !== saved;
        }

        function refresh() {
            var isDirty = dirty();
            exits.forEach(function (el) {
                if (isDirty) {
                    el.setAttribute('data-confirm', WORDS);
                    el.setAttribute('data-confirm-verb', 'Leave');
                } else {
                    el.removeAttribute('data-confirm');
                    el.removeAttribute('data-confirm-verb');
                }
            });
        }

        // input covers typing, change covers selects and checkboxes, and the
        // hidden inputs a script writes (the image arrangement) are caught by
        // the click that wrote them.
        ['input', 'change', 'click'].forEach(function (name) {
            form.addEventListener(name, function () { window.setTimeout(refresh, 0); });
        });

        form.addEventListener('submit', function () { leaving = true; });

        window.addEventListener('beforeunload', function (e) {
            if (leaving || !dirty()) return;
            // The browser writes its own sentence here; the standard asks
            // only that the handler cancel the event.
            e.preventDefault();
            e.returnValue = '';
        });

        refresh();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
