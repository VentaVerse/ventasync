/**
 * Global confirm modal for the new shell layout (layouts.shell).
 *
 * Ports the [data-confirm] convention that the legacy layouts.app used to
 * wire up inline for itself. This module is bundled into the shared app.js,
 * which every layout loads, so it guards on the presence of #x-confirm-modal:
 * that element only exists on pages that include
 * partials/confirm-modal.blade.php. The guard was originally there so this
 * module stayed inert on legacy-layout pages, where layouts.app's own inline
 * modal was active; that layout was deleted in Round F Task 4, and the guard
 * is kept because it is still what makes the module safe on a page (a print
 * view, a callback page) that includes no confirm modal at all.
 *
 * Convention (carried over from layouts.app, on distinct element ids):
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
        if (! backdrop.classList.contains('active')) return;

        if (e.key === 'Escape') {
            close(false);
            return;
        }

        // Focus stays inside the dialog while it is open. Without this, Tab
        // walked straight out into the page behind a backdrop that still
        // covered the screen, so a keyboard user could be operating controls
        // they could not see, with an unanswered confirmation still up.
        if (e.key !== 'Tab') return;

        var focusable = Array.prototype.filter.call(
            backdrop.querySelectorAll('button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])'),
            function (el) { return ! el.disabled && el.offsetParent !== null; }
        );

        if (! focusable.length) return;

        var first = focusable[0];
        var last = focusable[focusable.length - 1];

        if (e.shiftKey && document.activeElement === first) {
            e.preventDefault();
            last.focus();
        } else if (! e.shiftKey && document.activeElement === last) {
            e.preventDefault();
            first.focus();
        }
    });

    // The dialog is shared, so the trigger says whether what it guards is
    // destructive. Default danger (almost every data-confirm is a delete);
    // data-confirm-tone="primary" is for the ones that are not, so a red
    // Confirm never appears on an action that removes nothing.
    function applyTone(trigger) {
        var tone = (trigger && trigger.getAttribute && trigger.getAttribute('data-confirm-tone')) || 'danger';
        okBtn.classList.remove('x-btn--danger', 'x-btn--primary');
        okBtn.classList.add(tone === 'primary' ? 'x-btn--primary' : 'x-btn--danger');
    }

    function confirmModal(message, trigger) {
        lastTrigger = trigger || document.activeElement;
        applyTone(trigger);
        msgEl.textContent = message;
        backdrop.classList.add('active');
        // Focus lands on CANCEL, not Confirm.
        //
        // It used to open with the cursor already on the destructive button,
        // so a reflexive Enter - the keystroke that submitted the form that
        // opened this dialog - deleted listings from a public marketplace
        // before the operator had read a word. The safe choice is the one a
        // guard should default to; reaching Confirm is one Tab away, and that
        // Tab is the pause this dialog exists to create.
        cancelBtn.focus();
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

    // The same global contract layouts.app used to publish for legacy pages
    // (window.confirmModal(message) -> Promise<boolean>), so a page script can
    // ask for a confirmation without reaching for confirm(). The catalogue
    // form pages use it for the cost-override unlock and for deleting an image
    // off the server.
    window.confirmModal = confirmModal;

    okBtn.addEventListener('click', function () { close(true); });

    document.addEventListener('submit', function (e) {
        var form = e.target;
        var msg = form.getAttribute && form.getAttribute('data-confirm');
        if (!msg) return;
        if (form._confirmed) { form._confirmed = false; return; }
        e.preventDefault();
        confirmModal(msg, form).then(function (ok) {
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
