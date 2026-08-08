{{--
    The product picker.

    Pushed to the layout's script stack so it renders OUTSIDE the order form:
    a form cannot contain another, and this panel carries its own controls.
    Same mechanics the product image library already uses (a .modal-backdrop
    that JS toggles with .active, holding a picker panel).

    It searches through orders.search_products, the same read-tier endpoint
    the pre-redesign modal used, and adds to the line editor in place so a
    long order can be built without reopening it.
--}}
@push('scripts')
<div class="modal-backdrop" id="of-picker" role="dialog" aria-modal="true" aria-labelledby="of-pick-title">
    <div class="of-pick">
        <div class="of-pick__head">
            <h2 class="of-pick__title" id="of-pick-title">Add a product</h2>
            <div class="of-pick__head-right">
                <span class="of-pick__msg" id="of-pick-msg" role="status" aria-live="polite"></span>
                <x-ui.button type="button" size="sm" data-of-close="1">Close</x-ui.button>
            </div>
        </div>

        <div class="of-pick__body">
            <label class="x-sr" for="of-search">Search the catalog</label>
            <x-ui.input type="search" id="of-search" autocomplete="off"
                        placeholder="Search by name, model or SKU. Two letters is enough." />

            <p class="of-pick__added" id="of-pick-added" hidden></p>

            <div class="of-res__list" id="of-results" hidden></div>

            <div class="of-detail" id="of-detail" hidden>
                <div class="of-detail__id">
                    <span class="of-detail__name" id="of-detail-name"></span>
                    <span class="of-detail__sku x-num" id="of-detail-sku"></span>
                </div>
                <p class="of-detail__price x-num" id="of-detail-price"></p>

                <div class="of-detail__options" id="of-options" hidden>
                    <p class="of-detail__label">Variation</p>
                    <div id="of-options-list"></div>
                </div>

                <div class="of-detail__foot">
                    <label class="of-detail__qty">
                        <span>Qty</span>
                        <input type="number" id="of-pick-qty" class="x-input fm-input--num" value="1" min="1">
                    </label>
                    <x-ui.button type="button" variant="primary" id="of-pick-add">Add to order</x-ui.button>
                </div>
            </div>
        </div>
    </div>
</div>
@endpush
