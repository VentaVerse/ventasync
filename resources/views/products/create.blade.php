@php $pimToken = (string) \Illuminate\Support\Str::uuid(); @endphp
@extends('layouts.app')
@section('breadcrumb', 'Catalog / Products / Add')

@section('content')
<div class="card">
    <div class="page-header">
        <h2>Add Product</h2>
        <a class="btn secondary" href="{{ route('products.index') }}">Back</a>
    </div>

    <form method="POST" action="{{ route('products.store') }}">
        @csrf

        <div class="tabs mb-12">
            <button type="button" class="tab active" data-tab="prod-tab-general">General</button>
            <button type="button" class="tab" data-tab="prod-tab-options">Options</button>
            <button type="button" class="tab" data-tab="prod-tab-vendor">Vendor</button>
        </div>

        {{-- ===== GENERAL TAB ===== --}}
        <div id="prod-tab-general">

            <div class="typeahead mt-16">
                <label>Manufacturer</label>
                <input class="input" id="manufacturer_search" placeholder="Type to search..." autocomplete="off" value="{{ old('manufacturer_name','') }}" name="manufacturer_name">
                <input type="hidden" name="manufacturer_id" id="manufacturer_id" value="{{ old('manufacturer_id', 0) }}">
                <div class="typeahead-list" id="manufacturer_list"></div>
                <div class="hint">Start typing, then click a result. Required before uploading images.</div>
                @error('manufacturer_name')
                    <div class="field-error">{{ $message }}</div>
                @enderror
            </div>

            @include('products.partials.image_manager', ['pimToken' => $pimToken, 'pimProductId' => 0])

            <h3 class="section-title">General</h3>

            <div class="tab-pane mt-16">
                <div class="form-grid">
                <div class="full">
                    <label class="required">Name</label>
                    <div class="input-group">
                        <span class="input-group-prefix" id="name_mfg_prefix" style="display:none;"></span>
                        <input class="input" name="name" id="product_name" value="{{ old('name') }}">
                    </div>
                    @error('name')
                        <div class="field-error">{{ $message }}</div>
                    @enderror
                </div>

                <div>
                    <label class="required">Model</label>
                    <input class="input" name="model" value="{{ old('model') }}">
                    @error('model')
                        <div class="field-error">{{ $message }}</div>
                    @enderror
                </div>

                <div>
                    <label class="required">SKU</label>
                    <input class="input" name="sku" value="{{ old('sku') }}">
                    @error('sku')
                        <div class="field-error">{{ $message }}</div>
                    @enderror
                </div>

                <div>
                    <label>Quantity</label>
                    <input class="input" id="product_quantity" name="quantity" value="{{ old('quantity', '0') }}">
                    @error('quantity')
                        <div class="field-error">{{ $message }}</div>
                    @enderror
                    <div class="hint hidden" id="qty_hint">Quantity is auto-calculated from option quantities.</div>
                </div>

                <div>
                    <label class="required">Status</label>
                    <select class="input" name="status">
                        <option value="1" {{ old('status','1')=='1'?'selected':'' }}>Enabled</option>
                        <option value="0" {{ old('status','1')=='0'?'selected':'' }}>Disabled</option>
                    </select>
                    @error('status')
                        <div class="field-error">{{ $message }}</div>
                    @enderror
                </div>

                <div class="typeahead full">
                    <label>Categories</label>
                    <div id="category_tags" class="tag-container">
                        @foreach(old('category_ids', []) as $catId)
                            <span class="tag-chip" data-id="{{ $catId }}">
                                Category #{{ $catId }}
                                <input type="hidden" name="category_ids[]" value="{{ $catId }}">
                                <button type="button" class="tag-remove" onclick="this.parentElement.remove(); window.updateCatPlaceholder();">&times;</button>
                            </span>
                        @endforeach
                    </div>
                    <input class="input" id="category_search" placeholder="Type to search categories..." autocomplete="off">
                    <div class="typeahead-list" id="category_list"></div>
                    <div class="hint">Search and select multiple categories.</div>
                </div>

                <div class="full">
                    <label>Description</label>
                    <textarea class="input wysiwyg" name="description">{!! old('description', '') !!}</textarea>
                    @error('description')
                        <div class="field-error">{{ $message }}</div>
                    @enderror
                </div>
            </div>
            </div>

            <h3 class="section-title mt-24">Pricing & Cost</h3>

            <div class="tab-pane mt-16">
                <div class="form-grid">
                    <div>
                        <label>Selling Price</label>
                        <input class="input" name="price" id="product_price" value="{{ old('price', '0') }}">
                        @error('price')
                            <div class="field-error">{{ $message }}</div>
                        @enderror
                    </div>
                </div>

                <div class="cost-breakdown mt-16">
                    <div class="cost-breakdown-label">Cost Breakdown</div>
                    <div class="cost-breakdown-fields">
                        <div>
                            <label>Cost</label>
                            <input class="input" id="cost_amount" name="cost_amount" value="{{ old('cost_amount', '0') }}">
                            <div class="hint">Fixed amount</div>
                        </div>
                        <div class="cost-breakdown-op">and/or</div>
                        <div>
                            <label>Cost [%]</label>
                            <input class="input" id="cost_percentage" name="cost_percentage" value="{{ old('cost_percentage', '0') }}">
                            <div class="hint">% of selling price</div>
                        </div>
                        <div class="cost-breakdown-op">+</div>
                        <div>
                            <label>Additional Cost</label>
                            <input class="input" id="cost_additional" name="cost_additional" value="{{ old('cost_additional', '0') }}">
                            <div class="hint">e.g. supplier shipping</div>
                        </div>
                        <div class="cost-breakdown-op">=</div>
                        <div>
                            <label>Product Cost</label>
                            <input class="input" id="product_cost" name="cost" value="{{ old('cost', '0') }}" readonly tabindex="-1" style="background:var(--surface-alt); font-weight:600;">
                            <div class="hint">Computed total</div>
                        </div>
                    </div>
                    <div class="profit-metrics">
                        <div>
                            <label>Profit</label>
                            <input class="input" id="calc_profit" readonly tabindex="-1" style="background:var(--surface-alt); font-weight:600;">
                        </div>
                        <div>
                            <label>Margin [%]</label>
                            <input class="input" id="calc_margin" readonly tabindex="-1" style="background:var(--surface-alt); font-weight:600;">
                        </div>
                        <div>
                            <label>Markup [%]</label>
                            <input class="input" id="calc_markup" readonly tabindex="-1" style="background:var(--surface-alt); font-weight:600;">
                        </div>
                    </div>
                </div>
            </div>

            <h3 class="section-title mt-24">Shipping & Dimensions</h3>

            <div class="tab-pane mt-16">
                <div class="form-grid">
                    <div>
                        <label>Weight</label>
                        <input class="input" name="weight" value="{{ old('weight', '0') }}" placeholder="e.g. 0.5">
                        @error('weight') <div class="field-error">{{ $message }}</div> @enderror
                    </div>
                    <div>
                        <label>Length</label>
                        <input class="input" name="length" value="{{ old('length', '0') }}" placeholder="e.g. 10">
                        @error('length') <div class="field-error">{{ $message }}</div> @enderror
                    </div>
                    <div>
                        <label>Width</label>
                        <input class="input" name="width" value="{{ old('width', '0') }}" placeholder="e.g. 5">
                        @error('width') <div class="field-error">{{ $message }}</div> @enderror
                    </div>
                    <div>
                        <label>Height</label>
                        <input class="input" name="height" value="{{ old('height', '0') }}" placeholder="e.g. 3">
                        @error('height') <div class="field-error">{{ $message }}</div> @enderror
                    </div>
                </div>
            </div>

        </div>

        {{-- ===== OPTIONS TAB ===== --}}
        <div id="prod-tab-options" class="hidden">
            @include('products.partials.options_fields')
        </div>

        {{-- ===== VENDOR TAB ===== --}}
        <div id="prod-tab-vendor" class="hidden">

            <div class="form-grid mt-16">
                <div>
                    <label>Reorder Level</label>
                    <input class="input" type="number" name="reorder_level" value="{{ old('reorder_level', 0) }}" min="0">
                    <div class="hint">Alert when stock falls to this level (0 = disabled)</div>
                </div>
            </div>

            <h3 class="section-title mt-24">Buying Prices</h3>
            <div id="cv-buying-prices" class="mt-8">

                {{-- Vendor pricing table --}}
                <div class="table-wrap">
                    <table id="cv-vendor-table" style="font-size:12px;">
                        <thead>
                        <tr>
                            <th>Vendor</th>
                            <th style="width:120px;">Vendor SKU</th>
                            <th style="width:70px;">Min Qty</th>
                            <th style="width:85px;">Currency</th>
                            <th style="width:110px;">Unit Price</th>
                            <th style="width:60px;"></th>
                        </tr>
                        </thead>
                        <tbody id="cv-vendor-tbody">
                        </tbody>
                    </table>
                </div>
                <div id="cv-empty-msg" class="text-secondary text-xs mt-8">No vendor pricing yet.</div>

                {{-- Add new vendor --}}
                <div class="mt-16">
                    <div style="font-size:14px; font-weight:600; margin-bottom:8px;">Add New</div>
                    <div style="display:flex; gap:8px; flex-wrap:wrap; align-items:end;">
                        <div class="typeahead" style="position:relative;">
                            <label>Vendor</label>
                            <input class="input" id="cv-vendor-search" placeholder="Type to search..." autocomplete="off" style="width:200px;">
                            <input type="hidden" id="cv-vendor-id">
                            <div class="typeahead-list" id="cv-vendor-list"></div>
                        </div>
                        <div>
                            <label>Vendor SKU</label>
                            <input id="cv-vendor-sku" class="input" style="width:130px;">
                        </div>
                        <div>
                            <label>Min Qty</label>
                            <input id="cv-min-qty" class="input" type="number" min="1" value="1" style="width:80px;">
                        </div>
                        <div>
                            <label>Currency</label>
                            <select id="cv-currency" class="input" style="width:100px;">
                                @foreach($currencies as $c)
                                    <option value="{{ $c->code }}">{{ $c->code }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label>Unit Price</label>
                            <input id="cv-unit-price" class="input" type="number" step="0.0001" min="0" style="width:120px;">
                        </div>
                        <button type="button" class="btn secondary" id="cv-add-btn">Add</button>
                    </div>
                </div>
            </div>

        </div>

        <div class="mt-16 d-flex gap-10 items-center justify-end">
            <button class="btn" type="submit">Save</button>
        </div>
    </form>

</div>

<script>
(function() {
    document.querySelectorAll('.tabs .tab').forEach(function(btn) {
        btn.addEventListener('click', function() {
            document.querySelectorAll('.tabs .tab').forEach(function(b) { b.classList.remove('active'); });
            btn.classList.add('active');
            document.getElementById('prod-tab-general').classList.add('hidden');
            document.getElementById('prod-tab-options').classList.add('hidden');
            document.getElementById('prod-tab-vendor').classList.add('hidden');
            document.getElementById(btn.dataset.tab).classList.remove('hidden');
        });
    });
})();
</script>
@endsection

@push('scripts')
<script>
(function() {
    var price = document.getElementById('product_price');
    var amt   = document.getElementById('cost_amount');
    var pct   = document.getElementById('cost_percentage');
    var add   = document.getElementById('cost_additional');
    var out   = document.getElementById('product_cost');

    var profitEl = document.getElementById('calc_profit');
    var marginEl = document.getElementById('calc_margin');
    var markupEl = document.getElementById('calc_markup');

    function calc() {
        var p = parseFloat(price.value) || 0;
        var a = parseFloat(amt.value) || 0;
        var c = parseFloat(pct.value) || 0;
        var d = parseFloat(add.value) || 0;
        var cost = a + (c / 100 * p) + d;
        var result = cost.toFixed(4);
        out.value = result;

        var profit = p - cost;
        if (profitEl) profitEl.value = profit.toFixed(2);
        if (marginEl) marginEl.value = p > 0 ? (profit / p * 100).toFixed(2) + '%' : '0%';
        if (markupEl) markupEl.value = cost > 0 ? (profit / cost * 100).toFixed(2) + '%' : '0%';

        if (profitEl) profitEl.style.color = profit >= 0 ? 'var(--success)' : 'var(--danger)';
        if (marginEl) marginEl.style.color = profit >= 0 ? 'var(--success)' : 'var(--danger)';
        if (markupEl) markupEl.style.color = profit >= 0 ? 'var(--success)' : 'var(--danger)';
    }

    [price, amt, pct, add].forEach(function(el) { el.addEventListener('input', calc); });
    calc();
})();
</script>

{{-- ===== VENDOR TAB JS ===== --}}
<script>
document.addEventListener('DOMContentLoaded', function() {
    var vendorRows = [];
    var nextIdx = 0;

    var tbody = document.getElementById('cv-vendor-tbody');
    var emptyMsg = document.getElementById('cv-empty-msg');
    var searchInput = document.getElementById('cv-vendor-search');
    var vendorIdInput = document.getElementById('cv-vendor-id');
    var skuInput = document.getElementById('cv-vendor-sku');
    var minQtyInput = document.getElementById('cv-min-qty');
    var currencySelect = document.getElementById('cv-currency');
    var unitPriceInput = document.getElementById('cv-unit-price');
    var addBtn = document.getElementById('cv-add-btn');

    // ── Vendor typeahead (shared with edit page + PO pages, AJAX-backed) ──
    if (window.setupTypeahead) {
        window.setupTypeahead({
            inputId: 'cv-vendor-search',
            hiddenId: 'cv-vendor-id',
            listId: 'cv-vendor-list',
            url: '/api/vendors?q=',
            showAllOnFocus: true,
            onSelect: function(item) {
                if (item.currency_code) {
                    for (var ci = 0; ci < currencySelect.options.length; ci++) {
                        if (currencySelect.options[ci].value === item.currency_code) {
                            currencySelect.selectedIndex = ci;
                            break;
                        }
                    }
                }
            }
        });
    }

    // ── Restore from old('vendors') on validation failure ──
    @php
        $oldVendors = old('vendors', []);
    @endphp
    @if(!empty($oldVendors))
        var oldVendors = @json($oldVendors);
        for (var oi = 0; oi < oldVendors.length; oi++) {
            var ov = oldVendors[oi];
            appendVendorRow({
                vendor_id: parseInt(ov.vendor_id) || 0,
                vendor_name: ov.vendor_name || 'Vendor #' + ov.vendor_id,
                vendor_sku: ov.vendor_sku || '',
                min_qty: parseInt(ov.min_qty) || 1,
                currency_code: ov.currency_code || 'PHP',
                unit_price: parseFloat(ov.unit_price) || 0
            });
        }
    @endif

    // ── Add vendor row ──
    addBtn.addEventListener('click', function() {
        var vendorId = parseInt(vendorIdInput.value) || 0;
        if (!vendorId) {
            if (typeof showFlashError === 'function') showFlashError('Please select a vendor from the list.');
            return;
        }

        // Duplicate check
        for (var di = 0; di < vendorRows.length; di++) {
            if (vendorRows[di].vendor_id === vendorId) {
                if (typeof showFlashError === 'function') showFlashError('This vendor has already been added.');
                return;
            }
        }

        var vendorName = searchInput.value.trim();
        var vendorSku = skuInput.value.trim();
        var minQty = parseInt(minQtyInput.value) || 1;
        var currencyCode = currencySelect.value;
        var unitPrice = parseFloat(unitPriceInput.value) || 0;

        appendVendorRow({
            vendor_id: vendorId,
            vendor_name: vendorName,
            vendor_sku: vendorSku,
            min_qty: minQty,
            currency_code: currencyCode,
            unit_price: unitPrice
        });

        // Reset inputs
        searchInput.value = '';
        vendorIdInput.value = '';
        skuInput.value = '';
        minQtyInput.value = '1';
        unitPriceInput.value = '';
    });

    function appendVendorRow(data) {
        var idx = nextIdx++;
        vendorRows.push({ idx: idx, vendor_id: data.vendor_id });

        var tr = document.createElement('tr');
        tr.setAttribute('data-cv-idx', idx);

        // Vendor name cell
        var tdName = document.createElement('td');
        tdName.textContent = data.vendor_name;
        tr.appendChild(tdName);

        // Vendor SKU cell
        var tdSku = document.createElement('td');
        tdSku.textContent = data.vendor_sku || '\u2014';
        tr.appendChild(tdSku);

        // Min Qty cell
        var tdQty = document.createElement('td');
        tdQty.textContent = data.min_qty;
        tr.appendChild(tdQty);

        // Currency cell
        var tdCur = document.createElement('td');
        tdCur.textContent = data.currency_code;
        tr.appendChild(tdCur);

        // Unit Price cell
        var tdPrice = document.createElement('td');
        tdPrice.style.textAlign = 'right';
        tdPrice.textContent = parseFloat(data.unit_price).toFixed(4);
        tr.appendChild(tdPrice);

        // Remove button cell
        var tdAction = document.createElement('td');
        var removeBtn = document.createElement('button');
        removeBtn.type = 'button';
        removeBtn.className = 'btn danger small';
        removeBtn.textContent = 'Remove';
        removeBtn.addEventListener('click', function() {
            tr.remove();
            vendorRows = vendorRows.filter(function(r) { return r.idx !== idx; });
            toggleEmpty();
        });
        tdAction.appendChild(removeBtn);
        tr.appendChild(tdAction);

        // Hidden inputs for form submission
        var fields = {
            vendor_id: data.vendor_id,
            vendor_name: data.vendor_name,
            vendor_sku: data.vendor_sku,
            min_qty: data.min_qty,
            currency_code: data.currency_code,
            unit_price: data.unit_price
        };
        for (var key in fields) {
            var hidden = document.createElement('input');
            hidden.type = 'hidden';
            hidden.name = 'vendors[' + idx + '][' + key + ']';
            hidden.value = fields[key] != null ? fields[key] : '';
            tr.appendChild(hidden);
        }

        tbody.appendChild(tr);
        toggleEmpty();
    }

    function toggleEmpty() {
        emptyMsg.style.display = tbody.querySelectorAll('tr').length === 0 ? '' : 'none';
    }

    toggleEmpty();
});
</script>
@endpush
