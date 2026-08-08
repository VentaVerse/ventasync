{{--
    The line item editor.

    The rows are built by resources/js/pages/order-form.js, because a line is
    added, repriced and removed without a round trip. Everything below is the
    frame it renders into: the head row, the empty row it puts back when the
    last line goes, the totals foot, and the hidden `total` field.

    The price column is in the ORDER currency, which is what the controller
    reads. When the order currency is not the default, each line and the total
    also show the default-currency figure, because both are stored (price /
    total and foreign_price / foreign_total) and an operator should be able to
    see the pair before saving it.
--}}
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
                <th>Product</th>
                <th class="of-col-var">Variation</th>
                <th class="of-col-sku">SKU</th>
                <th class="of-col-price">Unit price</th>
                <th class="of-col-qty">Qty</th>
                <th class="of-col-total x-td-num">Line total</th>
                <th class="x-td-actions"><span class="x-sr">Remove</span></th>
            </tr>
        </x-slot:head>

        <tr id="of-empty">
            <td colspan="7" class="of-lines__empty">No products on this order yet. Add product opens the catalog.</td>
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
