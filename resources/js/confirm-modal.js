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

    function confirmAttr(trigger, name) {
        if (!trigger || !trigger.getAttribute) return null;
        return trigger.getAttribute(name)
            || (trigger.form && trigger.form.getAttribute && trigger.form.getAttribute(name))
            || null;
    }

    function applyTone(trigger) {
        var tone = confirmAttr(trigger, 'data-confirm-tone') || 'danger';
        okBtn.classList.remove('x-btn--danger', 'x-btn--primary');
        okBtn.classList.add(tone === 'primary' ? 'x-btn--primary' : 'x-btn--danger');
        okBtn.textContent = confirmAttr(trigger, 'data-confirm-verb') || 'Confirm';
    }

    function confirmModal(message, trigger) {
        lastTrigger = trigger || document.activeElement;
        applyTone(trigger);
        msgEl.textContent = message;
        backdrop.classList.add('active');
        cancelBtn.focus();
        // Click on body so Alpine's click.outside closes the menu that holds the trigger.
        document.body.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true }));
        return new Promise(function (resolve) { resolver = resolve; });
    }

    window.confirmModal = confirmModal;

    okBtn.addEventListener('click', function () { close(true); });

    document.addEventListener('submit', function (e) {
        var form = e.target;

        var submitter = e.submitter || null;
        var msg = (submitter && submitter.getAttribute('data-confirm'))
            || (form.getAttribute && form.getAttribute('data-confirm'));

        if (!msg) return;
        if (form._confirmed) { form._confirmed = false; return; }
        e.preventDefault();
        confirmModal(msg, submitter || form).then(function (ok) {
            if (ok) {
                form._confirmed = true;
                if (form.requestSubmit) {
                    form.requestSubmit(submitter);
                } else {
                    form.submit();
                }
            }
        });
    }, true);

    document.addEventListener('click', function (e) {
        var btn = e.target.closest('[data-confirm]');
        if (!btn || btn.tagName === 'FORM') return;

        if (btn.form && btn.type === 'submit' && !btn.getAttribute('data-confirm-submit')) return;

        var msg = btn.getAttribute('data-confirm');
        var submitId = btn.getAttribute('data-confirm-submit');
        e.preventDefault();
        // No stopPropagation: this capture listener would starve other document click handlers like Alpine's.
        confirmModal(msg, btn).then(function (ok) {
            if (!ok) return;

            var proceed = btn.dispatchEvent(new CustomEvent('confirm:accepted', { bubbles: true, cancelable: true }));

            if (submitId) {
                var f = document.getElementById(submitId);
                if (f) {
                    f._confirmed = true;
                    f.requestSubmit ? f.requestSubmit() : f.submit();
                }
                return;
            }

            var raw = btn.tagName === 'A' ? (btn.getAttribute('href') || '').trim() : '';
            var scheme = (raw.split(':')[0] || '').toLowerCase();
            var sameDoc = raw.charAt(0) === '/' || raw.charAt(0) === '#' || raw.charAt(0) === '?';
            var safe = raw !== '' && raw !== '#' && (sameDoc || scheme === 'http' || scheme === 'https' || scheme === 'mailto' || scheme === 'tel');
            if (proceed && safe) {
                if (btn.target === '_blank') {
                    window.open(btn.href, '_blank', 'noopener');
                } else {
                    window.location.assign(btn.href);
                }
            }
        });
    }, true);
});
