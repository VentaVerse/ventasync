{{-- Venta order modals: book a courier, tracking, and the loading overlay.

     One copy of the markup, included by orders/_panel.blade.php, which both
     the seller centre and the fulfilment hub render. Every id below is
     queried by resources/js/pages/venta-orders.js and must not change.

     The book modal is Shopee's ship modal in shape: step one asks a
     question the packer answers with one press (who will carry it), step
     two holds the few options a booking can take, with defaults that let
     the packer accept and go. Step two starts hidden via .vt-hidden, the
     same toggle the module uses for every step transition. --}}
@if($canManageVentaOrders)
<div id="vtBookModal" class="modal-backdrop">
    <div class="modal co-modal">
        <div class="modal-header co-modal__head">
            <h3 class="co-modal__title">Book a courier</h3>
            <button type="button" id="btnCloseVtBook" class="modal-close co-modal__close" aria-label="Close">&times;</button>
        </div>
        <div class="co-modal__meta">Order <strong id="vtBookOrderNo"></strong></div>

        {{-- The parcel: filled by the module from the row's own template
             (components/fulfilment/parcel) when the dialog opens. --}}
        <div id="vtBookParcel" class="vt-hidden"></div>

        {{-- Step 1: who can carry it. Filled by the module from the
             storefront's serviceability answer, one button per courier. --}}
        <div id="vtBookStep1">
            <p class="co-modal__prompt">Who will carry this parcel?</p>
            <div id="vtBookCouriersLoading" class="co-modal__loading">
                <span class="co-spinner" aria-hidden="true"></span>
                <span>Asking the couriers</span>
            </div>
            <div id="vtBookCouriers" class="co-modal__choices vt-hidden"></div>
            {{-- The third way a parcel leaves: a courier the store does not
                 integrate with, recorded by hand. Always offered, under the
                 list, because it is the answer when every integrated
                 courier refused or when a rider is simply at the door. --}}
            <div class="vt-book__manual-offer">
                <button type="button" id="btnVtManual" class="x-btn x-btn--ghost x-btn--sm">Another courier, by hand</button>
            </div>
        </div>

        {{-- Step 2, by hand: a name off the store's list and a number. --}}
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

        {{-- Step 2: the booking's options, in the storefront's own order
             (its queue's modal): where it leaves from, then what carries
             it and in what, then insurance, then who pays. No dimensions:
             the parcel size is a choice off the courier's list and the
             courier prices it. The quote is fetched the moment the courier
             is chosen and again on every change, through the storefront,
             which asks the courier; it is never typed in here. --}}
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
                        <label for="vtBookService" class="vt-book__label">Service</label>
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

                {{-- The courier's quote for exactly what is chosen above,
                     as the storefront's own modal shows it. --}}
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

{{-- Tracking: the courier's timeline for a booked parcel, as the storefront
     reports it. Same shape as TikTok's tracking modal. --}}
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

{{-- Loading overlay. Raised by the module while a booking runs, and by
     resources/js/busy-overlay.js for the confirmed form submits on this
     page (Fetch orders, Cancel booking), through the data-busy hooks. --}}
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
