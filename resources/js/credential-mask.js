/**
 * Credential field masking.
 *
 * Marketplace settings forms (Shopee / Lazada / TikTok / Pedallion)
 * pre-render stored credentials as `value="••••••••"` so the operator
 * sees a strong "something IS stored here" cue — not a grayed-out
 * placeholder that reads as empty.
 *
 * Two behaviours this script wires up:
 *
 *  1. On focus, the masked value clears so the operator can type a
 *     replacement without first having to select-all + delete the dots.
 *     The pristine flag flips so we know the field is no longer the
 *     server-rendered mask.
 *
 *  2. On form submit, any field that's still pristine (operator never
 *     touched it) is blanked before the form posts — preventing the
 *     literal `••••••••` from being saved as the new credential. The
 *     controller already treats blank fields as "keep existing", so
 *     this combination preserves the stored value when the operator
 *     re-saves the form without re-entering credentials.
 *
 * Markup contract:
 *   <input data-credential-mask="1" value="••••••••" ...>
 *
 * data-credential-mask="0" (or absent) means "no value stored, render
 * empty as usual" — the focus/submit hooks are no-ops in that state.
 */

document.addEventListener('DOMContentLoaded', function () {
    var masked = document.querySelectorAll('input[data-credential-mask]');

    masked.forEach(function (input) {
        input.addEventListener('focus', function () {
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
