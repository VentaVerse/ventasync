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

        {{-- Step 1: who can carry it. Filled by the module from the
             storefront's serviceability answer, one button per courier. --}}
        <div id="vtBookStep1">
            <p class="co-modal__prompt">Who will carry this parcel?</p>
            <div id="vtBookCouriersLoading" class="co-modal__loading">
                <span class="co-spinner" aria-hidden="true"></span>
                <span>Asking the couriers</span>
            </div>
            <div id="vtBookCouriers" class="co-modal__choices vt-hidden"></div>
        </div>

        {{-- Step 2: the booking's options. --}}
        <div id="vtBookStep2" class="vt-hidden">
            <div class="co-modal__meta vt-book__courier">Courier <strong id="vtBookCourierName"></strong></div>
            <div class="co-modal__body">
                <div id="vtBookSlotWrap" class="vt-book__field vt-hidden">
                    <label for="vtBookSlot" class="vt-book__label">Pickup</label>
                    <div class="x-select-wrap">
                        <select id="vtBookSlot" class="x-input">
                            <option value="">Drop off myself</option>
                        </select>
                        <x-ui.icon name="chevron-down" size="14" class="x-select-wrap__chevron" />
                    </div>
                </div>

                <div class="vt-book__grid">
                    <div class="vt-book__field">
                        <label for="vtBookWeight" class="vt-book__label">Weight, kg</label>
                        <input type="number" id="vtBookWeight" class="x-input" min="0.01" step="0.01" placeholder="Default">
                    </div>
                    <div class="vt-book__field">
                        <label for="vtBookLength" class="vt-book__label">Length, cm</label>
                        <input type="number" id="vtBookLength" class="x-input" min="0.01" step="0.1" placeholder="Default">
                    </div>
                    <div class="vt-book__field">
                        <label for="vtBookWidth" class="vt-book__label">Width, cm</label>
                        <input type="number" id="vtBookWidth" class="x-input" min="0.01" step="0.1" placeholder="Default">
                    </div>
                    <div class="vt-book__field">
                        <label for="vtBookHeight" class="vt-book__label">Height, cm</label>
                        <input type="number" id="vtBookHeight" class="x-input" min="0.01" step="0.1" placeholder="Default">
                    </div>
                </div>

                <label class="vt-book__check">
                    <input type="checkbox" id="vtBookInsure" value="1">
                    <span>Insure this parcel</span>
                </label>

                {{-- The fee, asked for on demand rather than on every
                     keystroke: an estimate is a courier call. --}}
                <div class="vt-book__estimate">
                    <button type="button" id="btnVtEstimate" class="x-btn x-btn--ghost x-btn--sm">Estimate the fee</button>
                    <span id="vtBookEstimate" class="vt-book__estimate-text"></span>
                </div>

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
