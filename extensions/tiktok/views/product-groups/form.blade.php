@extends('layouts.app')
@section('title', ($mode === 'create' ? 'Add' : 'Edit') . ' TikTok Product Group')
@section('breadcrumb', 'Marketplace / TikTok Shop / Product Groups / ' . ($mode === 'create' ? 'Add' : 'Edit'))

@section('content')
    <div class="page-header">
        <div>
            <h2>{{ $mode === 'create' ? 'Add TikTok Product Group' : 'Edit TikTok Product Group' }}</h2>
            <div class="text-muted text-sm">TikTok Shop — map ERP products to a TikTok category.</div>
        </div>
        <div class="page-header-actions">
            @if($mode === 'edit')
                <a class="btn secondary" href="{{ route('ext.tiktok.product-groups.products', $group->id) }}">View Products</a>
            @endif
            <a class="btn secondary" href="{{ route('ext.tiktok.product-groups.index') }}">← Back to Product Groups</a>
        </div>
    </div>

    <form id="group-form" method="POST" action="{{ $mode === 'create' ? route('ext.tiktok.product-groups.store') : route('ext.tiktok.product-groups.update', $group->id) }}">
        @csrf
        @if($mode === 'edit') @method('PUT') @endif

        <div class="card">
            <div>
                <label>Product Group Name <span style="color:#d11;">*</span></label>
                <input type="text" name="name" class="input" value="{{ old('name', $group->name) }}" placeholder="e.g. Guitar Pedals">
            </div>

            {{-- TikTok Category --}}
            <h3 class="section-title" style="margin-top:20px;">TikTok Category <span style="color:#d11;">*</span></h3>
            <div class="hint" style="margin-bottom:8px;">Select a leaf category from TikTok's catalog.</div>

            <div style="display:flex; gap:8px; align-items:start; max-width:560px;">
                <div style="position:relative; flex:1;">
                    <input type="text" class="input" id="ttCatSearch" placeholder="Search TikTok categories..." autocomplete="off"
                           value="{{ old('tiktok_category_id', $group->tiktok_category_id) ? ($tiktokCategories->firstWhere('id', old('tiktok_category_id', $group->tiktok_category_id))->name ?? '') : '' }}">
                    <input type="hidden" name="tiktok_category_id" id="ttCatId" value="{{ old('tiktok_category_id', $group->tiktok_category_id) }}">
                    <div id="ttCatDropdown" style="display:none; position:absolute; z-index:10; left:0; right:0; max-height:250px; overflow-y:auto; background:var(--surface); border:1px solid var(--border); border-radius:var(--radius-md); margin-top:2px;"></div>
                </div>
                <button type="button" class="btn small secondary" id="ttRefreshCatsBtn" title="Refresh categories from TikTok API">Refresh</button>
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
    // ── TikTok Category Picker ──────────────────────────────────
    var cats = @json($tiktokCategories->map(fn($c) => ['id' => $c->id, 'name' => $c->name]));
    var catSearchEl = document.getElementById('ttCatSearch');
    var catDropdown = document.getElementById('ttCatDropdown');
    var catHiddenId = document.getElementById('ttCatId');

    function showCatDropdown(filter) {
        var lower = filter.toLowerCase();
        var filtered = cats.filter(function(c) { return c.name.toLowerCase().indexOf(lower) !== -1; });
        if (filtered.length === 0 || filter === '') { catDropdown.style.display = 'none'; return; }
        catDropdown.textContent = '';
        filtered.slice(0, 50).forEach(function(cat) {
            var div = document.createElement('div');
            div.textContent = cat.name;
            div.style.cssText = 'padding:6px 10px; cursor:pointer; font-size:13px;';
            div.addEventListener('mouseenter', function() { div.style.background = 'var(--surface-hover)'; });
            div.addEventListener('mouseleave', function() { div.style.background = ''; });
            div.addEventListener('mousedown', function(e) {
                e.preventDefault();
                catSearchEl.value = cat.name;
                catHiddenId.value = cat.id;
                catDropdown.style.display = 'none';
            });
            catDropdown.appendChild(div);
        });
        catDropdown.style.display = 'block';
    }

    catSearchEl.addEventListener('input', function() { showCatDropdown(catSearchEl.value.trim()); });
    catSearchEl.addEventListener('focus', function() { if (catSearchEl.value.trim()) showCatDropdown(catSearchEl.value.trim()); });
    catSearchEl.addEventListener('blur', function() { setTimeout(function() { catDropdown.style.display = 'none'; }, 150); });

    // ── Refresh Categories from TikTok API ──────────────────────
    var ttRefreshBtn = document.getElementById('ttRefreshCatsBtn');
    var ttSyncUrl = @json(route('ext.tiktok.categories.sync'));
    var csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';

    if (ttRefreshBtn) {
        ttRefreshBtn.addEventListener('click', function() {
            ttRefreshBtn.disabled = true;
            ttRefreshBtn.textContent = 'Refreshing...';

            fetch(ttSyncUrl, {
                method: 'POST',
                headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': csrfToken, 'X-Requested-With': 'XMLHttpRequest' }
            })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.ok && data.categories) {
                    cats = data.categories.map(function(c) { return { id: c.id, name: c.name }; });
                    showFlashSuccess(data.count + ' categories refreshed.');
                } else {
                    showFlashError(data.message || 'Failed to refresh categories.');
                }
            })
            .catch(function() { showFlashError('Network error refreshing categories.'); })
            .finally(function() { ttRefreshBtn.disabled = false; ttRefreshBtn.textContent = 'Refresh'; });
        });
    }

    // ── Dual Listbox ────────────────────────────────────────────
    var searchUrl = @json(route('ext.tiktok.product-groups.searchProducts'));
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
