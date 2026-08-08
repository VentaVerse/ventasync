/**
 * The petty cash entry editor.
 *
 * Registered as an Alpine component (see app.js) rather than written
 * inline in the Blade view, so the view carries markup and the
 * behaviour lives here.
 *
 * It replaces a hand-rolled modal: a backdrop, a close button, an
 * Escape handler and a form whose `action` and `_method` were rewritten
 * by hand on every click. The panel is now part of the page, opened in
 * place above the ledger, because the figure being changed is the one
 * the operator was just reading and dimming it to edit it is the wrong
 * shape.
 *
 * What each mode may post is decided by PettyCashController, not by
 * this file:
 *
 *   store()   type, amount, category (expenses), description,
 *             transaction_date, notes, plus user_id when type=credit.
 *   update()  amount, category, description, transaction_date, notes,
 *             plus user_id only when an admin edits a credit. It does
 *             not accept a change of type, which is why edit mode
 *             disables the type control instead of offering one that
 *             would be silently ignored.
 *
 * A disabled control posts nothing, which is exactly how the fields
 * that must not appear in a given mode are kept out of the payload.
 */
export default function pettyCash(config) {
    const settings = config || {};

    return {
        // Named editorOpen, not `open`, and that is load-bearing.
        // <x-ui.menu> carries its own x-data="{ open: false }", and every
        // row's Edit item lives inside one. Alpine resolves a property
        // against the nearest scope that has it, so a plain `open` here
        // would be shadowed: clicking Edit filled the panel in and set the
        // MENU's open flag instead of this one, and the panel stayed
        // hidden. Nothing in a render test can see that.
        editorOpen: false,
        mode: 'create',
        kind: 'expense',
        title: '',
        action: settings.storeUrl || '',
        method: 'POST',

        form: {
            user_id: '',
            amount: '',
            category: '',
            description: '',
            notes: '',
            transaction_date: settings.today || '',
        },

        reset() {
            this.form = {
                user_id: '',
                amount: '',
                category: '',
                description: '',
                notes: '',
                transaction_date: settings.today || '',
            };
        },

        openCreate() {
            this.reset();
            this.mode = 'create';
            this.kind = 'expense';
            this.action = settings.storeUrl || '';
            this.method = 'POST';
            this.title = settings.canCredit ? 'New entry' : 'New expense';
            this.editorOpen = true;
            this.focusFirst();
        },

        openEdit(row) {
            this.reset();
            this.mode = 'edit';
            this.kind = row.kind === 'credit' ? 'credit' : 'expense';
            // Built from a route() call in Blade with an __ID__ token, so
            // no URL shape is assumed here.
            this.action = String(settings.updateUrl || '').replace('__ID__', row.id);
            this.method = 'PUT';
            this.title = row.kind === 'credit' ? 'Edit credit' : 'Edit expense';

            this.form = {
                user_id: row.user_id || '',
                amount: row.amount || '',
                category: row.category || '',
                description: row.description || '',
                notes: row.notes || '',
                transaction_date: row.transaction_date || settings.today || '',
            };

            this.editorOpen = true;
            this.focusFirst();
        },

        close() {
            this.editorOpen = false;
        },

        /**
         * Put the cursor on the amount once the panel has actually been
         * rendered. $nextTick is load-bearing: x-show flips the display
         * in the same tick and focusing a still-hidden element is a
         * silent no-op.
         */
        focusFirst() {
            this.$nextTick(() => {
                const editor = this.$refs.editor;
                if (!editor) return;
                editor.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                const amount = editor.querySelector('[name="amount"]');
                if (amount) amount.focus();
            });
        },
    };
}

/**
 * Category rows on the settings page: reveal a row's Save only once that
 * row has actually been changed.
 *
 * The button is rendered visible by Blade and hidden here on load, so a
 * browser with no JavaScript keeps a working, always-present Save rather
 * than a form it cannot submit. Each row carries its fields through the
 * form= attribute, so "this row" is the nearest .pc-cat.
 */
document.addEventListener('DOMContentLoaded', function () {
    const rows = document.querySelectorAll('.pc-cat');
    if (!rows.length) return;

    rows.forEach(function (row) {
        const save = row.querySelector('[data-pc-cat-save]');
        if (!save) return;

        save.hidden = true;

        function reveal() {
            save.hidden = false;
        }

        row.addEventListener('input', reveal);
        row.addEventListener('change', reveal);
    });
});
