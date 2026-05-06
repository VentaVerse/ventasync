@extends('layouts.app')
@section('breadcrumb', 'Marketplace / Lazada / Products / ' . ($mode === 'create' ? 'Add' : 'Edit'))

@section('title', $mode === 'create' ? 'Add Lazada Listing' : 'Edit Lazada Listing')

@section('content')
    <div class="page-header">
        <div>
            <h2>{{ $mode === 'create' ? 'Add Lazada Listing' : 'Edit Lazada Listing' }}</h2>
            <div class="text-muted text-sm">Phase 2: choose category + brand, sync category attributes, and fill required fields.</div>
        </div>
        <div class="page-header-actions">
            <a class="btn" href="{{ route('ext.lazada.products.index') }}">Back to Products</a>
            <a class="btn" href="{{ route('ext.lazada.index') }}">Lazada API</a>
        </div>
    </div>

    @if($errors->any())
        <div class="alert danger">
            <strong>Fix the following:</strong>
            <ul style="margin: 6px 0 0 18px;">
                @foreach($errors->all() as $e)
                    <li>{{ $e }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    @if($mode === 'edit' && !empty($productRow))
        <div class="card" style="margin-bottom:0; border-bottom-left-radius:0; border-bottom-right-radius:0;">
            <div class="d-flex items-center gap-12">
                @if(!empty($productImageUrl))
                    <img src="{{ $productImageUrl }}" alt="" class="thumb" style="width:72px; height:72px; flex-shrink:0;">
                @else
                    <div class="thumb" style="width:72px; height:72px; flex-shrink:0; display:flex; align-items:center; justify-content:center; color:var(--text-muted); font-size:12px;">No image</div>
                @endif
                <div style="min-width:0;">
                    <div class="font-bold" style="font-size:16px;">{{ html_entity_decode((string)($productRow->name ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8') ?: 'Unnamed Product' }}</div>
                    <div class="text-muted text-sm" style="margin-top:4px;">
                        @if(!empty($productRow->sku))SKU: <strong>{{ $productRow->sku }}</strong> @endif
                        @if(!empty($productRow->model))&middot; Model: <strong>{{ $productRow->model }}</strong> @endif
                        @if(isset($productRow->price))&middot; Price: <strong>{{ number_format((float)$productRow->price, 2) }}</strong> @endif
                    </div>
                    @php $linkedGroups = $listing->groups; @endphp
                    @if($linkedGroups->count() > 0)
                        <div class="text-muted text-sm" style="margin-top:2px;">
                            Product Group: <strong>{{ $linkedGroups->first()->name }}</strong>@if($linkedGroups->count() > 1) <span class="text-muted">(+{{ $linkedGroups->count() - 1 }})</span>@endif
                        </div>
                    @endif
                </div>
            </div>
        </div>
    @endif

    <div class="card" @if($mode === 'edit' && !empty($productRow)) style="border-top-left-radius:0; border-top-right-radius:0;" @endif>
        <form method="POST" action="{{ $mode === 'create' ? route('ext.lazada.products.store') : route('ext.lazada.products.update', $listing->id) }}">
            @csrf
            @if($mode === 'edit')
                @method('PUT')
            @endif

            @if($mode === 'create')
                <div>
                    <label>ERP Product <span style="color:#d11;">*</span></label>
                    <input type="hidden" name="product_id" id="lazSelectedProductId" value="{{ old('product_id', $listing->product_id) }}">
                    <div style="position:relative;">
                        <input type="text" class="input" id="lazProductSearch" placeholder="Search by name, SKU or product ID..." autocomplete="off" value="{{ old('_product_label', '') }}">
                        <div id="lazProductResults" style="display:none; position:absolute; z-index:60; top:100%; left:0; right:0; background:var(--surface); border:1px solid var(--border); border-radius:var(--radius-md); box-shadow:var(--shadow-lg); max-height:280px; overflow-y:auto; margin-top:4px;"></div>
                    </div>
                    <div id="lazSelectedProductInfo" style="display:none; margin-top:8px; padding:10px 14px; background:var(--bg-subtle, #f0f4f8); border-radius:var(--radius-md); border:1px solid var(--border-light);"></div>
                    <div class="hint" style="margin-top:4px;">Type at least 2 characters to search. This ERP product will be used to generate Lazada payloads.</div>
                </div>
            @endif

            <div style="display:grid; grid-template-columns: 1fr 1fr; gap: 12px; align-items:start;">
                <div>
                    <label>Lazada Category <span style="color:#d11;">*</span></label>
                    <select name="primary_category_id" class="input">
                        <option value="">-- Select Category --</option>
                        @foreach(($categories ?? collect()) as $c)
                            <option value="{{ (int)$c->category_id }}" {{ (string)old('primary_category_id', $listing->primary_category_id) === (string)$c->category_id ? 'selected' : '' }}>
                                {{ $c->name }} ({{ $c->category_id }})
                            </option>
                        @endforeach
                    </select>
                    <div class="hint">Choose the Lazada category first. Then sync the template to load mandatory attributes.</div>
                </div>
                <div>
                    <label>Brand <span style="color:#d11;">*</span></label>
                    <input type="hidden" name="brand_id" id="brandId" value="{{ old('brand_id', $listing->brand_id) }}">
                    @php
                        $noBrandDefault = old('no_brand');
                        if ($noBrandDefault === null) {
                            $noBrandDefault = (!empty($listing->brand_name_override) && strtolower(trim((string)$listing->brand_name_override)) === 'no brand') ? 1 : 0;
                        }
                    @endphp
                    <input type="hidden" name="no_brand" id="noBrandFlag" value="{{ $noBrandDefault ? 1 : 0 }}">

                    <input type="text" class="input" id="brandInput" list="brandList" autocomplete="off"
                           value="{{ ($noBrandDefault ? 'No Brand' : (old('brand_name') ?? ($selectedBrandName ?? ''))) }}"
                           placeholder="Type to search brand...">
                    <datalist id="brandList"></datalist>

                    <div class="hint d-flex gap-10 items-center flex-wrap" style="margin-top:6px;">
                        <span id="brandPickedHint">Selected Brand ID: <strong id="brandPickedId">{{ old('brand_id', $listing->brand_id) ?: '-' }}</strong></span>
                        <button type="button" class="btn small" id="setNoBrandBtn">Set as No Brand</button>
                        <a class="btn small" href="{{ route('ext.lazada.brands.index') }}" target="_blank" rel="noopener">View Brands List</a>
                    </div>

                    @if(!empty($productRow?->manufacturer_name))
                        <div class="hint" style="margin-top:6px;">
                            Manufacturer: <strong>{{ $productRow->manufacturer_name }}</strong>
                            @if(!empty($brandSuggestion['brand_id']))
                                <br>Suggested Lazada brand match: <strong>{{ $brandSuggestion['brand_name'] }}</strong> ({{ $brandSuggestion['brand_id'] }})
                            @else
                                <br>No Lazada brand match found (fetch brands and try again). You can use <strong>Set as No Brand</strong> for testing.
                            @endif
                        </div>
                    @endif
                </div>
            </div>

            @php
                $groupMarkup = $listing->groups->first();
                $effectiveFixed = old('markup_fixed', $listing->markup_fixed ?? ($groupMarkup->markup_fixed ?? null));
                $effectivePercent = old('markup_percent', $listing->markup_percent ?? ($groupMarkup->markup_percent ?? null));
            @endphp

            <h3 class="section-title" style="margin-top:20px;">Price Markup</h3>
            <div class="hint" style="margin-bottom:8px;">Formula: <strong>(Product Price &times; %) + Product Price + Fixed Amount</strong></div>

            <div style="display:grid; grid-template-columns: 1fr 1fr; gap: 12px;">
                <div>
                    <label>Fixed Amount</label>
                    <input type="number" name="markup_fixed" class="input" step="0.01" min="0"
                           id="markupFixed"
                           value="{{ $effectiveFixed }}"
                           placeholder="e.g. 100">
                    <div class="hint">Flat amount added after percentage.</div>
                </div>
                <div>
                    <label>Percentage (%)</label>
                    <input type="number" name="markup_percent" class="input" step="0.01" min="0"
                           id="markupPercent"
                           value="{{ $effectivePercent }}"
                           placeholder="e.g. 5">
                    <div class="hint">% of product price applied first.</div>
                </div>
            </div>

            @if($groupMarkup)
                <div class="hint" style="margin-top:8px;">
                    @php
                        $parts = [];
                        if (!empty($groupMarkup->markup_fixed)) $parts[] = '+' . number_format((float)$groupMarkup->markup_fixed, 2);
                        if (!empty($groupMarkup->markup_percent)) $parts[] = '+' . rtrim(rtrim(number_format((float)$groupMarkup->markup_percent, 2), '0'), '.') . '%';
                    @endphp
                    @if(!empty($parts))
                        From product group <strong>{{ $groupMarkup->name }}</strong>: {{ implode(' & ', $parts) }}.
                    @endif
                    Changing the values above overrides the product group.
                </div>
            @endif

            @if($mode === 'edit' && isset($productRow->price))
                <div id="totalPriceBox" style="margin-top:12px; padding:10px 14px; background:var(--bg-subtle, #f0f4f8); border-left:3px solid var(--accent, #3b82f6); border-radius:3px;">
                    <span style="font-size:13px;">Total Price (base + markup):</span>
                    <strong style="font-size:16px; margin-left:6px;" id="totalPriceValue">—</strong>
                    <span class="hint" style="margin-left:8px;">Base: {{ number_format((float)$productRow->price, 2) }}</span>
                </div>
            @endif

            @if($mode === 'create')
                <div class="alert" style="margin-top:16px; background:var(--accent-light, #eff6ff); border:1px solid rgba(59,130,246,.2); border-radius:var(--radius-md); padding:12px 16px;">
                    <strong>Next step:</strong> Click <strong>Create Listing</strong> to save, then you can sync the category template and fill required attributes.
                </div>
            @endif

            <div class="d-flex justify-end gap-8 mt-16">
                <button class="btn" type="submit">{{ $mode === 'create' ? 'Create Listing' : 'Save Listing' }}</button>
            </div>
        </form>
    </div>

    @if($mode === 'edit')
        <div class="card mt-16">
            <h3 class="section-title mt-0">Category Attributes</h3>
            <div class="hint">Sync category attributes from Lazada, then fill values and save.</div>
            @if($template && $template->fetched_at)
                <div class="hint" style="margin-top:4px;">Last synced: {{ $template->fetched_at->format('Y-m-d H:i') }}</div>
            @endif

            @if(empty($listing->primary_category_id))
                <div class="alert warning mt-12">Set a <strong>Primary Category ID</strong> and save listing first, then sync template.</div>
            @elseif(!$template)
                <div class="alert warning mt-12">Template not yet synced. Click <strong>Sync Template</strong> below.</div>
            @else
                <form method="POST" action="{{ route('ext.lazada.products.attributes_save', $listing->id) }}" class="mt-12">
                    @csrf
                    <div style="margin-top:6px;">
                        @foreach($attributes as $a)
                            @php
                                $key = (string)($a['key'] ?? '');
                                if (strtolower(trim($key)) === 'brand') { continue; }
                            @endphp
                            @php
                                $key = (string)$a['key'];
                                $suggest = $suggested[$key] ?? null;
                                $rawSaved = $saved[$key] ?? '';
                                $isFromGroup = !array_key_exists($key, ($productOwnAttrs ?? [])) && array_key_exists($key, ($groupAttrs ?? []));
                                $val = old('attributes.'.$key, $saved[$key] ?? ($suggest ?? ''));
                                $options = $a['options'] ?? [];
                                $isSelect = is_array($options) && count($options) > 0;

                                if ($isSelect && $suggest !== null) {
                                    $suggestMatch = false;
                                    foreach ($options as $opt) {
                                        $optLabel = is_array($opt) ? (string)($opt['name'] ?? $opt['label'] ?? $opt['value'] ?? '') : (string)$opt;
                                        $optValue = is_array($opt) ? (string)($opt['name'] ?? $opt['label'] ?? $opt['value'] ?? '') : (string)$opt;
                                        if ((string)$suggest === (string)$optValue) { $suggestMatch = true; break; }
                                    }
                                    if (!$suggestMatch && !isset($saved[$key]) && !old('attributes.'.$key)) {
                                        $val = '';
                                    }
                                }
                            @endphp
                            <div style="display:grid; grid-template-columns: 1fr 2fr; gap: 12px; padding: 10px 0; border-bottom: 1px solid var(--border-light); align-items:start;">
                                <div>
                                    <div class="font-semibold">
                                        {{ $a['name'] }}
                                        @if(!empty($a['required']))
                                            <span class="badge badge-red" style="margin-left:6px;">MANDATORY</span>
                                        @endif
                                    </div>
                                    <div class="hint" style="margin-top:4px;">Key: <strong>{{ $key }}</strong></div>
                                </div>

                                <div>
                                    @if($isSelect)
                                        <select class="input" name="attributes[{{ $key }}]">
                                            <option value="">-- Select --</option>
                                            @foreach($options as $opt)
                                                @php
                                                    $optLabel = is_array($opt) ? (string)($opt['name'] ?? $opt['label'] ?? $opt['value'] ?? '') : (string)$opt;
                                                    $optValue = is_array($opt) ? (string)($opt['name'] ?? $opt['label'] ?? $opt['value'] ?? '') : (string)$opt;
                                                @endphp
                                                <option value="{{ $optValue }}" {{ (string)$val === (string)$optValue ? 'selected' : '' }}>{{ $optLabel }}</option>
                                            @endforeach
                                        </select>
                                    @else
                                        @php
                                            $isMapVal = str_starts_with((string)$rawSaved, '__map:');
                                            $mapField = $isMapVal ? substr((string)$rawSaved, 6) : '';
                                            $manualVal = $isMapVal ? '' : $val;
                                            $resolvedRaw = ($isMapVal && isset($suggested[$key])) ? $suggested[$key] : null;
                                            $resolvedVal = $resolvedRaw !== null ? mb_substr(strip_tags(html_entity_decode((string)$resolvedRaw, ENT_QUOTES | ENT_HTML5, 'UTF-8')), 0, 200) : null;
                                        @endphp
                                        <div class="attr-row" style="display:grid; grid-template-columns: 1fr 1fr; gap: 8px;">
                                            <select class="input attr-mode-toggle">
                                                <option value="manual" {{ !$isMapVal ? 'selected' : '' }}>Manual Input</option>
                                                <option value="product_field" {{ $isMapVal ? 'selected' : '' }}>Product Field</option>
                                            </select>
                                            <input class="input attr-manual-input" value="{{ $manualVal }}" placeholder="Enter value" style="{{ $isMapVal ? 'display:none;' : '' }}">
                                            <select class="input attr-field-select" style="{{ !$isMapVal ? 'display:none;' : '' }}">
                                                <option value="">-- Select Product Field --</option>
                                                @foreach($erpSourceFields as $erpKey => $erpLabel)
                                                    <option value="{{ $erpKey }}" {{ $isMapVal && $mapField === $erpKey ? 'selected' : '' }}>{{ $erpLabel }}</option>
                                                @endforeach
                                            </select>
                                            <input type="hidden" name="attributes[{{ $key }}]" class="attr-hidden-value" value="{{ $isMapVal ? $rawSaved : $val }}">
                                            <div class="attr-resolved-value" style="grid-column: span 2; margin-top:4px; padding:6px 10px; background:var(--bg-subtle, #f0f4f8); border-left:3px solid var(--accent, #3b82f6); border-radius:3px; font-size:13px; {{ $isMapVal ? '' : 'display:none;' }}">
                                                Current Value: <strong class="attr-resolved-text">{{ $resolvedVal ?? '' }}</strong>
                                            </div>
                                        </div>
                                    @endif

                                    @if($isFromGroup)
                                        <div class="hint" style="margin-top:4px; color: var(--accent);">Inherited from product group — save to override.</div>
                                    @endif

                                    @if(!$isSelect && !($isMapVal ?? false) && empty($saved[$key] ?? null) && empty(old('attributes.'.$key)) && !empty($suggest))
                                        <div class="hint" style="margin-top:4px;">
                                            Suggested from ERP: <strong>{{ $suggest }}</strong>
                                        </div>
                                    @endif

                                    @if($errors->has('attributes.'.$key))
                                        <div class="field-error" style="margin-top:4px;">
                                            {{ $errors->first('attributes.'.$key) }}
                                        </div>
                                    @endif
                                </div>
                            </div>
                        @endforeach
                    </div>

                    <div class="d-flex justify-end gap-8 mt-16">
                        <button class="btn" type="submit">Save Attributes</button>
                    </div>
                </form>
            @endif
        </div>

        {{-- Re-Sync Template (bottom) --}}
        <div class="card mt-16">
            <div class="d-flex justify-between items-center gap-12 flex-wrap">
                <div>
                    <h3 class="section-title mt-0">{{ $template ? 'Re-Sync Template' : 'Sync Template' }}</h3>
                    <div class="hint">
                        @if($template)
                            Only use this if Lazada has updated their attributes for this category.
                            If everything is working fine, there is no need to re-sync.
                        @else
                            Fetch the category attribute template from Lazada so you can map attribute values above.
                        @endif
                    </div>
                    @if($template && $template->fetched_at)
                        <div class="hint" style="margin-top:4px;">Last synced: {{ $template->fetched_at->format('Y-m-d H:i') }}</div>
                    @endif
                </div>
                <form method="POST" action="{{ route('ext.lazada.products.template_sync', $listing->id) }}">
                    @csrf
                    <input type="hidden" name="primary_category_id" value="{{ (int)($listing->primary_category_id ?? 0) }}">
                    <button class="btn" type="submit" {{ empty($listing->primary_category_id) ? 'disabled' : '' }}>
                        {{ $template ? 'Re-sync Template' : 'Sync Template' }}
                    </button>
                </form>
            </div>
        </div>

        <div class="card mt-16">
            <div class="d-flex justify-between items-center gap-12 flex-wrap">
                <div>
                    <h3 class="section-title mt-0">Variant SKU Mapping</h3>
                    <div class="hint">Map ERP option rows to Lazada seller_sku / price / quantity. If left empty, defaults from ERP.</div>
                </div>
            </div>

            @if(($variants ?? collect())->count() === 0)
                <div class="alert warning mt-12">No variants found for this product (product_option_value). If this is a single SKU product, you can proceed without variant mapping for now.</div>
            @else
                <form method="POST" action="{{ route('ext.lazada.products.variants_save', $listing->id) }}" class="mt-12">
                    @csrf
                    <div class="table-wrap">
                        <table class="table" style="min-width:900px;">
                            <thead>
                            <tr>
                                <th style="width:240px;">ERP Variant</th>
                                <th style="width:160px;">ERP SKU</th>
                                <th style="width:120px;">ERP Price</th>
                                <th style="width:120px;">ERP Qty</th>
                                <th style="width:180px;">Lazada Seller SKU</th>
                                <th style="width:140px;">Lazada Price</th>
                                <th style="width:140px;">Lazada Qty</th>
                            </tr>
                            </thead>
                            <tbody>
                            @foreach($variants as $v)
                                @php
                                    $k = (string)$v->product_option_value_id;
                                    $m = $variantMap[$k] ?? null;
                                    $erpLabel = trim((string)($v->option_name ?? '')) !== ''
                                        ? ((string)$v->option_name).': '.((string)($v->option_value_name ?? ''))
                                        : ('Option #'.($v->option_id ?? '').' = '.($v->option_value_id ?? ''));
                                @endphp
                                <tr>
                                    <td>{{ $erpLabel }}</td>
                                    <td>{{ $v->sku }}</td>
                                    <td>{{ number_format((float)($v->absolute_price ?? 0), 2) }}</td>
                                    <td>{{ $v->quantity }}</td>
                                    <td>
                                        <input class="input" name="variants[{{ $v->product_option_value_id }}][seller_sku]" value="{{ old('variants.'.$v->product_option_value_id.'.seller_sku', $m?->seller_sku) }}" placeholder="Optional override">
                                    </td>
                                    <td>
                                        <input class="input" name="variants[{{ $v->product_option_value_id }}][price]" value="{{ old('variants.'.$v->product_option_value_id.'.price', $m?->price) }}" placeholder="Optional override">
                                    </td>
                                    <td>
                                        <input class="input" name="variants[{{ $v->product_option_value_id }}][quantity]" value="{{ old('variants.'.$v->product_option_value_id.'.quantity', $m?->quantity) }}" placeholder="Optional override">
                                    </td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>

                    <div class="d-flex justify-end gap-8 mt-16">
                        <button class="btn" type="submit">Save Variant Mapping</button>
                    </div>
                </form>
            @endif
        </div>

        <div class="card mt-16">
            <div class="d-flex justify-between items-center gap-12 flex-wrap">
                <div>
                    <h3 class="section-title mt-0">Build & Preview Payload</h3>
                    <div class="hint">This does not push to Lazada yet. It only builds a preview JSON for review.</div>
                </div>
                <div class="d-flex gap-8 items-center">
                    <form method="POST" action="{{ route('ext.lazada.products.payload_build', $listing->id) }}">
                        @csrf
                        <button class="btn" type="submit">Build Payload</button>
                    </form>
                    <form method="POST" action="{{ route('ext.lazada.products.push_sample', $listing->id) }}" data-confirm="Send a SAMPLE push to Lazada now? This is Phase 3 testing and may return QC errors.">
                        @csrf
                        <button class="btn" type="submit">Push Sample to Lazada</button>
                    </form>
                </div>
            </div>

            <div class="mt-12">
                <label>Preview JSON</label>
                <textarea class="input" style="font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, 'Liberation Mono', 'Courier New', monospace; min-height: 260px;">{{ $payloadPreview ?? '' }}</textarea>
                <div class="hint">Includes product weight/dimensions (PH), saved attributes, and variant mapping defaults.</div>
            </div>

            <div class="mt-16">
                <label>Last Push Request</label>
                <textarea class="input" style="font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, 'Liberation Mono', 'Courier New', monospace; min-height: 200px;">{{ $pushRequest ?? '' }}</textarea>
            </div>

            <div class="mt-16">
                <label>Last Push Response</label>
                <textarea class="input" style="font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, 'Liberation Mono', 'Courier New', monospace; min-height: 200px;">{{ $pushResponse ?? '' }}</textarea>
                <div class="hint">QC errors (missing/invalid fields) will appear here. We'll use these errors to adjust mapping and payload.</div>
            </div>
        </div>
    @endif

    <script>
        (function () {
            const input = document.getElementById('brandInput');
            const list = document.getElementById('brandList');
            const brandId = document.getElementById('brandId');
            const noBrand = document.getElementById('noBrandFlag');
            const btnNoBrand = document.getElementById('setNoBrandBtn');
            const pickedIdEl = document.getElementById('brandPickedId');
            if (!input || !list || !brandId || !noBrand || !pickedIdEl) return;

            let lastQuery = '';

            function setPicked(id) {
                pickedIdEl.textContent = id ? String(id) : '-';
            }

            async function fetchSuggestions(q) {
                const url = new URL("{{ route('ext.lazada.brands.autocomplete') }}", window.location.origin);
                url.searchParams.set('q', q);
                url.searchParams.set('limit', '20');

                const res = await fetch(url.toString(), { headers: { 'Accept': 'application/json' } });
                if (!res.ok) return [];
                const json = await res.json();
                return Array.isArray(json.items) ? json.items : [];
            }

            function renderList(rows) {
                list.innerHTML = '';
                for (const r of rows) {
                    const opt = document.createElement('option');
                    opt.value = r.name;
                    opt.dataset.id = String(r.brand_id);
                    list.appendChild(opt);
                }
            }

            input.addEventListener('input', async function () {
                const q = (this.value || '').trim();
                if (q === '' || q.toLowerCase() === 'no brand') return;
                if (q === lastQuery) return;
                lastQuery = q;

                try {
                    const items = await fetchSuggestions(q);
                    renderList(items);
                } catch (e) {
                    // ignore
                }
            });

            input.addEventListener('change', function () {
                const v = (this.value || '').trim();

                if (v.toLowerCase() === 'no brand') {
                    brandId.value = '';
                    noBrand.value = '1';
                    setPicked('');
                    return;
                }

                const match = Array.from(list.options).find(o => (o.value || '').trim() === v);
                if (match && match.dataset && match.dataset.id) {
                    brandId.value = match.dataset.id;
                    noBrand.value = '0';
                    setPicked(match.dataset.id);
                } else {
                    brandId.value = '';
                    noBrand.value = '0';
                    setPicked('');
                }
            });

            if (btnNoBrand) {
                btnNoBrand.addEventListener('click', function () {
                    input.value = 'No Brand';
                    brandId.value = '';
                    noBrand.value = '1';
                    setPicked('');
                });
            }

            setPicked(brandId.value);
        })();

        // ERP product field values for live preview (plain text only — HTML stripped)
        @php
            $erpFieldValuesJson = $productRow ? [
                'name' => $productRow->name ?? '',
                'description' => $productRow->description ? strip_tags(html_entity_decode((string)$productRow->description, ENT_QUOTES | ENT_HTML5, 'UTF-8')) : '',
                'meta_title' => $productRow->meta_title ?? '',
                'meta_description' => $productRow->meta_description ?? '',
                'model' => $productRow->model ?? '',
                'sku' => $productRow->sku ?? '',
                'upc' => $productRow->upc ?? '',
                'ean' => $productRow->ean ?? '',
                'jan' => $productRow->jan ?? '',
                'isbn' => $productRow->isbn ?? '',
                'mpn' => $productRow->mpn ?? '',
                'weight' => (string)($productRow->weight ?? ''),
                'length' => (string)($productRow->length ?? ''),
                'width' => (string)($productRow->width ?? ''),
                'height' => (string)($productRow->height ?? ''),
                'price' => (string)($productRow->price ?? ''),
                'quantity' => (string)($productRow->quantity ?? ''),
            ] : [];
        @endphp
        var erpFieldValues = @json($erpFieldValuesJson);

        // Attribute mode toggle (Manual Input ↔ Product Field)
        document.querySelectorAll('.attr-row').forEach(function(row) {
            var toggle = row.querySelector('.attr-mode-toggle');
            var manual = row.querySelector('.attr-manual-input');
            var fieldSelect = row.querySelector('.attr-field-select');
            var hidden = row.querySelector('.attr-hidden-value');
            var resolvedBox = row.querySelector('.attr-resolved-value');
            var resolvedText = row.querySelector('.attr-resolved-text');
            if (!toggle || !manual || !fieldSelect || !hidden) return;

            function updateResolved() {
                if (!resolvedBox || !resolvedText) return;
                if (toggle.value === 'product_field' && fieldSelect.value) {
                    var val = erpFieldValues[fieldSelect.value] || '';
                    var display = val || '(empty)';
                    if (display.length > 200) display = display.substring(0, 200) + '...';
                    resolvedText.textContent = display;
                    resolvedBox.style.display = '';
                } else {
                    resolvedBox.style.display = 'none';
                }
            }

            function updateHidden() {
                if (toggle.value === 'product_field') {
                    var f = fieldSelect.value;
                    hidden.value = f ? '__map:' + f : '';
                } else {
                    hidden.value = manual.value;
                }
                updateResolved();
            }

            toggle.addEventListener('change', function() {
                if (this.value === 'product_field') {
                    manual.style.display = 'none';
                    fieldSelect.style.display = '';
                } else {
                    manual.style.display = '';
                    fieldSelect.style.display = 'none';
                }
                updateHidden();
            });

            manual.addEventListener('input', updateHidden);
            fieldSelect.addEventListener('change', updateHidden);
        });

        // Live total price calculation
        (function() {
            var fixedInput = document.getElementById('markupFixed');
            var percentInput = document.getElementById('markupPercent');
            var totalEl = document.getElementById('totalPriceValue');
            var basePrice = {{ isset($productRow->price) ? (float)$productRow->price : 0 }};
            if (!totalEl) return;

            function recalc() {
                var fixed = parseFloat(fixedInput ? fixedInput.value : 0) || 0;
                var pct = parseFloat(percentInput ? percentInput.value : 0) || 0;
                // Formula: (Price * %) + Fixed
                var total = basePrice;
                if (pct > 0) total += basePrice * pct / 100;
                if (fixed > 0) total += fixed;
                totalEl.textContent = total.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
            }

            if (fixedInput) fixedInput.addEventListener('input', recalc);
            if (percentInput) percentInput.addEventListener('input', recalc);
            recalc();
        })();
    </script>

    @if($mode === 'create')
    <script>
    (function(){
        var searchInput = document.getElementById('lazProductSearch');
        var resultsDiv = document.getElementById('lazProductResults');
        var hiddenId = document.getElementById('lazSelectedProductId');
        var infoDiv = document.getElementById('lazSelectedProductInfo');
        var searchUrl = @json(route('ext.lazada.products.search_catalog'));
        var debounceTimer = null;

        if (!searchInput || !resultsDiv || !hiddenId) return;

        searchInput.addEventListener('input', function() {
            var q = searchInput.value.trim();
            if (q.length < 2) { resultsDiv.style.display = 'none'; resultsDiv.innerHTML = ''; return; }

            clearTimeout(debounceTimer);
            debounceTimer = setTimeout(function() {
                fetch(searchUrl + '?q=' + encodeURIComponent(q))
                    .then(function(r) { return r.json(); })
                    .then(function(items) {
                        if (!items.length) {
                            resultsDiv.innerHTML = '<div style="padding:10px 12px; font-size:13px; color:var(--text-muted);">No products found</div>';
                            resultsDiv.style.display = 'block';
                            return;
                        }
                        resultsDiv.innerHTML = items.map(function(item) {
                            var imgHtml = item.image ? '<img src="' + item.image + '" alt="">' : '<div style="width:32px;height:32px;background:var(--border-light);border-radius:3px;flex-shrink:0;"></div>';
                            var optsHtml = '';
                            if (item.options && item.options.length) {
                                optsHtml = item.options.map(function(o) {
                                    return '<span style="font-size:10px; color:var(--text-muted);">' + (o.name ? o.name + ' - ' : '') + o.sku + '</span>';
                                }).join(', ');
                                optsHtml = '<div style="margin-top:1px;">' + optsHtml + '</div>';
                            }
                            return '<div class="product-result-item" data-pid="' + item.product_id + '" data-name="' + item.name.replace(/"/g, '&quot;') + '" data-sku="' + (item.sku || '') + '" data-image="' + (item.image || '') + '">'
                                + imgHtml
                                + '<div style="flex:1; min-width:0;">'
                                + '<div class="pr-name">' + item.name + '</div>'
                                + '<div class="pr-meta">SKU: ' + (item.sku || '-') + ' | ID: ' + item.product_id + '</div>'
                                + optsHtml
                                + '</div></div>';
                        }).join('');
                        resultsDiv.style.display = 'block';
                    })
                    .catch(function() { resultsDiv.style.display = 'none'; });
            }, 300);
        });

        resultsDiv.addEventListener('click', function(e) {
            var item = e.target.closest('.product-result-item');
            if (!item) return;
            var pid = item.dataset.pid;
            var name = item.dataset.name;
            var sku = item.dataset.sku;
            var image = item.dataset.image;

            hiddenId.value = pid;
            searchInput.value = name + ' (#' + pid + ')';
            resultsDiv.style.display = 'none';

            if (infoDiv) {
                var infoHtml = '<div class="d-flex items-center gap-8">';
                if (image) infoHtml += '<img src="' + image + '" alt="" style="width:48px; height:48px; object-fit:cover; border-radius:4px; flex-shrink:0;">';
                infoHtml += '<div><div class="font-bold">' + name + '</div>';
                infoHtml += '<div class="text-muted text-xs">' + (sku ? 'SKU: ' + sku + ' ' : '') + 'ID: ' + pid + '</div>';
                infoHtml += '</div></div>';
                infoDiv.innerHTML = infoHtml;
                infoDiv.style.display = 'block';
            }
        });

        document.addEventListener('click', function(e) {
            if (!searchInput.contains(e.target) && !resultsDiv.contains(e.target)) {
                resultsDiv.style.display = 'none';
            }
        });

        searchInput.addEventListener('focus', function() {
            if (hiddenId.value) {
                searchInput.value = '';
                hiddenId.value = '';
                if (infoDiv) infoDiv.style.display = 'none';
            }
        });
    })();
    </script>
    @endif

@endsection
