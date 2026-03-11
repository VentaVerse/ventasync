@extends('layouts.app')
@section('breadcrumb', 'Marketplace / OpenCart / Orders')

@section('content')
<div class="card">
    <div class="page-header">
        <div>
            <h2>OpenCart Orders</h2>
            <div class="text-muted text-sm">{{ $storeLabel }}</div>
        </div>
        <div class="page-header-actions">
            <a class="btn" href="{{ route('orders.create') }}">Add Order</a>
        </div>
    </div>

    @php
        $hasFilters = ($q ?? '') !== '' || ($statusId ?? 0) > 0;
    @endphp
    <form method="GET" action="{{ route('ext.opencart.orders.index', $store) }}" id="oc-ord-filter-form">
        <div style="display:flex; align-items:center; gap:8px; margin-bottom:12px;">
            <div style="position:relative; flex:1; max-width:400px;">
                <svg style="position:absolute; left:12px; top:50%; transform:translateY(-50%); width:16px; height:16px; color:var(--text-muted); pointer-events:none;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                <input class="input" name="q" value="{{ $q ?? '' }}" placeholder="Search name, email, order ID..." style="padding-left:36px;">
            </div>
            <button class="btn" type="submit">Search</button>
            @if($hasFilters)
                <a href="{{ route('ext.opencart.orders.index', $store) }}" class="btn secondary" title="Clear all filters">Clear</a>
            @endif
        </div>

        <div style="display:grid; grid-template-columns:repeat(auto-fill, minmax(180px, 1fr)); gap:10px; margin-bottom:16px;">
            <div>
                <label style="margin-bottom:4px;">Status</label>
                <select class="input" name="status" onchange="this.form.submit()">
                    <option value="0">All Statuses</option>
                    @foreach($statuses as $s)
                        <option value="{{ $s->order_status_id }}" {{ ($statusId ?? 0) == $s->order_status_id ? 'selected' : '' }}>{{ $s->name }}</option>
                    @endforeach
                </select>
            </div>
        </div>
    </form>

    <div class="table-wrap">
    <table>
        <thead>
        <tr>
            <th style="width:70px;">
                <a href="{{ route('ext.opencart.orders.index', array_merge(['store' => $store], request()->except('sort','dir'), ['sort' => 'order_id', 'dir' => ($sort === 'order_id' && $dir === 'asc') ? 'desc' : 'asc'])) }}">
                    ID {!! $sort === 'order_id' ? ($dir === 'asc' ? '&#9650;' : '&#9660;') : '' !!}
                </a>
            </th>
            <th style="width:56px;">Image</th>
            <th>
                <a href="{{ route('ext.opencart.orders.index', array_merge(['store' => $store], request()->except('sort','dir'), ['sort' => 'firstname', 'dir' => ($sort === 'firstname' && $dir === 'asc') ? 'desc' : 'asc'])) }}">
                    Customer {!! $sort === 'firstname' ? ($dir === 'asc' ? '&#9650;' : '&#9660;') : '' !!}
                </a>
            </th>
            <th>Store</th>
            <th>Marketplace Order #</th>
            <th>Status</th>
            <th class="text-right">
                <a href="{{ route('ext.opencart.orders.index', array_merge(['store' => $store], request()->except('sort','dir'), ['sort' => 'total', 'dir' => ($sort === 'total' && $dir === 'asc') ? 'desc' : 'asc'])) }}">
                    Total {!! $sort === 'total' ? ($dir === 'asc' ? '&#9650;' : '&#9660;') : '' !!}
                </a>
            </th>
            <th>
                <a href="{{ route('ext.opencart.orders.index', array_merge(['store' => $store], request()->except('sort','dir'), ['sort' => 'date_added', 'dir' => ($sort === 'date_added' && $dir === 'asc') ? 'desc' : 'asc'])) }}">
                    Date Added {!! $sort === 'date_added' ? ($dir === 'asc' ? '&#9650;' : '&#9660;') : '' !!}
                </a>
            </th>
            <th style="width:200px;">Actions</th>
        </tr>
        </thead>
        <tbody>
        @forelse($orders as $o)
            @php
                $firstProduct = $o->products->first();
                $imgPath = $firstProduct ? trim($productImages[$firstProduct->order_product_id] ?? '') : '';
                $imgSrc = $imgPath !== '' ? asset('storage/' . ltrim($imgPath, '/')) : '';
                if ($imgSrc === '' && $firstProduct) {
                    $lzImg = trim($lazadaImages[$firstProduct->order_product_id] ?? '');
                    if ($lzImg !== '') { $imgSrc = $lzImg; }
                }
                $ocId = $store;
                $ocName = ($ocStoreNames[$ocId] ?? '') ?: ('OpenCart #' . $ocId);
            @endphp
            <tr>
                <td>{{ $o->order_id }}</td>
                <td>
                    @if($imgSrc)
                        <img src="{{ $imgSrc }}" alt="" class="thumb thumb-sm" loading="lazy" decoding="async" onerror="this.style.display='none';">
                    @else
                        <span class="thumb-placeholder" style="width:42px;height:42px;"></span>
                    @endif
                </td>
                <td>{{ $o->firstname }} {{ $o->lastname }}</td>
                <td><span class="badge badge-dark">{{ $ocName }}</span></td>
                <td>{{ $o->marketplace_order_id ?: '—' }}</td>
                <td>{{ $o->status->name ?? '-' }}</td>
                <td class="text-right">{{ number_format($o->total, 2) }}</td>
                <td class="text-nowrap">{{ $o->date_added }}</td>
                <td>
                    <div class="d-flex gap-6 items-center">
                        <a class="btn small" href="{{ route('orders.show', $o->order_id) }}">View</a>
                        <a class="btn small secondary" href="{{ route('orders.edit', $o->order_id) }}">Edit</a>
                        <form method="POST" action="{{ route('orders.destroy', $o->order_id) }}" data-confirm="Delete this order?">
                            @csrf
                            @method('DELETE')
                            <button class="btn danger small" type="submit">Delete</button>
                        </form>
                    </div>
                </td>
            </tr>
        @empty
            <tr><td colspan="9" class="text-center text-muted" style="padding:24px;">No OpenCart orders found.</td></tr>
        @endforelse
        </tbody>
    </table>
    </div>

    <div class="mt-16">
        {{ $orders->onEachSide(1)->links('vendor.pagination.simple') }}
    </div>
</div>
@endsection
