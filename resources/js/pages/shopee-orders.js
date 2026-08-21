/**
 * Shopee Orders, Shopee Returns and Shopee order detail
 * (extensions/shopee/views/orders/index.blade.php,
 * extensions/shopee/views/orders/returns.blade.php and
 * extensions/shopee/views/orders/show.blade.php).
 *
 * This used to be a ~400 line inline <script> block at the bottom of the
 * Orders Blade file. It is moved here as part of that page's exit from the
 * .x-legacy boundary: a new-UX page owns no inline script, same as it owns
 * no inline style. Bundled into the shared app.js (every layout loads it),
 * guarded on #shopee-orders-page and, separately, on #shopee-returns-page,
 * so it is inert everywhere else - the same convention orders-filter.js and
 * confirm-modal.js already use for their own root elements. Returns never
 * had an inline script of its own (it had no page-level JS at all before
 * Task 3); it is guarded here rather than sharing the Orders page's root id
 * because the two are separate documents with different DOM contents, and
 * "only bind what a page actually has" is the same rule the individual
 * init functions already follow element by element.
 *
 * Behaviour is unchanged from the inline version: same endpoints, same POST
 * fields, same success/failure handling, same window.showFlashError /
 * showFlashSuccess calls (defined by legacy-flash.js, also bundled into
 * app.js so both are guaranteed present together).
 *
 * Two defects fixed while moving, both logged in the Task 2 report:
 *
 *   1. The address panel the ship modal built for the pickup/drop-off step
 *      assembled an HTML string from data returned by Shopee's own API
 *      (addresses, flags, branch lists) and wrote it straight into the DOM,
 *      including hardcoded hex colours baked into that string (#16a34a,
 *      #2563eb, #6b7280). Writing markup built from fetched data straight
 *      into the DOM is a stored-XSS surface if that API ever returns a
 *      value containing markup, and the hex values could not be reskinned
 *      for dark theme. Every one of those strings is now
 *      createElement/textContent construction, and the colours are CSS
 *      classes (.sp-ship-addr, .sp-ship-flag--*) built from the token set
 *      in shopee-orders.css. The tracking modal's event rows and the hover
 *      image preview also used to build one static <img> tag the same way;
 *      converted for consistency so no code path in this module writes
 *      markup into the DOM as a string.
 *
 *   2. #shipStep2 and #shipStep2Content carried an inline style="display:
 *      none" in the Blade markup because the old script revealed them with
 *      .style.display = '', which falls back to the stylesheet. Both are
 *      now toggled with the .sp-hidden class (added by Task 1's CSS,
 *      already used on #formShipOrder), on all four step elements
 *      (shipStep1, shipStep2, shipStep2Loading, shipStep2Content) so the
 *      show/hide mechanism is consistent across the whole flow rather than
 *      three of the four using inline styles and one using a class.
 *
 * The C1 lesson (this file's own docblock references it): a failure path
 * that does not re-enable the button it disabled strands the operator with
 * no way to retry. Every fetch().catch() and every `!data.ok` branch below
 * re-enables whatever button it disabled and hides whatever overlay it
 * showed before returning - checked one at a time against the original.
 */
