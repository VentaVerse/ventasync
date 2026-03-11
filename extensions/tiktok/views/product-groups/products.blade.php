@extends('layouts.app')
@section('breadcrumb', 'TikTok Shop / ' . $group->name . ' / Products')

@section('content')
    <div class="page-header">
        <div>
            <h2>{{ $group->name }} — Products</h2>
            <div class="text-muted text-sm">TikTok Shop — assign products and push to TikTok.</div>
        </div>
        <div class="page-header-actions">
            <a class="btn secondary" href="{{ route('ext.tiktok.product-groups.edit', $group->id) }}">Edit Group</a>
            <a class="btn secondary" href="{{ route('ext.tiktok.product-groups.index') }}">Back to Groups</a>

            <form method="POST" action="{{ route('ext.tiktok.product-groups.pushPrices', $group->id) }}" style="display:inline;" data-confirm="Push prices for all pushed products?">
                @csrf
                <button class="btn secondary" type="submit">Push Prices</button>
            </form>
            <form method="POST" action="{{ route('ext.tiktok.product-groups.pushStock', $group->id) }}" style="display:inline;" data-confirm="Push stock for all pushed products?">
                @csrf
                <button class="btn secondary" type="submit">Push Stock</button>
            </form>

            <button class="btn" type="submit" form="push-form" onclick="return confirmPush(event);">Push Selected</button>
        </div>
    </div>

    @if(session('status'))
        <div class="alert success">{{ session('status') }}</div>
    @endif

    {{-- Search --}}
    <div class="toolbar">
        <form method="GET" action="{{ route('ext.tiktok.product-groups.products', $group->id) }}">
            <input class="input" name="q" value="{{ $q ?? '' }}" placeholder="Search name / SKU / model" style="min-width:220px;">
            <button class="btn" type="submit">Search</button>
        </form>
    </div>

    {{-- Push form --}}
    <form id="push-form" method="POST" action="{{ route('ext.tiktok.product-groups.push', $group->id) }}">
        @csrf
    </form>

    {{-- Add manual products form --}}
    <form id="add-products-form" method="POST" action="{{ route('ext.tiktok.product-groups.addProducts', $group->id) }}">
        @csrf
    </form>

    <div class="card">
        <div class="table-wrap">
            <table class="table">
                <thead>
                <tr>
                    <th style="width:36px;"><input type="checkbox" id="select-all" title="Select all"></th>
                    <th style="width:70px;">ID</th>
                    <th style="width:70px;">Image</th>
                    <th>Name</th>
                    <th style="width:110px;">SKU</th>
                    <th style="width:90px;">Price</th>
                    <th style="width:60px;">Qty</th>
                    <th style="width:90px;">Status</th>
                    <th style="width:90px;">Sync</th>
                    <th style="width:90px;">Source</th>
                    <th style="width:120px;"></th>
                </tr>
                </thead>
                <tbody>
                @forelse($products as $p)
                    @php
                        $pivot = $pivotMap->get($p->product_id);
                        $isManual = in_array((int)$p->product_id, $manualIds);
                        $img = trim((string)($p->image ?? ''));
                        $src = $img !== '' ? asset('storage/' . ltrim($img, '/')) : '';
                    @endphp
                    <tr>
                        <td><input type="checkbox" class="row-check" name="ids[]" value="{{ (int)$p->product_id }}" form="push-form"></td>
                        <td>{{ $p->product_id }}</td>
                        <td>
                            @if($src)
                                <img src="{{ $src }}" alt="" class="thumb" loading="lazy" decoding="async" onerror="this.style.display='none';">
                            @else
                                <span class="text-muted">-</span>
                            @endif
                        </td>
                        <td>
                            <div class="font-semibold truncate">{{ $p->name ?? '-' }}</div>
                            @if($pivot && $pivot->push_error)
                                <div class="text-sm" style="color:#d11; margin-top:2px;" title="{{ $pivot->push_error }}">{{ \Illuminate\Support\Str::limit($pivot->push_error, 80) }}</div>
                            @endif
                            @if($pivot && $pivot->tiktok_product_id)
                                <div class="text-sm text-muted">TT: {{ $pivot->tiktok_product_id }}</div>
                            @endif
                        </td>
                        <td>{{ $p->sku ?: ($p->model ?: '-') }}</td>
                        <td>{{ number_format((float)$p->price, 2) }}</td>
                        <td>{{ (int)$p->quantity }}</td>
                        <td>{{ (int)$p->status === 1 ? 'Enabled' : 'Disabled' }}</td>
                        <td>
                            @if($pivot)
                                @if($pivot->sync_status === 'pushed')
                                    <span class="badge badge-green">Pushed</span>
                                @elseif($pivot->sync_status === 'error')
                                    <span class="badge badge-red">Error</span>
                                @else
                                    <span class="badge">Pending</span>
                                @endif
                            @else
                                <span class="text-muted text-sm">Filter only</span>
                            @endif
                        </td>
                        <td>
                            @if($isManual)
                                <span class="badge">Manual</span>
                            @else
                                <span class="text-muted text-sm">Filter</span>
                            @endif
                        </td>
                        <td class="d-flex gap-4">
                            @if($isManual)
                                <form method="POST" action="{{ route('ext.tiktok.product-groups.removeProduct', [$group->id, $p->product_id]) }}" data-confirm="Remove from product group?">
                                    @csrf @method('DELETE')
                                    <button type="submit" class="btn small danger">Remove</button>
                                </form>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="11" class="text-muted">No products match this product group. Add filters or add products manually below.</td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </div>

        <div class="mt-16">{{ $products->onEachSide(1)->links('vendor.pagination.simple') }}</div>
    </div>

    {{-- Add Individual Products --}}
    <div class="card mt-16">
        <h3 class="section-title mt-0">Add Products Manually</h3>
        <div class="hint" style="margin-bottom:10px;">Search for products to add individually to this product group.</div>

        <div class="d-flex gap-8 items-center flex-wrap">
            <input type="text" class="input" id="manual-search" placeholder="Search by name or SKU..." style="min-width:260px;">
            <button type="button" class="btn" id="manual-search-btn">Search</button>
            <button type="button" class="btn" id="add-selected-btn">Add Selected</button>
        </div>

        <div class="table-wrap mt-10" id="manual-results-wrap" style="display:none;">
            <table class="table" id="manual-results-table">
                <thead>
                <tr>
                    <th style="width:36px;"><input type="checkbox" id="manual-select-all"></th>
                    <th style="width:70px;">ID</th>
                    <th>Name</th>
                    <th style="width:130px;">SKU</th>
                    <th style="width:100px;">Price</th>
                </tr>
                </thead>
                <tbody></tbody>
            </table>
        </div>
    </div>

    <script>
    (function () {
        // Select all for push
        var selectAll = document.getElementById('select-all');
        var checks = function () { return Array.from(document.querySelectorAll('.row-check')); };
        if (selectAll) {
            selectAll.addEventListener('change', function () {
                checks().forEach(function (b) { b.checked = selectAll.checked; });
            });
        }

        window.confirmPush = function (e) {
            var selected = checks().filter(function (b) { return b.checked; }).length;
            if (selected === 0) { showFlashError('Please select at least one product.'); return false; }
            if (e && e.preventDefault) e.preventDefault();
            var form = document.getElementById('push-form');
            confirmModal('Push ' + selected + ' product(s) to TikTok Shop?').then(function (ok) {
                if (ok) { form._confirmed = true; form.requestSubmit ? form.requestSubmit() : form.submit(); }
            });
            return false;
        };

        // Manual product search
        var searchInput = document.getElementById('manual-search');
        var searchBtn = document.getElementById('manual-search-btn');
        var resultsWrap = document.getElementById('manual-results-wrap');
        var resultsBody = document.querySelector('#manual-results-table tbody');
        var addBtn = document.getElementById('add-selected-btn');
        var manualSelectAll = document.getElementById('manual-select-all');
        var searchUrl = '{{ route("ext.tiktok.product-groups.searchProducts") }}';

        function doSearch() {
            var q = (searchInput.value || '').trim();
            if (q.length < 2) return;
            fetch(searchUrl + '?q=' + encodeURIComponent(q), {
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
            })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                var items = data.items || [];
                resultsBody.textContent = '';
                if (items.length === 0) {
                    var tr = document.createElement('tr');
                    var td = document.createElement('td');
                    td.setAttribute('colspan', '5'); td.className = 'text-muted'; td.textContent = 'No results.';
                    tr.appendChild(td); resultsBody.appendChild(tr);
                } else {
                    items.forEach(function (item) {
                        var tr = document.createElement('tr');
                        var td1 = document.createElement('td');
                        var cb = document.createElement('input');
                        cb.type = 'checkbox'; cb.className = 'manual-row-check'; cb.value = item.product_id;
                        cb.setAttribute('form', 'add-products-form'); cb.name = 'ids[]';
                        td1.appendChild(cb); tr.appendChild(td1);

                        var td2 = document.createElement('td'); td2.textContent = item.product_id; tr.appendChild(td2);
                        var td3 = document.createElement('td'); td3.textContent = item.name || '-'; tr.appendChild(td3);
                        var td4 = document.createElement('td'); td4.textContent = item.sku || '-'; tr.appendChild(td4);
                        var td5 = document.createElement('td'); td5.textContent = item.price ? parseFloat(item.price).toFixed(2) : '0.00'; tr.appendChild(td5);
                        resultsBody.appendChild(tr);
                    });
                }
                resultsWrap.style.display = '';
            })
            .catch(function () {
                resultsBody.textContent = '';
                var tr = document.createElement('tr');
                var td = document.createElement('td');
                td.setAttribute('colspan', '5'); td.className = 'text-muted'; td.textContent = 'Search failed.';
                tr.appendChild(td); resultsBody.appendChild(tr);
                resultsWrap.style.display = '';
            });
        }

        if (searchBtn) searchBtn.addEventListener('click', doSearch);
        if (searchInput) searchInput.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); doSearch(); } });
        if (manualSelectAll) {
            manualSelectAll.addEventListener('change', function () {
                document.querySelectorAll('.manual-row-check').forEach(function (b) { b.checked = manualSelectAll.checked; });
            });
        }
        if (addBtn) {
            addBtn.addEventListener('click', function () {
                var selected = document.querySelectorAll('.manual-row-check:checked');
                if (selected.length === 0) { showFlashError('Select at least one product to add.'); return; }
                document.getElementById('add-products-form').submit();
            });
        }
    })();
    </script>
@endsection
