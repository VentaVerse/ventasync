@extends('layouts.app')
@section('breadcrumb', 'Catalog / Products / Edit')

@section('content')
<div class="card">
    <div class="page-header">
        <h2>Edit Product #{{ $product->product_id }}</h2>
        <div class="page-header-actions">
            {{-- Related actions contributed by installed extensions (Step 6 of decoupling refactor).
                 Core knows nothing about which extensions are installed; the IntegrationRegistry
                 walks ProductActionContributor implementations and aggregates their action lists. --}}
            @php
                $__productActions = app(\App\Integrations\IntegrationRegistry::class)
                    ->productActions((int) $product->product_id);
                $__user = auth()->user();
            @endphp
            @foreach($__productActions as $__action)
                @php
                    $__perm = $__action['permission'] ?? null;
                    $__visible = !$__perm || ($__user && $__user->hasPermission($__perm));
                @endphp
                @if($__visible)
                    <a class="btn secondary"
                       href="{{ $__action['url'] }}"
                       title="{{ $__action['label'] }}">
                        @if(!empty($__action['icon']) && $__action['icon'] === 'truck')
                            <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" style="vertical-align:-3px;margin-right:6px;"><rect x="1" y="3" width="15" height="13"/><polygon points="16 8 20 8 23 11 23 16 16 16 16 8"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/></svg>
                        @endif
                        {{ $__action['label'] }}
                    </a>
                @endif
            @endforeach
            <a class="btn secondary" href="{{ $returnUrl ?? route('products.index') }}">Back</a>
        </div>
    </div>

    <form method="POST" action="{{ route('products.update', $product->product_id) }}">
        @csrf
        @method('PUT')
        <input type="hidden" name="_return" value="{{ $returnUrl ?? route('products.index') }}">

        <div class="tabs mb-12">
            <button type="button" class="tab active" data-tab="prod-tab-general">General</button>
            <button type="button" class="tab" data-tab="prod-tab-options">Options</button>
        </div>

        {{-- ===== GENERAL TAB ===== --}}
        <div id="prod-tab-general">

            <div class="typeahead mt-16">
                <label>Manufacturer</label>
                <input class="input" id="manufacturer_search" placeholder="Type to search..." autocomplete="off" value="{{ old('manufacturer_name', $manufacturerName ?? '') }}" name="manufacturer_name">
                <input type="hidden" name="manufacturer_id" id="manufacturer_id" value="{{ old('manufacturer_id', $product->manufacturer_id) }}">
                <div class="typeahead-list" id="manufacturer_list"></div>
                <div class="hint">Start typing, then click a result. Required before uploading images.</div>
            </div>

            @include('products.partials.image_manager', ['pimToken' => '', 'pimProductId' => (int) $product->product_id])

            <h3 class="section-title">General</h3>

            <div class="form-grid">
                <div class="full">
                    <label class="required">Name</label>
                    <div class="input-group">
                        <span class="input-group-prefix" id="name_mfg_prefix" style="display:none;"></span>
                        <input class="input" name="name" id="product_name" value="{{ old('name', html_entity_decode($product->name ?? '')) }}">
                    </div>
                    @error('name')
                        <div class="field-error">{{ $message }}</div>
                    @enderror
                </div>

                <div>
                    <label class="required">Model</label>
                    <input class="input" name="model" value="{{ old('model', $product->model) }}">
                    @error('model')
                        <div class="field-error">{{ $message }}</div>
                    @enderror
                </div>

                <div>
                    <label class="required">SKU</label>
                    <input class="input" name="sku" value="{{ old('sku', $product->sku) }}">
                    @error('sku')
                        <div class="field-error">{{ $message }}</div>
                    @enderror
                </div>

                <div>
                    <label>Quantity</label>
                    <input class="input" id="product_quantity" name="quantity" value="{{ old('quantity', $product->quantity) }}">
                    <div class="hint" id="qty_hint" style="display:none;">Quantity is auto-calculated from option quantities.</div>
                    @php
                        $__inventoryBreakdown = null;
                        foreach (app(\App\Integrations\IntegrationRegistry::class)->productInventoryDetailContributors() as $__c) {
                            $__line = $__c->inventoryBreakdownFor((int) $product->product_id);
                            if ($__line !== null) { $__inventoryBreakdown = $__line; break; }
                        }
                    @endphp
                    @if($__inventoryBreakdown !== null)
                        <div class="text-xs text-secondary" style="margin-top:4px;">{{ $__inventoryBreakdown }}</div>
                    @endif
                </div>

                <div>
                    <label>Reorder Level</label>
                    <input class="input" type="number" name="reorder_level" value="{{ old('reorder_level', $product->reorder_level ?? 0) }}" min="0">
                    <div class="hint">Alert when stock falls to this level (0 = disabled)</div>
                </div>

                <div>
                    <label class="required">Status</label>
                    <select class="input" name="status">
                        <option value="1" {{ old('status', (string)$product->status)=='1'?'selected':'' }}>Enabled</option>
                        <option value="0" {{ old('status', (string)$product->status)=='0'?'selected':'' }}>Disabled</option>
                    </select>
                </div>

                <div class="typeahead full">
                    <label>Categories</label>
                    <div id="category_tags" class="tag-container">
                        @foreach(old('category_ids', collect($currentCategories ?? [])->pluck('id')->all()) as $catId)
                            @php
                                $catName = collect($currentCategories ?? [])->firstWhere('id', $catId)['name'] ?? 'Category #'.$catId;
                            @endphp
                            <span class="tag-chip" data-id="{{ $catId }}">
                                {{ $catName }}
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
                    <textarea class="input wysiwyg" name="description">{!! old('description', $product->description) !!}</textarea>
                </div>
            </div>

            <h3 class="section-title mt-24">Pricing & Cost</h3>

            <div class="form-grid mt-16">
                <div>
                    <label>Selling Price</label>
                    <input class="input" name="price" id="product_price" value="{{ old('price', $product->price) }}">
                </div>
            </div>

            <div class="cost-breakdown mt-16">
                <div class="cost-breakdown-label" style="display:flex; align-items:center; gap:8px;">
                    Cost Breakdown
                    <span id="cost-lock-badge" style="font-size:11px; padding:2px 8px; border-radius:4px; background:var(--surface-alt); color:#94a3b8;">Locked — updated on PO receive</span>
                    <button type="button" id="cost-override-btn" class="btn secondary small" style="font-size:11px; padding:2px 8px;">Override</button>
                </div>
                <div class="cost-breakdown-fields">
                    <div>
                        <label>Cost</label>
                        <input class="input" id="cost_amount" name="cost_amount" value="{{ old('cost_amount', $product->cost_amount ?? 0) }}" readonly tabindex="-1" style="background:var(--surface-alt);">
                        <div class="hint">Fixed amount</div>
                    </div>
                    <div class="cost-breakdown-op">and/or</div>
                    <div>
                        <label>Cost [%]</label>
                        <input class="input" id="cost_percentage" name="cost_percentage" value="{{ old('cost_percentage', $product->cost_percentage ?? 0) }}" readonly tabindex="-1" style="background:var(--surface-alt);">
                        <div class="hint">% of selling price</div>
                    </div>
                    <div class="cost-breakdown-op">+</div>
                    <div>
                        <label>Additional Cost</label>
                        <input class="input" id="cost_additional" name="cost_additional" value="{{ old('cost_additional', $product->cost_additional ?? 0) }}" readonly tabindex="-1" style="background:var(--surface-alt);">
                        <div class="hint">e.g. supplier shipping</div>
                    </div>
                    <div class="cost-breakdown-op">=</div>
                    <div>
                        <label>Product Cost</label>
                        <input class="input" id="product_cost" name="cost" value="{{ old('cost', $product->cost ?? 0) }}" readonly tabindex="-1" style="background:var(--surface-alt); font-weight:600;">
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

            <h3 class="section-title mt-24">Shipping & Dimensions</h3>

            <div class="form-grid mt-16">
                <div>
                    <label>Weight</label>
                    <input class="input" name="weight" value="{{ old('weight', $product->weight ?? '0') }}" placeholder="e.g. 0.5">
                    @error('weight') <div class="field-error">{{ $message }}</div> @enderror
                </div>
                <div>
                    <label>Length</label>
                    <input class="input" name="length" value="{{ old('length', $product->length ?? '0') }}" placeholder="e.g. 10">
                    @error('length') <div class="field-error">{{ $message }}</div> @enderror
                </div>
                <div>
                    <label>Width</label>
                    <input class="input" name="width" value="{{ old('width', $product->width ?? '0') }}" placeholder="e.g. 5">
                    @error('width') <div class="field-error">{{ $message }}</div> @enderror
                </div>
                <div>
                    <label>Height</label>
                    <input class="input" name="height" value="{{ old('height', $product->height ?? '0') }}" placeholder="e.g. 3">
                    @error('height') <div class="field-error">{{ $message }}</div> @enderror
                </div>
            </div>

        </div>

        {{-- ===== OPTIONS TAB ===== --}}
        <div id="prod-tab-options" class="hidden">
            @include('products.partials.options_fields')
        </div>

        <div class="d-flex gap-10 items-center justify-end mt-20">
            <button class="btn" type="submit">Update</button>
            <button class="btn danger" type="button" data-confirm="Delete this item?" data-confirm-submit="delete-form">Delete</button>
        </div>
    </form>

    <form id="delete-form" method="POST" action="{{ route('products.destroy', $product->product_id) }}" class="hidden">
        @csrf
        @method('DELETE')
    </form>
