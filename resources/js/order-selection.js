function initOrderSelection(scope) {
    var selectAll = scope.querySelector('[data-selection-all]');
    var countEl = scope.querySelector('[data-selection-count]');
    var actions = Array.prototype.slice.call(scope.querySelectorAll('[data-selection-actions]'));

    if (!selectAll || !countEl || actions.length === 0) return;

    function boxes() {
        return Array.prototype.slice.call(scope.querySelectorAll('.x-pick[data-selection-item]'));
    }

    function update() {
        var all = boxes();
        var picked = all.filter(function (b) { return b.checked; });

        actions.forEach(function (el) { el.hidden = picked.length === 0; });

        countEl.textContent = picked.length === 0
            ? ''
            : picked.length + (picked.length === 1 ? ' order selected' : ' orders selected');

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
