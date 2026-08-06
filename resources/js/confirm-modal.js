/**
 * Global confirm modal for the new shell layout (layouts.shell).
 *
 * Ports the [data-confirm] convention that layouts.app wires up inline for
 * itself. This module is bundled into the shared app.js, which both layouts
 * load, so it guards on the presence of #x-confirm-modal: that element only
 * exists on pages built on the new shell (see partials/confirm-modal.blade.php).
 * On legacy-layout pages the guard returns immediately, leaving layouts.app's
 * own inline modal and listeners as the only ones active there.
 *
 * Convention (matches layouts.app exactly, just on distinct element ids):
 *   - A <form data-confirm="message"> is intercepted on submit.
 *   - Any other element with data-confirm is intercepted on click; if it
 *     also carries data-confirm-submit="<form id>", that form is submitted
 *     after the user confirms. This is how a menu button outside any form
 *     (e.g. a destructive item in <x-ui.menu>) drives a hidden delete form.
 */
document.addEventListener('DOMContentLoaded', function () {
    var backdrop = document.getElementById('x-confirm-modal');
    if (!backdrop) return;

    var msgEl = document.getElementById('x-confirm-modal-message');
    var okBtn = document.getElementById('x-confirm-modal-ok');
    var cancelBtn = document.getElementById('x-confirm-modal-cancel');
    var closeBtn = document.getElementById('x-confirm-modal-close');
    var resolver = null;

    function close(result) {
        backdrop.classList.remove('active');
        if (resolver) {
            resolver(result);
            resolver = null;
        }
    }

    cancelBtn.addEventListener('click', function () { close(false); });
    closeBtn.addEventListener('click', function () { close(false); });
    backdrop.addEventListener('click', function (e) {
        if (e.target === backdrop) close(false);
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && backdrop.classList.contains('active')) close(false);
    });

    function confirmModal(message) {
        msgEl.textContent = message;
        backdrop.classList.add('active');
        okBtn.focus();
        return new Promise(function (resolve) { resolver = resolve; });
    }

    okBtn.addEventListener('click', function () { close(true); });

    document.addEventListener('submit', function (e) {
        var form = e.target;
        var msg = form.getAttribute && form.getAttribute('data-confirm');
        if (!msg) return;
        if (form._confirmed) { form._confirmed = false; return; }
        e.preventDefault();
        confirmModal(msg).then(function (ok) {
            if (ok) {
                form._confirmed = true;
                form.requestSubmit ? form.requestSubmit() : form.submit();
            }
        });
    }, true);

    document.addEventListener('click', function (e) {
        var btn = e.target.closest('[data-confirm]');
        if (!btn || btn.tagName === 'FORM') return;
        var msg = btn.getAttribute('data-confirm');
        var submitId = btn.getAttribute('data-confirm-submit');
        e.preventDefault();
        e.stopPropagation();
        confirmModal(msg).then(function (ok) {
            if (!ok) return;
            if (submitId) {
                var f = document.getElementById(submitId);
                if (f) {
                    f._confirmed = true;
                    f.requestSubmit ? f.requestSubmit() : f.submit();
                }
            }
        });
    }, true);
});
