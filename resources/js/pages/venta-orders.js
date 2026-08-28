/**
 * Venta orders page: book a courier, tracking (#168).
 *
 * The storefront owns the courier integration; the ERP asks it who can
 * carry a parcel, books, and prints the waybill it hands back. The flow
 * is Shopee's ship modal in shape, so a packer who knows one knows the
 * other:
 *
 *   1. Book courier -> the modal opens and asks the storefront which
 *      couriers will take this address. One button per courier, with the
 *      cheapest fee where the courier quoted one; a refusal shows its
 *      reason and cannot be pressed.
 *   2. Choose one -> the options: pickup slot when the courier offers
 *      slots, weight and dimensions (left blank, the courier's defaults
 *      apply), insurance, and an estimate on demand.
 *   3. Book -> the modal closes, the overlay says "Booking with SPX
 *      Express", then "Getting the waybill" while the label is polled, the
 *      waybill opens in a new tab and the page reloads into To Handover.
 *      A refusal lowers the overlay and reopens the options with the
 *      storefront's own sentence, so the packer can change something and
 *      try again.
 *
 * Every URL comes off the button that opened the modal, not from a path
 * assembled here, so the routes file can move without this file knowing.
 * Nothing is written with innerHTML: every node is built and given text.
 */
document.addEventListener('DOMContentLoaded', function () {
    var page = document.getElementById('venta-orders-page');
    if (!page) return;

    initBookModal();
    initTrackingModal();

    /* ------------------------------------------------------------------
       Shared helpers.
       ------------------------------------------------------------------ */
    function showModal(el) { if (el) el.classList.add('active'); }
    function hideModal(el) { if (el) el.classList.remove('active'); }
    function hide(el) { if (el) el.classList.add('vt-hidden'); }
    function show(el) { if (el) el.classList.remove('vt-hidden'); }

    function clear(el) {
        while (el && el.firstChild) el.removeChild(el.firstChild);
    }

    function textNode(tag, className, text) {
        var el = document.createElement(tag);
        if (className) el.className = className;
        el.textContent = text;
        return el;
    }

    function csrfToken() {
        var meta = document.querySelector('meta[name="csrf-token"]');
        return meta ? meta.content : '';
    }

    function getJson(url) {
        return fetch(url, { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' })
            .then(function (r) { return r.json(); });
    }

    function postJson(url, body) {
        return fetch(url, {
            method: 'POST',
            headers: {
                'Accept': 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken(),
                'X-Requested-With': 'XMLHttpRequest'
            },
            credentials: 'same-origin',
            body: JSON.stringify(body)
        }).then(function (r) { return r.json().then(function (data) { return { status: r.status, data: data }; }); });
    }

    function bindModalDismiss(modal, buttons) {
        if (!modal) return;
        buttons.forEach(function (btn) {
            if (btn) btn.addEventListener('click', function () { hideModal(modal); });
        });
        modal.addEventListener('click', function (e) {
            if (e.target === modal) hideModal(modal);
        });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && modal.classList.contains('active')) hideModal(modal);
        });
    }

    function money(amount, currency) {
        if (amount === null || amount === undefined) return '';
        var n = Number(amount);
        if (isNaN(n)) return String(amount);
        return (currency || 'PHP') + ' ' + n.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    // The names a packer reads for the storefront's courier keys. Mirrors
    // Extensions\venta\Services\Venta\VentaCouriers; an unknown key reads as
    // itself in capitals rather than blank.
    var COURIER_NAMES = { spx: 'SPX Express', quadx: 'QuadX', lbc: 'LBC' };
    function courierName(key) {
        key = String(key || '').toLowerCase();
        return COURIER_NAMES[key] || key.toUpperCase();
    }

    // The storefront's own sentence for a refusal, by kind, so the courier's
    // raw text is never printed beside a button.
    var REFUSALS = {
        destination: 'Does not deliver to this address',
        origin: 'Does not collect from the pickup address',
        cod: 'Does not take cash on delivery here',
        postcode: 'Does not recognise the postcode',
        parcel: 'Will not take a parcel this size',
        slot: 'No pickup slot available',
        their_side: 'Refused the booking',
        unreachable: 'Could not be reached'
    };

    /* ------------------------------------------------------------------
       Book a courier.
       ------------------------------------------------------------------ */
    function initBookModal() {
        var modal = document.getElementById('vtBookModal');
        if (!modal) return;

        var overlay = document.getElementById('vtLoadingOverlay');
        var overlayTitle = document.getElementById('vtLoadingTitle');
        var orderNoEl = document.getElementById('vtBookOrderNo');
        var step1 = document.getElementById('vtBookStep1');
        var step2 = document.getElementById('vtBookStep2');
        var couriersLoading = document.getElementById('vtBookCouriersLoading');
        var couriersEl = document.getElementById('vtBookCouriers');
        var courierNameEl = document.getElementById('vtBookCourierName');
        var slotWrap = document.getElementById('vtBookSlotWrap');
        var slotEl = document.getElementById('vtBookSlot');
        var weightEl = document.getElementById('vtBookWeight');
        var lengthEl = document.getElementById('vtBookLength');
        var widthEl = document.getElementById('vtBookWidth');
        var heightEl = document.getElementById('vtBookHeight');
        var insureEl = document.getElementById('vtBookInsure');
        var estimateBtn = document.getElementById('btnVtEstimate');
        var estimateEl = document.getElementById('vtBookEstimate');
        var errorEl = document.getElementById('vtBookError');
        var backBtn = document.getElementById('btnVtBookBack');
        var confirmBtn = document.getElementById('btnVtBookConfirm');

        bindModalDismiss(modal, [document.getElementById('btnCloseVtBook')]);

        var urls = {};
        var courier = '';

        function setOverlay(title) {
            if (overlayTitle) overlayTitle.textContent = title;
            showModal(overlay);
        }

        function showError(message) {
            errorEl.textContent = message;
            show(errorEl);
        }

        function reset() {
            show(step1);
            hide(step2);
            show(couriersLoading);
            hide(couriersEl);
            clear(couriersEl);
            hide(slotWrap);
            clear(slotEl);
            slotEl.appendChild(new Option('Drop off myself', ''));
            weightEl.value = '';
            lengthEl.value = '';
            widthEl.value = '';
            heightEl.value = '';
            insureEl.checked = false;
            estimateEl.textContent = '';
            hide(errorEl);
            errorEl.textContent = '';
            confirmBtn.disabled = false;
            courier = '';
        }

        // One button per courier. A courier that refused is drawn but inert,
        // with the refusal under its name, because "SPX will not go there"
        // is an answer and hiding it would leave the packer guessing why the
        // list is short.
        function renderCouriers(couriers, allRefused) {
            clear(couriersEl);
            var keys = Object.keys(couriers || {});

            if (keys.length === 0) {
                couriersEl.appendChild(textNode('div', 'text-secondary', 'No courier is set up on the storefront, or this order cannot be booked in its current status.'));
                return;
            }

            keys.forEach(function (key) {
                var v = couriers[key] || {};
                var btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'x-btn x-btn--secondary vt-courier';
                btn.setAttribute('data-courier', key);

                btn.appendChild(textNode('span', 'vt-courier__name', courierName(key)));

                if (v.status === 'serviceable') {
                    btn.appendChild(textNode('span', 'vt-courier__note',
                        (v.from !== null && v.from !== undefined) ? 'from ' + money(v.from, v.currency) : 'Will carry it'));
                } else if (v.status === 'not_serviceable') {
                    btn.disabled = true;
                    btn.classList.add('vt-courier--refused');
                    btn.appendChild(textNode('span', 'vt-courier__note', REFUSALS[v.kind] || v.reason || 'Refused'));
                } else {
                    btn.classList.add('vt-courier--unknown');
                    btn.appendChild(textNode('span', 'vt-courier__note', 'Could not check; you can still try'));
                }

                btn.addEventListener('click', function () { chooseCourier(key); });
                couriersEl.appendChild(btn);
            });

            if (allRefused) {
                couriersEl.appendChild(textNode('p', 'vt-book__error', 'Every courier refused this address. Check the shipping address on the storefront, or hand it to a courier by hand there.'));
            }
        }

        function chooseCourier(key) {
            courier = key;
            courierNameEl.textContent = courierName(key);
            hide(step1);
            show(step2);
            estimateEl.textContent = '';
            hide(errorEl);
            loadSlots();
        }

        // Slots only for couriers that offer them (SPX). An empty answer is
        // the normal case for the others and hides the field.
        function loadSlots() {
            hide(slotWrap);
            clear(slotEl);
            slotEl.appendChild(new Option('Drop off myself', ''));

            getJson(urls.slots + (urls.slots.indexOf('?') === -1 ? '?' : '&') + 'courier=' + encodeURIComponent(courier))
                .then(function (data) {
                    var slots = (data && data.ok && Array.isArray(data.slots)) ? data.slots : [];
                    if (slots.length === 0) return;
                    slots.forEach(function (s) { slotEl.appendChild(new Option(s.label, s.value)); });
                    show(slotWrap);
                })
                .catch(function () { /* no slots is a valid state */ });
        }

        function options() {
            var opts = { courier: courier };
            if (slotEl.value) opts.pickup_slot = slotEl.value;
            if (weightEl.value) opts.weight_kg = weightEl.value;
            if (lengthEl.value) opts.length_cm = lengthEl.value;
            if (widthEl.value) opts.width_cm = widthEl.value;
            if (heightEl.value) opts.height_cm = heightEl.value;
            if (insureEl.checked) opts.insure = true;
            return opts;
        }

        estimateBtn.addEventListener('click', function () {
            estimateBtn.disabled = true;
            estimateEl.textContent = 'Asking ' + courierName(courier);
            hide(errorEl);

            postJson(urls.estimate, options())
                .then(function (res) {
                    estimateBtn.disabled = false;
                    var d = res.data || {};
                    if (!d.ok) {
                        estimateEl.textContent = '';
                        showError(d.message || 'An estimate is not available.');
                        return;
                    }
                    var text = d.label || money(d.amount, d.currency);
                    if (d.insurance && d.total !== null && d.total !== undefined && d.total !== d.amount) {
                        text += ', ' + money(d.total, d.currency) + ' with insurance';
                    }
                    estimateEl.textContent = text;
                })
                .catch(function () {
                    estimateBtn.disabled = false;
                    estimateEl.textContent = '';
                    showError('The estimate did not come back.');
                });
        });

        backBtn.addEventListener('click', function () {
            show(step1);
            hide(step2);
        });

        // Poll for the waybill after a booking. The storefront's own
        // create-then-poll couriers (SPX) can take a few seconds to hand
        // back a printable label; twenty tries three seconds apart is the
        // same patience Shopee's AWB poll has.
        function waitForWaybill(awbUrl) {
            var attempts = 0;
            var max = 20;

            function done(message) {
                hideModal(overlay);
                if (message && window.showFlashError) window.showFlashError(message);
                location.reload();
            }

            function poll() {
                attempts++;
                getJson(awbUrl)
                    .then(function (data) {
                        if (data && data.ok && data.ready) {
                            hideModal(overlay);
                            window.open(awbUrl, '_blank');
                            location.reload();
                        } else if (attempts < max) {
                            setTimeout(poll, 3000);
                        } else {
                            done('Booked, but the waybill is still being prepared. Use Print waybill on the row to try again.');
                        }
                    })
                    .catch(function () {
                        if (attempts < max) {
                            setTimeout(poll, 3000);
                        } else {
                            done('Booked, but the waybill could not be fetched. Use Print waybill on the row to try again.');
                        }
                    });
            }

            setTimeout(poll, 1500);
        }

        confirmBtn.addEventListener('click', function () {
            confirmBtn.disabled = true;
            hide(errorEl);
            hideModal(modal);
            setOverlay('Booking with ' + courierName(courier));

            postJson(urls.book, options())
                .then(function (res) {
                    var d = res.data || {};
                    if (!d.ok) {
                        // Back to the options with the storefront's own
                        // sentence, so something can be changed and tried
                        // again without starting over.
                        hideModal(overlay);
                        confirmBtn.disabled = false;
                        showError(d.message || 'The storefront could not book this parcel.');
                        showModal(modal);
                        return;
                    }

                    if (overlayTitle) overlayTitle.textContent = 'Getting the waybill';
                    waitForWaybill(d.awb_url);
                })
                .catch(function () {
                    hideModal(overlay);
                    confirmBtn.disabled = false;
                    showError('The booking did not go through: the request failed before the storefront answered.');
                    showModal(modal);
                });
        });

        // Delegated, so it keeps working for rows added by anything later.
        document.addEventListener('click', function (e) {
            var btn = e.target.closest ? e.target.closest('.btnVentaBook') : null;
            if (!btn) return;
            e.preventDefault();

            urls = {
                serviceability: btn.getAttribute('data-serviceability-url'),
                slots: btn.getAttribute('data-slots-url'),
                estimate: btn.getAttribute('data-estimate-url'),
                book: btn.getAttribute('data-book-url')
            };
            orderNoEl.textContent = btn.getAttribute('data-order-no') || '';

            reset();
            showModal(modal);

            getJson(urls.serviceability)
                .then(function (data) {
                    hide(couriersLoading);
                    show(couriersEl);
                    if (!data || !data.ok) {
                        clear(couriersEl);
                        couriersEl.appendChild(textNode('div', 'vt-book__error', (data && data.message) || 'The storefront could not be reached.'));
                        return;
                    }
                    renderCouriers(data.couriers || {}, !!data.all_refused);
                })
                .catch(function () {
                    hide(couriersLoading);
                    show(couriersEl);
                    clear(couriersEl);
                    couriersEl.appendChild(textNode('div', 'vt-book__error', 'The storefront could not be reached.'));
                });
        });
    }

    /* ------------------------------------------------------------------
       Tracking: the courier's timeline as the storefront reports it, in
       the same {status, at} shape for every courier.
       ------------------------------------------------------------------ */
    function initTrackingModal() {
        var modal = document.getElementById('vtTrackingModal');
        var body = document.getElementById('vtTrackingBody');
        var sub = document.getElementById('vtTrackingSub');
        if (!modal || !body) return;

        bindModalDismiss(modal, [
            document.getElementById('btnCloseVtTracking'),
            document.getElementById('btnCloseVtTracking2')
        ]);

        function formatTime(t) {
            if (!t) return '';
            var d = typeof t === 'number' ? new Date(t > 1000000000000 ? t : t * 1000) : new Date(t);
            if (isNaN(d.getTime())) return String(t);
            return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0')
                + ' ' + String(d.getHours()).padStart(2, '0') + ':' + String(d.getMinutes()).padStart(2, '0');
        }

        function humanise(status) {
            return String(status || '').replace(/[_-]+/g, ' ').replace(/^\w/, function (c) { return c.toUpperCase(); });
        }

        function render(res) {
            clear(body);
            if (sub) sub.textContent = '';

            if (!res || !res.ok) {
                body.appendChild(textNode('div', 'text-danger', (res && res.message) || 'Tracking is not available for this order.'));
                return;
            }

            if (sub) {
                var parts = [];
                if (res.courier) parts.push(courierName(res.courier));
                if (res.tracking_number) parts.push(res.tracking_number);
                if (res.status) parts.push(humanise(res.status));
                sub.textContent = parts.join(' · ');
            }

            var events = Array.isArray(res.timeline) ? res.timeline : [];
            if (events.length === 0) {
                body.appendChild(textNode('div', 'text-secondary', 'The courier has reported no events yet.'));
            } else {
                var wrap = document.createElement('div');
                wrap.className = 'co-trace';
                events.forEach(function (ev) {
                    var row = document.createElement('div');
                    row.className = 'co-trace__event';
                    row.appendChild(textNode('div', 'co-trace__time', formatTime(ev.at || ev.time || ev.timestamp)));
                    row.appendChild(textNode('div', 'co-trace__status', humanise(ev.status || ev.description || ev.message)));
                    var detail = ev.detail || ev.location || ev.remarks || '';
                    if (detail) row.appendChild(textNode('div', 'co-trace__detail', detail));
                    wrap.appendChild(row);
                });
                body.appendChild(wrap);
            }

            var links = res.links || {};
            var linkKeys = Object.keys(links).filter(function (k) { return links[k]; });
            if (linkKeys.length > 0) {
                var p = document.createElement('p');
                p.className = 'vt-track__links';
                linkKeys.forEach(function (k) {
                    var a = document.createElement('a');
                    a.href = links[k];
                    a.target = '_blank';
                    a.rel = 'noopener';
                    a.textContent = 'Open ' + humanise(k).toLowerCase() + ' tracking on the courier’s site';
                    p.appendChild(a);
                });
                body.appendChild(p);
            }
        }

        document.addEventListener('click', function (e) {
            var btn = e.target.closest ? e.target.closest('.btnVentaTracking') : null;
            if (!btn) return;
            var url = btn.getAttribute('data-url');
            if (!url) return;
            e.preventDefault();

            clear(body);
            if (sub) sub.textContent = '';
            body.appendChild(textNode('div', 'text-secondary', 'Loading'));
            showModal(modal);

            getJson(url)
                .then(render)
                .catch(function () {
                    clear(body);
                    body.appendChild(textNode('div', 'text-danger', 'Could not load tracking for this order.'));
                });
        });
    }
});
