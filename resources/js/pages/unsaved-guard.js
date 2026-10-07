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
        exits.forEach(function (el) {
            el.addEventListener('confirm:accepted', function () { leaving = true; });
        });
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

        ['input', 'change', 'click'].forEach(function (name) {
            document.addEventListener(name, function (e) {
                var t = e.target;
                if (form.contains(t) || (t && t.form === form)) window.setTimeout(refresh, 0);
            });
        });

        document.addEventListener('submit', function () { leaving = true; }, true);

        window.addEventListener('beforeunload', function (e) {
            if (leaving || !dirty()) return;
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
