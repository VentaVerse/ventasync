document.addEventListener('DOMContentLoaded', function () {
    var modal = document.getElementById('add-store-modal');
    if (!modal) return;

    var channelStep = modal.querySelector('[data-add-step="channel"]');
    var forms = Array.prototype.slice.call(modal.querySelectorAll('[data-add-form]'));

    function showChannelStep() {
        channelStep.hidden = false;
        forms.forEach(function (f) { f.hidden = true; });
    }

    function open() {
        showChannelStep();
        modal.classList.add('active');
        var first = channelStep.querySelector('[data-add-pick]');
        if (first) first.focus();
    }

    function close() {
        modal.classList.remove('active');
    }

    function pick(id) {
        channelStep.hidden = true;
        forms.forEach(function (f) {
            var match = f.getAttribute('data-add-form') === id;
            f.hidden = !match;
            if (match) {
                var input = f.querySelector('input[name="store_name"]');
                if (input) input.focus();
            }
        });
    }

    if (modal.hasAttribute('data-add-store-auto')) {
        open();
    }

    document.querySelectorAll('[data-add-store-open]').forEach(function (btn) {
        btn.addEventListener('click', open);
    });
    modal.querySelectorAll('[data-add-store-close]').forEach(function (btn) {
        btn.addEventListener('click', close);
    });
    modal.querySelectorAll('[data-add-pick]').forEach(function (tile) {
        tile.addEventListener('click', function () { pick(tile.getAttribute('data-add-pick')); });
    });
    modal.querySelectorAll('[data-add-back]').forEach(function (btn) {
        btn.addEventListener('click', showChannelStep);
    });

    modal.addEventListener('click', function (e) {
        if (e.target === modal) close();
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && modal.classList.contains('active')) close();
    });

    modal.querySelectorAll('[data-ch-logo]').forEach(function (img) {
        img.addEventListener('error', function () { img.style.display = 'none'; });
        if (img.complete && img.naturalWidth === 0) img.style.display = 'none';
    });
});
