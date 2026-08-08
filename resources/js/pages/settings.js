/* ============================================================
 * Settings pages
 * ------------------------------------------------------------
 * The behaviours the settings pages share. Every one of them used to
 * live as an inline onclick attribute or a page <script> block in a
 * Blade view; none of them belongs there.
 *
 *   1. [data-copy-target="<id>"] copies that element's text.
 *   2. [data-toggle-disables="<id>"] disables a field while a
 *      checkbox is on. Used by the API expiry date, which must not
 *      submit when "No expiry" is ticked.
 *   3. [data-select-on-focus] selects a read-only field's contents.
 *   4. .st-file__input reports the chosen filename beside the label
 *      that stands in for the browser's own file button, and
 *      previews an image into [data-image-preview]'s target.
 *   5. [data-smtp-toggle] shows the SMTP credentials only for the
 *      mailer that uses them.
 *   6. [data-test-mail] posts a test message and reports the reply.
 *
 * Everything is written with textContent / value, never innerHTML,
 * so nothing here can be an injection point for a stored name.
 * ============================================================ */
(function () {
    'use strict';

    // ---------------------------------------------------------------- copy
    function textOf(el) {
        if (!el) return '';
        // An <input>/<textarea> carries its text in .value; anything else
        // (a <code> block) carries it in .textContent.
        return 'value' in el && el.value !== undefined && el.tagName !== 'CODE'
            ? String(el.value)
            : String(el.textContent || '');
    }

    async function copy(text) {
        if (navigator.clipboard && window.isSecureContext) {
            try {
                await navigator.clipboard.writeText(text);
                return true;
            } catch (e) {
                // Fall through to the selection fallback below.
            }
        }
        // Fallback for a page served over plain http, where the async
        // clipboard API is unavailable. A detached textarea keeps the
        // visible document untouched.
        const scratch = document.createElement('textarea');
        scratch.value = text;
        scratch.setAttribute('readonly', 'readonly');
        scratch.setAttribute('aria-hidden', 'true');
        scratch.classList.add('x-sr');
        document.body.appendChild(scratch);
        scratch.select();
        let ok = false;
        try {
            ok = document.execCommand('copy');
        } catch (e) {
            ok = false;
        }
        document.body.removeChild(scratch);
        return ok;
    }

    document.addEventListener('click', function (ev) {
        const btn = ev.target.closest('[data-copy-target]');
        if (!btn) return;

        ev.preventDefault();

        const source = document.getElementById(btn.dataset.copyTarget);
        if (!source) return;

        const original = btn.dataset.copyLabel || btn.textContent.trim();
        btn.dataset.copyLabel = original;

        copy(textOf(source)).then(function (ok) {
            btn.textContent = ok ? 'Copied' : 'Press Ctrl C';
            window.setTimeout(function () {
                btn.textContent = btn.dataset.copyLabel;
            }, 1600);
        });
    });

    // ---------------------------------------------------------------- toggle
    // `disabled`, deliberately, not `hidden`: a disabled control does not
    // submit, which is exactly what "No expiry" has to mean to the
    // controller, and it avoids depending on a display rule that any
    // layout class could out-specify.
    function applyToggle(box) {
        const field = document.getElementById(box.dataset.toggleDisables);
        if (!field) return;
        field.disabled = box.checked;
    }

    const toggles = document.querySelectorAll('[data-toggle-disables]');
    toggles.forEach(function (box) {
        applyToggle(box);
        box.addEventListener('change', function () {
            applyToggle(box);
        });
    });

    // ---------------------------------------------------------------- select
    // A read-only field holding something the operator has to take away with
    // them (the one-time API token) selects itself when focused, so keyboard
    // copy works without reaching for the mouse.
    document.querySelectorAll('[data-select-on-focus]').forEach(function (field) {
        field.addEventListener('focus', function () {
            field.select();
        });
    });

    // ---------------------------------------------------------------- files
    // A bare <input type="file"> is OS chrome. The input is kept (it is the
    // wire) and visually hidden; a <label> stands in for the button and this
    // reports the chosen name beside it. Written with textContent, so a file
    // named with markup is text.
    document.querySelectorAll('.st-file__input').forEach(function (input) {
        const readout = document.querySelector('[data-file-name-for="' + input.id + '"]');

        input.addEventListener('change', function () {
            const file = input.files && input.files[0];

            if (readout) {
                readout.textContent = file ? file.name : 'No file chosen';
            }

            // Preview target, for the logo and favicon. A data: URL from
            // FileReader assigned to img.src, never innerHTML.
            const targetId = input.dataset.imagePreview;
            if (!targetId || !file) return;

            const img = document.getElementById(targetId);
            if (!img) return;

            const reader = new FileReader();
            reader.onload = function (ev) {
                img.src = ev.target.result;
                const caption = img.parentNode
                    ? img.parentNode.querySelector('.st-brand__cap')
                    : null;
                if (caption) caption.textContent = 'Not saved yet';
            };
            reader.readAsDataURL(file);
        });
    });

    // ---------------------------------------------------------------- smtp
    // The SMTP credential block only applies to one of the two mailers.
    // `hidden`, with the matching `.st-smtp[hidden]` rule in
    // pages/settings.css: the attribute alone is a user agent display rule
    // and `.fm-fields`'s own `display: grid` out-specifies it.
    document.querySelectorAll('[data-smtp-toggle]').forEach(function (select) {
        const block = document.getElementById(select.dataset.smtpToggle);
        if (!block) return;

        function apply() {
            block.hidden = select.value !== 'smtp';
        }

        select.addEventListener('change', apply);
        apply();
    });

    // ----------------------------------------------------------- test mail
    // Posts JSON to settings.mail.test and reports the reply in place. The
    // reply is the mail transport's own exception message when it fails, so
    // it is written with textContent and never parsed as markup.
    document.querySelectorAll('[data-test-mail]').forEach(function (button) {
        const input = document.getElementById(button.dataset.testInput);
        const result = document.getElementById(button.dataset.testResult);
        if (!input || !result) return;

        const token = document.querySelector('meta[name="csrf-token"]');

        button.addEventListener('click', function () {
            const to = (input.value || '').trim();

            result.hidden = false;
            result.className = 'st-test__result';

            if (!to) {
                result.classList.add('is-fail');
                result.textContent = 'Type an address to send to.';
                return;
            }

            const label = button.textContent;
            button.disabled = true;
            button.textContent = 'Sending';
            result.textContent = 'Sending to ' + to;

            fetch(button.dataset.testMail, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': token ? token.getAttribute('content') : '',
                },
                body: JSON.stringify({ to: to }),
            })
                .then(function (response) { return response.json(); })
                .then(function (data) {
                    result.classList.add(data.ok ? 'is-ok' : 'is-fail');
                    result.textContent = data.message || (data.ok ? 'Sent.' : 'It did not send.');
                })
                .catch(function () {
                    result.classList.add('is-fail');
                    result.textContent = 'The server did not answer. Check the connection and try again.';
                })
                .finally(function () {
                    button.disabled = false;
                    button.textContent = label;
                });
        });
    });
})();
