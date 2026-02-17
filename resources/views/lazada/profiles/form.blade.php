@extends('layouts.app')
@section('breadcrumb', 'Marketplace / Lazada / Profiles / ' . ($mode === 'create' ? 'Add' : 'Edit'))

@section('title', $mode === 'create' ? 'Add Lazada Profile' : 'Edit Lazada Profile')

@section('content')
    <div class="page-header">
        <div>
            <h2>{{ $mode === 'create' ? 'Add Lazada Profile' : 'Edit Lazada Profile' }}</h2>
            <div class="text-muted text-sm">Map products to a Lazada category, brand, and shared attributes.</div>
        </div>
        <div class="page-header-actions">
            <a class="btn" href="{{ route('lazada.profiles.index') }}">Back to Profiles</a>
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
        <form method="POST" action="{{ $mode === 'create' ? route('lazada.profiles.store') : route('lazada.profiles.update', $profile->id) }}">
            @csrf
            @if($mode === 'edit')
                @method('PUT')
            @endif

            {{-- Row 1: Name --}}
            <div>
                <label>Profile Name <span style="color:#d11;">*</span></label>
                <input type="text" name="name" class="input" value="{{ old('name', $profile->name) }}" placeholder="e.g. Valeton Multi-FX" required>
            </div>

            {{-- Row 2: Product Filters --}}
            <h3 class="section-title" style="margin-top:20px;">Product Filter</h3>
            <div class="hint" style="margin-bottom:8px;">Leave both empty to include <strong>all</strong> products. Select one or more to narrow down. Hold Ctrl/Cmd to select multiple.</div>

            @php
                $selectedCatIds = old('catalog_category_ids', $profile->catalog_category_ids ?? []) ?? [];
                $selectedMfgIds = old('manufacturer_ids', $profile->manufacturer_ids ?? []) ?? [];
                $selectedCatIds = array_map('intval', (array)$selectedCatIds);
                $selectedMfgIds = array_map('intval', (array)$selectedMfgIds);
            @endphp

            <div style="display:grid; grid-template-columns: 1fr 1fr; gap: 12px;">
                <div>
                    <label>Categories</label>
                    <select name="catalog_category_ids[]" class="input" multiple size="6">
                        @foreach($catalogCategories as $c)
                            <option value="{{ $c->category_id }}" {{ in_array((int)$c->category_id, $selectedCatIds) ? 'selected' : '' }}>
                                {{ $c->name }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label>Manufacturers</label>
                    <select name="manufacturer_ids[]" class="input" multiple size="6">
                        @foreach(($manufacturers ?? collect()) as $m)
                            <option value="{{ $m->manufacturer_id }}" {{ in_array((int)$m->manufacturer_id, $selectedMfgIds) ? 'selected' : '' }}>
                                {{ $m->name }}
                            </option>
                        @endforeach
                    </select>
                </div>
            </div>
            <div class="hint" style="margin-top:6px;">
                Matching products: <strong>{{ $productCount }}</strong>
            </div>

            {{-- Row 3: Lazada Mapping --}}
            <h3 class="section-title" style="margin-top:20px;">Lazada Mapping</h3>

            <div style="display:grid; grid-template-columns: 1fr 1fr; gap: 12px;">
                <div>
                    <label>Lazada Category</label>
                    <select name="lazada_category_id" class="input">
                        <option value="">-- Select Lazada Category --</option>
                        @foreach(($lazadaCategories ?? collect()) as $c)
                            <option value="{{ (int)$c->category_id }}" {{ (string)old('lazada_category_id', $profile->lazada_category_id) === (string)$c->category_id ? 'selected' : '' }}>
                                {{ $c->name }} ({{ $c->category_id }})
                            </option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label>Brand</label>
                    <input type="hidden" name="brand_id" id="brandId" value="{{ old('brand_id', $profile->brand_id) }}">
                    @php
                        $noBrandDefault = old('no_brand');
                        if ($noBrandDefault === null) {
                            $noBrandDefault = (!empty($profile->brand_name_override) && strtolower(trim((string)$profile->brand_name_override)) === 'no brand') ? 1 : 0;
                        }
                    @endphp
                    <input type="hidden" name="no_brand" id="noBrandFlag" value="{{ $noBrandDefault ? 1 : 0 }}">

                    <input type="text" class="input" id="brandInput" list="brandList" autocomplete="off"
                           value="{{ ($noBrandDefault ? 'No Brand' : (old('brand_name') ?? ($selectedBrandName ?? ''))) }}"
                           placeholder="Type to search brand...">
                    <datalist id="brandList"></datalist>

                    <div class="hint d-flex gap-10 items-center flex-wrap" style="margin-top:6px;">
                        <span>Brand ID: <strong id="brandPickedId">{{ old('brand_id', $profile->brand_id) ?: '-' }}</strong></span>
                        <button type="button" class="btn small" id="setNoBrandBtn">No Brand</button>
                    </div>
                </div>
            </div>

            {{-- Row 4: Markup --}}
            <h3 class="section-title" style="margin-top:20px;">Price Markup</h3>
            <div class="hint" style="margin-bottom:8px;">Add markup to cover Lazada fees. Set one, both, or leave empty for no markup.</div>

            <div style="display:grid; grid-template-columns: 1fr 1fr; gap: 12px;">
                <div>
                    <label>Fixed Amount</label>
                    <input type="number" name="markup_fixed" class="input" step="0.01" min="0"
                           value="{{ old('markup_fixed', $profile->markup_fixed) }}"
                           placeholder="e.g. 100">
                    <div class="hint">Flat amount added to price.</div>
                </div>
                <div>
                    <label>Percentage (%)</label>
                    <input type="number" name="markup_percent" class="input" step="0.01" min="0"
                           value="{{ old('markup_percent', $profile->markup_percent) }}"
                           placeholder="e.g. 5">
                    <div class="hint">% of product price added on top.</div>
                </div>
            </div>

            <div class="d-flex justify-end gap-8 mt-16">
                <button class="btn" type="submit">{{ $mode === 'create' ? 'Create Profile' : 'Save Profile' }}</button>
            </div>
        </form>
    </div>

    @if($mode === 'edit')
        {{-- Category Attributes --}}
        <div class="card mt-16">
            <div class="d-flex justify-between items-center gap-12 flex-wrap">
                <div>
                    <h3 class="section-title mt-0">Category Attributes</h3>
                    <div class="hint">Sync the Lazada category template, then map shared attribute values.</div>
                    @if($template && $template->fetched_at)
                        <div class="hint" style="margin-top:4px;">Last synced: {{ $template->fetched_at->format('Y-m-d H:i') }}</div>
                    @endif
                </div>
                <form method="POST" action="{{ route('lazada.profiles.template_sync', $profile->id) }}">
                    @csrf
                    <input type="hidden" name="lazada_category_id" value="{{ (int)($profile->lazada_category_id ?? 0) }}">
                    <button class="btn" type="submit" {{ empty($profile->lazada_category_id) ? 'disabled' : '' }}>
                        {{ $template ? 'Re-sync Template' : 'Sync Template' }}
                    </button>
                </form>
            </div>

            @if(empty($profile->lazada_category_id))
                <div class="alert warning mt-12">Set a <strong>Lazada Category</strong> and save the profile first, then sync template.</div>
            @elseif(!$template)
                <div class="alert warning mt-12">Template not yet synced. Click <strong>Sync Template</strong>.</div>
            @else
                <form method="POST" action="{{ route('lazada.profiles.update', $profile->id) }}" class="mt-12">
                    @csrf
                    @method('PUT')

                    {{-- Carry over core fields --}}
                    <input type="hidden" name="name" value="{{ $profile->name }}">
                    @foreach(($profile->catalog_category_ids ?? []) as $cid)
                        <input type="hidden" name="catalog_category_ids[]" value="{{ $cid }}">
                    @endforeach
                    @foreach(($profile->manufacturer_ids ?? []) as $mid)
                        <input type="hidden" name="manufacturer_ids[]" value="{{ $mid }}">
                    @endforeach
                    <input type="hidden" name="lazada_category_id" value="{{ $profile->lazada_category_id }}">
                    <input type="hidden" name="brand_id" value="{{ $profile->brand_id }}">
                    <input type="hidden" name="no_brand" value="{{ $profile->brand_name_override ? 1 : 0 }}">
                    <input type="hidden" name="markup_fixed" value="{{ $profile->markup_fixed }}">
                    <input type="hidden" name="markup_percent" value="{{ $profile->markup_percent }}">

                    <div style="margin-top:6px;">
                        @foreach($attributes as $a)
                            @php
                                $key = (string)($a['key'] ?? '');
                                if (strtolower(trim($key)) === 'brand') { continue; }
                                $val = old('attributes.'.$key, $saved[$key] ?? '');
                                $options = $a['options'] ?? [];
                                $isSelect = is_array($options) && count($options) > 0;
                            @endphp
                            <div style="display:grid; grid-template-columns: 1fr 2fr; gap: 12px; padding: 10px 0; border-bottom: 1px solid var(--border-light); align-items:start;">
                                <div>
                                    <div class="font-semibold">
                                        {{ $a['name'] }}
                                        @if(!empty($a['required']))
                                            <span class="badge badge-red" style="margin-left:6px;">MANDATORY</span>
                                        @endif
                                    </div>
                                    <div class="hint" style="margin-top:4px;">{{ $key }}</div>
                                </div>
                                <div>
                                    @if($isSelect)
                                        <select class="input" name="attributes[{{ $key }}]">
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
                                        <input class="input" name="attributes[{{ $key }}]" value="{{ $val }}" placeholder="Enter value">
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

        {{-- Sync Products --}}
        <div class="card mt-16">
            <div class="d-flex justify-between items-center gap-12 flex-wrap">
                <div>
                    <h3 class="section-title mt-0">Sync Products</h3>
                    <div class="hint">Create Lazada listings for matching products. Already listed products are skipped. <strong>{{ $productCount }} product(s)</strong> match the filter.</div>
                </div>
                <form method="POST" action="{{ route('lazada.profiles.sync_products', $profile->id) }}" onsubmit="return confirm('Sync {{ $productCount }} matching product(s) to Lazada listings?');">
                    @csrf
                    <button class="btn" type="submit" {{ $profile->lazada_category_id ? '' : 'disabled' }}>Sync Products</button>
                </form>
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
                } catch (e) {}
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
