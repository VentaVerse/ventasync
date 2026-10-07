<div id="ttTrackingModal" class="modal-backdrop">
    <div class="modal co-modal co-modal--md">
        <div class="modal-header co-modal__head">
            <h3 class="co-modal__title">Tracking</h3>
            <button type="button" id="btnCloseTtTracking" class="modal-close co-modal__close" aria-label="Close">&times;</button>
        </div>
        <div id="ttTrackingSub" class="co-modal__meta"></div>
        <div id="ttTrackingBody" class="co-modal__body">
            <div class="text-secondary">Loading</div>
        </div>
        <div class="co-modal__foot co-modal__foot--end">
            <button type="button" id="btnCloseTtTracking2" class="x-btn x-btn--secondary">Close</button>
        </div>
    </div>
</div>

<div id="ttShipModal" class="modal-backdrop">
    <div class="modal co-modal">
        <div class="modal-header co-modal__head">
            <h3 class="co-modal__title">Ship order</h3>
            <button type="button" id="btnCloseTtShip" class="modal-close co-modal__close" aria-label="Close">&times;</button>
        </div>
        <div class="co-modal__meta">Order <strong id="ttShipOrderNo"></strong></div>
        <div id="ttShipParcel" class="tt-hidden"></div>
        <p class="co-modal__prompt">TikTok is told the parcel has left, and that cannot be undone from here.</p>
        <div class="co-modal__foot co-modal__foot--end">
            <button type="button" id="btnCloseTtShip2" class="x-btn x-btn--ghost">Cancel</button>
            <button type="button" id="btnTtShipConfirm" class="x-btn x-btn--primary">Ship order</button>
        </div>
    </div>
</div>
