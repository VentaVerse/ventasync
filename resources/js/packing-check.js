(function () {
    var meta = document.querySelector('meta[name="packing-check"]');
    if (!meta) return;

    var base = meta.getAttribute('content').replace(/\/$/, '');
    var csrf = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';
    var WRONG = 'That count is wrong. Count again.';
    var FAILED = 'The count could not be checked. Try again.';

    var dialog = null;
    var current = null;

    function el(tag, cls, text) {
        var n = document.createElement(tag);
        if (cls) n.className = cls;
        if (text !== undefined && text !== null) n.textContent = text;
        return n;
    }

    function build() {
        if (dialog) return dialog;

        var backdrop = el('div', 'modal-backdrop pc-backdrop');
        var box = el('div', 'modal co-modal pc-modal');
        box.setAttribute('role', 'dialog');
        box.setAttribute('aria-modal', 'true');
        box.setAttribute('aria-labelledby', 'pc-title');

        var head = el('div', 'modal-header co-modal__head');
        var title = el('h3', 'co-modal__title', 'How many did you pack?');
        title.id = 'pc-title';
        var close = el('button', 'modal-close co-modal__close');
        close.type = 'button';
        close.setAttribute('aria-label', 'Cancel');
        close.textContent = '×';
        head.appendChild(title);
        head.appendChild(close);

        var meta = el('div', 'co-modal__meta');
        var error = el('div', 'fm-note fm-note--fail pc-error');
        error.setAttribute('role', 'alert');
        error.hidden = true;
        var errorBody = el('div', 'fm-note__body');
        error.appendChild(errorBody);

        var form = el('form', 'pc-form');
        form.noValidate = true;
        var list = el('ul', 'pc-lines');
        form.appendChild(list);

        var foot = el('div', 'co-modal__foot');
        var cancel = el('button', 'x-btn x-btn--secondary x-btn--md', 'Cancel');
        cancel.type = 'button';
        var go = el('button', 'x-btn x-btn--primary x-btn--md', 'Continue');
        go.type = 'submit';
        foot.appendChild(cancel);
        foot.appendChild(go);
        form.appendChild(foot);

        box.appendChild(head);
        box.appendChild(meta);
        box.appendChild(error);
        box.appendChild(form);
        backdrop.appendChild(box);
        document.body.appendChild(backdrop);

        close.addEventListener('click', function () { finish(false); });
        cancel.addEventListener('click', function () { finish(false); });
        backdrop.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') { e.preventDefault(); finish(false); }
        });
        form.addEventListener('submit', function (e) {
            e.preventDefault();
            submitCount();
        });

        dialog = { backdrop: backdrop, meta: meta, error: error, errorBody: errorBody, list: list, go: go };
        return dialog;
    }

    function showError(message) {
        dialog.errorBody.textContent = message;
        dialog.error.hidden = false;
        var first = dialog.list.querySelector('input');
        if (first) first.focus();
    }

    function busy(on) {
        dialog.go.disabled = on;
        dialog.go.setAttribute('aria-busy', on ? 'true' : 'false');
    }

    function request(method, url, body) {
        return fetch(url, {
            method: method,
            credentials: 'same-origin',
            headers: {
                'Accept': 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrf,
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: body ? JSON.stringify(body) : undefined
        });
    }

    function renderLines(lines) {
        var list = dialog.list;
        while (list.firstChild) list.removeChild(list.firstChild);
        list.appendChild(el('li', 'pc-lines__head', 'Packed')).setAttribute('aria-hidden', 'true');

        lines.forEach(function (line) {
            var li = el('li', 'pc-line');
            if (line.image) {
                var img = el('img', 'pc-line__img');
                img.src = line.image;
                img.alt = '';
                img.loading = 'lazy';
                li.appendChild(img);
            } else {
                li.appendChild(el('span', 'pc-line__img pc-line__img--none'));
            }

            var what = el('span', 'pc-line__what');
            what.appendChild(el('span', 'pc-line__name', line.name || line.sku || 'Item'));
            if (line.sku) what.appendChild(el('span', 'pc-line__sku', line.sku));
            if (line.variation) what.appendChild(el('span', 'pc-line__var', line.variation));
            li.appendChild(what);

            var label = el('label', 'pc-line__count');
            label.appendChild(el('span', 'x-sr', 'How many of ' + (line.name || 'this item') + ' did you pack?'));
            var input = el('input', 'x-input pc-input');
            input.type = 'text';
            input.inputMode = 'numeric';
            input.pattern = '[0-9]*';
            input.autocomplete = 'off';
            input.maxLength = 6;
            input.setAttribute('data-key', line.key);
            label.appendChild(input);
            li.appendChild(label);

            list.appendChild(li);
        });
    }

    function loadOrder() {
        var c = current;
        var id = c.orders[c.index];
        var d = build();
        d.error.hidden = true;
        d.go.textContent = c.index < c.orders.length - 1 ? 'Next order' : 'Continue';
        busy(true);

        request('GET', base + '/' + encodeURIComponent(c.channel) + '/' + encodeURIComponent(id))
            .then(function (res) { return res.ok ? res.json() : Promise.reject(res.status); })
            .then(function (data) {
                if (current !== c) return;
                d.meta.textContent = (c.orders.length > 1 ? 'Order ' + (c.index + 1) + ' of ' + c.orders.length + ' · ' : 'Order ')
                    + (data.reference || id);
                renderLines(data.lines || []);
                busy(false);
                var first = d.list.querySelector('input');
                if (first) first.focus();
            })
            .catch(function () {
                if (current !== c) return;
                busy(false);
                showError(FAILED);
            });
    }

    function submitCount() {
        var c = current;
        if (!c) return;
        var counts = {};
        dialog.list.querySelectorAll('input[data-key]').forEach(function (input) {
            counts[input.getAttribute('data-key')] = input.value.trim();
        });
        busy(true);

        request('POST', base + '/' + encodeURIComponent(c.channel) + '/' + encodeURIComponent(c.orders[c.index]), { counts: counts })
            .then(function (res) {
                if (current !== c) return;
                busy(false);
                if (res.ok) {
                    c.index++;
                    if (c.index < c.orders.length) {
                        loadOrder();
                    } else {
                        finish(true);
                    }
                    return;
                }
                showError(res.status === 422 ? WRONG : FAILED);
            })
            .catch(function () {
                if (current !== c) return;
                busy(false);
                showError(FAILED);
            });
    }

    function start(channel, orders, onPass) {
        current = { channel: channel, orders: orders, index: 0, onPass: onPass, opener: document.activeElement };
        var d = build();
        d.backdrop.classList.add('active');
        loadOrder();
    }

    function finish(passed) {
        var c = current;
        current = null;
        if (dialog) dialog.backdrop.classList.remove('active');
        if (!c) return;
        if (passed) {
            c.onPass();
        } else if (c.opener && c.opener.focus) {
            c.opener.focus();
        }
    }

    document.addEventListener('click', function (e) {
        var trigger = e.target.closest('[data-pc-channel][data-pc-order]');
        if (!trigger || trigger.tagName === 'FORM') return;
        if (trigger.type === 'submit' && trigger.form) return;
        if (trigger._pcPassed) { trigger._pcPassed = false; return; }

        e.preventDefault();
        e.stopImmediatePropagation();
        start(trigger.getAttribute('data-pc-channel'), [trigger.getAttribute('data-pc-order')], function () {
            trigger._pcPassed = true;
            trigger.click();
        });
    }, true);

    document.addEventListener('submit', function (e) {
        var form = e.target;
        var submitter = e.submitter || null;
        var trigger = submitter && submitter.hasAttribute('data-pc-channel') ? submitter
            : (form.hasAttribute && form.hasAttribute('data-pc-channel') ? form : null);
        if (!trigger) return;
        if (form._pcPassed) { form._pcPassed = false; return; }

        var orders;
        var bulk = trigger.getAttribute('data-pc-bulk');
        if (bulk) {
            orders = [];
            var boxes = form.elements.namedItem(bulk);
            boxes = boxes ? (boxes.length !== undefined && !boxes.tagName ? Array.prototype.slice.call(boxes) : [boxes]) : [];
            boxes.forEach(function (box) { if (box.checked) orders.push(box.value); });
            if (orders.length === 0) return;
        } else {
            orders = [trigger.getAttribute('data-pc-order')];
        }

        e.preventDefault();
        e.stopImmediatePropagation();
        start(trigger.getAttribute('data-pc-channel'), orders, function () {
            form._pcPassed = true;
            if (form.requestSubmit) {
                form.requestSubmit(submitter);
            } else {
                form.submit();
            }
        });
    }, true);
})();
