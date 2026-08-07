{{-- Lazada logistics trace modal.

     Extracted from orders/_panel.blade.php when the order DETAIL page became
     the second surface that needs it. Every id below is queried by
     resources/js/pages/lazada-orders.js and must not change, which is exactly
     why there is one copy of this markup rather than one per page: two copies
     of a contract the module reads by id is how the two drift. --}}
<div id="lzLogisticsModal" class="modal-backdrop">
    <div class="modal co-modal co-modal--lg">
        <div class="modal-header co-modal__head">
            <h3 class="co-modal__title">Tracking</h3>
            <button type="button" id="btnCloseLogistics" class="modal-close co-modal__close" aria-label="Close">&times;</button>
        </div>
        <div id="lzLogisticsSub" class="co-modal__meta"></div>
        <div id="lzLogisticsBody" class="co-modal__body">
            <div class="text-secondary">Loading</div>
        </div>
        <div class="co-modal__foot co-modal__foot--end">
            <button type="button" id="btnLogisticsClose2" class="x-btn x-btn--secondary">Close</button>
        </div>
    </div>
</div>
