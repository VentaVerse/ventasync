@extends('layouts.app')

@section('title', $mode === 'create' ? 'Add Lazada Listing' : 'Edit Lazada Listing')

@section('content')
    <div class="page-head">
        <div>
            <h1>{{ $mode === 'create' ? 'Add Lazada Listing' : 'Edit Lazada Listing' }}</h1>
            <div class="muted">Phase 2: choose category + brand, sync category attributes, and fill required fields.</div>
        </div>
        <div class="page-actions" style="display:flex; gap:8px; align-items:center;">
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
        <div class="card-body">
            <form method="POST" action="{{ $mode === 'create' ? route('lazada.products.store') : route('lazada.products.update', $listing->id) }}">
                @csrf
                @if($mode === 'edit')
                    @method('PUT')
                @endif

                <div class="grid" style="grid-template-columns: 2fr 1fr 1fr; gap: 12px; align-items:end;">
                    <div>
                        <label class="label">ERP Product</label>
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
                        <div class="muted" style="font-size:12px; margin-top:4px;">This ERP product will be used later to generate Lazada payloads.</div>
                    </div>
                    <div>
                        <label class="label">Lazada Category <span style="color:#d11;">*</span></label>
                        <select name="primary_category_id" class="input">
                            <option value="">-- Select Category --</option>
                            @foreach(($categories ?? collect()) as $c)
                                <option value="{{ (int)$c->category_id }}" {{ (string)old('primary_category_id', $listing->primary_category_id) === (string)$c->category_id ? 'selected' : '' }}>
                                    {{ $c->name }} ({{ $c->category_id }})
                                </option>
                            @endforeach
                        </select>
                        <div class="muted" style="font-size:12px; margin-top:4px;">Choose the Lazada category first. Then sync the template to load mandatory attributes.</div>
                    </div>
                    <div>
                        <label class="label">Brand <span style="color:#d11;">*</span></label>
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

                        <div class="muted" style="font-size:12px; margin-top:6px; display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
                            <span id="brandPickedHint">Selected Brand ID: <strong id="brandPickedId">{{ old('brand_id', $listing->brand_id) ?: '-' }}</strong></span>
                            <button type="button" class="btn" id="setNoBrandBtn">Set as No Brand</button>
                            <a class="btn" href="{{ route('lazada.brands.index') }}" target="_blank" rel="noopener">View Brands List</a>
                        </div>

                        @if(!empty($productRow?->manufacturer_name))
                            <div class="muted" style="font-size:12px; margin-top:6px;">
                                Manufacturer: <strong>{{ $productRow->manufacturer_name }}</strong>
                                @if(!empty($brandSuggestion['brand_id']))
                                    <br>Suggested Lazada brand match: <strong>{{ $brandSuggestion['brand_name'] }}</strong> ({{ $brandSuggestion['brand_id'] }})
                                @else
                                    <br>No Lazada brand match found (fetch brands and try again). You can use <strong>Set as No Brand</strong> for testing.
                                @endif
                            </div>
                        @endif

                        <div class="muted" style="font-size:12px; margin-top:8px;">
                            Tip: Click <strong>Fetch All Brands</strong> below to refresh the local brand cache.
                        </div>
                    </div>
                </div>

                <div style="margin-top: 14px; display:flex; justify-content:flex-end; gap:8px;">
                    <button class="btn" type="submit">{{ $mode === 'create' ? 'Create Listing' : 'Save Listing' }}</button>
                </div>
            </form>

            <form method="POST" action="{{ route('lazada.brands.fetch') }}" style="margin-top:10px; display:flex; gap:8px; justify-content:flex-end; align-items:end;">
                @csrf
                <button class="btn" type="submit">Fetch All Brands</button>
            </form>
        </div>
    </div>

    @if($mode === 'edit')
        <div class="card" style="margin-top:14px;">
            <div class="card-body">
                <div style="display:flex; justify-content:space-between; align-items:center; gap:12px; flex-wrap:wrap;">
                    <div>
                        <h3 style="margin:0;">Category Attributes</h3>
                        <div class="muted" style="font-size:12px;">Sync category attributes from Lazada, then fill values and save.</div>
                        @if($template && $template->fetched_at)
                            <div class="muted" style="font-size:12px; margin-top:4px;">Last synced: {{ $template->fetched_at->format('Y-m-d H:i') }}</div>
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
                    <div class="alert" style="margin-top:10px;">Set a <strong>Primary Category ID</strong> and save listing first, then sync template.</div>
                @elseif(!$template)
                    <div class="alert" style="margin-top:10px;">Template not yet synced. Click <strong>Sync Template</strong>.</div>
                @else
                    <form method="POST" action="{{ route('lazada.products.attributes_save', $listing->id) }}" style="margin-top:12px;">
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

                                    // For select attributes, only auto-fill when the suggested value matches an option value.
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
                                <div style="display:grid; grid-template-columns: 1fr 2fr; gap: 12px; padding: 10px 0; border-bottom: 1px solid rgba(255,255,255,0.08); align-items:start;">
                                    <div>
                                        <div class="label" style="margin-bottom:0;">
                                            {{ $a['name'] }}
                                            @if(!empty($a['required']))
                                                <span style="display:inline-block; padding:2px 8px; border-radius:999px; background:#ffe8e8; color:#b30000; font-weight:700; font-size:12px; margin-left:6px;">MANDATORY</span>
                                            @endif
                                        </div>
                                        <div class="muted" style="font-size:12px; margin-top:4px;">Key: <strong>{{ $key }}</strong></div>
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
                                            <div class="muted" style="font-size:12px; margin-top:4px;">
                                                Sourced from ERP product details (edit in core Product page).
                                            </div>
                                        @endif

                                        @if(empty($saved[$key] ?? null) && empty(old('attributes.'.$key)) && !empty($suggest))
                                            <div class="muted" style="font-size:12px; margin-top:4px;">
                                                Suggested from ERP: <strong>{{ $suggest }}</strong>
                                            </div>
                                        @endif

                                        @if($errors->has('attributes.'.$key))
                                            <div style="color:#b30000; font-size:12px; margin-top:4px; font-weight:600;">
                                                {{ $errors->first('attributes.'.$key) }}
                                            </div>
                                        @endif
                                    </div>
                                </div>
                            @endforeach
                        </div>

                        <div style="margin-top: 14px; display:flex; justify-content:flex-end; gap:8px;">
                            <button class="btn" type="submit">Save Attributes</button>
                        </div>
                    </form>
                @endif
            </div>
        </div>

        <div class="card" style="margin-top:14px;">
            <div class="card-body">
                <div style="display:flex; justify-content:space-between; align-items:center; gap:12px; flex-wrap:wrap;">
                    <div>
                        <h3 style="margin:0;">Variant SKU Mapping</h3>
                        <div class="muted" style="font-size:12px;">Map ERP option rows to Lazada seller_sku / price / quantity. If left empty, defaults from ERP.</div>
                    </div>
                </div>

                @if(($variants ?? collect())->count() === 0)
                    <div class="alert" style="margin-top:10px;">No variants found for this product (product_option_value). If this is a single SKU product, you can proceed without variant mapping for now.</div>
                @else
                    <form method="POST" action="{{ route('lazada.products.variants_save', $listing->id) }}" style="margin-top:12px;">
                        @csrf
                        <div style="overflow:auto;">
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

                        <div style="margin-top: 14px; display:flex; justify-content:flex-end; gap:8px;">
                            <button class="btn" type="submit">Save Variant Mapping</button>
                        </div>
                    </form>
                @endif
            </div>
        </div>

        <div class="card" style="margin-top:14px;">
            <div class="card-body">
                <div style="display:flex; justify-content:space-between; align-items:center; gap:12px; flex-wrap:wrap;">
                    <div>
                        <h3 style="margin:0;">Build & Preview Payload</h3>
                        <div class="muted" style="font-size:12px;">This does not push to Lazada yet. It only builds a preview JSON for review.</div>
                    </div>
                    <div style="display:flex; gap:8px; align-items:center;">
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

                <div style="margin-top:12px;">
                    <label class="label">Preview JSON</label>
                    <textarea class="input" style="font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, 'Liberation Mono', 'Courier New', monospace; min-height: 260px;">{{ $payloadPreview ?? '' }}</textarea>
                    <div class="muted" style="font-size:12px; margin-top:6px;">Includes product weight/dimensions (PH), saved attributes, and variant mapping defaults.</div>
                </div>

                <div style="margin-top:14px;">
                    <label class="label">Last Push Request</label>
                    <textarea class="input" style="font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, 'Liberation Mono', 'Courier New', monospace; min-height: 200px;">{{ $pushRequest ?? '' }}</textarea>
                </div>

                <div style="margin-top:14px;">
                    <label class="label">Last Push Response</label>
                    <textarea class="input" style="font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, 'Liberation Mono', 'Courier New', monospace; min-height: 200px;">{{ $pushResponse ?? '' }}</textarea>
                    <div class="muted" style="font-size:12px; margin-top:6px;">QC errors (missing/invalid fields) will appear here. We'll use these errors to adjust mapping and payload.</div>
                </div>
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
                    // Not a known Lazada brand; keep as unselected.
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
