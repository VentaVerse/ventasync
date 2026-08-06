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
    var lastTrigger = null;

    function close(result) {
        backdrop.classList.remove('active');
        // Return focus to whatever opened the modal (the Delete menu item,
        // typically) rather than leaving it stranded on the modal's own
        // now-hidden button - standard modal-dialog focus management. The
        // trigger itself is frequently a menu item that just got hidden by
        // the click-outside close a few lines up in confirmModal() (its
        // parent <x-ui.menu>'s x-show flips to false), and focusing a
        // display:none element is a silent no-op, so fall back to that
        // menu's own always-visible "..." trigger button - still a sane,
        // focusable place to land - before giving up.
        if (lastTrigger && typeof lastTrigger.focus === 'function') {
            lastTrigger.focus();
            if (document.activeElement !== lastTrigger) {
                var menuTrigger = lastTrigger.closest ? lastTrigger.closest('.x-menu')?.querySelector('.x-menu__trigger') : null;
                if (menuTrigger) menuTrigger.focus();
            }
        }
        lastTrigger = null;
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

    function confirmModal(message, trigger) {
        lastTrigger = trigger || document.activeElement;
        msgEl.textContent = message;
        backdrop.classList.add('active');
        okBtn.focus();
        // The trigger for a data-confirm item is frequently inside an
        // open <x-ui.menu> (e.g. the row overflow menu's Delete item).
        // Alpine's @click.outside on that menu only closes it in response
        // to an actual click event whose target is outside the menu; the
        // click that opened this modal had its target INSIDE the menu, so
        // Alpine correctly ignored it, and nothing else in this flow ever
        // tells the menu to close. Dispatching one synthetic, undirected
        // click on <body> gives Alpine's own document-level listener a
        // click whose target genuinely is outside every open menu, so it
        // closes normally through its own mechanism instead of this
        // module reaching into Alpine's internal state. Harmless
        // elsewhere: every other document click listener in the app
        // (invoice-copy, permission-pill) already no-ops correctly when
        // the target doesn't match what it's looking for.
        document.body.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true }));
        return new Promise(function (resolve) { resolver = resolve; });
    }

    okBtn.addEventListener('click', function () { close(true); });

    document.addEventListener('submit', function (e) {
        var form = e.target;
        var msg = form.getAttribute && form.getAttribute('data-confirm');
        if (!msg) return;
        if (form._confirmed) { form._confirmed = false; return; }
        e.preventDefault();
        confirmModal(msg, document.activeElement).then(function (ok) {
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
        // No e.stopPropagation() here (C-round finding): this is a
        // capture-phase listener on document, so calling stopPropagation
        // would kill the event before it ever reaches the target or
        // bubbles back up, which silently breaks any OTHER document-level
        // listener that a later, unrelated data-confirm click should have
        // reached (Alpine's own click.outside among them). preventDefault
        // alone is enough to stop the button/link's default action.
        confirmModal(msg, btn).then(function (ok) {
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
