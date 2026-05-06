@extends('layouts.app')
@section('breadcrumb', 'Marketplace / Shopee / Product Groups / ' . ($mode === 'create' ? 'Add' : 'Edit'))

@section('title', $mode === 'create' ? 'Add Shopee Product Group' : 'Edit Shopee Product Group')

@section('content')
    <div class="page-header">
        <div>
            <h2>{{ $mode === 'create' ? 'Add Shopee Product Group' : 'Edit Shopee Product Group' }}</h2>
            <div class="text-muted text-sm">Map products to a Shopee category and shared attributes.</div>
        </div>
        <div class="page-header-actions">
            @if($mode === 'edit')
                <a class="btn secondary" href="{{ route('ext.shopee.product-groups.products', $group->id) }}">View Products</a>
            @endif
            <a class="btn secondary" href="{{ route('ext.shopee.product-groups.index') }}">← Back to Product Groups</a>
        </div>
    </div>

    <form id="group-form" method="POST" action="{{ $mode === 'create' ? route('ext.shopee.product-groups.store') : route('ext.shopee.product-groups.update', $group->id) }}">
        @csrf
        @if($mode === 'edit') @method('PUT') @endif

        <div class="card">
            {{-- Row 1: Name --}}
            <div>
                <label>Product Group Name <span style="color:#d11;">*</span></label>
                <input type="text" name="name" class="input" value="{{ old('name', $group->name) }}" placeholder="e.g. Guitar Pedals">
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

            {{-- Shopee Mapping --}}
            <h3 class="section-title" style="margin-top:20px;">Shopee Mapping</h3>

            <div>
                <label>Shopee Category <span style="color:var(--danger);">*</span></label>
                <div style="display:flex; gap:8px; align-items:start;">
                    <select name="shopee_category_id" id="shopeeCategorySelect" class="input" style="flex:1;">
                        <option value="">-- Select Shopee Category --</option>
                        @foreach(($shopeeCategories ?? collect()) as $c)
                            <option value="{{ (int)$c->category_id }}" {{ (string)old('shopee_category_id', $group->shopee_category_id) === (string)$c->category_id ? 'selected' : '' }}>
                                {{ $c->name }} ({{ $c->category_id }})
                            </option>
                        @endforeach
                    </select>
                    <button type="button" class="btn small secondary" id="refreshCatsBtn" title="Refresh categories from Shopee API">Refresh</button>
                </div>
            </div>

            {{-- Logistics Channels --}}
            <h3 class="section-title" style="margin-top:20px;">Logistics Channels <span style="color:var(--danger);">*</span></h3>
            <div class="hint" style="margin-bottom:8px;">Select the logistics channels to use when pushing products from this product group.</div>

            @php
                $selectedLogIds = old('logistic_ids', $group->logistic_ids ?? []) ?? [];
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
                <div class="text-muted">No logistics channels cached. <a href="{{ route('ext.shopee.logistics.index') }}">Fetch them from the Logistics page</a> first.</div>
            @endif
        </div>

        {{-- Category Attributes (loaded via AJAX on category change, or from server on edit) --}}
        <div class="card mt-16" id="shopee-attributes-card" style="{{ ($template && !empty($attributes)) ? '' : 'display:none;' }}">
            <div id="shopee-attributes-container">
                @if($template && !empty($attributes))
                    @include('ext-shopee::product-groups._attributes', ['attributes' => $attributes, 'saved' => $saved, 'template' => $template])
                @endif
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

        <div class="d-flex justify-end gap-8 mt-16">
            <button class="btn" type="submit">
                {{ $mode === 'create' ? 'Create Product Group' : 'Save Product Group' }}
            </button>
        </div>
    </form>

@push('scripts')
<script>
(function() {
    // ── Refresh Categories ──────────────────────────────────────
    var refreshCatsBtn = document.getElementById('refreshCatsBtn');
    var catSelect = document.getElementById('shopeeCategorySelect');
    var refreshCatsUrl = @json(route('ext.shopee.product-groups.refreshCategories'));
    var fetchAttrsUrl = @json(route('ext.shopee.product-groups.fetchAttributes'));
    var csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';

    if (refreshCatsBtn && catSelect) {
        refreshCatsBtn.addEventListener('click', function() {
            refreshCatsBtn.disabled = true;
            refreshCatsBtn.textContent = 'Refreshing...';

            fetch(refreshCatsUrl, {
                method: 'POST',
                headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': csrfToken, 'X-Requested-With': 'XMLHttpRequest' }
            })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.ok && data.categories) {
                    var curVal = catSelect.value;
                    catSelect.textContent = '';
                    var def = document.createElement('option');
                    def.value = '';
                    def.textContent = '-- Select Shopee Category --';
                    catSelect.appendChild(def);
                    data.categories.forEach(function(c) {
                        var opt = document.createElement('option');
                        opt.value = c.category_id;
                        opt.textContent = c.name + ' (' + c.category_id + ')';
                        if (String(c.category_id) === String(curVal)) opt.selected = true;
                        catSelect.appendChild(opt);
                    });
                    showFlashSuccess(data.count + ' categories refreshed.');
                } else {
                    showFlashError(data.message || 'Failed to refresh categories.');
                }
            })
            .catch(function() { showFlashError('Network error refreshing categories.'); })
            .finally(function() { refreshCatsBtn.disabled = false; refreshCatsBtn.textContent = 'Refresh'; });
        });
    }

    // ── Proactive Attribute Fetch on Category Change ──────────
    var attrsCard = document.getElementById('shopee-attributes-card');
    var attrsContainer = document.getElementById('shopee-attributes-container');

    if (catSelect && attrsCard && attrsContainer) {
        catSelect.addEventListener('change', function() {
            var catId = catSelect.value;
            if (!catId) {
                attrsCard.style.display = 'none';
                attrsContainer.textContent = '';
                return;
            }

            attrsContainer.textContent = '';
            var loading = document.createElement('div');
            loading.className = 'text-muted';
            loading.style.padding = '12px';
            loading.textContent = 'Fetching attributes...';
            attrsContainer.appendChild(loading);
            attrsCard.style.display = '';

            var formData = new FormData();
            formData.append('shopee_category_id', catId);

            fetch(fetchAttrsUrl, {
                method: 'POST',
                headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': csrfToken, 'X-Requested-With': 'XMLHttpRequest' },
                body: formData
            })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.ok && data.html) {
                    attrsContainer.insertAdjacentHTML('afterbegin', data.html);
                } else {
                    attrsContainer.textContent = '';
                    var msg = document.createElement('div');
                    msg.className = 'text-muted';
                    msg.style.padding = '12px';
                    msg.textContent = 'No attributes found for this category.';
                    attrsContainer.appendChild(msg);
                }
            })
            .catch(function() {
                attrsContainer.textContent = '';
                var msg = document.createElement('div');
                msg.className = 'text-muted';
                msg.style.padding = '12px';
                msg.textContent = 'Failed to fetch attributes.';
                attrsContainer.appendChild(msg);
            });
        });
    }

    // ── Dual Listbox ────────────────────────────────────────────
    var searchUrl = @json(route('ext.shopee.product-groups.searchProducts'));
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
