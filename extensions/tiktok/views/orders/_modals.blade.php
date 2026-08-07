{{-- TikTok tracking modal.

     Extracted from orders/_panel.blade.php when the order DETAIL page became
     the second surface that needs it. Every id below is queried by
     resources/js/pages/tiktok-orders.js and must not change, which is exactly
     why there is one copy of this markup rather than one per page: two copies
     of a contract the module reads by id is how the two drift. --}}
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
