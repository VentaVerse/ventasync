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
                    <label>Reorder Level</label>
                    <input class="input" type="number" name="reorder_level" value="{{ old('reorder_level', 0) }}" min="0">
                    <div class="hint">Alert when stock falls to this level (0 = disabled)</div>
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

@endpush
