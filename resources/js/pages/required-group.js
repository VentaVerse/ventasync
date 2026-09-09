/* "Anything that is required = null should not save as well." (the owner,
 * 2026-09-09)
 *
 * A plain <input required> covers a single box. It covers neither of the
 * other two shapes a listing page marks with an asterisk:
 *
 *   - a SET of checkboxes where at least one must be ticked (Shopee's
 *     couriers). `required` on a checkbox demands THAT box, not one of
 *     the group, so it is the wrong constraint rather than a missing one.
 *   - a picker whose answer lives in a hidden input, with an escape hatch
 *     beside it (Lazada's brand, satisfied either by a matched brand id or
 *     by pressing No brand). A hidden control cannot be validated at all -
 *     the browser refuses the submit and shows nothing.
 *
 * Both are the same problem the category combo has, so both get the same
 * answer: work out whether the requirement is MET from the real values,
 * and hang the browser's own bubble on a control that can take focus.
 *
 *   data-must-pick="the sentence"        on the wrapper; the message shown
 *   data-must-pick-value="<selector>"    optional: the input holding the answer
 *   data-must-pick-unless="<selector>"   optional: a flag that satisfies it anyway
 *
 * With no value selector the wrapper is read as a checkbox set: ticked is
 * filled. A wrapper with nothing focusable inside it is left alone - an
 * empty courier list is not something the operator can fix by being
 * refused.
 */
(function () {
    function truthy(el) {
        if (!el) return false;
        var v = String(el.value || '').trim().toLowerCase();
        return v !== '' && v !== '0' && v !== 'false';
    }

    function wire(box) {
        var message = box.getAttribute('data-must-pick') || 'This is required.';
        var valueSel = box.getAttribute('data-must-pick-value');
        var unlessSel = box.getAttribute('data-must-pick-unless');

        var sentinel = box.querySelector('input:not([type="hidden"]):not([disabled]), select:not([disabled]), textarea:not([disabled])');
        if (!sentinel || typeof sentinel.setCustomValidity !== 'function') return;

        function filled() {
            if (unlessSel && truthy(box.querySelector(unlessSel))) return true;
            if (valueSel) return truthy(box.querySelector(valueSel));
            return box.querySelector('input[type="checkbox"]:checked') !== null;
        }

        function sync() {
            sentinel.setCustomValidity(filled() ? '' : message);
        }

        box.addEventListener('change', sync);
        box.addEventListener('input', sync);
        /* A button inside the wrapper (No brand) writes the hidden flag in
           its own handler; this runs after it rather than racing it. */
        box.addEventListener('click', function () { window.setTimeout(sync, 0); });

        sync();
    }

    function init() {
        document.querySelectorAll('[data-must-pick]').forEach(wire);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
