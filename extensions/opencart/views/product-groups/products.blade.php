@extends('layouts.app')
@section('breadcrumb', 'Integrations / ' . $setting->store_name . ' / ' . $group->name . ' / Products')

@section('content')
@php
    $canManageOpencart = true;
@endphp
    <div class="page-header">
        <div>
            <h2>{{ $group->name }} — Products</h2>
            <div class="text-muted text-sm">{{ $setting->store_name }} — select products and push to OpenCart.</div>
        </div>
        <div class="page-header-actions">
            @if($canManageOpencart)
            <a class="btn secondary" href="{{ route('ext.opencart.product-groups.edit', [$setting->id, $group->id]) }}">Edit Product Group</a>
            @endif
            <a class="btn secondary" href="{{ route('ext.opencart.product-groups.index', $setting->id) }}">Back to Product Groups</a>
            @if($canManageOpencart)
            <button class="btn" type="submit" form="push-form" onclick="return confirmPush(event);">Push Selected</button>
            @endif
        </div>
    </div>

    {{-- Search --}}
    <div class="toolbar">
        <form method="GET" action="{{ route('ext.opencart.product-groups.products', [$setting->id, $group->id]) }}">
            <input class="input" name="q" value="{{ $q ?? '' }}" placeholder="Search name / SKU / model" style="min-width:220px;">
            <button class="btn" type="submit">Search</button>
        </form>
    </div>

    {{-- Push form --}}
    <form id="push-form" method="POST" action="{{ route('ext.opencart.product-groups.push', [$setting->id, $group->id]) }}">
        @csrf
    </form>

    {{-- Add manual products form --}}
    <form id="add-products-form" method="POST" action="{{ route('ext.opencart.product-groups.addProducts', [$setting->id, $group->id]) }}">
        @csrf
    </form>

    <div class="card">
        <div class="table-wrap">
            <table class="table">
                <thead>
                <tr>
                    <th style="width:36px;">
                        @if($canManageOpencart)<input type="checkbox" id="select-all" title="Select all">@endif
                    </th>
                    <th style="width:70px;">ID</th>
                    <th style="width:70px;">Image</th>
                    <th>Name</th>
                    <th style="width:130px;">SKU</th>
                    <th style="width:100px;">Price</th>
                    <th style="width:80px;">Qty</th>
                    <th style="width:90px;">Status</th>
                    <th>Sync Status</th>
                    <th style="width:100px;">Source</th>
                    <th style="width:140px;"></th>
                </tr>
                </thead>
                <tbody>
                @forelse($products as $p)
                    @php
                        $link = $links->get($p->product_id);
                        $isManual = in_array((int)$p->product_id, $manualIds);
                        $img = trim((string)($p->image ?? ''));
                        $src = $img !== '' ? asset('storage/' . ltrim($img, '/')) : '';
                    @endphp
                    <tr>
                        <td>
                            @if($canManageOpencart)<input type="checkbox" class="row-check" name="ids[]" value="{{ (int)$p->product_id }}" form="push-form">@endif
                        </td>
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
                        </td>
                        <td>{{ $p->sku ?? '-' }}</td>
                        <td>{{ number_format((float)$p->price, 2) }}</td>
                        <td>{{ (int)$p->quantity }}</td>
                        <td>{{ (int)$p->status === 1 ? 'Enabled' : 'Disabled' }}</td>
                        <td>
                            <div class="d-flex gap-4 flex-wrap">
                                @if($link)
                                    <span class="badge badge-green" title="{{ $setting->store_name }} #{{ $link->oc_product_id }}">{{ $setting->store_name }} #{{ $link->oc_product_id }}</span>
                                @endif
                                @if(isset($otherOcLinks[$p->product_id]))
                                    @foreach($otherOcLinks[$p->product_id] as $oLink)
                                        <span class="badge badge-green" title="{{ $otherStoreNames[$oLink->opencart_setting_id] ?? 'OC' }} #{{ $oLink->oc_product_id }}">{{ $otherStoreNames[$oLink->opencart_setting_id] ?? 'OC' }} #{{ $oLink->oc_product_id }}</span>
                                    @endforeach
                                @endif
                                @if($shopeeLinked->has($p->product_id))
                                    <span class="badge badge-green" title="Shopee Item {{ $shopeeLinked[$p->product_id] }}">Shopee</span>
                                @endif
                                @if($lazadaLinked->has($p->product_id))
                                    <span class="badge badge-green" title="Lazada Item {{ $lazadaLinked[$p->product_id] }}">Lazada</span>
                                @endif
                                @if($tiktokLinked->has($p->product_id))
                                    <span class="badge badge-green" title="TikTok Product {{ $tiktokLinked[$p->product_id] }}">TikTok</span>
                                @endif
                                @if(!$link && !isset($otherOcLinks[$p->product_id]) && !$shopeeLinked->has($p->product_id) && !$lazadaLinked->has($p->product_id) && !$tiktokLinked->has($p->product_id))
                                    <span class="text-muted">Not synced</span>
                                @endif
                            </div>
                        </td>
                        <td>
                            @if($isManual)
                                <span class="badge">Manual</span>
                            @else
                                <span class="text-muted text-sm">Filter</span>
                            @endif
                        </td>
                        <td class="d-flex gap-4">
                            @if($canManageOpencart)
                            <form method="POST" action="{{ route('ext.opencart.products.push', [$setting->id, $p->product_id]) }}" data-confirm="Push this product to {{ $setting->store_name }}?">
                                @csrf
                                <button type="submit" class="btn small">Push</button>
                            </form>
                            @if($isManual)
                                <form method="POST" action="{{ route('ext.opencart.product-groups.removeProduct', [$setting->id, $group->id, $p->product_id]) }}" data-confirm="Remove from product group?">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="btn small danger">Remove</button>
                                </form>
                            @endif
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

        <div class="mt-16">
            {{ $products->onEachSide(1)->links('vendor.pagination.simple') }}
        </div>
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
        var selectAll = document.getElementById('select-all');
        var checks = function () { return Array.from(document.querySelectorAll('.row-check')); };

        if (selectAll) {
            selectAll.addEventListener('change', function () {
                checks().forEach(function (b) { b.checked = selectAll.checked; });
            });
        }

        window.confirmPush = function (e) {
            var selected = checks().filter(function (b) { return b.checked; }).length;
            if (selected === 0) {
                showFlashError('Please select at least one product.');
                return false;
            }
            if (e && e.preventDefault) e.preventDefault();
            var form = document.getElementById('push-form');
            confirmModal('Push ' + selected + ' product(s) to {{ $setting->store_name }}?').then(function (ok) {
                if (ok) { form._confirmed = true; form.requestSubmit ? form.requestSubmit() : form.submit(); }
            });
            return false;
        };

        var searchInput = document.getElementById('manual-search');
        var searchBtn = document.getElementById('manual-search-btn');
        var resultsWrap = document.getElementById('manual-results-wrap');
        var resultsBody = document.querySelector('#manual-results-table tbody');
        var addBtn = document.getElementById('add-selected-btn');
        var manualSelectAll = document.getElementById('manual-select-all');
        var searchUrl = '{{ route("ext.opencart.product-groups.searchProducts", $setting->id) }}';

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
                    var emptyTr = document.createElement('tr');
                    var emptyTd = document.createElement('td');
                    emptyTd.setAttribute('colspan', '5');
                    emptyTd.className = 'text-muted';
                    emptyTd.textContent = 'No results.';
                    emptyTr.appendChild(emptyTd);
                    resultsBody.appendChild(emptyTr);
                } else {
                    items.forEach(function (item) {
                        var tr = document.createElement('tr');

                        var td1 = document.createElement('td');
                        var cb = document.createElement('input');
                        cb.type = 'checkbox';
                        cb.className = 'manual-row-check';
                        cb.value = item.product_id;
                        cb.setAttribute('form', 'add-products-form');
                        cb.name = 'ids[]';
                        td1.appendChild(cb);
                        tr.appendChild(td1);

                        var td2 = document.createElement('td');
                        td2.textContent = item.product_id;
                        tr.appendChild(td2);

                        var td3 = document.createElement('td');
                        td3.textContent = item.name || '-';
                        tr.appendChild(td3);

                        var td4 = document.createElement('td');
                        td4.textContent = item.sku || '-';
                        tr.appendChild(td4);

                        var td5 = document.createElement('td');
                        td5.textContent = item.price ? parseFloat(item.price).toFixed(2) : '0.00';
                        tr.appendChild(td5);

                        resultsBody.appendChild(tr);
                    });
                }

                resultsWrap.style.display = '';
            })
            .catch(function () {
                resultsBody.textContent = '';
                var errTr = document.createElement('tr');
                var errTd = document.createElement('td');
                errTd.setAttribute('colspan', '5');
                errTd.className = 'text-muted';
                errTd.textContent = 'Search failed.';
                errTr.appendChild(errTd);
                resultsBody.appendChild(errTr);
                resultsWrap.style.display = '';
            });
        }

        if (searchBtn) searchBtn.addEventListener('click', doSearch);
        if (searchInput) searchInput.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') { e.preventDefault(); doSearch(); }
        });

        if (manualSelectAll) {
            manualSelectAll.addEventListener('change', function () {
                document.querySelectorAll('.manual-row-check').forEach(function (b) { b.checked = manualSelectAll.checked; });
            });
        }

        if (addBtn) {
            addBtn.addEventListener('click', function () {
                var selected = document.querySelectorAll('.manual-row-check:checked');
                if (selected.length === 0) {
                    showFlashError('Select at least one product to add.');
                    return;
                }
                document.getElementById('add-products-form').submit();
            });
        }
    })();
    </script>
@endsection
