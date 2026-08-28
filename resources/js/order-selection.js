/**
 * Order selection, for every fulfilment list that offers a bulk action.
 *
 * Generalised out of the Venta orders page, which had the only working
 * selection model in the product. The behaviour is unchanged; what moved is
 * the coupling. The old module hard-coded three element ids and one form,
 * so a second list wanting the same thing had to copy it.
 *
 * A list opts in by rendering `[data-order-selection]` around its rows, with
 *   - a select-all box marked `[data-selection-all]`
 *   - a live region marked `[data-selection-count]`
 *   - one or more action containers marked `[data-selection-actions]`
 *   - a checkbox per order carrying `.x-pick` inside that scope
 *
 * and nothing else. Several action forms may sit in one bar: the pick list
 * and the packing list are two buttons over one selection.
 *
 * Delegated rather than bound per checkbox, because the rows are re-rendered
 * on every filter, sort and page change and a delegated listener survives it
 * without this module being told.
 */
function initOrderSelection(scope) {
    var selectAll = scope.querySelector('[data-selection-all]');
    var countEl = scope.querySelector('[data-selection-count]');
    var actions = Array.prototype.slice.call(scope.querySelectorAll('[data-selection-actions]'));

    // A read-only operator, or an empty list, reaches this with nothing to
    // bind. Not an error: the bar simply is not rendered for them.
    if (!selectAll || !countEl || actions.length === 0) return;

    function boxes() {
        return Array.prototype.slice.call(scope.querySelectorAll('.x-pick[data-selection-item]'));
    }

    function update() {
        var all = boxes();
        var picked = all.filter(function (b) { return b.checked; });

        // [hidden] rather than a written style: CSS owns what hidden looks
        // like, and the bar's resting height does not depend on it.
        actions.forEach(function (el) { el.hidden = picked.length === 0; });

        countEl.textContent = picked.length === 0
            ? ''
            : picked.length + (picked.length === 1 ? ' order selected' : ' orders selected');

        // A confirmation names the number it is about to act on.
        //
        // These dialogs guard irreversible marketplace writes, and the
        // dialog covers the selection bar that holds the count - so the
        // operator was being asked to commit to a number they could no
        // longer see. [data-confirm-count] carries the wording with an :n
        // placeholder, and the live count is written into [data-confirm]
        // here, where the count is already known.
        // The question and the wait both name the count: "Pack 12 selected
        // orders?" and then "Packing 12 orders on Lazada". Same :n, same
        // moment, one rewrite for both attributes.
        actions.forEach(function (container) {
            [['data-confirm-count', 'data-confirm'], ['data-busy-count', 'data-busy']].forEach(function (pair) {
                var counted = container.querySelectorAll('[' + pair[0] + ']');

                Array.prototype.forEach.call(counted, function (el) {
                    el.setAttribute(
                        pair[1],
                        el.getAttribute(pair[0]).replace(':n', picked.length)
                    );
                });
            });
        });

        selectAll.checked = all.length > 0 && picked.length === all.length;
        selectAll.indeterminate = picked.length > 0 && picked.length < all.length;
    }

    selectAll.addEventListener('change', function () {
        boxes().forEach(function (b) { b.checked = selectAll.checked; });
        update();
    });

    scope.addEventListener('change', function (e) {
        if (e.target && e.target.matches && e.target.matches('.x-pick[data-selection-item]')) {
            update();
        }
    });

    update();
}

document.addEventListener('DOMContentLoaded', function () {
    Array.prototype.slice
        .call(document.querySelectorAll('[data-order-selection]'))
        .forEach(initOrderSelection);
});
