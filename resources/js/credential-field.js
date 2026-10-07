const REMASK_AFTER_MS = 30000;

export default function credentialField({ channel, store, field, mask }) {
    return {
        shown: false,
        busy: false,
        edited: false,
        mask,
        timer: null,

        init() {
            this.$refs.input.dataset.credentialManaged = '1';

            this.$refs.input.addEventListener('input', () => {
                this.edited = true;
                clearTimeout(this.timer);
                this.timer = null;
            });

            this.$refs.input.addEventListener('blur', (event) => {
                if (! this.shown || this.edited) {
                    return;
                }

                if (event.relatedTarget === this.$refs.toggle) {
                    return;
                }

                this.hide();
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

            // Never fetch the stored value once edited, or the operator's unsaved input is overwritten.
            if (this.edited) {
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
                input.readOnly = false;
                input.dataset.credentialMask = '0';
                this.shown = true;

                input.focus();
                input.setSelectionRange(input.value.length, input.value.length);

                this.timer = setTimeout(() => this.hide(), REMASK_AFTER_MS);
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
            return `/channels/${encodeURIComponent(channel)}/settings/credential/reveal`;
        },
    };
}
