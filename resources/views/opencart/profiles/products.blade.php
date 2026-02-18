@extends('layouts.app')
@section('breadcrumb', 'Marketplace / ' . $setting->store_name . ' / ' . $profile->name . ' / Products')

@section('content')
    <div class="page-header">
        <div>
            <h2>{{ $profile->name }} — Products</h2>
            <div class="text-muted text-sm">{{ $setting->store_name }} — select products and push to OpenCart.</div>
        </div>
        <div class="page-header-actions">
            <a class="btn secondary" href="{{ route('opencart.profiles.edit', [$setting->id, $profile->id]) }}">Edit Profile</a>
            <a class="btn secondary" href="{{ route('opencart.profiles.index', $setting->id) }}">Back to Profiles</a>
            <button class="btn" type="submit" form="push-form" onclick="return confirmPush();">Push Selected</button>
        </div>
    </div>

    @if(session('status'))
        <div class="alert success">{{ session('status') }}</div>
    @endif

    {{-- Search --}}
    <div class="toolbar">
        <form method="GET" action="{{ route('opencart.profiles.products', [$setting->id, $profile->id]) }}">
            <input class="input" name="q" value="{{ $q ?? '' }}" placeholder="Search name / SKU / model" style="min-width:220px;">
            <button class="btn secondary" type="submit">Search</button>
        </form>
    </div>

    {{-- Push form --}}
    <form id="push-form" method="POST" action="{{ route('opencart.profiles.push', [$setting->id, $profile->id]) }}">
        @csrf
    </form>

    {{-- Add manual products form --}}
    <form id="add-products-form" method="POST" action="{{ route('opencart.profiles.addProducts', [$setting->id, $profile->id]) }}">
        @csrf
    </form>

    <div class="card">
        <div class="table-wrap">
            <table class="table">
                <thead>
                <tr>
                    <th style="width:36px;">
                        <input type="checkbox" id="select-all" title="Select all">
                    </th>
                    <th style="width:70px;">ID</th>
                    <th style="width:70px;">Image</th>
                    <th>Name</th>
                    <th style="width:130px;">SKU</th>
                    <th style="width:100px;">Price</th>
                    <th style="width:80px;">Qty</th>
                    <th style="width:90px;">Status</th>
                    <th style="width:100px;">OC Link</th>
                    <th style="width:100px;">Source</th>
                    <th style="width:80px;"></th>
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
                            <input type="checkbox" class="row-check" name="ids[]" value="{{ (int)$p->product_id }}" form="push-form">
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
                            @if($link)
                                <span class="badge badge-green">OC #{{ $link->oc_product_id }}</span>
                            @else
                                <span class="text-muted">Not pushed</span>
                            @endif
                        </td>
                        <td>
                            @if($isManual)
                                <span class="badge">Manual</span>
                            @else
                                <span class="text-muted text-sm">Filter</span>
                            @endif
                        </td>
                        <td>
                            @if($isManual)
                                <form method="POST" action="{{ route('opencart.profiles.removeProduct', [$setting->id, $profile->id, $p->product_id]) }}" style="margin:0;" onsubmit="return confirm('Remove from profile?');">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="btn small danger">Remove</button>
                                </form>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="11" class="text-muted">No products match this profile. Add filters or add products manually below.</td>
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
        <div class="hint" style="margin-bottom:10px;">Search for products to add individually to this profile.</div>

        <div class="d-flex gap-8 items-center flex-wrap">
            <input type="text" class="input" id="manual-search" placeholder="Search by name or SKU..." style="min-width:260px;">
            <button type="button" class="btn secondary" id="manual-search-btn">Search</button>
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
        // ── Push form: select all / confirm ──
        var selectAll = document.getElementById('select-all');
        var checks = function () { return Array.from(document.querySelectorAll('.row-check')); };

        if (selectAll) {
            selectAll.addEventListener('change', function () {
                checks().forEach(function (b) { b.checked = selectAll.checked; });
            });
        }

        window.confirmPush = function () {
            var selected = checks().filter(function (b) { return b.checked; }).length;
            if (selected === 0) {
                alert('Please select at least one product.');
                return false;
            }
            return confirm('Push ' + selected + ' product(s) to {{ $setting->store_name }}?');
        };

        // ── Manual product search ──
        var searchInput = document.getElementById('manual-search');
        var searchBtn = document.getElementById('manual-search-btn');
        var resultsWrap = document.getElementById('manual-results-wrap');
        var resultsBody = document.querySelector('#manual-results-table tbody');
        var addBtn = document.getElementById('add-selected-btn');
        var manualSelectAll = document.getElementById('manual-select-all');
        var searchUrl = '{{ route("opencart.profiles.searchProducts", $setting->id) }}';

        function doSearch() {
            var q = (searchInput.value || '').trim();
            if (q.length < 2) return;

            fetch(searchUrl + '?q=' + encodeURIComponent(q), {
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
            })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                var items = data.items || [];
                resultsBody.innerHTML = '';

                if (items.length === 0) {
                    resultsBody.innerHTML = '<tr><td colspan="5" class="text-muted">No results.</td></tr>';
                } else {
                    items.forEach(function (item) {
                        var tr = document.createElement('tr');
                        var name = document.createElement('span');
                        name.textContent = item.name || '-';
                        var sku = document.createElement('span');
                        sku.textContent = item.sku || '-';

                        tr.innerHTML = '<td><input type="checkbox" class="manual-row-check" value="' + item.product_id + '" form="add-products-form" name="ids[]"></td>'
                            + '<td>' + item.product_id + '</td>'
                            + '<td></td>'
                            + '<td></td>'
                            + '<td>' + (item.price ? parseFloat(item.price).toFixed(2) : '0.00') + '</td>';

                        tr.children[2].appendChild(name);
                        tr.children[3].appendChild(sku);
                        resultsBody.appendChild(tr);
                    });
                }

                resultsWrap.style.display = '';
            })
            .catch(function () {
                resultsBody.innerHTML = '<tr><td colspan="5" class="text-muted">Search failed.</td></tr>';
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
                    alert('Select at least one product to add.');
                    return;
                }
                document.getElementById('add-products-form').submit();
            });
        }
    })();
    </script>
@endsection
