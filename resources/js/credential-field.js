/**
 * The behaviour behind <x-ui.credential>.
 *
 * A stored credential renders masked and READ-ONLY. The real value is not in
 * the page. "View and edit" asks the server for it, once, over an
 * authenticated request the server gates on the channel's manage permission
 * and writes to the activity log.
 *
 * WHAT CHANGED, AND WHY
 *
 * The field used to be editable-but-masked, and it destroyed its own contents
 * the moment editing began - on the reasoning that a half-edited secret is a
 * corrupt secret. That reasoning is sound and the interaction was still wrong.
 * Nobody types a 64-character token by hand; they paste. So the clearing
 * behaviour never once saved anyone from a corrupt secret, and every single
 * time it read as "I have just destroyed the saved value".
 *
 * Now the field is read-only until the operator asks for it. Asking reveals
 * the real value AND makes the field editable in place, so one wrong character
 * in a token can be corrected instead of the whole thing being re-pasted.
 * Nothing is ever cleared.
 *
 * Four things this deliberately does:
 *
 *  1. Fetches every time rather than caching after the first look. A cached
 *     secret sits in JS memory for the life of the page and turns one recorded
 *     read into any number of unrecorded ones.
 *
 *  2. Re-masks after 30 seconds of NOT being edited, and on blur when
 *     untouched. A credential revealed on a packing-station monitor should not
 *     still be legible an hour later. The timer is cancelled by the first
 *     edit, because re-masking a field someone is typing into would discard
 *     their work - which is the defect this rewrite exists to remove.
 *
 *  3. Submits BLANK when the value was revealed but not edited. The
 *     controllers read blank as "keep what is stored", so looking at a secret
 *     never rewrites it and an unchanged secret does not travel back over the
 *     wire.
 *
 *  4. Marks its own input so credential-mask.js leaves it alone. Two submit
 *     handlers blanking one field is how a saved credential gets wiped by a
 *     form the operator only opened to read.
 */

const REMASK_AFTER_MS = 30000;

export default function credentialField({ channel, store, field, mask }) {
    return {
        shown: false,
        busy: false,
        edited: false,
        mask,
        timer: null,

        init() {
            // credential-mask.js handles credentials with no reveal endpoint.
            // This one owns its field, including what happens on submit.
            this.$refs.input.dataset.credentialManaged = '1';

            this.$refs.input.addEventListener('input', () => {
                this.edited = true;
                // Whatever is in the field now is the operator's, so nothing
                // may put it back or take it away.
                clearTimeout(this.timer);
                this.timer = null;
            });

            const form = this.$refs.input.closest('form');

            if (form) {
                form.addEventListener('submit', () => {
                    if (! this.edited) {
                        this.$refs.input.value = '';
                    }
                });
            }
        },

        async toggle() {
            if (this.shown) {
                this.hide();
                return;
            }

            this.busy = true;

            try {
                const res = await fetch(this.url(), {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
                    },
                    body: JSON.stringify({ field, store }),
                });

                const body = await res.json();

                if (!res.ok || !body.ok) {
                    window.showFlashError?.(body.message ?? 'That credential could not be shown.');
                    return;
                }

                const input = this.$refs.input;

                input.type = 'text';
                input.value = body.value;
                // Editable in place. This is the whole point of the button:
                // "View and edit", not "view".
                input.readOnly = false;
                input.dataset.credentialMask = '0';
                this.shown = true;

                // Caret at the end rather than a full selection: a selection
                // says "typing replaces all of this", which is exactly the
                // behaviour being removed.
                input.focus();
                input.setSelectionRange(input.value.length, input.value.length);

                this.timer = setTimeout(() => this.hide(), REMASK_AFTER_MS);
                input.addEventListener('blur', () => {
                    if (! this.edited) {
                        this.hide();
                    }
                }, { once: true });
            } catch (e) {
                window.showFlashError?.('That credential could not be shown.');
            } finally {
                this.busy = false;
            }
        },

        hide() {
            if (! this.shown) {
                return;
            }

            clearTimeout(this.timer);
            this.timer = null;
            this.shown = false;

            const input = this.$refs.input;

            // An edited value is the operator's work. Hiding must not discard
            // it, so the field goes back to dots ONLY when it still holds the
            // value it was given.
            if (this.edited) {
                input.type = 'password';
                return;
            }

            input.type = 'password';
            input.value = this.mask;
            input.readOnly = true;
            input.dataset.credentialMask = '1';
        },

        url() {
            return `/integrations/${encodeURIComponent(channel)}/settings/credential/reveal`;
        },
    };
}
