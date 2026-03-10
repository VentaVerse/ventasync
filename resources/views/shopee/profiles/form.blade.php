@extends('layouts.app')
@section('breadcrumb', 'Marketplace / Shopee / Profiles / ' . ($mode === 'create' ? 'Add' : 'Edit'))

@section('title', $mode === 'create' ? 'Add Shopee Profile' : 'Edit Shopee Profile')

@section('content')
    <div class="page-header">
        <div>
            <h2>{{ $mode === 'create' ? 'Add Shopee Profile' : 'Edit Shopee Profile' }}</h2>
            <div class="text-muted text-sm">Map products to a Shopee category and shared attributes.</div>
        </div>
        <div class="page-header-actions">
            <a class="btn" href="{{ route('shopee.profiles.index') }}">Back to Profiles</a>
        </div>
    </div>

    @if(session('status'))
        <div class="alert success">{{ session('status') }}</div>
    @endif


    <div class="card">
        <form method="POST" action="{{ $mode === 'create' ? route('shopee.profiles.store') : route('shopee.profiles.update', $profile->id) }}">
            @csrf
            @if($mode === 'edit')
                @method('PUT')
            @endif

            {{-- Row 1: Name --}}
            <div>
                <label>Profile Name <span style="color:#d11;">*</span></label>
                <input type="text" name="name" class="input" value="{{ old('name', $profile->name) }}" placeholder="e.g. Guitar Pedals">
            </div>

            {{-- Row 2: Product Filters --}}
            <h3 class="section-title" style="margin-top:20px;">Product Filter</h3>
            <div class="hint" style="margin-bottom:8px;">Leave both empty to include <strong>all</strong> products. Type to search, click to add.</div>

            @php
                $selectedCatIds = old('catalog_category_ids', $profile->catalog_category_ids ?? []) ?? [];
                $selectedMfgIds = old('manufacturer_ids', $profile->manufacturer_ids ?? []) ?? [];
                $selectedCatIds = array_map('intval', (array)$selectedCatIds);
                $selectedMfgIds = array_map('intval', (array)$selectedMfgIds);
            @endphp

            <div style="display:grid; grid-template-columns: 1fr 1fr; gap: 12px;">
                <div>
                    <label>Categories</label>
                    <div style="position:relative;">
                        <input type="text" class="input" id="catSearch" placeholder="Search categories..." autocomplete="off">
                        <div id="catDropdown" style="display:none; position:absolute; z-index:10; left:0; right:0; max-height:200px; overflow-y:auto; background:var(--surface); border:1px solid var(--border); border-radius:var(--radius-md); margin-top:2px;"></div>
                    </div>
                    <div id="catSelected" style="border:1px solid var(--border); border-radius:var(--radius-md); min-height:40px; margin-top:8px; padding:4px;"></div>
                </div>
                <div>
                    <label>Manufacturers</label>
                    <div style="position:relative;">
                        <input type="text" class="input" id="mfgSearch" placeholder="Search manufacturers..." autocomplete="off">
                        <div id="mfgDropdown" style="display:none; position:absolute; z-index:10; left:0; right:0; max-height:200px; overflow-y:auto; background:var(--surface); border:1px solid var(--border); border-radius:var(--radius-md); margin-top:2px;"></div>
                    </div>
                    <div id="mfgSelected" style="border:1px solid var(--border); border-radius:var(--radius-md); min-height:40px; margin-top:8px; padding:4px;"></div>
                </div>
            </div>
            <div class="hint" style="margin-top:6px;">
                Matching products: <strong>{{ $productCount }}</strong>
            </div>

            {{-- Row 3: Markup --}}
            <h3 class="section-title" style="margin-top:20px;">Price Markup</h3>
            <div class="hint" style="margin-bottom:8px;">Formula: <strong>(Product Price &times; %) + Product Price + Fixed Amount</strong></div>

            <div style="display:grid; grid-template-columns: 1fr 1fr; gap: 12px;">
                <div>
                    <label>Fixed Amount</label>
                    <input type="number" name="markup_fixed" class="input" step="0.01" min="0"
                           value="{{ old('markup_fixed', $profile->markup_fixed) }}"
                           placeholder="e.g. 100">
                    <div class="hint">Flat amount added after percentage.</div>
                </div>
                <div>
                    <label>Percentage (%)</label>
                    <input type="number" name="markup_percent" class="input" step="0.01" min="0"
                           value="{{ old('markup_percent', $profile->markup_percent) }}"
                           placeholder="e.g. 5">
                    <div class="hint">% of product price applied first.</div>
                </div>
            </div>

            {{-- Row 4: Shopee Mapping --}}
            <h3 class="section-title" style="margin-top:20px;">Shopee Mapping</h3>

            <div>
                <label>Shopee Category <span style="color:var(--danger);">*</span></label>
                <select name="shopee_category_id" class="input">
                    <option value="">-- Select Shopee Category --</option>
                    @foreach(($shopeeCategories ?? collect()) as $c)
                        <option value="{{ (int)$c->category_id }}" {{ (string)old('shopee_category_id', $profile->shopee_category_id) === (string)$c->category_id ? 'selected' : '' }}>
                            {{ $c->name }} ({{ $c->category_id }})
                        </option>
                    @endforeach
                </select>
            </div>

            {{-- Logistics Channels --}}
            <h3 class="section-title" style="margin-top:20px;">Logistics Channels <span style="color:var(--danger);">*</span></h3>
            <div class="hint" style="margin-bottom:8px;">Select the logistics channels to use when pushing products from this profile.</div>

            @php
                $selectedLogIds = old('logistic_ids', $profile->logistic_ids ?? []) ?? [];
                $selectedLogIds = array_map('intval', (array)$selectedLogIds);
            @endphp

            @if(!empty($logistics))
                <div style="display:grid; grid-template-columns: 1fr 1fr; gap: 8px;">
                    @foreach($logistics as $ch)
                        @php
                            $lid = (int)($ch['logistic_id'] ?? 0);
                            $lname = (string)($ch['logistic_name'] ?? 'Channel #' . $lid);
                        @endphp
                        <label style="display:flex; align-items:center; gap:8px; padding:6px 0;">
                            <input type="checkbox" name="logistic_ids[]" value="{{ $lid }}"
                                {{ in_array($lid, $selectedLogIds) ? 'checked' : '' }}>
                            <span>{{ $lname }} <span class="text-muted text-xs">(ID: {{ $lid }})</span></span>
                        </label>
                    @endforeach
                </div>
            @else
                <div class="text-muted">No logistics channels cached. <a href="{{ route('shopee.logistics.index') }}">Fetch them from the Logistics page</a> first.</div>
            @endif

            @if($mode === 'edit' && $template && !empty($attributes))
                {{-- Category Attributes --}}
                <h3 class="section-title" style="margin-top:20px;">Category Attributes</h3>
                <div class="hint" style="margin-bottom:8px;">Map shared attribute values for this Shopee category.
                    @if($template->fetched_at)
                        Last synced: {{ $template->fetched_at->format('Y-m-d H:i') }}
                    @endif
                </div>
                <div>
                    @foreach($attributes as $a)
                        @php
                            $key = (string)($a['key'] ?? '');
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
                                                $optLabel = is_array($opt) ? (string)($opt['original_value_name'] ?? $opt['display_value_name'] ?? $opt['value_name'] ?? $opt['name'] ?? $opt['value'] ?? '') : (string)$opt;
                                                $optValue = is_array($opt) ? (string)($opt['original_value_name'] ?? $opt['display_value_name'] ?? $opt['value_name'] ?? $opt['name'] ?? $opt['value'] ?? '') : (string)$opt;
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
            @endif

            <div class="d-flex justify-end gap-8 mt-16">
                <button class="btn" type="submit">
                    @if($mode === 'create')
                        Create Profile
                    @else
                        Save Profile
                    @endif
                </button>
            </div>
        </form>
    </div>

    @if($mode === 'edit')
        @if(!$template && $profile->shopee_category_id)
            <div class="alert warning mt-16">Template not yet synced. Click <strong>Sync Template</strong> below to fetch category attributes from Shopee.</div>
        @endif

        {{-- Sync Template --}}
        <div class="card mt-16">
            <div class="d-flex justify-between items-center gap-12 flex-wrap">
                <div>
                    <h3 class="section-title mt-0">{{ $template ? 'Re-Sync Template' : 'Sync Template' }}</h3>
                    <div class="hint">
                        @if($template)
                            Only use this if Shopee has updated their attributes for this category.
                        @else
                            Fetch the category attribute template from Shopee so you can map attribute values above.
                        @endif
                    </div>
                    @if($template && $template->fetched_at)
                        <div class="hint" style="margin-top:4px;">Last synced: {{ $template->fetched_at->format('Y-m-d H:i') }}</div>
                    @endif
                </div>
                <form method="POST" action="{{ route('shopee.profiles.template_sync', $profile->id) }}">
                    @csrf
                    <input type="hidden" name="shopee_category_id" value="{{ (int)($profile->shopee_category_id ?? 0) }}">
                    <button class="btn" type="submit" {{ empty($profile->shopee_category_id) ? 'disabled' : '' }}>
                        {{ $template ? 'Re-sync Template' : 'Sync Template' }}
                    </button>
                </form>
            </div>
        </div>

        {{-- Sync Products --}}
        <div class="card mt-16">
            <div class="d-flex justify-between items-center gap-12 flex-wrap">
                <div>
                    <h3 class="section-title mt-0">Sync Products</h3>
                    <div class="hint">Check how many products match this profile's filter criteria.</div>
                </div>
                <form method="POST" action="{{ route('shopee.profiles.sync_products', $profile->id) }}">
                    @csrf
                    <button class="btn" type="submit" {{ empty($profile->shopee_category_id) ? 'disabled' : '' }}>Sync Products</button>
                </form>
            </div>
        </div>
    @endif

    <script>
        function initListPicker(config) {
            var items = config.items;
            var selectedIds = config.selectedIds;
            var searchEl = config.searchEl;
            var dropdownEl = config.dropdownEl;
            var listEl = config.listEl;
            var fieldName = config.fieldName;
            var selected = new Map();

            selectedIds.forEach(function(id) {
                var item = items.find(function(i) { return i.id === id; });
                if (item) selected.set(id, item.name);
            });

            function render() {
                listEl.innerHTML = '';
                if (selected.size === 0) {
                    listEl.innerHTML = '<div style="padding:8px; color:var(--text-muted); font-size:13px;">None selected</div>';
                    return;
                }
                selected.forEach(function(name, id) {
                    var row = document.createElement('div');
                    row.style.cssText = 'display:flex; justify-content:space-between; align-items:center; padding:4px 8px;';
                    var span = document.createElement('span');
                    span.textContent = name;
                    row.appendChild(span);
                    var btn = document.createElement('button');
                    btn.type = 'button';
                    btn.innerHTML = '&times;';
                    btn.style.cssText = 'background:none; border:none; cursor:pointer; font-size:18px; color:var(--text-muted); padding:0 4px; line-height:1;';
                    btn.addEventListener('click', function() { selected.delete(id); render(); });
                    row.appendChild(btn);
                    var inp = document.createElement('input');
                    inp.type = 'hidden';
                    inp.name = fieldName;
                    inp.value = id;
                    row.appendChild(inp);
                    listEl.appendChild(row);
                });
            }

            function showDropdown(filter) {
                var lower = filter.toLowerCase();
                var filtered = items.filter(function(i) {
                    return !selected.has(i.id) && i.name.toLowerCase().indexOf(lower) !== -1;
                });
                if (filtered.length === 0 || filter === '') {
                    dropdownEl.style.display = 'none';
                    return;
                }
                dropdownEl.innerHTML = '';
                filtered.slice(0, 50).forEach(function(item) {
                    var div = document.createElement('div');
                    div.textContent = item.name;
                    div.style.cssText = 'padding:6px 10px; cursor:pointer;';
                    div.addEventListener('mouseenter', function() { div.style.background = 'var(--surface-hover)'; });
                    div.addEventListener('mouseleave', function() { div.style.background = ''; });
                    div.addEventListener('mousedown', function(e) {
                        e.preventDefault();
                        selected.set(item.id, item.name);
                        searchEl.value = '';
                        dropdownEl.style.display = 'none';
                        render();
                    });
                    dropdownEl.appendChild(div);
                });
                dropdownEl.style.display = 'block';
            }

            searchEl.addEventListener('input', function() { showDropdown(searchEl.value.trim()); });
            searchEl.addEventListener('focus', function() { if (searchEl.value.trim()) showDropdown(searchEl.value.trim()); });
            searchEl.addEventListener('blur', function() { setTimeout(function() { dropdownEl.style.display = 'none'; }, 150); });

            render();
        }

        initListPicker({
            items: @json($catalogCategories->map(fn($c) => ['id' => (int)$c->category_id, 'name' => $c->name])),
            selectedIds: @json($selectedCatIds),
            searchEl: document.getElementById('catSearch'),
            dropdownEl: document.getElementById('catDropdown'),
            listEl: document.getElementById('catSelected'),
            fieldName: 'catalog_category_ids[]'
        });

        initListPicker({
            items: @json(($manufacturers ?? collect())->map(fn($m) => ['id' => (int)$m->manufacturer_id, 'name' => $m->name])),
            selectedIds: @json($selectedMfgIds),
            searchEl: document.getElementById('mfgSearch'),
            dropdownEl: document.getElementById('mfgDropdown'),
            listEl: document.getElementById('mfgSelected'),
            fieldName: 'manufacturer_ids[]'
        });
    </script>
@endsection
