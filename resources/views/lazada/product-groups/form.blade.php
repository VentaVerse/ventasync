@extends('layouts.app')
@section('breadcrumb', 'Marketplace / Lazada / Product Groups / ' . ($mode === 'create' ? 'Add' : 'Edit'))

@section('title', $mode === 'create' ? 'Add Lazada Product Group' : 'Edit Lazada Product Group')

@section('content')
    <div class="page-header">
        <div>
            <h2>{{ $mode === 'create' ? 'Add Lazada Product Group' : 'Edit Lazada Product Group' }}</h2>
            <div class="text-muted text-sm">Map products to a Lazada category, brand, and shared attributes.</div>
        </div>
        <div class="page-header-actions">
            @if($mode === 'edit')
                <a class="btn secondary" href="{{ route('lazada.product-groups.products', $group->id) }}">View Products</a>
            @endif
            <a class="btn" href="{{ route('lazada.product-groups.index') }}">Back to Product Groups</a>
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

    <form id="group-form" method="POST" action="{{ $mode === 'create' ? route('lazada.product-groups.store') : route('lazada.product-groups.update', $group->id) }}">
        @csrf
        @if($mode === 'edit') @method('PUT') @endif

        <div class="card">
            {{-- Row 1: Name --}}
            <div>
                <label>Product Group Name <span style="color:#d11;">*</span></label>
                <input type="text" name="name" class="input" value="{{ old('name', $group->name) }}" placeholder="e.g. Valeton Multi-FX">
            </div>

            {{-- Price Markup --}}
            <h3 class="section-title" style="margin-top:20px;">Price Markup</h3>
            <div class="hint" style="margin-bottom:8px;">Formula: <strong>(Product Price &times; %) + Product Price + Fixed Amount</strong></div>

            <div style="display:grid; grid-template-columns: 1fr 1fr; gap: 12px;">
                <div>
                    <label>Percentage (%)</label>
                    <input type="number" name="markup_percent" class="input" step="0.01" min="0"
                           value="{{ old('markup_percent', $group->markup_percent) }}"
                           placeholder="e.g. 5">
                    <div class="hint">% of product price applied first.</div>
                </div>
                <div>
                    <label>Fixed Amount</label>
                    <input type="number" name="markup_fixed" class="input" step="0.01" min="0"
                           value="{{ old('markup_fixed', $group->markup_fixed) }}"
                           placeholder="e.g. 100">
                    <div class="hint">Flat amount added after percentage.</div>
                </div>
            </div>

            {{-- Lazada Mapping --}}
            <h3 class="section-title" style="margin-top:20px;">Lazada Mapping</h3>

            <div style="display:grid; grid-template-columns: 1fr 1fr; gap: 12px;">
                <div>
                    <label>Lazada Category</label>
                    <select name="lazada_category_id" class="input">
                        <option value="">-- Select Lazada Category --</option>
                        @foreach(($lazadaCategories ?? collect()) as $c)
                            <option value="{{ (int)$c->category_id }}" {{ (string)old('lazada_category_id', $group->lazada_category_id) === (string)$c->category_id ? 'selected' : '' }}>
                                {{ $c->name }} ({{ $c->category_id }})
                            </option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label>Brand</label>
                    <input type="hidden" name="brand_id" id="brandId" value="{{ old('brand_id', $group->brand_id) }}">
                    @php
                        $noBrandDefault = old('no_brand');
                        if ($noBrandDefault === null) {
                            $noBrandDefault = (!empty($group->brand_name_override) && strtolower(trim((string)$group->brand_name_override)) === 'no brand') ? 1 : 0;
                        }
                    @endphp
                    <input type="hidden" name="no_brand" id="noBrandFlag" value="{{ $noBrandDefault ? 1 : 0 }}">

                    <input type="text" class="input" id="brandInput" list="brandList" autocomplete="off"
                           value="{{ ($noBrandDefault ? 'No Brand' : (old('brand_name') ?? ($selectedBrandName ?? ''))) }}"
                           placeholder="Type to search brand...">
                    <datalist id="brandList"></datalist>

                    <div class="hint d-flex gap-10 items-center flex-wrap" style="margin-top:6px;">
                        <span>Brand ID: <strong id="brandPickedId">{{ old('brand_id', $group->brand_id) ?: '-' }}</strong></span>
                        <button type="button" class="btn small" id="setNoBrandBtn">No Brand</button>
                    </div>
                </div>
            </div>
        </div>

        {{-- Product Assignment (Dual Listbox) --}}
        <div class="card mt-16">
            <h3 class="section-title mt-0">Products</h3>
            <div class="hint" style="margin-bottom:12px;">Use the filters to find products, then move them to the right panel to include in this group.</div>

            {{-- Search Filters --}}
            <div style="display:grid; grid-template-columns:1fr 1fr 1fr auto; gap:10px; align-items:end; margin-bottom:16px;">
                <div>
                    <label class="text-xs text-muted">Search</label>
                    <input type="text" class="input" id="pl-search" placeholder="Name, SKU or model..." autocomplete="off">
                </div>
                <div>
                    <label class="text-xs text-muted">Category</label>
                    <select class="input" id="pl-category">
                        <option value="">All Categories</option>
                        @foreach($catalogCategories as $cat)
                            <option value="{{ $cat->category_id }}">{{ $cat->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="text-xs text-muted">Manufacturer</label>
                    <select class="input" id="pl-manufacturer">
                        <option value="">All Manufacturers</option>
                        @foreach($manufacturers as $mfg)
                            <option value="{{ $mfg->manufacturer_id }}">{{ $mfg->name }}</option>
                        @endforeach
                    </select>
                </div>
                <button type="button" class="btn" id="pl-search-btn">Search</button>
            </div>

            {{-- Dual Listbox --}}
            <div style="display:grid; grid-template-columns:1fr 48px 1fr; gap:0; align-items:start;">
                {{-- Left Panel: Available --}}
                <div>
                    <div style="font-weight:600; font-size:13px; margin-bottom:6px;">
                        Available <span id="pl-left-count" class="text-muted font-normal">(0)</span>
                    </div>
                    <div style="border:1px solid var(--border); border-radius:var(--radius-md); height:420px; overflow-y:auto; background:var(--surface);">
                        <table class="table" style="margin:0;">
                            <thead style="position:sticky; top:0; background:var(--surface); z-index:1;">
                                <tr>
                                    <th style="width:30px; padding:6px 4px;"><input type="checkbox" id="pl-left-check-all"></th>
                                    <th style="width:50px; padding:6px 4px;">ID</th>
                                    <th style="padding:6px 4px;">Name</th>
                                    <th style="width:90px; padding:6px 4px;">SKU</th>
                                    <th style="width:40px; padding:6px 4px;">Qty</th>
                                </tr>
                            </thead>
                            <tbody id="pl-left-body">
                                <tr><td colspan="5" class="text-muted text-center" style="padding:40px 8px; font-size:13px;">Use the filters above and click Search</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                {{-- Middle: Add/Remove Buttons --}}
                <div style="display:flex; flex-direction:column; gap:8px; align-items:center; padding-top:80px;">
                    <button type="button" class="btn small" id="pl-add-btn" title="Add selected to group" style="padding:4px 10px; font-size:16px; font-weight:700; line-height:1;">&rsaquo;&rsaquo;</button>
                    <button type="button" class="btn small secondary" id="pl-remove-btn" title="Remove selected from group" style="padding:4px 10px; font-size:16px; font-weight:700; line-height:1;">&lsaquo;&lsaquo;</button>
                </div>

                {{-- Right Panel: In Group --}}
                <div>
                    <div style="font-weight:600; font-size:13px; margin-bottom:6px;">
                        In Group <span id="pl-right-count" class="text-muted font-normal">({{ $groupProducts->count() }})</span>
                    </div>
                    <div style="border:1px solid var(--border); border-radius:var(--radius-md); height:420px; overflow-y:auto; background:var(--surface);">
                        <table class="table" style="margin:0;">
                            <thead style="position:sticky; top:0; background:var(--surface); z-index:1;">
                                <tr>
                                    <th style="width:30px; padding:6px 4px;"><input type="checkbox" id="pl-right-check-all"></th>
                                    <th style="width:50px; padding:6px 4px;">ID</th>
                                    <th style="padding:6px 4px;">Name</th>
                                    <th style="width:90px; padding:6px 4px;">SKU</th>
                                    <th style="width:40px; padding:6px 4px;">Qty</th>
                                </tr>
                            </thead>
                            <tbody id="pl-right-body">
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            {{-- Hidden inputs for product IDs --}}
            <div id="pl-hidden-inputs"></div>
        </div>

        @if($mode === 'edit' && $template && !empty($attributes))
            {{-- Category Attributes --}}
            <div class="card mt-16">
                <h3 class="section-title mt-0">Category Attributes</h3>
                <div class="hint" style="margin-bottom:8px;">Map shared attribute values for this Lazada category.
                    @if($template->fetched_at)
                        Last synced: {{ $template->fetched_at->format('Y-m-d H:i') }}
                    @endif
                </div>
                <div>
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
                                                $optValue = is_array($opt) ? (string)($opt['name'] ?? $opt['label'] ?? $opt['value'] ?? '') : (string)$opt;
                                            @endphp
                                            <option value="{{ $optValue }}" {{ (string)$val === (string)$optValue ? 'selected' : '' }}>{{ $optLabel }}</option>
                                        @endforeach
                                    </select>
                                @else
                                    @php
                                        $isMapVal = str_starts_with((string)$val, '__map:');
                                        $mapField = $isMapVal ? substr((string)$val, 6) : '';
                                        $manualVal = $isMapVal ? '' : $val;
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
                                        <input type="hidden" name="attributes[{{ $key }}]" class="attr-hidden-value" value="{{ $val }}">
                                    </div>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif

        <div class="d-flex justify-end gap-8 mt-16">
            <button class="btn" type="submit">
                {{ $mode === 'create' ? 'Create Product Group' : 'Save Product Group' }}
            </button>
        </div>
    </form>

    @if($mode === 'edit')
        @if(!$template && $group->lazada_category_id)
            <div class="alert warning mt-16">Template not yet synced. Click <strong>Sync Template</strong> below to fetch category attributes from Lazada.</div>
        @endif

        {{-- Re-Sync Template --}}
        <div class="card mt-16">
            <div class="d-flex justify-between items-center gap-12 flex-wrap">
                <div>
                    <h3 class="section-title mt-0">{{ $template ? 'Re-Sync Template' : 'Sync Template' }}</h3>
                    <div class="hint">
                        @if($template)
                            Only use this if Lazada has updated their attributes for this category.
                        @else
                            Fetch the category attribute template from Lazada so you can map attribute values above.
                        @endif
                    </div>
                    @if($template && $template->fetched_at)
                        <div class="hint" style="margin-top:4px;">Last synced: {{ $template->fetched_at->format('Y-m-d H:i') }}</div>
                    @endif
                </div>
                <form method="POST" action="{{ route('lazada.product-groups.template_sync', $group->id) }}">
                    @csrf
                    <input type="hidden" name="lazada_category_id" value="{{ (int)($group->lazada_category_id ?? 0) }}">
                    <button class="btn" type="submit" {{ empty($group->lazada_category_id) ? 'disabled' : '' }}>
                        {{ $template ? 'Re-sync Template' : 'Sync Template' }}
                    </button>
                </form>
            </div>
        </div>
    @endif

@push('scripts')
<script>
(function() {
    // ── Brand Picker ────────────────────────────────────────────
    var brandInput = document.getElementById('brandInput');
    var brandList = document.getElementById('brandList');
    var brandId = document.getElementById('brandId');
    var noBrand = document.getElementById('noBrandFlag');
    var btnNoBrand = document.getElementById('setNoBrandBtn');
    var pickedIdEl = document.getElementById('brandPickedId');

    if (brandInput && brandList && brandId && noBrand && pickedIdEl) {
        var lastQuery = '';

        function setPicked(id) {
            pickedIdEl.textContent = id ? String(id) : '-';
        }

        function fetchSuggestions(q) {
            var url = @json(route('lazada.brands.autocomplete')) + '?q=' + encodeURIComponent(q) + '&limit=20';
            return fetch(url, { headers: { 'Accept': 'application/json' } })
                .then(function(res) { return res.ok ? res.json() : { items: [] }; })
                .then(function(json) { return Array.isArray(json.items) ? json.items : []; });
        }

        function renderBrandList(rows) {
            brandList.textContent = '';
            for (var i = 0; i < rows.length; i++) {
                var opt = document.createElement('option');
                opt.value = rows[i].name;
                opt.dataset.id = String(rows[i].brand_id);
                brandList.appendChild(opt);
            }
        }

        brandInput.addEventListener('input', function() {
            var q = (this.value || '').trim();
            if (q === '' || q.toLowerCase() === 'no brand' || q === lastQuery) return;
            lastQuery = q;
            fetchSuggestions(q).then(renderBrandList);
        });

        brandInput.addEventListener('change', function() {
            var v = (this.value || '').trim();
            if (v.toLowerCase() === 'no brand') {
                brandId.value = '';
                noBrand.value = '1';
                setPicked('');
                return;
            }
            var opts = brandList.options;
            for (var i = 0; i < opts.length; i++) {
                if ((opts[i].value || '').trim() === v && opts[i].dataset && opts[i].dataset.id) {
                    brandId.value = opts[i].dataset.id;
                    noBrand.value = '0';
                    setPicked(opts[i].dataset.id);
                    return;
                }
            }
            brandId.value = '';
            noBrand.value = '0';
            setPicked('');
        });

        if (btnNoBrand) {
            btnNoBrand.addEventListener('click', function() {
                brandInput.value = 'No Brand';
                brandId.value = '';
                noBrand.value = '1';
                setPicked('');
            });
        }

        setPicked(brandId.value);
    }

    // ── Attribute mode toggle ───────────────────────────────────
    document.querySelectorAll('.attr-row').forEach(function(row) {
        var toggle = row.querySelector('.attr-mode-toggle');
        var manual = row.querySelector('.attr-manual-input');
        var fieldSelect = row.querySelector('.attr-field-select');
        var hidden = row.querySelector('.attr-hidden-value');
        if (!toggle || !manual || !fieldSelect || !hidden) return;

        function updateHidden() {
            if (toggle.value === 'product_field') {
                var f = fieldSelect.value;
                hidden.value = f ? '__map:' + f : '';
            } else {
                hidden.value = manual.value;
            }
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

    // ── Dual Listbox ────────────────────────────────────────────
    var searchUrl = @json(route('lazada.product-groups.searchProducts'));
    @php
        $rightItemsJson = $groupProducts->map(fn($p) => [
            'product_id' => (int) $p->product_id,
            'name'       => $p->name,
            'sku'        => $p->sku ?: ($p->model ?: ''),
            'quantity'   => (int) $p->quantity,
        ]);
    @endphp
    var rightItems = @json($rightItemsJson);

    var leftItems = [];
    var rightSet = {};
    rightItems.forEach(function(item) { rightSet[item.product_id] = true; });

    var leftBody = document.getElementById('pl-left-body');
    var rightBody = document.getElementById('pl-right-body');
    var leftCount = document.getElementById('pl-left-count');
    var rightCount = document.getElementById('pl-right-count');
    var hiddenDiv = document.getElementById('pl-hidden-inputs');
    var leftCheckAll = document.getElementById('pl-left-check-all');
    var rightCheckAll = document.getElementById('pl-right-check-all');

    function makeRow(item, side) {
        var tr = document.createElement('tr');

        var tdCb = document.createElement('td');
        tdCb.style.cssText = 'padding:4px;';
        var cb = document.createElement('input');
        cb.type = 'checkbox';
        cb.className = side === 'left' ? 'pl-left-check' : 'pl-right-check';
        cb.value = item.product_id;
        tdCb.appendChild(cb);
        tr.appendChild(tdCb);

        var tdId = document.createElement('td');
        tdId.style.cssText = 'padding:4px; font-size:12px;';
        tdId.textContent = item.product_id;
        tr.appendChild(tdId);

        var tdName = document.createElement('td');
        tdName.style.cssText = 'padding:4px; font-size:12px;';
        tdName.textContent = item.name || '-';
        tr.appendChild(tdName);

        var tdSku = document.createElement('td');
        tdSku.style.cssText = 'padding:4px; font-size:12px;';
        var code = document.createElement('code');
        code.textContent = item.sku || '-';
        tdSku.appendChild(code);
        tr.appendChild(tdSku);

        var tdQty = document.createElement('td');
        tdQty.style.cssText = 'padding:4px; font-size:12px;';
        tdQty.textContent = item.quantity;
        tr.appendChild(tdQty);

        return tr;
    }

    function makeEmptyRow(msg, colspan) {
        var tr = document.createElement('tr');
        var td = document.createElement('td');
        td.setAttribute('colspan', colspan);
        td.className = 'text-muted text-center';
        td.style.cssText = 'padding:40px 8px; font-size:13px;';
        td.textContent = msg;
        tr.appendChild(td);
        return tr;
    }

    function renderLeft() {
        var visible = leftItems.filter(function(item) { return !rightSet[item.product_id]; });
        leftCount.textContent = '(' + visible.length + ')';
        if (leftCheckAll) leftCheckAll.checked = false;
        leftBody.textContent = '';

        if (visible.length === 0) {
            leftBody.appendChild(makeEmptyRow(leftItems.length > 0 ? 'All results already in group' : 'No results', 5));
            return;
        }

        visible.forEach(function(item) {
            leftBody.appendChild(makeRow(item, 'left'));
        });
    }

    function renderRight() {
        rightCount.textContent = '(' + rightItems.length + ')';
        if (rightCheckAll) rightCheckAll.checked = false;
        syncHiddenInputs();
        rightBody.textContent = '';

        if (rightItems.length === 0) {
            rightBody.appendChild(makeEmptyRow('No products added', 5));
            return;
        }

        rightItems.forEach(function(item) {
            rightBody.appendChild(makeRow(item, 'right'));
        });
    }

    function syncHiddenInputs() {
        hiddenDiv.textContent = '';
        rightItems.forEach(function(item) {
            var inp = document.createElement('input');
            inp.type = 'hidden';
            inp.name = 'product_ids[]';
            inp.value = item.product_id;
            hiddenDiv.appendChild(inp);
        });
    }

    function doSearch() {
        var q = document.getElementById('pl-search').value.trim();
        var catId = document.getElementById('pl-category').value;
        var mfgId = document.getElementById('pl-manufacturer').value;

        if (q.length < 2 && !catId && !mfgId) {
            showFlashError('Enter a search term or select a category/manufacturer.');
            return;
        }

        var params = [];
        if (q) params.push('q=' + encodeURIComponent(q));
        if (catId) params.push('category_id=' + catId);
        if (mfgId) params.push('manufacturer_id=' + mfgId);

        var btn = document.getElementById('pl-search-btn');
        btn.disabled = true;
        btn.textContent = 'Searching...';

        fetch(searchUrl + '?' + params.join('&'), {
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            leftItems = (data.items || []).map(function(item) {
                return {
                    product_id: parseInt(item.product_id),
                    name: item.name || '',
                    sku: item.sku || item.model || '',
                    quantity: parseInt(item.quantity) || 0
                };
            });
            renderLeft();
        })
        .catch(function() {
            leftItems = [];
            leftBody.textContent = '';
            leftBody.appendChild(makeEmptyRow('Search failed', 5));
        })
        .finally(function() {
            btn.disabled = false;
            btn.textContent = 'Search';
        });
    }

    // Add selected left items to right
    document.getElementById('pl-add-btn').addEventListener('click', function() {
        var checked = leftBody.querySelectorAll('.pl-left-check:checked');
        if (checked.length === 0) return;

        checked.forEach(function(cb) {
            var pid = parseInt(cb.value);
            if (rightSet[pid]) return;
            var item = leftItems.find(function(i) { return i.product_id === pid; });
            if (item) {
                rightItems.push(item);
                rightSet[pid] = true;
            }
        });

        renderLeft();
        renderRight();
    });

    // Remove selected right items
    document.getElementById('pl-remove-btn').addEventListener('click', function() {
        var checked = rightBody.querySelectorAll('.pl-right-check:checked');
        if (checked.length === 0) return;

        var removeIds = {};
        checked.forEach(function(cb) { removeIds[parseInt(cb.value)] = true; });

        rightItems = rightItems.filter(function(item) { return !removeIds[item.product_id]; });

        rightSet = {};
        rightItems.forEach(function(item) { rightSet[item.product_id] = true; });

        renderLeft();
        renderRight();
    });

    // Search button + Enter key
    document.getElementById('pl-search-btn').addEventListener('click', doSearch);
    document.getElementById('pl-search').addEventListener('keydown', function(e) {
        if (e.key === 'Enter') { e.preventDefault(); doSearch(); }
    });

    // Check-all toggles
    if (leftCheckAll) {
        leftCheckAll.addEventListener('change', function() {
            leftBody.querySelectorAll('.pl-left-check').forEach(function(cb) { cb.checked = leftCheckAll.checked; });
        });
    }
    if (rightCheckAll) {
        rightCheckAll.addEventListener('change', function() {
            rightBody.querySelectorAll('.pl-right-check').forEach(function(cb) { cb.checked = rightCheckAll.checked; });
        });
    }

    // Initial render
    renderRight();
})();
</script>
@endpush
@endsection
