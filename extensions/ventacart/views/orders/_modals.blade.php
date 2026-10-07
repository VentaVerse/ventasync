@if($canManageVentaOrders)
<div id="vtBookModal" class="modal-backdrop">
    <div class="modal co-modal">
        <div class="modal-header co-modal__head">
            <h3 class="co-modal__title">Book a courier</h3>
            <button type="button" id="btnCloseVtBook" class="modal-close co-modal__close" aria-label="Close">&times;</button>
        </div>
        <div class="co-modal__meta">Order <strong id="vtBookOrderNo"></strong></div>

        <div id="vtBookParcelSummary" class="vt-hidden"></div>

        <div id="vtBookStep1">
            <p class="co-modal__prompt">Who will carry this parcel?</p>
            <div id="vtBookCouriersLoading" class="co-modal__loading">
                <span class="co-spinner" aria-hidden="true"></span>
                <span>Asking the couriers</span>
            </div>
            <div id="vtBookCouriers" class="co-modal__choices vt-hidden"></div>
        </div>

        <div id="vtBookManual" class="vt-hidden">
            <div class="co-modal__meta vt-book__courier">Recorded by hand, no waybill</div>
            <div class="co-modal__body">
                <div class="vt-book__field">
                    <label for="vtManualCourier" class="vt-book__label">Courier</label>
                    <div class="x-select-wrap">
                        <select id="vtManualCourier" class="x-input">
                            <option value="">Loading the store's couriers</option>
                        </select>
                        <x-ui.icon name="chevron-down" size="14" class="x-select-wrap__chevron" />
                    </div>
                </div>
                <div class="vt-book__grid vt-book__grid--2">
                    <div class="vt-book__field">
                        <label for="vtManualTracking" class="vt-book__label">Tracking number</label>
                        <input type="text" id="vtManualTracking" class="x-input" maxlength="190" placeholder="Optional">
                    </div>
                    <div class="vt-book__field">
                        <label for="vtManualComment" class="vt-book__label">Note</label>
                        <input type="text" id="vtManualComment" class="x-input" maxlength="500" placeholder="Optional, kept on the order">
                    </div>
                </div>
                <p id="vtManualError" class="vt-book__error vt-hidden" role="alert"></p>
            </div>
            <div class="co-modal__foot">
                <button type="button" id="btnVtManualBack" class="x-btn x-btn--ghost">Back</button>
                <button type="button" id="btnVtManualConfirm" class="x-btn x-btn--primary">Record the shipment</button>
            </div>
        </div>

        <div id="vtBookStep2" class="vt-hidden">
            <div class="co-modal__meta vt-book__courier">Courier <strong id="vtBookCourierName"></strong></div>
            <div class="co-modal__body">
                <div id="vtBookAddressWrap" class="vt-book__field vt-hidden">
                    <label for="vtBookAddress" class="vt-book__label">Ships from</label>
                    <div class="x-select-wrap">
                        <select id="vtBookAddress" class="x-input"></select>
                        <x-ui.icon name="chevron-down" size="14" class="x-select-wrap__chevron" />
                    </div>
                </div>

                <div class="vt-book__grid vt-book__grid--2">
                    <div id="vtBookServiceWrap" class="vt-book__field vt-hidden">
                        <label for="vtBookService" class="vt-book__label">Schedule</label>
                        <div class="x-select-wrap">
                            <select id="vtBookService" class="x-input"></select>
                            <x-ui.icon name="chevron-down" size="14" class="x-select-wrap__chevron" />
                        </div>
                    </div>
                    <div id="vtBookParcelWrap" class="vt-book__field vt-hidden">
                        <label for="vtBookParcel" class="vt-book__label">Parcel</label>
                        <div class="x-select-wrap">
                            <select id="vtBookParcel" class="x-input"></select>
                            <x-ui.icon name="chevron-down" size="14" class="x-select-wrap__chevron" />
                        </div>
                    </div>
                </div>

                <div id="vtBookSlotWrap" class="vt-book__field vt-hidden">
                    <label for="vtBookSlot" class="vt-book__label">Pickup</label>
                    <div class="x-select-wrap">
                        <select id="vtBookSlot" class="x-input">
                            <option value="">Drop off myself</option>
                        </select>
                        <x-ui.icon name="chevron-down" size="14" class="x-select-wrap__chevron" />
                    </div>
                </div>

                <label class="vt-book__check">
                    <input type="checkbox" id="vtBookInsure" value="1">
                    <span>Insure this parcel</span>
                </label>

                <div id="vtBookPaymentWrap" class="vt-book__field vt-hidden">
                    <label for="vtBookPayment" class="vt-book__label">You pay shipping</label>
                    <div class="x-select-wrap">
                        <select id="vtBookPayment" class="x-input"></select>
                        <x-ui.icon name="chevron-down" size="14" class="x-select-wrap__chevron" />
                    </div>
                </div>

                <dl id="vtBookQuote" class="vt-quote" aria-live="polite">
                    <div class="vt-quote__row"><dt>Shipping</dt><dd id="vtQuoteShipping">Asking</dd></div>
                    <div class="vt-quote__row vt-hidden" id="vtQuoteInsuranceRow"><dt>Insurance</dt><dd id="vtQuoteInsurance"></dd></div>
                    <div class="vt-quote__row vt-quote__row--total"><dt>Total</dt><dd id="vtQuoteTotal"></dd></div>
                </dl>
                <p id="vtBookQuoteNote" class="vt-book__quote-note vt-hidden">
                    <span id="vtBookQuoteNoteText"></span>
                    <button type="button" id="btnVtEstimate" class="x-btn x-btn--ghost x-btn--sm">Ask again</button>
                </p>

                <p id="vtBookError" class="vt-book__error vt-hidden" role="alert"></p>
            </div>
            <div class="co-modal__foot">
                <button type="button" id="btnVtBookBack" class="x-btn x-btn--ghost">Back</button>
                <button type="button" id="btnVtBookConfirm" class="x-btn x-btn--primary">Book and print the waybill</button>
            </div>
        </div>
    </div>
</div>
@endif

<div id="vtTrackingModal" class="modal-backdrop">
    <div class="modal co-modal co-modal--md">
        <div class="modal-header co-modal__head">
            <h3 class="co-modal__title">Tracking</h3>
            <button type="button" id="btnCloseVtTracking" class="modal-close co-modal__close" aria-label="Close">&times;</button>
        </div>
        <div id="vtTrackingSub" class="co-modal__meta"></div>
        <div id="vtTrackingBody" class="co-modal__body">
            <div class="text-secondary">Loading</div>
        </div>
        <div class="co-modal__foot co-modal__foot--end">
            <button type="button" id="btnCloseVtTracking2" class="x-btn x-btn--secondary">Close</button>
        </div>
    </div>
</div>

<div id="vtLoadingOverlay" class="modal-backdrop" data-busy-overlay>
    <div class="modal co-modal co-modal--sm">
        <div class="co-progress">
            <span class="co-spinner co-spinner--lg" aria-hidden="true"></span>
            <div>
                <div id="vtLoadingTitle" class="co-progress__title" data-busy-title>Working</div>
                <div class="co-progress__note">Please keep this tab open.</div>
            </div>
        </div>
    </div>
</div>
