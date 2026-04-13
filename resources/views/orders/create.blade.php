@extends('layouts.app')
@section('breadcrumb', 'Sales / Orders / Add')

@section('content')
<div class="card">
    <div class="page-header">
        <h2>Add Order</h2>
        <div class="page-header-actions">
            <a class="btn secondary" href="{{ route('orders.index') }}">Back</a>
        </div>
    </div>

    <form id="order-form" method="POST" action="{{ route('orders.store') }}">
        @csrf

        {{-- Currency --}}
        <h3 class="section-title">Currency</h3>
        <div class="form-grid">
            <div>
                <label class="required">Currency</label>
                <select class="input" id="currency-select">
                    @foreach($currencies as $cur)
                        <option value="{{ $cur->id }}"
                                data-code="{{ $cur->code }}"
                                data-symbol="{{ $cur->symbol }}"
                                data-rate="{{ $cur->exchange_rate }}"
                                {{ $cur->is_default ? 'selected' : '' }}>
                            {{ $cur->code }} &mdash; {{ $cur->name }}
                        </option>
                    @endforeach
                </select>
                <input type="hidden" name="currency_code" id="currency-code-hidden" value="{{ $currencies->firstWhere('is_default', 1)->code ?? 'PHP' }}">
            </div>
        </div>

        {{-- Customer Details --}}
        <h3 class="section-title">Customer Details</h3>
        <div class="form-grid">
            <div>
                <label class="required">First Name</label>
                <input class="input" name="firstname" id="cust_firstname" value="{{ old('firstname') }}" maxlength="32">
            </div>
            <div>
                <label>Last Name</label>
                <input class="input" name="lastname" id="cust_lastname" value="{{ old('lastname') }}" maxlength="32">
            </div>
            <div>
                <label>Email</label>
                <input class="input" name="email" value="{{ old('email') }}" maxlength="96">
            </div>
            <div>
                <label>Telephone</label>
                <input class="input" name="telephone" value="{{ old('telephone') }}" maxlength="32">
            </div>
        </div>

        {{-- Shipping Details --}}
        <h3 class="section-title">Shipping Details</h3>
        <div class="form-grid" id="shipping-fields">
            <div>
                <label>First Name</label>
                <input class="input ship-field" name="shipping_firstname" data-field="firstname" value="{{ old('shipping_firstname') }}" maxlength="32">
            </div>
            <div>
                <label>Last Name</label>
                <input class="input ship-field" name="shipping_lastname" data-field="lastname" value="{{ old('shipping_lastname') }}" maxlength="32">
            </div>
            <div>
                <label>Company</label>
                <input class="input ship-field" name="shipping_company" data-field="company" value="{{ old('shipping_company') }}" maxlength="40">
            </div>
            <div>
                <label>Address 1</label>
                <input class="input ship-field" name="shipping_address_1" data-field="address_1" value="{{ old('shipping_address_1') }}" maxlength="128">
            </div>
            <div>
                <label>Address 2</label>
                <input class="input ship-field" name="shipping_address_2" data-field="address_2" value="{{ old('shipping_address_2') }}" maxlength="128">
            </div>
            <div>
                <label>City</label>
                <input class="input ship-field" name="shipping_city" data-field="city" value="{{ old('shipping_city') }}" maxlength="128">
            </div>
            <div>
                <label>Postcode</label>
                <input class="input ship-field" name="shipping_postcode" data-field="postcode" value="{{ old('shipping_postcode') }}" maxlength="10">
            </div>
            <div>
                <label>Country</label>
                <input class="input ship-field" name="shipping_country" data-field="country" value="{{ old('shipping_country') }}" maxlength="128">
            </div>
            <div>
                <label>Zone / Region</label>
                <input class="input ship-field" name="shipping_zone" data-field="zone" value="{{ old('shipping_zone') }}" maxlength="128">
            </div>
            <div>
                <label>Shipping Method</label>
                <input class="input" name="shipping_method" value="{{ old('shipping_method') }}">
            </div>
        </div>

        {{-- Payment Details --}}
        <div class="d-flex items-center gap-8" style="margin-top:24px;">
            <h3 class="section-title" style="margin:0;">Payment Details</h3>
            <label class="d-flex items-center gap-6" style="font-size:13px; font-weight:500; cursor:pointer;">
                <input type="checkbox" id="payment-same-as-shipping" checked>
                <span>Same as Shipping</span>
            </label>
        </div>
        <div id="payment-fields" style="display:none;">
            <div class="form-grid" style="margin-top:12px;">
                <div>
                    <label>First Name</label>
                    <input class="input pay-field" name="payment_firstname" data-field="firstname" value="{{ old('payment_firstname') }}" maxlength="32">
                </div>
                <div>
                    <label>Last Name</label>
                    <input class="input pay-field" name="payment_lastname" data-field="lastname" value="{{ old('payment_lastname') }}" maxlength="32">
                </div>
                <div>
                    <label>Company</label>
                    <input class="input pay-field" name="payment_company" value="{{ old('payment_company') }}" maxlength="40">
                </div>
                <div>
                    <label>Address 1</label>
                    <input class="input pay-field" name="payment_address_1" data-field="address_1" value="{{ old('payment_address_1') }}" maxlength="128">
                </div>
                <div>
                    <label>Address 2</label>
                    <input class="input pay-field" name="payment_address_2" data-field="address_2" value="{{ old('payment_address_2') }}" maxlength="128">
                </div>
                <div>
                    <label>City</label>
                    <input class="input pay-field" name="payment_city" data-field="city" value="{{ old('payment_city') }}" maxlength="128">
                </div>
                <div>
                    <label>Postcode</label>
                    <input class="input pay-field" name="payment_postcode" data-field="postcode" value="{{ old('payment_postcode') }}" maxlength="10">
                </div>
                <div>
                    <label>Country</label>
                    <input class="input pay-field" name="payment_country" data-field="country" value="{{ old('payment_country') }}" maxlength="128">
                </div>
                <div>
                    <label>Zone / Region</label>
                    <input class="input pay-field" name="payment_zone" data-field="zone" value="{{ old('payment_zone') }}" maxlength="128">
                </div>
                <div>
                    <label>Payment Method</label>
                    <input class="input" name="payment_method" value="{{ old('payment_method') }}" maxlength="128">
                </div>
            </div>
        </div>

        {{-- Products --}}
        <h3 class="section-title">Products</h3>
        <div class="table-wrap">
            <table class="table" id="order-products-table">
                <thead>
                <tr>
                    <th style="width:60px;">ID</th>
                    <th>Product</th>
                    <th>Option</th>
                    <th style="width:120px;">SKU</th>
                    <th style="width:100px;">Price</th>
                    <th style="width:60px;">Qty</th>
                    <th style="width:100px;">Total</th>
                    <th style="width:50px;"></th>
                </tr>
                </thead>
                <tbody id="order-products-body">
                <tr id="no-products-row">
                    <td colspan="8" class="text-muted">No products added yet.</td>
                </tr>
                </tbody>
                <tfoot>
                <tr>
                    <td colspan="6" style="text-align:right; font-weight:700;">Order Total</td>
                    <td style="font-weight:700;" id="order-total-display">0.00</td>
                    <td></td>
                </tr>
                </tfoot>
            </table>
        </div>
        <div class="d-flex" style="justify-content:flex-end; margin-top:8px;">
            <button type="button" class="btn small" id="add-product-btn">Add Product</button>
        </div>
        <input type="hidden" name="total" id="order-total-hidden" value="0">

        {{-- Order Info --}}
        <h3 class="section-title">Order Info</h3>
        <div class="form-grid">
            <div>
                <label class="required">Order Status</label>
                <select class="input" name="order_status_id" id="order-status-select">
                    @foreach($statuses as $s)
                        <option value="{{ $s->order_status_id }}" {{ (int)old('order_status_id', 1) === $s->order_status_id ? 'selected' : '' }}>{{ $s->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="full">
                <label>Comment</label>
                <textarea class="input" name="comment" rows="2">{{ old('comment') }}</textarea>
            </div>
        </div>


        {{-- Save --}}
        <div class="d-flex" style="justify-content:flex-end; margin-top:24px;">
            <button class="btn" type="submit">Save Order</button>
        </div>
    </form>
</div>

{{-- Add Product Modal --}}
<div class="modal-backdrop" id="product-modal-backdrop">
    <div class="modal" style="max-width:700px;">
        <div class="modal-header">
            <h3>Add Product</h3>
            <button type="button" class="modal-close" id="product-modal-close">&times;</button>
        </div>

        {{-- Search inside modal --}}
        <div style="margin-bottom:12px;">
            <input type="text" class="input" id="modal-product-search" placeholder="Start typing to search products..." style="width:100%;" autocomplete="off">
        </div>

        {{-- Added count banner --}}
        <div id="modal-added-banner" style="display:none; padding:8px 12px; margin-bottom:12px; background:var(--accent-light); border:1px solid rgba(59,130,246,.2); border-radius:var(--radius-sm); font-size:13px; font-weight:600; color:var(--accent);">
            <span id="modal-added-count">0</span> product(s) added to order
        </div>

        {{-- Search results --}}
        <div id="modal-search-results" style="max-height:220px; overflow-y:auto; border:1px solid var(--border); border-radius:var(--radius-md); display:none;"></div>

        {{-- Product detail panel (shown after selecting a product) --}}
        <div id="modal-product-detail" style="display:none; margin-top:16px; padding:16px; border:1px solid var(--border); border-radius:var(--radius-md); background:var(--surface-alt, var(--surface));">
            <div style="margin-bottom:8px;">
                <strong id="modal-detail-name"></strong>
                <span class="text-muted" id="modal-detail-sku" style="margin-left:8px;"></span>
            </div>
            <div id="modal-detail-price-line" style="margin-bottom:12px; font-variant-numeric:tabular-nums;"></div>

            {{-- Options radio list --}}
            <div id="modal-options-wrap" style="display:none; margin-bottom:12px;">
                <label style="font-weight:600; margin-bottom:6px; display:block;">Select Option</label>
                <div id="modal-options-list"></div>
            </div>

            {{-- Quantity + Add button --}}
            <div class="d-flex items-center gap-8" style="justify-content:space-between;">
                <div class="d-flex items-center gap-8">
                    <label style="font-weight:600;">Qty</label>
                    <input type="number" class="input" id="modal-qty" value="1" min="1" style="width:80px;">
                </div>
                <button type="button" class="btn" id="modal-add-to-order-btn">Add to Order</button>
            </div>
        </div>

        {{-- Modal footer --}}
        <div style="margin-top:16px; display:flex; justify-content:flex-end;">
            <button type="button" class="btn secondary" id="modal-done-btn">Done</button>
        </div>
    </div>
</div>

@push('scripts')
<script>
(function() {
    // ── Currency management ──────────────────────────────────
    var currencySelect = document.getElementById('currency-select');
    var currencyCodeHidden = document.getElementById('currency-code-hidden');

    function getSelectedCurrency() {
        var opt = currencySelect.options[currencySelect.selectedIndex];
        return {
            code: opt.dataset.code,
            symbol: opt.dataset.symbol,
            rate: parseFloat(opt.dataset.rate) || 1
        };
    }

    function convertPrice(phpPrice) {
        var cur = getSelectedCurrency();
        // rate means "1 foreign = X PHP", so to convert PHP to foreign: price / rate
        // If rate is 1 (PHP itself), price stays the same
        if (cur.rate === 0) return phpPrice;
        return phpPrice / cur.rate;
    }

    currencySelect.addEventListener('change', function() {
        var opt = currencySelect.options[currencySelect.selectedIndex];
        currencyCodeHidden.value = opt.dataset.code;
        renderProducts();
    });

    // ── Payment same as shipping ──────────────────────────────
    var sameCheckbox = document.getElementById('payment-same-as-shipping');
    var paymentFields = document.getElementById('payment-fields');

    function togglePayment() {
        paymentFields.style.display = sameCheckbox.checked ? 'none' : 'block';
    }
    sameCheckbox.addEventListener('change', togglePayment);
    togglePayment();

    document.getElementById('order-form').addEventListener('submit', function() {
        if (sameCheckbox.checked) {
            document.querySelectorAll('.ship-field').forEach(function(shipInput) {
                var field = shipInput.dataset.field;
                if (!field) return;
                var payInput = document.querySelector('.pay-field[data-field="' + field + '"]');
                if (payInput) payInput.value = shipInput.value;
            });
        }
    });

    // ── Product line items ───────────────────────────────────
    var tbody = document.getElementById('order-products-body');
    var noProductsRow = document.getElementById('no-products-row');
    var totalDisplay = document.getElementById('order-total-display');
    var totalHidden = document.getElementById('order-total-hidden');
    // Restore line items from old() on validation failure
    @php
        $oldProducts = collect(old('products', []))->values()->map(function ($p) {
            return [
                'product_id'      => (int) ($p['product_id'] ?? 0),
                'name'            => $p['name'] ?? '',
                'model'           => $p['model'] ?? '',
                'sku'             => $p['model'] ?? '',
                'basePrice'       => (float) ($p['price'] ?? 0),
                'baseCost'        => (float) ($p['cost'] ?? 0),
                'qty'             => (int) ($p['quantity'] ?? 1),
                'option_value_id' => $p['option_value_id'] ?? null,
                'option_name'     => $p['option_name'] ?? '',
                'option_value'    => $p['option_value'] ?? '',
            ];
        })->toArray();
    @endphp
    var lineItems = @json($oldProducts);

    function recalcTotal() {
        var total = 0;
        lineItems.forEach(function(item) {
            total += convertPrice(item.basePrice) * item.qty;
        });
        var cur = getSelectedCurrency();
        totalDisplay.textContent = cur.symbol + ' ' + total.toFixed(2);
        totalHidden.value = total.toFixed(2);
    }

    function renderProducts() {
        tbody.textContent = '';
        if (lineItems.length === 0) {
            tbody.appendChild(noProductsRow);
            recalcTotal();
            return;
        }

        var cur = getSelectedCurrency();

        lineItems.forEach(function(item, idx) {
            var tr = document.createElement('tr');
            var displayPrice = convertPrice(item.basePrice);

            // ID
            var tdId = document.createElement('td');
            tdId.textContent = item.product_id;
            tr.appendChild(tdId);

            // Product name + hidden inputs
            var tdName = document.createElement('td');
            var displayName = item.option_value ? item.name + ' - ' + item.option_value : item.name;
            tdName.textContent = displayName;

            var hiddens = [
                { n: 'product_id', v: item.product_id },
                { n: 'name', v: displayName },
                { n: 'model', v: item.model },
                { n: 'cost', v: convertPrice(item.baseCost).toFixed(2) },
                { n: 'price', v: displayPrice.toFixed(2) },
                { n: 'quantity', v: item.qty }
            ];
            if (item.option_value_id) {
                hiddens.push({ n: 'option_value_id', v: item.option_value_id });
                hiddens.push({ n: 'option_name', v: item.option_name || '' });
                hiddens.push({ n: 'option_value', v: item.option_value || '' });
            }
            hiddens.forEach(function(h) {
                var inp = document.createElement('input');
                inp.type = 'hidden';
                inp.name = 'products[' + idx + '][' + h.n + ']';
                inp.value = h.v;
                tdName.appendChild(inp);
            });
            tr.appendChild(tdName);

            // Option
            var tdOpt = document.createElement('td');
            tdOpt.textContent = item.option_value ? item.option_name + ': ' + item.option_value : '-';
            tr.appendChild(tdOpt);

            // SKU
            var tdSku = document.createElement('td');
            var code = document.createElement('code');
            code.textContent = item.sku || item.model || '-';
            tdSku.appendChild(code);
            tr.appendChild(tdSku);

            // Price input
            var tdPrice = document.createElement('td');
            var priceInput = document.createElement('input');
            priceInput.type = 'number';
            priceInput.className = 'input line-price';
            priceInput.dataset.idx = idx;
            priceInput.value = displayPrice.toFixed(2);
            priceInput.step = '0.01';
            priceInput.min = '0';
            priceInput.style.width = '90px';
            tdPrice.appendChild(priceInput);
            tr.appendChild(tdPrice);

            // Qty input
            var tdQty = document.createElement('td');
            var qtyInput = document.createElement('input');
            qtyInput.type = 'number';
            qtyInput.className = 'input line-qty';
            qtyInput.dataset.idx = idx;
            qtyInput.value = item.qty;
            qtyInput.min = '1';
            qtyInput.style.width = '60px';
            tdQty.appendChild(qtyInput);
            tr.appendChild(tdQty);

            // Line total
            var tdTotal = document.createElement('td');
            tdTotal.className = 'line-total';
            tdTotal.style.fontVariantNumeric = 'tabular-nums';
            tdTotal.textContent = cur.symbol + ' ' + (displayPrice * item.qty).toFixed(2);
            tr.appendChild(tdTotal);

            // Remove button
            var tdRemove = document.createElement('td');
            var removeBtn = document.createElement('button');
            removeBtn.type = 'button';
            removeBtn.className = 'btn small danger line-remove';
            removeBtn.dataset.idx = idx;
            removeBtn.textContent = '\u00D7';
            tdRemove.appendChild(removeBtn);
            tr.appendChild(tdRemove);

            tbody.appendChild(tr);
        });

        recalcTotal();
    }

    // Line item events (delegation)
    document.addEventListener('input', function(e) {
        var idx, cur;
        if (e.target.classList.contains('line-price')) {
            idx = parseInt(e.target.dataset.idx);
            var newDisplayPrice = parseFloat(e.target.value) || 0;
            // Convert display price back to PHP base price
            cur = getSelectedCurrency();
            lineItems[idx].basePrice = newDisplayPrice * cur.rate;
            // Update hidden input
            var row = e.target.closest('tr');
            var hiddenPrice = row.querySelector('input[name="products[' + idx + '][price]"]');
            if (hiddenPrice) hiddenPrice.value = newDisplayPrice.toFixed(2);
            row.querySelector('.line-total').textContent = cur.symbol + ' ' + (newDisplayPrice * lineItems[idx].qty).toFixed(2);
            recalcTotal();
        }
        if (e.target.classList.contains('line-qty')) {
            idx = parseInt(e.target.dataset.idx);
            lineItems[idx].qty = parseInt(e.target.value) || 1;
            cur = getSelectedCurrency();
            var displayPrice = convertPrice(lineItems[idx].basePrice);
            var row = e.target.closest('tr');
            var hiddenQty = row.querySelector('input[name="products[' + idx + '][quantity]"]');
            if (hiddenQty) hiddenQty.value = lineItems[idx].qty;
            row.querySelector('.line-total').textContent = cur.symbol + ' ' + (displayPrice * lineItems[idx].qty).toFixed(2);
            recalcTotal();
        }
    });

    document.addEventListener('click', function(e) {
        if (e.target.classList.contains('line-remove')) {
            var idx = parseInt(e.target.dataset.idx);
            lineItems.splice(idx, 1);
            renderProducts();
        }
    });

    // ── Add Product Modal ────────────────────────────────────
    var modalBackdrop = document.getElementById('product-modal-backdrop');
    var modalClose = document.getElementById('product-modal-close');
    var addProductBtn = document.getElementById('add-product-btn');
    var modalSearchInput = document.getElementById('modal-product-search');
    var modalResults = document.getElementById('modal-search-results');
    var modalAddedBanner = document.getElementById('modal-added-banner');
    var modalAddedCount = document.getElementById('modal-added-count');
    var modalDoneBtn = document.getElementById('modal-done-btn');
    var modalSessionAdded = 0;
    var searchTimer = null;
    var modalDetail = document.getElementById('modal-product-detail');
    var modalDetailName = document.getElementById('modal-detail-name');
    var modalDetailSku = document.getElementById('modal-detail-sku');
    var modalDetailPriceLine = document.getElementById('modal-detail-price-line');
    var modalOptionsWrap = document.getElementById('modal-options-wrap');
    var modalOptionsList = document.getElementById('modal-options-list');
    var modalQty = document.getElementById('modal-qty');
    var modalAddBtn = document.getElementById('modal-add-to-order-btn');

    var selectedProduct = null; // stash the product data from search

    function openModal() {
        modalBackdrop.classList.add('active');
        modalSearchInput.value = '';
        modalResults.style.display = 'none';
        modalResults.textContent = '';
        modalDetail.style.display = 'none';
        selectedProduct = null;
        modalQty.value = 1;
        modalSessionAdded = 0;
        modalAddedBanner.style.display = 'none';
        setTimeout(function() { modalSearchInput.focus(); }, 100);
    }

    function closeModal() {
        modalBackdrop.classList.remove('active');
        selectedProduct = null;
        if (searchTimer) clearTimeout(searchTimer);
    }

    addProductBtn.addEventListener('click', openModal);
    modalClose.addEventListener('click', closeModal);
    modalDoneBtn.addEventListener('click', closeModal);
    modalBackdrop.addEventListener('click', function(e) {
        if (e.target === modalBackdrop) closeModal();
    });

    // Proactive search inside modal (debounced)
    function doModalSearch() {
        var q = modalSearchInput.value.trim();
        if (q.length < 2) {
            modalResults.style.display = 'none';
            modalResults.textContent = '';
            return;
        }

        modalDetail.style.display = 'none';
        selectedProduct = null;

        fetch(@json(route('orders.search_products')) + '?q=' + encodeURIComponent(q), {
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            var items = data.items || [];
            modalResults.textContent = '';

            // Add header row
            var header = document.createElement('div');
            header.style.cssText = 'display:grid; grid-template-columns:1fr 110px 70px 60px 70px; padding:6px 12px; font-size:11px; font-weight:700; color:var(--text-muted); text-transform:uppercase; border-bottom:2px solid var(--border); gap:8px; position:sticky; top:0; background:var(--surface); z-index:1;';
            ['Product', 'SKU', 'Price', 'Stock', ''].forEach(function(label, i) {
                var col = document.createElement('div');
                col.textContent = label;
                if (i >= 2 && i <= 3) col.style.textAlign = 'right';
                header.appendChild(col);
            });
            modalResults.appendChild(header);

            if (items.length === 0) {
                var empty = document.createElement('div');
                empty.style.cssText = 'padding:12px;';
                empty.className = 'text-muted';
                empty.textContent = 'No products found.';
                modalResults.appendChild(empty);
            } else {
                items.forEach(function(p) {
                    var row = document.createElement('div');
                    row.style.cssText = 'display:grid; grid-template-columns:1fr 110px 70px 60px 70px; align-items:center; padding:6px 12px; cursor:pointer; border-bottom:1px solid var(--border-light); font-size:13px; gap:8px;';
                    row.addEventListener('mouseenter', function() { row.style.background = 'var(--surface-hover)'; });
                    row.addEventListener('mouseleave', function() { row.style.background = ''; });

                    var cur = getSelectedCurrency();

                    // Name
                    var nameCol = document.createElement('div');
                    nameCol.style.cssText = 'overflow:hidden; text-overflow:ellipsis; white-space:nowrap;';
                    var nameEl = document.createElement('strong');
                    nameEl.textContent = p.name;
                    nameCol.appendChild(nameEl);

                    // SKU
                    var skuCol = document.createElement('div');
                    var skuCode = document.createElement('code');
                    skuCode.style.cssText = 'font-size:12px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; display:block;';
                    skuCode.textContent = p.sku || p.model || '-';
                    skuCol.appendChild(skuCode);

                    // Price
                    var priceCol = document.createElement('div');
                    priceCol.style.cssText = 'text-align:right; font-variant-numeric:tabular-nums;';
                    priceCol.textContent = cur.symbol + ' ' + convertPrice(parseFloat(p.price)).toFixed(2);

                    // Stock
                    var qtyCol = document.createElement('div');
                    qtyCol.style.cssText = 'text-align:right; color:var(--text-muted);';
                    qtyCol.textContent = p.quantity;

                    // Options badge
                    var optCol = document.createElement('div');
                    optCol.style.textAlign = 'right';
                    if (p.options && p.options.length > 0) {
                        var badge = document.createElement('span');
                        badge.className = 'badge';
                        badge.style.fontSize = '11px';
                        badge.textContent = p.options.length + ' opt';
                        optCol.appendChild(badge);
                    }

                    row.appendChild(nameCol);
                    row.appendChild(skuCol);
                    row.appendChild(priceCol);
                    row.appendChild(qtyCol);
                    row.appendChild(optCol);

                    row.addEventListener('click', function() {
                        selectProductForDetail(p);
                    });

                    modalResults.appendChild(row);
                });
            }
            modalResults.style.display = '';
        })
        .catch(function() {
            showFlashError('Product search failed. Please try again.');
        });
    }

    modalSearchInput.addEventListener('input', function() {
        if (searchTimer) clearTimeout(searchTimer);
        searchTimer = setTimeout(doModalSearch, 300);
    });
    modalSearchInput.addEventListener('keydown', function(e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            if (searchTimer) clearTimeout(searchTimer);
            doModalSearch();
        }
    });

    // Select a product to show detail panel
    function selectProductForDetail(p) {
        selectedProduct = p;
        modalDetailName.textContent = p.name;
        modalDetailSku.textContent = p.sku || p.model || '';

        var cur = getSelectedCurrency();
        var baseDisplayPrice = convertPrice(parseFloat(p.price));
        modalDetailPriceLine.textContent = 'Base price: ' + cur.symbol + ' ' + baseDisplayPrice.toFixed(2);

        modalOptionsList.textContent = '';
        modalQty.value = 1;

        if (p.options && p.options.length > 0) {
            modalOptionsWrap.style.display = '';

            p.options.forEach(function(opt, oi) {
                var label = document.createElement('label');
                label.style.cssText = 'display:flex; align-items:center; gap:8px; padding:4px 0; cursor:pointer;';

                var radio = document.createElement('input');
                radio.type = 'radio';
                radio.name = 'modal_option_select';
                radio.value = oi;
                if (oi === 0) radio.checked = true;

                var optPrice = computeOptionPrice(p, opt);
                var displayOptPrice = convertPrice(optPrice);

                var text = document.createElement('span');
                text.textContent = opt.option_name + ': ' + opt.value_name
                    + ' (' + cur.symbol + ' ' + displayOptPrice.toFixed(2) + ')'
                    + (opt.option_sku ? ' [' + opt.option_sku + ']' : '')
                    + ' - Stock: ' + opt.option_qty;

                label.appendChild(radio);
                label.appendChild(text);
                modalOptionsList.appendChild(label);
            });
        } else {
            modalOptionsWrap.style.display = 'none';
        }

        modalDetail.style.display = '';
    }

    // Compute the final PHP price for a product + option
    function computeOptionPrice(product, option) {
        var basePrice = parseFloat(product.price) || 0;
        var absPrice = parseFloat(option.absolute_price) || 0;
        if (absPrice > 0) return absPrice;

        var modifier = parseFloat(option.option_price) || 0;
        if (option.price_prefix === '-') {
            return basePrice - modifier;
        }
        return basePrice + modifier;
    }

    function computeOptionCost(product, option) {
        var absCost = parseFloat(option.absolute_cost) || 0;
        if (absCost > 0) return absCost;
        var baseCost = parseFloat(product.cost) || 0;
        var modifier = parseFloat(option.option_cost) || 0;
        if (option.cost_prefix === '-') {
            return baseCost - modifier;
        }
        return baseCost + modifier;
    }

    // Add to order from modal
    modalAddBtn.addEventListener('click', function() {
        if (!selectedProduct) {
            showFlashError('No product selected.');
            return;
        }

        var qty = parseInt(modalQty.value) || 1;
        if (qty < 1) qty = 1;

        var p = selectedProduct;
        var item = {
            product_id: parseInt(p.product_id),
            name: p.name || '',
            sku: p.sku || '',
            model: p.model || '',
            basePrice: parseFloat(p.price) || 0,
            baseCost: parseFloat(p.cost) || 0,
            qty: qty,
            option_value_id: null,
            option_name: '',
            option_value: ''
        };

        if (p.options && p.options.length > 0) {
            var checked = modalOptionsList.querySelector('input[name="modal_option_select"]:checked');
            if (!checked) {
                showFlashError('Please select an option.');
                return;
            }
            var optIdx = parseInt(checked.value);
            var opt = p.options[optIdx];

            item.basePrice = computeOptionPrice(p, opt);
            item.baseCost = computeOptionCost(p, opt);
            item.sku = opt.option_sku || item.sku;
            item.option_value_id = opt.product_option_value_id || opt.combination_id || null;
            item.option_name = opt.option_name;
            item.option_value = opt.value_name;
        }

        // Check if same product + same SKU + same option already exists — increment qty
        var existingIdx = -1;
        for (var i = 0; i < lineItems.length; i++) {
            var li = lineItems[i];
            if (li.product_id === item.product_id && li.sku === item.sku && li.option_value === item.option_value) {
                existingIdx = i;
                break;
            }
        }

        if (existingIdx >= 0) {
            lineItems[existingIdx].qty += item.qty;
        } else {
            lineItems.push(item);
        }
        renderProducts();

        // Stay in modal for mass adding — reset detail, show count
        modalSessionAdded++;
        modalAddedCount.textContent = modalSessionAdded;
        modalAddedBanner.style.display = '';
        modalDetail.style.display = 'none';
        selectedProduct = null;
        modalQty.value = 1;
    });

    // Initial render
    renderProducts();
})();
</script>
@endpush
@endsection
