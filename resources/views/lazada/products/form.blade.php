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
            <a class="btn" href="{{ route('lazada.products.index') }}">Back to Products</a>
            <a class="btn" href="{{ route('lazada.index') }}">Lazada API</a>
        </div>
    </div>

    @if(session('status'))
        <div class="alert success">{{ session('status') }}</div>
    @endif

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

    <div class="card">
        <form method="POST" action="{{ $mode === 'create' ? route('lazada.products.store') : route('lazada.products.update', $listing->id) }}">
            @csrf
            @if($mode === 'edit')
                @method('PUT')
            @endif

            <div style="display:grid; grid-template-columns: 2fr 1fr 1fr; gap: 12px; align-items:end;">
                <div>
                    <label>ERP Product</label>
                    <select name="product_id" class="input">
                        @foreach($products as $p)
                            @php
                                $nm = html_entity_decode((string)($p->name ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                                $label = '#'.$p->product_id.' — '.$nm;
                                if (!empty($p->sku)) $label .= ' ('.$p->sku.')';
                            @endphp
                            <option value="{{ $p->product_id }}" {{ (int)old('product_id', $listing->product_id) === (int)$p->product_id ? 'selected' : '' }}>
                                {{ $label }}
                            </option>
                        @endforeach
                    </select>
                    <div class="hint">This ERP product will be used later to generate Lazada payloads.</div>
                </div>
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
                        <a class="btn small" href="{{ route('lazada.brands.index') }}" target="_blank" rel="noopener">View Brands List</a>
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

                    <div class="hint" style="margin-top:8px;">
                        Tip: Click <strong>Fetch All Brands</strong> below to refresh the local brand cache.
                    </div>
                </div>
            </div>

            <div style="display:grid; grid-template-columns: 1fr 1fr 1fr; gap: 12px; margin-top:16px;">
                <div style="grid-column: span 1;">
                    <label>Price Markup — Fixed Amount</label>
                    <input type="number" name="markup_fixed" class="input" step="0.01" min="0"
                           value="{{ old('markup_fixed', $listing->markup_fixed) }}"
                           placeholder="e.g. 100">
                    <div class="hint">Flat amount added to price. Overrides profile markup if set.</div>
                </div>
                <div style="grid-column: span 1;">
                    <label>Price Markup — Percentage (%)</label>
                    <input type="number" name="markup_percent" class="input" step="0.01" min="0"
                           value="{{ old('markup_percent', $listing->markup_percent) }}"
                           placeholder="e.g. 5">
                    <div class="hint">% of product price added on top. Overrides profile markup if set.</div>
                </div>
                <div style="grid-column: span 1;">
                    @if($listing->lazada_profile_id)
                        @php
                            $profileMarkup = \App\Models\LazadaProfile::find($listing->lazada_profile_id);
                        @endphp
                        @if($profileMarkup)
                            <label>Profile Markup</label>
                            <div class="hint" style="margin-top:8px;">
                                @php
                                    $parts = [];
                                    if (!empty($profileMarkup->markup_fixed)) $parts[] = '+' . number_format((float)$profileMarkup->markup_fixed, 2);
                                    if (!empty($profileMarkup->markup_percent)) $parts[] = '+' . rtrim(rtrim(number_format((float)$profileMarkup->markup_percent, 2), '0'), '.') . '%';
                                @endphp
                                @if(!empty($parts))
                                    From profile <strong>{{ $profileMarkup->name }}</strong>: {{ implode(' & ', $parts) }}
                                @else
                                    Profile <strong>{{ $profileMarkup->name }}</strong> has no markup.
                                @endif
                                <br>Leave product markup empty to use profile markup.
                            </div>
                        @endif
                    @else
                        <label>&nbsp;</label>
                        <div class="hint" style="margin-top:8px;">No profile linked. Set markup here or leave empty.</div>
                    @endif
                </div>
            </div>

            <div class="d-flex justify-end gap-8 mt-16">
                <button class="btn" type="submit">{{ $mode === 'create' ? 'Create Listing' : 'Save Listing' }}</button>
            </div>
        </form>

        <form method="POST" action="{{ route('lazada.brands.fetch') }}" class="d-flex gap-8 justify-end items-center" style="margin-top:10px;">
            @csrf
            <button class="btn" type="submit">Fetch All Brands</button>
        </form>
    </div>

    @if($mode === 'edit')
        <div class="card mt-16">
            <div class="d-flex justify-between items-center gap-12 flex-wrap">
                <div>
                    <h3 class="section-title mt-0">Category Attributes</h3>
                    <div class="hint">Sync category attributes from Lazada, then fill values and save.</div>
                    @if($template && $template->fetched_at)
                        <div class="hint" style="margin-top:4px;">Last synced: {{ $template->fetched_at->format('Y-m-d H:i') }}</div>
                    @endif
                </div>
                <form method="POST" action="{{ route('lazada.products.template_sync', $listing->id) }}">
                    @csrf
                    <input type="hidden" name="primary_category_id" value="{{ (int)($listing->primary_category_id ?? 0) }}">
                    <button class="btn" type="submit" {{ empty($listing->primary_category_id) ? 'disabled' : '' }}>
                        {{ $template ? 'Re-sync Template' : 'Sync Template' }}
                    </button>
                </form>
            </div>

            @if(empty($listing->primary_category_id))
                <div class="alert warning mt-12">Set a <strong>Primary Category ID</strong> and save listing first, then sync template.</div>
            @elseif(!$template)
                <div class="alert warning mt-12">Template not yet synced. Click <strong>Sync Template</strong>.</div>
            @else
                <form method="POST" action="{{ route('lazada.products.attributes_save', $listing->id) }}" class="mt-12">
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
                                $val = old('attributes.'.$key, $saved[$key] ?? ($suggest ?? ''));
                                $isLocked = !empty($lockedKeys[$key] ?? null);
                                $options = $a['options'] ?? [];
                                $isSelect = is_array($options) && count($options) > 0;

                                if ($isSelect && $suggest !== null) {
                                    $suggestMatch = false;
                                    foreach ($options as $opt) {
                                        $optLabel = is_array($opt) ? (string)($opt['name'] ?? $opt['label'] ?? $opt['value'] ?? '') : (string)$opt;
                                        $optValue = is_array($opt) ? (string)($opt['value'] ?? $opt['id'] ?? $optLabel) : (string)$opt;
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
                                        <select class="input" name="attributes[{{ $key }}]" {{ $isLocked ? 'disabled' : '' }}>
                                            <option value="">-- Select --</option>
                                            @foreach($options as $opt)
                                                @php
                                                    $optLabel = is_array($opt) ? (string)($opt['name'] ?? $opt['label'] ?? $opt['value'] ?? '') : (string)$opt;
                                                    $optValue = is_array($opt) ? (string)($opt['value'] ?? $opt['id'] ?? $optLabel) : (string)$opt;
                                                @endphp
                                                <option value="{{ $optValue }}" {{ (string)$val === (string)$optValue ? 'selected' : '' }}>{{ $optLabel }}</option>
                                            @endforeach
                                        </select>
                                    @else
                                        <input class="input" name="attributes[{{ $key }}]" value="{{ $val }}" placeholder="Enter value" {{ $isLocked ? 'readonly' : '' }} {{ $isLocked ? 'disabled' : '' }}>
                                    @endif

                                    @if($isLocked)
                                        <div class="hint" style="margin-top:4px;">
                                            Sourced from ERP product details (edit in core Product page).
                                        </div>
                                    @endif

                                    @if(empty($saved[$key] ?? null) && empty(old('attributes.'.$key)) && !empty($suggest))
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
                <form method="POST" action="{{ route('lazada.products.variants_save', $listing->id) }}" class="mt-12">
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
                                    <td>{{ $v->price_prefix }}{{ $v->price }}</td>
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
                    <form method="POST" action="{{ route('lazada.products.payload_build', $listing->id) }}">
                        @csrf
                        <button class="btn" type="submit">Build Payload</button>
                    </form>
                    <form method="POST" action="{{ route('lazada.products.push_sample', $listing->id) }}" onsubmit="return confirm('Send a SAMPLE push to Lazada now? This is Phase 3 testing and may return QC errors.');">
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
                const url = new URL("{{ route('lazada.brands.autocomplete') }}", window.location.origin);
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
    </script>

@endsection
