document.addEventListener('DOMContentLoaded', function () {
    var page = document.getElementById('ventacart-orders-page');
    if (!page) return;

    initBookModal();
    initTrackingModal();

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
        var sym = (!currency || currency === 'PHP') ? '₱' : currency + ' ';
        return sym + n.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    var COURIER_NAMES = { spx: 'SPX Express', quadx: 'QuadX', lbc: 'LBC' };
    function courierName(key, given) {
        if (given) return given;
        key = String(key || '').toLowerCase();
        return COURIER_NAMES[key] || key.toUpperCase();
    }

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

    function fillSelect(select, options, chosen, placeholder) {
        clear(select);
        if (placeholder) {
            var ph = new Option(placeholder, '');
            ph.selected = true;
            select.appendChild(ph);
        }
        (options || []).forEach(function (o) {
            var opt = new Option(o.label, String(o.value));
            if (!placeholder && chosen !== null && chosen !== undefined && String(o.value) === String(chosen)) opt.selected = true;
            select.appendChild(opt);
        });
        return (options || []).length > 0;
    }

    function fillParcel(container, key, hiddenClass) {
        if (!container) return;
        while (container.firstChild) container.removeChild(container.firstChild);
        var tpl = key ? document.querySelector('template[data-parcel-for="' + CSS.escape(String(key)) + '"]') : null;
        if (!tpl || !tpl.content || !tpl.content.firstElementChild) {
            container.classList.add(hiddenClass);
            return;
        }
        container.appendChild(tpl.content.cloneNode(true));
        container.classList.remove(hiddenClass);
    }

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

        var addressWrap = document.getElementById('vtBookAddressWrap');
        var addressEl = document.getElementById('vtBookAddress');
        var serviceWrap = document.getElementById('vtBookServiceWrap');
        var serviceEl = document.getElementById('vtBookService');
        var parcelWrap = document.getElementById('vtBookParcelWrap');
        var parcelEl = document.getElementById('vtBookParcel');
        var slotWrap = document.getElementById('vtBookSlotWrap');
        var slotEl = document.getElementById('vtBookSlot');
        var insureEl = document.getElementById('vtBookInsure');
        var paymentWrap = document.getElementById('vtBookPaymentWrap');
        var paymentEl = document.getElementById('vtBookPayment');

        var quoteShipping = document.getElementById('vtQuoteShipping');
        var quoteInsuranceRow = document.getElementById('vtQuoteInsuranceRow');
        var quoteInsurance = document.getElementById('vtQuoteInsurance');
        var quoteTotal = document.getElementById('vtQuoteTotal');
        var quoteNote = document.getElementById('vtBookQuoteNote');
        var quoteNoteText = document.getElementById('vtBookQuoteNoteText');
        var estimateBtn = document.getElementById('btnVtEstimate');

        var errorEl = document.getElementById('vtBookError');
        var backBtn = document.getElementById('btnVtBookBack');
        var confirmBtn = document.getElementById('btnVtBookConfirm');

        var manualPane = document.getElementById('vtBookManual');
        var manualCourierEl = document.getElementById('vtManualCourier');
        var manualTrackingEl = document.getElementById('vtManualTracking');
        var manualCommentEl = document.getElementById('vtManualComment');
        var manualErrorEl = document.getElementById('vtManualError');
        var manualBackBtn = document.getElementById('btnVtManualBack');
        var manualConfirmBtn = document.getElementById('btnVtManualConfirm');

        bindModalDismiss(modal, [document.getElementById('btnCloseVtBook')]);

        var urls = {};
        var courier = '';
        var courierLabel = '';
        var couriersInfo = {};
        var addresses = null;
        var manualCouriers = null;
        var quoteSeq = 0;
        var quoteTimer = null;

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
            hide(manualPane);
            show(couriersLoading);
            hide(couriersEl);
            clear(couriersEl);
            hide(addressWrap); hide(serviceWrap); hide(parcelWrap); hide(slotWrap); hide(paymentWrap);
            clear(slotEl);
            slotEl.appendChild(new Option('Drop off myself', ''));
            insureEl.checked = false;
            hide(errorEl);
            errorEl.textContent = '';
            hide(manualErrorEl);
            manualErrorEl.textContent = '';
            manualTrackingEl.value = '';
            manualCommentEl.value = '';
            confirmBtn.disabled = false;
            manualConfirmBtn.disabled = false;
            courier = '';
            courierLabel = '';
            couriersInfo = {};
            setQuote(null, 'Asking');
        }

        function appendManualCard() {
            var btn = document.createElement('button');
            btn.type = 'button';
            btn.id = 'btnVtManual';
            btn.className = 'x-btn x-btn--secondary vt-courier vt-courier--manual';
            btn.appendChild(textNode('span', 'vt-courier__name', 'Another courier'));
            btn.appendChild(textNode('span', 'vt-courier__note', 'Record it by hand'));
            btn.addEventListener('click', openManual);
            couriersEl.appendChild(btn);
        }

        function renderCouriers(couriers, allRefused) {
            clear(couriersEl);
            var keys = Object.keys(couriers || {});

            if (keys.length === 0) {
                couriersEl.appendChild(textNode('div', 'text-secondary', 'No courier is set up on the storefront, or this order cannot be booked in its current status.'));
                appendManualCard();
                return;
            }

            keys.forEach(function (key) {
                var v = couriers[key] || {};
                var btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'x-btn x-btn--secondary vt-courier';
                btn.setAttribute('data-courier', key);

                btn.appendChild(textNode('span', 'vt-courier__name', courierName(key, v.name)));

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

            appendManualCard();

            if (allRefused) {
                couriersEl.appendChild(textNode('p', 'vt-book__error', 'Every courier refused this address. Check the shipping address on the storefront, or record another courier by hand.'));
            }
        }

        function chooseCourier(key) {
            var info = couriersInfo[key] || {};
            courier = key;
            courierLabel = courierName(key, info.name);
            courierNameEl.textContent = courierLabel;
            hide(step1);
            show(step2);
            hide(errorEl);

            var d = info.defaults || {};
            if (fillSelect(serviceEl, info.service_types, null, 'Choose a schedule…')) show(serviceWrap); else hide(serviceWrap);
            if (fillSelect(parcelEl, info.parcel_options, null, 'Choose a parcel size…')) show(parcelWrap); else hide(parcelWrap);
            if (fillSelect(paymentEl, info.shipping_payment_options, d.shipping_payment)) show(paymentWrap); else hide(paymentWrap);

            loadAddresses();
            loadSlots();
            quote();
        }

        function loadAddresses() {
            if (addresses !== null) { applyAddresses(); return; }
            getJson(urls.addresses)
                .then(function (data) {
                    addresses = (data && data.ok && Array.isArray(data.addresses)) ? data.addresses : [];
                    applyAddresses();
                })
                .catch(function () { addresses = []; applyAddresses(); });
        }

        function applyAddresses() {
            if (!addresses || addresses.length === 0) { hide(addressWrap); return; }
            var def = null;
            addresses.forEach(function (a) { if (a.is_default) def = a.id; });
            fillSelect(addressEl, addresses.map(function (a) {
                return { value: a.id, label: a.summary ? a.label + ' · ' + a.summary : a.label };
            }), def !== null ? def : addresses[0].id);
            show(addressWrap);
        }

        function loadSlots() {
            hide(slotWrap);
            clear(slotEl);
            slotEl.appendChild(new Option('Drop off myself', ''));

            getJson(urls.slots + (urls.slots.indexOf('?') === -1 ? '?' : '&') + 'courier=' + encodeURIComponent(courier))
                .then(function (data) {
                    var slots = (data && data.ok && Array.isArray(data.slots)) ? data.slots : [];
                    if (slots.length === 0) return;
                    clear(slotEl);
                    var ph = new Option('Choose a pickup…', UNCHOSEN_SLOT);
                    ph.selected = true;
                    slotEl.appendChild(ph);
                    slotEl.appendChild(new Option('Drop off myself', ''));
                    slots.forEach(function (s) { slotEl.appendChild(new Option(s.label, s.value)); });
                    show(slotWrap);
                })
                .catch(function () { });
        }

        var UNCHOSEN_SLOT = '__choose__';

        function unanswered() {
            var missing = [];
            if (!serviceWrap.classList.contains('vt-hidden') && serviceEl.value === '') missing.push({ el: serviceEl, name: 'the schedule' });
            if (!parcelWrap.classList.contains('vt-hidden') && parcelEl.value === '') missing.push({ el: parcelEl, name: 'the parcel size' });
            if (!slotWrap.classList.contains('vt-hidden') && slotEl.value === UNCHOSEN_SLOT) missing.push({ el: slotEl, name: 'the pickup' });
            return missing;
        }

        function options() {
            var opts = { courier: courier };
            if (!addressWrap.classList.contains('vt-hidden') && addressEl.value) opts.pickup_address_id = addressEl.value;
            if (!serviceWrap.classList.contains('vt-hidden') && serviceEl.value) opts.service = serviceEl.value;
            if (!parcelWrap.classList.contains('vt-hidden') && parcelEl.value) opts.parcel = parcelEl.value;
            if (!paymentWrap.classList.contains('vt-hidden') && paymentEl.value) opts.shipping_payment = paymentEl.value;
            if (slotEl.value && slotEl.value !== UNCHOSEN_SLOT) opts.pickup_slot = slotEl.value;
            if (insureEl.checked) opts.insure = true;
            return opts;
        }

        function setQuote(q, message) {
            hide(quoteNote);
            if (!q) {
                quoteShipping.textContent = message || '';
                quoteTotal.textContent = '';
                hide(quoteInsuranceRow);
                return;
            }
            quoteShipping.textContent = money(q.amount, q.currency);
            if (q.insurance !== null && q.insurance !== undefined && Number(q.insurance) > 0 && insureEl.checked) {
                quoteInsurance.textContent = money(q.insurance, q.currency);
                show(quoteInsuranceRow);
                quoteTotal.textContent = money(q.total, q.currency);
            } else {
                hide(quoteInsuranceRow);
                quoteTotal.textContent = money(q.amount, q.currency);
            }
        }

        function quote() {
            if (quoteTimer) clearTimeout(quoteTimer);
            var missing = unanswered();
            if (missing.length > 0) {
                ++quoteSeq;
                setQuote(null, 'Choose ' + missing.map(function (m) { return m.name; }).join(' and ') + ' to see the price.');
                return;
            }
            quoteTimer = setTimeout(function () {
                var seq = ++quoteSeq;
                setQuote(null, 'Asking ' + courierLabel);

                postJson(urls.estimate, options())
                    .then(function (res) {
                        if (seq !== quoteSeq) return;
                        var d = res.data || {};
                        if (!d.ok) {
                            setQuote(null, '');
                            quoteNoteText.textContent = d.message || 'No quote yet.';
                            show(quoteNote);
                            return;
                        }
                        setQuote(d);
                    })
                    .catch(function () {
                        if (seq !== quoteSeq) return;
                        setQuote(null, '');
                        quoteNoteText.textContent = 'The quote did not come back.';
                        show(quoteNote);
                    });
            }, 250);
        }

        [addressEl, serviceEl, parcelEl, slotEl, paymentEl].forEach(function (el) {
            el.addEventListener('change', function () {
                el.classList.remove('vt-book__select--missing');
                quote();
            });
        });
        insureEl.addEventListener('change', quote);
        estimateBtn.addEventListener('click', quote);

        backBtn.addEventListener('click', function () {
            show(step1);
            hide(step2);
        });

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
            [serviceEl, parcelEl, slotEl].forEach(function (el) { el.classList.remove('vt-book__select--missing'); });
            var missing = unanswered();
            if (missing.length > 0) {
                missing.forEach(function (m) { m.el.classList.add('vt-book__select--missing'); });
                showError('Choose ' + missing.map(function (m) { return m.name; }).join(' and ') + ' first. Nothing was booked.');
                missing[0].el.focus();
                return;
            }

            confirmBtn.disabled = true;
            hide(errorEl);
            hideModal(modal);
            setOverlay('Booking with ' + courierLabel);

            postJson(urls.book, options())
                .then(function (res) {
                    var d = res.data || {};
                    if (!d.ok) {
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

        function loadManualCouriers() {
            if (manualCouriers !== null) return;
            getJson(urls.couriers)
                .then(function (data) {
                    clear(manualCourierEl);
                    var list = (data && data.ok && Array.isArray(data.couriers)) ? data.couriers : [];
                    manualCourierEl.appendChild(new Option(list.length ? 'Choose a courier' : 'No couriers set up on the store', ''));
                    list.forEach(function (c) {
                        var opt = new Option(c.name, String(c.id));
                        opt.setAttribute('data-name', c.name);
                        manualCourierEl.appendChild(opt);
                    });
                    manualCouriers = list;
                })
                .catch(function () {
                    clear(manualCourierEl);
                    manualCourierEl.appendChild(new Option('The store’s courier list could not be read', ''));
                });
        }

        function openManual() {
            hide(step1);
            hide(step2);
            show(manualPane);
            hide(manualErrorEl);
            loadManualCouriers();
        }

        manualBackBtn.addEventListener('click', function () {
            hide(manualPane);
            show(step1);
        });

        manualConfirmBtn.addEventListener('click', function () {
            var selected = manualCourierEl.options[manualCourierEl.selectedIndex];
            var body = {};
            if (manualCourierEl.value) {
                body.shipping_courier_id = manualCourierEl.value;
                body.courier_name = selected ? selected.getAttribute('data-name') : '';
            }
            if (manualTrackingEl.value.trim()) body.tracking_number = manualTrackingEl.value.trim();
            if (manualCommentEl.value.trim()) body.comment = manualCommentEl.value.trim();

            manualConfirmBtn.disabled = true;
            hide(manualErrorEl);
            hideModal(modal);
            setOverlay('Recording the shipment');

            postJson(urls.bookManual, body)
                .then(function (res) {
                    var d = res.data || {};
                    if (!d.ok) {
                        hideModal(overlay);
                        manualConfirmBtn.disabled = false;
                        manualErrorEl.textContent = d.message || 'The store could not record this shipment.';
                        show(manualErrorEl);
                        showModal(modal);
                        return;
                    }
                    location.reload();
                })
                .catch(function () {
                    hideModal(overlay);
                    manualConfirmBtn.disabled = false;
                    manualErrorEl.textContent = 'The request failed before the store answered.';
                    show(manualErrorEl);
                    showModal(modal);
                });
        });

        document.addEventListener('click', function (e) {
            var btn = e.target.closest ? e.target.closest('.btnVentaBook') : null;
            if (!btn) return;
            e.preventDefault();

            urls = {
                serviceability: btn.getAttribute('data-serviceability-url'),
                slots: btn.getAttribute('data-slots-url'),
                estimate: btn.getAttribute('data-estimate-url'),
                book: btn.getAttribute('data-book-url'),
                couriers: btn.getAttribute('data-couriers-url'),
                addresses: btn.getAttribute('data-addresses-url'),
                bookManual: btn.getAttribute('data-book-manual-url')
            };
            orderNoEl.textContent = btn.getAttribute('data-order-no') || '';

            reset();
            fillParcel(document.getElementById('vtBookParcelSummary'), btn.getAttribute('data-order-id'), 'vt-hidden');
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
                    couriersInfo = data.couriers || {};
                    renderCouriers(couriersInfo, !!data.all_refused);
                })
                .catch(function () {
                    hide(couriersLoading);
                    show(couriersEl);
                    clear(couriersEl);
                    couriersEl.appendChild(textNode('div', 'vt-book__error', 'The storefront could not be reached.'));
                });
        });
    }

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
            var linkKeys = Object.keys(links).filter(function (k) {
                return /^https?:\/\//i.test(String(links[k] || ''));
            });
            if (linkKeys.length > 0) {
                var p = document.createElement('p');
                p.className = 'vt-track__links';
                linkKeys.forEach(function (k) {
                    var a = document.createElement('a');
                    a.href = String(links[k]);
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
