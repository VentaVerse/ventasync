/* ============================================================
 * Settings pages
 * ------------------------------------------------------------
 * Two behaviours shared by the settings CRUD family. Both used to
 * live as inline onclick attributes in the Blade views (currencies
 * index, API token reveal); neither belongs there.
 *
 *   1. [data-copy-target="<id>"] copies that element's text.
 *   2. [data-toggle-disables="<id>"] disables a field while a
 *      checkbox is on. Used by the API expiry date, which must not
 *      submit when "No expiry" is ticked.
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
})();
