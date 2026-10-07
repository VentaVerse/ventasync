export default function pettyCash(config) {
    const settings = config || {};

    return {
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
