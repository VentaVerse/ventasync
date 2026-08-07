/**
 * Order detail page behaviour.
 *
 * Bundled into the shared app.js (both layouts load it) and guarded on the
 * presence of [data-order-detail], which only exists on the order detail
 * pages, so this module is inert everywhere else - the same guard
 * convention confirm-modal.js and orders-filter.js already use.
 *
 * What it owns:
 *   1. The four click-to-edit money figures (unit cost, shipping cost, fee
 *      amount, fee label). These POST/PUT to the same endpoints the page
 *      always used and reload afterwards so the profit arithmetic
 *      recomputes server-side rather than being duplicated here.
 *   2. The page's own flash region, because layouts.shell renders none.
 *   3. Hiding a thumbnail whose image file is missing, which used to be an
 *      inline onerror="" attribute on the <img>.
 *
 * Two rules this file is written against, both from earlier rounds:
 *
 *   - No innerHTML anywhere. Every node is createElement/textContent, so a
 *     fee label or an error message containing markup is shown as text and
 *     cannot inject anything.
 *
 *   - Every failure path re-enables its controls (the C1 lesson: a Confirm
 *     Ship button that stayed disabled forever after a failed request). The
 *     editor below disables only its own input while a request is in
 *     flight, and every terminal branch - ok, not-ok, HTTP error, network
 *     error, malformed JSON, cancel - runs through finish(), which removes
 *     the input, restores the control and returns focus to it.
 *
 * The editor is deliberately NOT optimistic. The previous version wrote the
 * new figure into the page before the request resolved, so a rejected save
 * left a number on screen that did not exist in the database - the exact
 * class of "a figure that says something the data does not" this page
 * cannot afford. The stored value stays on screen until the server
 * confirms, and a confirmed numeric change reloads the page rather than
 * being patched in.
 */

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

    /* ------------------------------------------------------------------
       Flash region. layouts.shell renders none of its own, and without
       one an inline save would succeed or fail in silence.
       ------------------------------------------------------------------ */

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

    /* ------------------------------------------------------------------
       Click-to-edit.
       ------------------------------------------------------------------ */

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

    // Reload rather than patch the DOM: unit cost and shipping cost both
    // feed COGS, net profit, margin and markup, and re-deriving those in
    // JavaScript would be a second implementation of the arithmetic this
    // page exists to show correctly.
    function reloadSoon() {
        window.setTimeout(function () { window.location.reload(); }, 400);
    }

    /**
     * @param {HTMLElement} button  the .od-edit control that was activated
     * @param {Object} config
     *        type       'number' | 'text'
     *        value      the stored value the editor opens on
     *        inputClass optional extra class for the input
     *        save(v)    -> Promise resolving the JSON envelope
     *        applied(v, button, finish) -> void, on a confirmed save
     */
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

        // The single exit. Whatever happened - saved, rejected, cancelled,
        // network down - the input goes away and the control comes back
        // usable and focused. Nothing here can end up permanently disabled.
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

            // An empty label is a mis-click, not a change. Leave the stored
            // value alone rather than writing a blank one.
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
                    // updateFee validates `amount` as required even when
                    // only the label is changing, so the row's current
                    // amount rides along unchanged.
                    return send(baseUrl + '/' + orderId + '/fees/' + button.dataset.feeId, 'PUT', {
                        label: value,
                        amount: parseFloat(button.dataset.amount) || 0
                    });
                },
                applied: function (value, control, finish) {
                    // A label does not feed the arithmetic, so this one
                    // updates in place instead of costing a page load.
                    finish();
                    control.dataset.label = value;
                    control.textContent = value;
                    flash('Fee label updated.', 'ok');
                }
            });
        }
    });

    /* ------------------------------------------------------------------
       A thumbnail whose image file is missing. Capture phase, because
       `error` does not bubble.
       ------------------------------------------------------------------ */

    page.addEventListener('error', function (event) {
        var img = event.target;
        if (!img || img.tagName !== 'IMG' || !img.classList.contains('order-img')) return;
        img.hidden = true;
    }, true);
});
