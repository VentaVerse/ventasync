export default function ventasyncPalette(entries) {
    return {
        entries,
        open: false,
        q: '',
        idx: 0,
        records: { orders: [], products: [] },
        loading: false,
        _timer: null,
        _restoreFocus: null,

        routes() {
            const term = this.q.trim().toLowerCase();

            if (term === '') {
                return this.entries;
            }

            return this.entries.filter((e) => e.hay.includes(term));
        },

        items() {
            const routeItems = this.routes().map((r) => ({ url: r.url }));
            const orderItems = this.records.orders.map((o) => ({ url: o.url }));
            const productItems = this.records.products.map((p) => ({ url: p.url }));

            return [...routeItems, ...orderItems, ...productItems];
        },

        toggle() {
            if (this.open) {
                this.close();
            } else {
                this.openPalette();
            }
        },

        openPalette() {
            this._restoreFocus = document.activeElement;
            this.open = true;
            this.idx = 0;
            this.$nextTick(() => this.$refs.input.focus());
        },

        close() {
            this.open = false;
            this.q = '';
            this.idx = 0;
            this.records = { orders: [], products: [] };
            clearTimeout(this._timer);

            const toFocus = this._restoreFocus;
            this._restoreFocus = null;

            if (toFocus && typeof toFocus.focus === 'function') {
                toFocus.focus();
            }
        },

        move(delta) {
            const total = this.items().length;

            if (total === 0) {
                return;
            }

            this.idx = (this.idx + delta + total) % total;
        },

        go() {
            const item = this.items()[this.idx];

            if (item) {
                window.location.href = item.url;
            }
        },

        search() {
            this.idx = 0;
            clearTimeout(this._timer);

            const term = this.q.trim();

            if (term.length < 2) {
                this.loading = false;
                this.records = { orders: [], products: [] };
                return;
            }

            this.loading = true;

            this._timer = setTimeout(() => {
                fetch('/palette/search?q=' + encodeURIComponent(term), {
                    headers: { Accept: 'application/json' },
                })
                    .then((res) => (res.ok ? res.json() : { orders: [], products: [] }))
                    .then((data) => {
                        this.records = {
                            orders: Array.isArray(data.orders) ? data.orders : [],
                            products: Array.isArray(data.products) ? data.products : [],
                        };
                    })
                    .catch(() => {
                        this.records = { orders: [], products: [] };
                    })
                    .finally(() => {
                        this.loading = false;
                    });
            }, 200);
        },
    };
}
