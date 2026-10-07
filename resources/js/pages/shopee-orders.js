document.addEventListener('DOMContentLoaded', function () {
    var page = document.getElementById('shopee-orders-page');
    var returnsPage = document.getElementById('shopee-returns-page');
    var detailPage = document.getElementById('shopee-order-detail-page');
    var returnDetail = document.getElementById('shopee-return-detail-page');

    if (page) {
        initFetchUpdateOverlay();
        initShipModal();
        initTrackingModal();
    }

    if (returnDetail) {
        initReturnSolutions(returnDetail);
    }

    if (detailPage) {
        initShipModal();
        initTrackingModal();
        initBrokenImages(detailPage);
    }

    if (returnsPage) {
        initFetchReturnsOverlay();
    }

    function initFetchUpdateOverlay() {
        var overlay = document.getElementById('spLoadingOverlay');
        var fetchForm = document.getElementById('formFetchShopeeOrders');

        var clickedBtn = null;
        var fetchBtn = document.getElementById('btnFetchOrders');
        var updateBtn = document.getElementById('btnUpdateOrders');

        if (fetchBtn) fetchBtn.addEventListener('click', function () { clickedBtn = 'fetch'; });
        if (updateBtn) updateBtn.addEventListener('click', function () { clickedBtn = 'update'; });

        if (fetchForm) {
            fetchForm.addEventListener('submit', function (e) {
                if (e.defaultPrevented) return;

                var dateFrom = fetchForm.querySelector('input[name="date_from"]');
                var dateTo = fetchForm.querySelector('input[name="date_to"]');
                if (!dateFrom.value || !dateTo.value) {
                    e.preventDefault();
                    window.showFlashError('Please provide both From and To dates.');
                    return;
                }
                if (overlay) {
                    document.getElementById('spLoadingTitle').textContent = (clickedBtn === 'update') ? 'Updating orders…' : 'Fetching orders…';
                    overlay.style.display = 'flex';
                }
            });
        }
    }

    function initFetchReturnsOverlay() {
        var overlay = document.getElementById('spLoadingOverlay');
        var fetchReturnsForm = document.getElementById('formFetchReturns');
        if (!fetchReturnsForm || !overlay) return;

        fetchReturnsForm.addEventListener('submit', function (e) {
            if (e.defaultPrevented) return;

            document.getElementById('spLoadingTitle').textContent = 'Fetching returns…';
            overlay.style.display = 'flex';
        });
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

    function initShipModal() {
        var overlay = document.getElementById('spLoadingOverlay');
        var shipModal = document.getElementById('spShipModal');
        var shipOrderSnEl = document.getElementById('spShipOrderSn');
        var shipForm = document.getElementById('formShipOrder');
        var shipTypeInput = document.getElementById('shipTypeInput');
        var shipAddressInput = document.getElementById('shipAddressInput');
        var shipBranchInput = document.getElementById('shipBranchInput');
        var shipStep1 = document.getElementById('shipStep1');
        var shipStep2 = document.getElementById('shipStep2');
        var shipStep2Loading = document.getElementById('shipStep2Loading');
        var shipStep2Content = document.getElementById('shipStep2Content');
        var btnShipConfirm = document.getElementById('btnShipConfirm');
        var btnShipBack = document.getElementById('btnShipBack');
        if (!shipModal) return;

        var currentShipOrderSn = '';
        var currentShipType = '';

        function hide(el) { el.classList.add('sp-hidden'); }
        function show(el) { el.classList.remove('sp-hidden'); }

        function clearContent() {
            while (shipStep2Content.firstChild) {
                shipStep2Content.removeChild(shipStep2Content.firstChild);
            }
        }

        function setContentMessage(text, className) {
            clearContent();
            var div = document.createElement('div');
            div.className = className;
            div.textContent = text;
            shipStep2Content.appendChild(div);
        }

        function shipResetModal() {
            show(shipStep1);
            hide(shipStep2);
            show(shipStep2Loading);
            hide(shipStep2Content);
            clearContent();
            shipAddressInput.value = '';
            shipBranchInput.value = '';
            btnShipConfirm.disabled = true;
            currentShipType = '';
        }

        function renderPickupAddress(addresses) {
            if (addresses.length === 0) {
                setContentMessage('No pickup addresses available.', 'text-secondary');
                return;
            }

            var autoAddr = null;
            addresses.forEach(function (addr) {
                if (!autoAddr && (addr.address_flag || []).indexOf('pickup_address') !== -1) {
                    autoAddr = addr;
                }
            });
            if (!autoAddr) autoAddr = addresses[0];

            shipAddressInput.value = autoAddr.address_id || 0;
            var addrParts = [autoAddr.address, autoAddr.city, autoAddr.state, autoAddr.district]
                .filter(function (p) { return p && p !== ''; });
            var flags = autoAddr.address_flag || [];

            clearContent();

            var label = document.createElement('div');
            label.className = 'sp-ship-addr-label';
            label.textContent = 'Pickup address (auto-selected):';
            shipStep2Content.appendChild(label);

            var box = document.createElement('div');
            box.className = 'sp-ship-addr';

            var text = document.createElement('div');
            text.className = 'text-sm';
            text.textContent = addrParts.join(', ');
            box.appendChild(text);

            if (flags.length > 0) {
                var flagsWrap = document.createElement('div');
                flagsWrap.className = 'sp-ship-addr__flags';
                flags.forEach(function (f) {
                    var tone = f === 'pickup_address' ? 'pickup' : (f === 'return_address' ? 'return' : 'other');
                    var chip = document.createElement('span');
                    chip.className = 'sp-ship-flag sp-ship-flag--' + tone;
                    chip.textContent = f.replace(/_/g, ' ');
                    flagsWrap.appendChild(chip);
                });
                box.appendChild(flagsWrap);
            }

            shipStep2Content.appendChild(box);
            btnShipConfirm.disabled = false;
        }

        function renderDropoffBranches(branches) {
            if (branches.length === 0) {
                setContentMessage('No drop-off branches available. The order will be shipped without branch selection.', 'text-secondary');
                btnShipConfirm.disabled = false;
                return;
            }

            clearContent();

            var label = document.createElement('div');
            label.className = 'sp-ship-addr-label';
            label.textContent = 'Select drop-off branch:';
            shipStep2Content.appendChild(label);

            branches.forEach(function (branch, i) {
                var addrParts = [branch.address, branch.city, branch.state, branch.district]
                    .filter(function (p) { return p && p !== ''; });

                var row = document.createElement('label');
                row.className = 'sp-ship-branch';

                var body = document.createElement('div');
                body.className = 'd-flex items-start gap-10';

                var radio = document.createElement('input');
                radio.type = 'radio';
                radio.name = 'ship_branch';
                radio.value = branch.branch_id || 0;
                radio.className = 'sp-ship-branch__radio';
                if (i === 0) radio.checked = true;

                var textWrap = document.createElement('div');
                textWrap.className = 'sp-ship-branch__text';
                var text = document.createElement('div');
                text.className = 'text-sm';
                text.textContent = addrParts.join(', ');
                textWrap.appendChild(text);

                body.appendChild(radio);
                body.appendChild(textWrap);
                row.appendChild(body);
                shipStep2Content.appendChild(row);

                if (i === 0) shipBranchInput.value = branch.branch_id || 0;
            });

            btnShipConfirm.disabled = false;
        }

        var ordersBase = (shipForm && shipForm.getAttribute('data-orders-base')) || '/channels/shopee/orders';
        document.querySelectorAll('.btnArrangeShipment').forEach(function (el) {
            el.addEventListener('click', function () {
                currentShipOrderSn = el.getAttribute('data-order-sn');
                shipOrderSnEl.textContent = currentShipOrderSn;
                shipForm.action = ordersBase + '/' + encodeURIComponent(currentShipOrderSn) + '/ship';
                shipResetModal();
                fillParcel(document.getElementById('spShipParcel'), currentShipOrderSn, 'sp-hidden');
                shipModal.style.display = 'flex';
            });
        });

        document.querySelectorAll('.btnShipChoice').forEach(function (el) {
            el.addEventListener('click', function () {
                currentShipType = el.getAttribute('data-type');
                shipTypeInput.value = currentShipType;
                hide(shipStep1);
                show(shipStep2);
                show(shipStep2Loading);
                hide(shipStep2Content);

                fetch(ordersBase + '/' + encodeURIComponent(currentShipOrderSn) + '/shipping-addresses', {
                    headers: { 'Accept': 'application/json' }
                })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    hide(shipStep2Loading);
                    show(shipStep2Content);

                    if (!data.ok) {
                        setContentMessage(data.message || 'Failed to load addresses.', 'alert danger');
                        return;
                    }

                    if (currentShipType === 'pickup') {
                        var addresses = (data.pickup && data.pickup.address_list) ? data.pickup.address_list : [];
                        renderPickupAddress(addresses);
                    } else {
                        var branches = (data.dropoff && data.dropoff.branch_list) ? data.dropoff.branch_list : [];
                        renderDropoffBranches(branches);
                    }

                    shipStep2Content.querySelectorAll('input[type=radio]').forEach(function (radio) {
                        radio.addEventListener('change', function () {
                            if (currentShipType === 'pickup') {
                                shipAddressInput.value = radio.value;
                            } else {
                                shipBranchInput.value = radio.value;
                            }
                        });
                    });
                })
                .catch(function () {
                    hide(shipStep2Loading);
                    show(shipStep2Content);
                    setContentMessage('Network error loading addresses.', 'alert danger');
                });
            });
        });

        btnShipBack.addEventListener('click', function () {
            show(shipStep1);
            hide(shipStep2);
        });

        btnShipConfirm.addEventListener('click', function () {
            btnShipConfirm.disabled = true;
            shipModal.style.display = 'none';

            if (overlay) {
                overlay.style.display = 'flex';
                document.getElementById('spLoadingTitle').textContent = 'Shipping order…';
            }

            var csrfToken = document.querySelector('meta[name="csrf-token"]');
            if (!csrfToken) csrfToken = shipForm.querySelector('input[name="_token"]');
            var token = csrfToken ? (csrfToken.content || csrfToken.value) : '';

            var formData = new FormData(shipForm);

            fetch(shipForm.action, {
                method: 'POST',
                headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': token },
                body: formData
            })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data.ok) {
                    if (overlay) overlay.style.display = 'none';
                    window.showFlashError('Ship failed: ' + (data.message || 'Unknown error'));
                    btnShipConfirm.disabled = false;
                    return;
                }

                if (overlay) {
                    document.getElementById('spLoadingTitle').textContent = 'Generating AWB…';
                }

                var awbUrl = ordersBase + '/' + encodeURIComponent(currentShipOrderSn) + '/awb';
                var awbAttempts = 0;
                var maxAttempts = 20;

                function pollAwb() {
                    awbAttempts++;
                    fetch(awbUrl, { headers: { 'Accept': 'application/json' } })
                    .then(function (r) { return r.json(); })
                    .then(function (awbData) {
                        if (awbData.ok && awbData.ready) {
                            if (overlay) overlay.style.display = 'none';
                            window.open(awbUrl, '_blank');
                            location.reload();
                        } else if (awbAttempts < maxAttempts) {
                            setTimeout(pollAwb, 3000);
                        } else {
                            if (overlay) overlay.style.display = 'none';
                            window.showFlashError('Order shipped successfully but AWB is still being generated by Shopee. Use the Print AWB button to try again.');
                            location.reload();
                        }
                    })
                    .catch(function () {
                        if (awbAttempts < maxAttempts) {
                            setTimeout(pollAwb, 3000);
                        } else {
                            if (overlay) overlay.style.display = 'none';
                            window.showFlashError('Order shipped successfully but could not generate AWB. Use the Print AWB button to try again.');
                            location.reload();
                        }
                    });
                }

                setTimeout(pollAwb, 2000);
            })
            .catch(function () {
                if (overlay) overlay.style.display = 'none';
                window.showFlashError('Network error while shipping order.');
                btnShipConfirm.disabled = false;
            });
        });

        var closeShip = function () { shipModal.style.display = 'none'; };
        var btnCloseShip = document.getElementById('btnCloseShip');
        if (btnCloseShip) btnCloseShip.addEventListener('click', closeShip);
        shipModal.addEventListener('click', function (e) { if (e.target === shipModal) closeShip(); });
    }

    // Fails closed: offer a solution only when the server says eligibility is true.
    function initReturnSolutions(root) {
        var btn = root.querySelector('[data-solutions-ask]');
        var out = root.querySelector('[data-solutions-out]');
        var url = root.getAttribute('data-solutions-url');
        if (!btn || !out || !url) return;

        var esc = function (s) {
            return String(s === null || s === undefined ? '' : s).replace(/[&<>"]/g, function (c) {
                return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c];
            });
        };

        var money = function (n) {
            if (n === null || n === undefined) return null;
            return Number(n).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        };

        var note = function (text, tone) {
            out.innerHTML = '<p class="rs__note rs__note--' + tone + '">' + esc(text) + '</p>';
        };

        btn.addEventListener('click', function () {
            btn.disabled = true;
            note('Asking Shopee\u2026', 'wait');

            fetch(url, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
                .then(function (r) { return r.ok ? r.json() : null; })
                .then(function (data) {
                    btn.disabled = false;
                    if (!data) { note('The request did not complete. Nothing is offered here until it does.', 'bad'); return; }
                    if (!data.ok) { note(data.message || 'Shopee did not answer.', 'bad'); return; }

                    var open = (data.solutions || []).filter(function (s) { return s.eligible; });
                    if (!open.length) {
                        note('Shopee is not offering either resolution on this return right now.', 'none');
                        return;
                    }

                    out.innerHTML = open.map(function (s) {
                        var cap = s.adjustable
                            ? (s.max_refund_amount !== null
                                ? 'You can counter with a different amount, up to ' + esc(money(s.max_refund_amount)) + '.'
                                : 'You can counter with a different amount.')
                            : 'The amount is fixed - it cannot be countered.';
                        return '<div class="rs__opt"><b>' + esc(s.label) + '</b>' +
                               '<span>' + esc(s.blurb) + '</span>' +
                               '<em>' + cap + '</em></div>';
                    }).join('') +
                    '<p class="rs__note rs__note--wait">Offer it in Shopee Seller Centre. ' +
                    'This panel reports what is permitted; it does not submit.</p>';
                })
                .catch(function () {
                    btn.disabled = false;
                    note('The request failed. Nothing is offered here until it succeeds.', 'bad');
                });
        });
    }

    // Shopee event times are Unix seconds or milliseconds, or a string; accept all three.
    function formatTrackTime(ts) {
        if (!ts) return '';
        var dt = typeof ts === 'number' ? new Date(ts > 1e12 ? ts : ts * 1000) : new Date(ts);
        if (isNaN(dt.getTime())) return String(ts);
        return dt.getFullYear() + '-' + String(dt.getMonth() + 1).padStart(2, '0') + '-' + String(dt.getDate()).padStart(2, '0') + ' ' + String(dt.getHours()).padStart(2, '0') + ':' + String(dt.getMinutes()).padStart(2, '0');
    }

    function initTrackingModal() {
        var trackingModal = document.getElementById('spTrackingModal');
        var trackingBody = document.getElementById('spTrackingBody');
        if (!trackingModal) return;

        var closeTracking = function () { trackingModal.classList.remove('active'); };
        var btnCloseTracking = document.getElementById('btnCloseTracking');
        var btnTrackingClose2 = document.getElementById('btnTrackingClose2');
        if (btnCloseTracking) btnCloseTracking.addEventListener('click', closeTracking);
        if (btnTrackingClose2) btnTrackingClose2.addEventListener('click', closeTracking);
        trackingModal.addEventListener('click', function (e) { if (e.target === trackingModal) closeTracking(); });

        document.addEventListener('click', function (e) {
            var btn = e.target.closest('.btnShopeeTracking');
            if (!btn) return;
            var url = btn.getAttribute('data-url');
            if (!url) return;
            trackingBody.textContent = 'Loading…';
            trackingModal.classList.add('active');

            fetch(url, { headers: { 'Accept': 'application/json' } })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    trackingBody.textContent = '';
                    if (!data.ok) {
                        var err = document.createElement('div');
                        err.className = 'text-danger';
                        err.textContent = data.message || 'Error loading tracking info.';
                        trackingBody.appendChild(err);
                        return;
                    }

                    if (data.tracking_number) {
                        var tnDiv = document.createElement('div');
                        tnDiv.className = 'mb-8';
                        var tnLabel = document.createElement('strong');
                        tnLabel.textContent = 'Tracking: ';
                        var tnValue = document.createElement('span');
                        tnValue.textContent = data.tracking_number;
                        tnDiv.appendChild(tnLabel);
                        tnDiv.appendChild(tnValue);
                        trackingBody.appendChild(tnDiv);
                    }
                    if (data.shipping_carrier) {
                        var scDiv = document.createElement('div');
                        scDiv.className = 'mb-8';
                        var scLabel = document.createElement('strong');
                        scLabel.textContent = 'Courier: ';
                        var scValue = document.createElement('span');
                        scValue.textContent = data.shipping_carrier;
                        scDiv.appendChild(scLabel);
                        scDiv.appendChild(scValue);
                        trackingBody.appendChild(scDiv);
                    }

                    var events = data.tracking_info || [];
                    if (!Array.isArray(events) || events.length === 0) {
                        if (!data.tracking_number) {
                            var empty = document.createElement('div');
                            empty.className = 'text-secondary';
                            empty.textContent = 'No tracking info available yet.';
                            trackingBody.appendChild(empty);
                        }
                        return;
                    }

                    events.sort(function (a, b) {
                        return (b.update_time || b.ctime || 0) - (a.update_time || a.ctime || 0);
                    });

                    if (data.logistics_status) {
                        var statusHeader = document.createElement('div');
                        statusHeader.className = 'text-xs text-secondary mb-8';
                        statusHeader.textContent = 'Logistics status: ' + data.logistics_status;
                        trackingBody.appendChild(statusHeader);
                    }

                    events.forEach(function (ev) {
                        var row = document.createElement('div');
                        row.className = 'sp-track-event';
                        var time = document.createElement('div');
                        time.className = 'text-xs text-secondary';
                        time.textContent = formatTrackTime(ev.update_time || ev.ctime);
                        var status = document.createElement('div');
                        status.className = 'text-xs font-bold';
                        status.textContent = ev.logistics_status || '';
                        var desc = document.createElement('div');
                        desc.className = 'text-sm';
                        desc.textContent = ev.description || '';
                        row.appendChild(time);
                        if (ev.logistics_status) row.appendChild(status);
                        row.appendChild(desc);
                        trackingBody.appendChild(row);
                    });
                })
                .catch(function () {
                    trackingBody.textContent = '';
                    var errEl = document.createElement('div');
                    errEl.className = 'text-danger';
                    errEl.textContent = 'Failed to load tracking info.';
                    trackingBody.appendChild(errEl);
                });
        });
    }

    function initBrokenImages(root) {
        root.addEventListener('error', function (e) {
            var img = e.target;
            if (!img || img.tagName !== 'IMG' || !img.classList.contains('order-img')) return;
            img.hidden = true;
        }, true);
    }
});
