/**
 * The reveal behaviour behind <x-ui.credential>.
 *
 * The field renders masked to the stored length and the real value is NOT in
 * the page. Clicking the eye asks for it, once, over an authenticated request
 * the server gates on the channel's manage permission and writes to the
 * activity log.
 *
 * Three things this deliberately does:
 *
 *  1. Fetches every time rather than caching the value after the first look.
 *     A cached secret sits in JS memory for the life of the page and turns one
 *     recorded read into any number of unrecorded ones.
 *
 *  2. Re-masks after 30 seconds and whenever the field loses focus. A
 *     credential revealed on a packing-station monitor should not still be
 *     legible an hour later because nobody clicked the eye again.
 *
 *  3. Leaves the typed state alone. Once an operator starts typing a
 *     replacement the field is theirs: revealing would overwrite what they
 *     are in the middle of entering, so the eye stops offering.
 */

const REMASK_AFTER_MS = 30000;

export default function credentialField({ channel, store, field, mask }) {
    return {
        shown: false,
        busy: false,
        mask,
        timer: null,

        async toggle() {
            if (this.shown) {
                this.hide();
                return;
            }

            // The operator has started replacing the value; there is nothing
            // stored-and-hidden left to look at.
            if (this.$refs.input.dataset.credentialMask !== '1') {
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

                this.$refs.input.type = 'text';
                this.$refs.input.value = body.value;
                this.shown = true;

                // Both exits from the revealed state. clearTimeout on hide()
                // stops a stale timer from re-masking a field the operator has
                // since started typing into.
                this.timer = setTimeout(() => this.hide(), REMASK_AFTER_MS);
                this.$refs.input.addEventListener('blur', () => this.hide(), { once: true });
            } catch (e) {
                window.showFlashError?.('That credential could not be shown.');
            } finally {
                this.busy = false;
            }
        },

        hide() {
            if (!this.shown) {
                return;
            }

            clearTimeout(this.timer);
            this.timer = null;
            this.shown = false;

            // Only restore the mask if the field still holds the revealed
            // secret. If the operator typed over it, that is their value and
            // putting dots back would silently discard it.
            if (this.$refs.input.dataset.credentialMask === '1') {
                this.$refs.input.type = 'password';
                this.$refs.input.value = this.mask;
            }
        },

        url() {
            return `/integrations/${encodeURIComponent(channel)}/settings/credential/reveal`;
        },
    };
}
