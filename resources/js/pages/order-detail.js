document.addEventListener('DOMContentLoaded', function () {
    var page = document.querySelector('[data-order-detail]');
    if (!page) return;

    var orderId = page.dataset.orderId;
    var baseUrl = page.dataset.baseUrl;
    var flashHost = document.getElementById('od-flash');

    function csrf() {
        var meta = document.querySelector('meta[name="csrf-token"]');
        return meta ? meta.content : '';
    }

    function flash(message, kind) {
        if (!flashHost) return;

        var note = document.createElement('div');
        note.className = 'od-note od-note--' + (kind === 'ok' ? 'ok' : 'fail');

        var body = document.createElement('div');
        body.className = 'od-note__body';
        body.textContent = message;
        note.appendChild(body);

        var close = document.createElement('button');
        close.type = 'button';
        close.className = 'od-note__close';
        close.setAttribute('aria-label', 'Dismiss');
        close.textContent = '×';
        close.addEventListener('click', function () { note.remove(); });
        note.appendChild(close);

        flashHost.appendChild(note);
        note.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }

    function send(url, method, payload) {
        return fetch(url, {
            method: method,
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': csrf(),
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: JSON.stringify(payload)
        }).then(function (response) {
            if (!response.ok) {
                throw new Error('HTTP ' + response.status);
            }
            return response.json();
        });
    }

    function reloadSoon() {
        window.setTimeout(function () { window.location.reload(); }, 400);
    }

    function openEditor(button, config) {
        if (button.dataset.editing === '1') return;
        button.dataset.editing = '1';

        var input = document.createElement('input');
        input.type = config.type;
        if (config.type === 'number') {
            input.step = '0.01';
            input.min = '0';
        }
        input.className = 'x-input od-edit__input' + (config.inputClass ? ' ' + config.inputClass : '');
        input.value = config.value;

        button.hidden = true;
        button.parentNode.insertBefore(input, button);
        input.focus();
        input.select();

        var settled = false;

        function finish() {
            if (input.parentNode) input.parentNode.removeChild(input);
            button.hidden = false;
            button.dataset.editing = '';
            button.focus();
        }

        function cancel() {
            if (settled) return;
            settled = true;
            finish();
        }

        function commit() {
            if (settled) return;
            settled = true;

            var raw = config.type === 'number' ? parseFloat(input.value) : input.value.trim();

            if (config.type === 'number' && (isNaN(raw) || raw < 0)) {
                flash('Enter an amount of 0 or more. Nothing was changed.', 'fail');
                finish();
                return;
            }

            if (config.type === 'text' && raw === '') {
                finish();
                return;
            }

            if (String(raw) === String(config.value)) {
                finish();
                return;
            }

            input.disabled = true;

            config.save(raw)
                .then(function (data) {
                    if (data && data.ok) {
                        config.applied(raw, button, finish);
                    } else {
                        flash((data && data.error) || 'That change was not saved.', 'fail');
                        finish();
                    }
                })
                .catch(function () {
                    flash('Could not reach the server. Nothing was changed.', 'fail');
                    finish();
                });
        }

        input.addEventListener('blur', commit);
        input.addEventListener('keydown', function (event) {
            if (event.key === 'Enter') {
                event.preventDefault();
                input.blur();
            } else if (event.key === 'Escape') {
                event.preventDefault();
                cancel();
            }
        });
    }

    page.addEventListener('click', function (event) {
        var button = event.target.closest('.od-edit');
        if (!button || !page.contains(button)) return;

        var kind = button.dataset.edit;

        if (kind === 'cost') {
            openEditor(button, {
                type: 'number',
                value: parseFloat(button.dataset.cost) || 0,
                save: function (value) {
                    return send(baseUrl + '/' + orderId + '/update-product-cost', 'POST', {
                        order_product_id: button.dataset.opId,
                        cost: value
                    });
                },
                applied: function () {
                    flash('Cost updated.', 'ok');
                    reloadSoon();
                }
            });
        } else if (kind === 'shipping') {
            openEditor(button, {
                type: 'number',
                value: parseFloat(button.dataset.cost) || 0,
                save: function (value) {
                    return send(baseUrl + '/' + orderId + '/update-shipping-cost', 'POST', {
                        shipping_cost: value
                    });
                },
                applied: function () {
                    flash('Shipping cost updated.', 'ok');
                    reloadSoon();
                }
            });
        } else if (kind === 'fee-amount') {
            openEditor(button, {
                type: 'number',
                value: parseFloat(button.dataset.amount) || 0,
                save: function (value) {
                    return send(baseUrl + '/' + orderId + '/fees/' + button.dataset.feeId, 'PUT', {
                        amount: value
                    });
                },
                applied: function () {
                    flash('Fee updated.', 'ok');
                    reloadSoon();
                }
            });
        } else if (kind === 'fee-label') {
            openEditor(button, {
                type: 'text',
                inputClass: 'od-edit__input--text',
                value: button.dataset.label || '',
                save: function (value) {
                    return send(baseUrl + '/' + orderId + '/fees/' + button.dataset.feeId, 'PUT', {
                        label: value,
                        amount: parseFloat(button.dataset.amount) || 0
                    });
                },
                applied: function (value, control, finish) {
                    finish();
                    control.dataset.label = value;
                    control.textContent = value;
                    flash('Fee label updated.', 'ok');
                }
            });
        }
    });

    page.addEventListener('error', function (event) {
        var img = event.target;
        if (!img || img.tagName !== 'IMG' || !img.classList.contains('order-img')) return;
        img.hidden = true;
    }, true);
});


(function () {
    const select = document.querySelector('.od-status [data-status-select]');
    const form = document.querySelector('.od-status [data-status-form]');
    const note = document.querySelector('.od-status [data-status-note]');
    if (!select || !form || !note) return;

    const currentSubtract = select.getAttribute('data-current-subtract') === '1';
    const units = parseInt(select.getAttribute('data-units') || '0', 10);
    const fallback = note.getAttribute('data-note-default') || note.textContent;

    function describe() {
        const opt = select.options[select.selectedIndex];
        const target = opt ? opt.textContent.trim() : '';
        const willSubtract = opt && opt.getAttribute('data-subtract') === '1';
        const unchanged = opt && opt.value === select.getAttribute('data-current');
        if (unchanged || willSubtract === currentSubtract) {
            note.textContent = fallback;
            note.classList.remove('is-move');
            form.removeAttribute('data-confirm');
            return;
        }
        const verb = willSubtract ? 'deducts' : 'restores';
        const sentence = 'Moving to ' + target + ' ' + verb + ' ' + units + (units === 1 ? ' unit' : ' units') + ' of stock the moment you update.';
        note.textContent = sentence;
        note.classList.add('is-move');
        form.setAttribute('data-confirm', 'Move this order to ' + target + '? It ' + verb + ' ' + units + (units === 1 ? ' unit' : ' units') + ' of stock.');
    }

    select.addEventListener('change', describe);
    describe();

    const details = select.closest('details.od-status');
    if (details) {
        details.querySelectorAll('[data-status-close]').forEach(function (el) {
            el.addEventListener('click', function () { details.open = false; details.querySelector('summary').focus(); });
        });
    }
})();