document.addEventListener('DOMContentLoaded', function () {
    var page = document.getElementById('shopee-orders-page');
    var returnsPage = document.getElementById('shopee-returns-page');
    var detailPage = document.getElementById('shopee-order-detail-page');
    var returnDetail = document.getElementById('shopee-return-detail-page');

    if (page) {
        initFetchUpdateOverlay();
        initShipModal();
        initTrackingModal();
        initImagePreview();
    }

    // The order detail page (extensions/shopee/views/orders/show.blade.php)
    // is a third document with a third root id, not a section of the list.
    // It carries the same ship and tracking modals - one copy of that markup
    // lives in orders/_modals.blade.php and both pages include it - and its
    // Arrange shipment button is the same .btnArrangeShipment contract, so
    // both flows are the module's existing ones rather than a second
    // implementation. It has no fetch/update command bar, so the overlay
    // initialiser that binds those two buttons is not called here; the
    // overlay ELEMENT is still present, and initShipModal() uses it for the
    // "Shipping order" and "Generating AWB" states exactly as on the list.
    //
    // initImagePreview() is deliberately NOT called: the detail page's
    // thumbnails are already the 96px the preview zooms a list row's up to,
    // and the shared detail anatomy states that decision in CSS
    // (.od-page .order-img-wrap { cursor: default }). What it does need is
    // the broken-image handling the old page had as an inline onerror="".
    if (returnDetail) {
        initReturnSolutions(returnDetail);
    }

    if (detailPage) {
        initShipModal();
        initTrackingModal();
        initBrokenImages(detailPage);
    }

    // Returns (extensions/shopee/views/orders/returns.blade.php) is its own
    // page, not a section of the orders page, so it gets its own root id
    // and only wires up what it actually has: the fetch overlay and the
    // item-thumbnail hover preview. It has no ship modal and no tracking
    // modal, so initShipModal()/initTrackingModal() are not called here -
    // both already no-op safely on a page missing their elements, but
    // calling only what a page uses is what "guarded so it only binds on
    // the returns page root id" means in practice.
    if (returnsPage) {
        initFetchReturnsOverlay();
        initImagePreview();
    }

    /* ------------------------------------------------------------------
       Loading overlay on Fetch / Update (Orders page).
       ------------------------------------------------------------------ */
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
                // A submit that will not happen must not raise the overlay: the
                // confirmation dialog preventDefaults this same event from a
                // capture-phase listener, and Cancel never takes an overlay down.
                // See the note above the submit handler in confirm-modal.js.
                if (e.defaultPrevented) return;

                // Require From/To dates.
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

    /* ------------------------------------------------------------------
       Loading overlay on Fetch returns (Returns page). Same #spLoadingOverlay
       / #spLoadingTitle contract as the Orders page's own overlay, just a
       separate copy of the modal markup because the two pages are separate
       documents.
       ------------------------------------------------------------------ */
    function initFetchReturnsOverlay() {
        var overlay = document.getElementById('spLoadingOverlay');
        var fetchReturnsForm = document.getElementById('formFetchReturns');
        if (!fetchReturnsForm || !overlay) return;

        fetchReturnsForm.addEventListener('submit', function (e) {
            // A submit that will not happen must not raise the overlay: the
            // confirmation dialog preventDefaults this same event from a
            // capture-phase listener, and Cancel never takes an overlay down.
            // See the note above the submit handler in confirm-modal.js.
            if (e.defaultPrevented) return;

            document.getElementById('spLoadingTitle').textContent = 'Fetching returns…';
            overlay.style.display = 'flex';
        });
    }

    /* ------------------------------------------------------------------
       Ship method modal: choice -> address/branch fetch -> confirm.
       ------------------------------------------------------------------ */
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

        // Replaces every one-line "write a <div class=...>text</div>
        // string into the container" call from the inline script: a single
        // text node wrapped in the same class it used to carry.
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

        // Pickup: one auto-selected address plus its flags, rendered as
        // tone-coded chips (Shopee's own address flags -
        // pickup_address / return_address / anything else).
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

        // Drop-off: a list of branches, first one pre-selected, same as the
        // original `checked = i === 0`.
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

        // The three Shopee order URLs below are hardcoded paths, not values
        // handed down from a route() call, so they do NOT follow the routes
        // file when it moves. They were updated with it in the IA migration's
        // Task 2 (2026-08-08) and must be updated with it again next time:
        // the ship POST in particular cannot be rescued by a 301, which drops
        // the request body. Worth lifting onto data-attributes when this file
        // is next touched for its own reasons.
        document.querySelectorAll('.btnArrangeShipment').forEach(function (el) {
            el.addEventListener('click', function () {
                currentShipOrderSn = el.getAttribute('data-order-sn');
                shipOrderSnEl.textContent = currentShipOrderSn;
                shipForm.action = '/integrations/shopee/orders/' + encodeURIComponent(currentShipOrderSn) + '/ship';
                shipResetModal();
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

                fetch('/integrations/shopee/orders/' + encodeURIComponent(currentShipOrderSn) + '/shipping-addresses', {
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

                    // Listen for radio changes.
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

                // Ship succeeded - now poll for the AWB.
                if (overlay) {
                    document.getElementById('spLoadingTitle').textContent = 'Generating AWB…';
                }

                var awbUrl = '/integrations/shopee/orders/' + encodeURIComponent(currentShipOrderSn) + '/awb';
                var awbAttempts = 0;
                var maxAttempts = 20;

                function pollAwb() {
                    awbAttempts++;
                    fetch(awbUrl, { headers: { 'Accept': 'application/json' } })
                    .then(function (r) { return r.json(); })
                    .then(function (awbData) {
                        if (awbData.ok && awbData.ready) {
                            // AWB ready - open in a new tab and reload the page.
                            if (overlay) overlay.style.display = 'none';
                            window.open(awbUrl, '_blank');
                            location.reload();
                        } else if (awbAttempts < maxAttempts) {
                            setTimeout(pollAwb, 3000);
                        } else {
                            // Timed out - let the operator know and reload.
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

                // Start polling after a short initial delay.
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

    /* ------------------------------------------------------------------
       What Shopee will accept on this return.

       Asked on demand, never on load: it is a live marketplace call and the
       answer is only wanted once someone has decided to act on this return.

       Fails closed. A solution is drawn as available only when the server
       says eligibility is true, and any refusal is printed as the reason
       rather than as an empty list - "we did not get to ask" and "there is
       nothing to offer" are different facts and must not share a rendering.
       ------------------------------------------------------------------ */
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

    /* ------------------------------------------------------------------
       Tracking modal.
       ------------------------------------------------------------------ */

    // Shopee's tracking events carry a Unix timestamp (seconds or
    // milliseconds) in `update_time`/`ctime`, or occasionally a string
    // timestamp. Accept all three; fall back to the raw value as text
    // rather than rendering a blank cell.
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

                    // Newest first, same as the events Shopee itself returns
                    // newest-last. Some events carry only `ctime`, never
                    // `update_time`, so both are checked.
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

    /* ------------------------------------------------------------------
       A thumbnail whose image URL no longer resolves. Shopee's CDN links
       expire on old orders, and the old detail page handled this with an
       inline onerror="this.style.display='none'" attribute on every <img>.
       Capture phase, because `error` does not bubble. Same shape as
       order-detail.js's own handler.
       ------------------------------------------------------------------ */
    function initBrokenImages(root) {
        root.addEventListener('error', function (e) {
            var img = e.target;
            if (!img || img.tagName !== 'IMG' || !img.classList.contains('order-img')) return;
            img.hidden = true;
        }, true);
    }

    /* ------------------------------------------------------------------
       Thumbnail hover preview (order-img-wrap / img.order-img).
       ------------------------------------------------------------------ */
    function initImagePreview() {
        var preview = document.createElement('div');
        preview.className = 'order-img-preview';
        var previewImg = document.createElement('img');
        previewImg.src = '';
        previewImg.alt = '';
        preview.appendChild(previewImg);
        document.body.appendChild(preview);

        document.addEventListener('mouseover', function (e) {
            var wrap = e.target.closest('.order-img-wrap');
            if (!wrap) return;
            var img = wrap.querySelector('img.order-img');
            if (!img || !img.src) return;
            previewImg.src = img.src;
            var rect = wrap.getBoundingClientRect();
            var top = rect.top + rect.height / 2 - 110;
            var left = rect.right + 12;
            if (left + 230 > window.innerWidth) left = rect.left - 232;
            if (top + 220 > window.innerHeight) top = window.innerHeight - 224;
            if (top < 4) top = 4;
            preview.style.transformOrigin = (left < rect.left ? 'right' : 'left') + ' center';
            preview.style.top = top + 'px';
            preview.style.left = left + 'px';
            preview.classList.add('visible');
        }, false);

        document.addEventListener('mouseout', function (e) {
            var wrap = e.target.closest('.order-img-wrap');
            if (!wrap) return;
            var related = e.relatedTarget;
            if (related && wrap.contains(related)) return;
            preview.classList.remove('visible');
        }, false);
    }
});
