<section class="fm-section">
    <div class="fm-section__head">
        <h2 class="fm-section__title">Products</h2>
        <div class="fm-section__aside">
            <x-ui.button type="button" id="of-add">
                <x-ui.icon name="plus" size="14" /> Add product
            </x-ui.button>
        </div>
    </div>

    <x-ui.table class="of-lines">
        <x-slot:head>
            <tr>
                <th scope="col">Product</th>
                <th scope="col" class="of-col-price">Unit price</th>
                <th scope="col" class="of-col-qty">Qty</th>
                <th scope="col" class="of-col-total x-td-num">Line total</th>
                <th scope="col" class="x-td-actions"><span class="x-sr">Remove</span></th>
            </tr>
        </x-slot:head>

        <tr id="of-empty">
            <td colspan="5" class="of-lines__empty">No products on this order yet. Add product opens the catalog.</td>
        </tr>
    </x-ui.table>

    <div class="of-sum">
        <span class="of-sum__count" id="of-count">Nothing added yet</span>
        <div class="of-sum__totals">
            <span class="of-sum__k">Order total</span>
            <span class="of-sum__v x-num" id="of-total">0.00</span>
            <span class="of-sum__base x-num" id="of-total-base" hidden></span>
        </div>
    </div>

    <input type="hidden" name="total" id="of-total-input" value="{{ $initialTotal }}">
</section>
