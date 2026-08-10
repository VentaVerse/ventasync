/**
 * Credential field masking.
 *
 * Marketplace settings forms pre-render stored credentials as a mask sized to
 * the stored value, so the operator sees a strong "something IS stored here"
 * cue rather than a field that reads as empty.
 *
 * WHAT CHANGED, AND WHY
 *
 * This used to clear the field on FOCUS. Clicking a field that visibly held
 * something emptied it before a single key was pressed, so the interface
 * answered a click by destroying the only evidence that anything was saved -
 * and if the operator then clicked away, they were left staring at an empty
 * box wondering whether they had just wiped the credential. They had not; the
 * submit hook below put it back. But nothing on screen said so.
 *
 * Now the mask survives until the operator actually types:
 *
 *  1. On focus the mask is SELECTED, not cleared. The field still shows its
 *     contents, and the selection says what typing will do - replace all of
 *     it - using the same convention every browser uses for a field whose
 *     whole value is about to be overwritten. Click away without typing and
 *     nothing has changed, because nothing was changed.
 *
 *  2. On the first real edit - beforeinput, which covers typing, pasting and
 *     dictation alike - the mask is cleared so the new value lands in a clean
 *     field. beforeinput fires BEFORE the character is inserted, which is what
 *     makes this work without the first keystroke landing among the dots.
 *
 *  3. On submit, a field the operator never edited posts blank. The
 *     controllers treat blank as "keep what is stored", so re-saving a form
 *     without retyping preserves the credential. Without this the literal mask
 *     characters would be saved as the new secret.
 *
 * Markup contract:
 *   <input data-credential-mask="1" value="••••…">
 *
 * data-credential-mask="0" (or absent) means nothing is stored, so the field
 * renders empty and every hook here is a no-op.
 */

document.addEventListener('DOMContentLoaded', function () {
    var masked = document.querySelectorAll('input[data-credential-mask]');

    masked.forEach(function (input) {
        input.addEventListener('focus', function () {
            if (input.dataset.credentialMask !== '1') {
                return;
            }

            // Deferred: a click sets the caret AFTER focus fires, so selecting
            // synchronously here would be undone by the click that caused it.
            setTimeout(function () {
                if (input.dataset.credentialMask === '1') {
                    input.select();
                }
            }, 0);
        });

        // The first genuine edit, whatever the input method. Clearing here
        // rather than on focus is the whole fix: the mask is destroyed by
        // typing, which is what the operator asked for, and never by looking.
        input.addEventListener('beforeinput', function () {
            if (input.dataset.credentialMask === '1') {
                input.value = '';
                input.dataset.credentialMask = '0';
            }
        });

        var form = input.closest('form');
        if (!form) {
            return;
        }

        form.addEventListener('submit', function () {
            if (input.dataset.credentialMask === '1') {
                input.value = '';
            }
        });
    });
});
