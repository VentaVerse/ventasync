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
