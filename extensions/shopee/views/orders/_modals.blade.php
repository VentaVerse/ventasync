<div id="spShipModal" class="modal-backdrop">
    <div class="modal co-modal">
        <div class="modal-header co-modal__head">
            <h3 class="co-modal__title">Arrange shipment</h3>
            <button type="button" id="btnCloseShip" class="modal-close co-modal__close" aria-label="Close">&times;</button>
        </div>
        <div class="co-modal__meta">Order <strong id="spShipOrderSn"></strong></div>

        <div id="spShipParcel" class="sp-hidden"></div>

        <div id="shipStep1">
            <p class="co-modal__prompt">How will this order be shipped?</p>
            <div class="co-modal__choices">
                <button type="button" class="btnShipChoice x-btn x-btn--secondary" data-type="dropoff">
                    <x-ui.icon name="map-pin" size="14" />
                    Drop off
                </button>
                <button type="button" class="btnShipChoice x-btn x-btn--secondary" data-type="pickup">
                    <x-ui.icon name="truck" size="14" />
                    Pickup
                </button>
            </div>
        </div>

        <div id="shipStep2" class="sp-hidden">
            <div id="shipStep2Loading" class="co-modal__loading">
                <span class="co-spinner" aria-hidden="true"></span>
                <span>Loading addresses</span>
            </div>
            <div id="shipStep2Content" class="co-modal__body sp-hidden"></div>
            <div class="co-modal__foot">
                <button type="button" id="btnShipBack" class="x-btn x-btn--ghost">Back</button>
                <button type="button" id="btnShipConfirm" class="x-btn x-btn--primary" disabled>Confirm and ship</button>
            </div>
        </div>

        <form method="POST" id="formShipOrder" class="sp-hidden" data-orders-base="{{ route('ext.shopee.orders.index') }}">
            @csrf
            <input type="hidden" name="shipping_type" id="shipTypeInput" value="">
            <input type="hidden" name="address_id" id="shipAddressInput" value="">
            <input type="hidden" name="branch_id" id="shipBranchInput" value="">
        </form>
    </div>
</div>

<div id="spLoadingOverlay" class="modal-backdrop">
    <div class="modal co-modal co-modal--sm">
        <div class="co-progress">
            <span class="co-spinner co-spinner--lg" aria-hidden="true"></span>
            <div>
                <div id="spLoadingTitle" class="co-progress__title">Fetching orders</div>
                <div class="co-progress__note">Please keep this tab open.</div>
            </div>
        </div>
    </div>
</div>

<div id="spTrackingModal" class="modal-backdrop">
    <div class="modal co-modal co-modal--md">
        <div class="modal-header co-modal__head">
            <h3 class="co-modal__title">Tracking</h3>
            <button type="button" id="btnCloseTracking" class="modal-close co-modal__close" aria-label="Close">&times;</button>
        </div>
        <div id="spTrackingBody" class="co-modal__body">
            <div class="text-secondary">Loading</div>
        </div>
        <div class="co-modal__foot co-modal__foot--end">
            <button type="button" id="btnTrackingClose2" class="x-btn x-btn--secondary">Close</button>
        </div>
    </div>
</div>