</div>

<script>
(function() {
    var tabs = ['prod-tab-general', 'prod-tab-options'];
    var tabBtns = document.querySelectorAll('.tabs .tab');

    function switchTo(tabId) {
        tabBtns.forEach(function(b) {
            b.classList.toggle('active', b.dataset.tab === tabId);
        });
        tabs.forEach(function(id) {
            document.getElementById(id).classList.toggle('hidden', id !== tabId);
        });
    }

    tabBtns.forEach(function(btn) {
        btn.addEventListener('click', function() { switchTo(btn.dataset.tab); });
    });

    // On validation error, switch to the first tab that contains an error
    @if($errors->any())
    var errorTab = null;
    var errorKeys = @json($errors->keys());
    var optionKeys = ['option_sku'];
    for (var i = 0; i < errorKeys.length; i++) {
        if (optionKeys.indexOf(errorKeys[i]) !== -1) { errorTab = 'prod-tab-options'; break; }
    }
    if (!errorTab) {
        // Check if any tab panel contains an error element
        for (var t = 0; t < tabs.length; t++) {
            if (document.getElementById(tabs[t]).querySelector('.alert.error, .alert.danger, .field-error')) {
                errorTab = tabs[t]; break;
            }
        }
    }
    if (errorTab) switchTo(errorTab);
    @endif
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
    var mirror = document.getElementById('product_cost_mirror');

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
        if (mirror) mirror.value = result;

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

    // Cost override toggle
    var overrideBtn = document.getElementById('cost-override-btn');
    var lockBadge = document.getElementById('cost-lock-badge');
    var costFields = [amt, pct, add];
    var unlocked = false;

    if (overrideBtn) {
        overrideBtn.addEventListener('click', function() {
            if (!unlocked) {
                confirmModal('Cost is normally updated when receiving a PO. Are you sure you want to edit manually?').then(function(ok) {
                    if (!ok) return;
                    costFields.forEach(function(el) {
                        el.removeAttribute('readonly');
                        el.removeAttribute('tabindex');
                        el.style.background = '';
                    });
                    lockBadge.textContent = 'Manual override';
                    lockBadge.style.background = 'var(--warning-bg, #fef3c7)';
                    lockBadge.style.color = 'var(--warning, #d97706)';
                    overrideBtn.textContent = 'Lock';
                    unlocked = true;
                });
            } else {
                costFields.forEach(function(el) {
                    el.setAttribute('readonly', true);
                    el.setAttribute('tabindex', '-1');
                    el.style.background = 'var(--surface-alt)';
                });
                lockBadge.textContent = 'Locked \u2014 updated on PO receive';
                lockBadge.style.background = 'var(--surface-alt)';
                lockBadge.style.color = '#94a3b8';
                overrideBtn.textContent = 'Override';
                unlocked = false;
            }
        });
    }
})();
</script>
@endpush
