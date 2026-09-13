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
 *
 * A SUBMIT IS NOT AN ESCAPE, WHICHEVER FORM IT CAME FROM. The listing
 * strip's verbs are their own little forms, so the first version of this
 * armed the browser's native unload dialog on every push - the popup the
 * house rules ban everywhere else - and it stacked on top of the house
 * confirm the verb already had.
 *
 * And a push while the record is dirty sends the LAST SAVED version: the
 * operator watches a success flash believing the title they just typed
 * went up. Elements marked data-guard-stale say so before they run, in
 * their own confirm, and hand the sentence back when the page is clean.
 */
(function () {
    var WORDS = 'Leave without saving? The changes on this page are not saved yet.';
    var STALE = 'This sends the last saved version. The changes on this page are not saved yet, so they are not included.';

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
        /* A confirmed exit is the page leaving: the house confirm already
           asked, so the browser's own unload dialog must not ask again. */
        exits.forEach(function (el) {
            el.addEventListener('confirm:accepted', function () { leaving = true; });
        });
        /* Verbs that send the SAVED record somewhere. Their own confirm
           sentence is kept, and replaced while the page is dirty. */
        var stale = Array.prototype.slice.call(document.querySelectorAll('[data-guard-stale]'));
        stale.forEach(function (el) {
            el.setAttribute('data-guard-said', el.getAttribute('data-confirm') || '');
        });

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
            stale.forEach(function (el) {
                var own = el.getAttribute('data-guard-said') || '';
                el.setAttribute('data-confirm', isDirty ? STALE + (own !== '' ? ' ' + own : '') : own);
            });
        }

        // input covers typing, change covers selects and checkboxes, and the
        // hidden inputs a script writes (the image arrangement) are caught by
        // the click that wrote them.
        ['input', 'change', 'click'].forEach(function (name) {
            form.addEventListener(name, function () { window.setTimeout(refresh, 0); });
        });

        /* ANY submit is the page leaving, not only this form's. The strip's
           verbs are separate forms, and each of them navigates. */
        document.addEventListener('submit', function () { leaving = true; }, true);

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
