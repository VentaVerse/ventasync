@extends('layouts.app')
@section('breadcrumb', 'Sales / Orders')

@section('content')
<div class="card">
    <div class="page-header">
        <h2>Orders</h2>
        <div class="page-header-actions">
            <a class="btn" href="{{ route('orders.create') }}">Add Order</a>
            <button class="btn danger" type="submit" form="ord-bulk-form" id="ord-bulk-delete-btn">Delete Selected</button>
        </div>
    </div>

    <div class="toolbar">
        <form method="GET" action="{{ route('orders.index') }}">
            <input class="input" name="q" value="{{ $q ?? '' }}" placeholder="Search name, email, order ID" style="min-width:220px;">
            <select class="input" name="status" style="min-width:140px;">
                <option value="0">All Statuses</option>
                @foreach($statuses as $s)
                    <option value="{{ $s->order_status_id }}" {{ ($statusId ?? 0) == $s->order_status_id ? 'selected' : '' }}>{{ $s->name }}</option>
                @endforeach
            </select>
            <select class="input" name="source" style="min-width:120px;">
                <option value="">All Sources</option>
                <option value="manual" {{ ($source ?? '') === 'manual' ? 'selected' : '' }}>Manual</option>
                <option value="lazada" {{ ($source ?? '') === 'lazada' ? 'selected' : '' }}>Lazada</option>
            </select>
            <button class="btn secondary" type="submit">Search</button>
        </form>
    </div>

    <form id="ord-bulk-form" method="POST" action="{{ route('orders.bulk') }}" onsubmit="return window.__confirmOrdBulkDelete();">
        @csrf
    </form>

    <div class="table-wrap">
    <table>
        <thead>
        <tr>
            <th style="width:36px;"><input type="checkbox" id="ord-select-all" title="Select all"></th>
            <th style="width:70px;">
                <a href="{{ route('orders.index', array_merge(request()->except('sort','dir'), ['sort' => 'order_id', 'dir' => ($sort === 'order_id' && $dir === 'asc') ? 'desc' : 'asc'])) }}">
                    ID {!! $sort === 'order_id' ? ($dir === 'asc' ? '&#9650;' : '&#9660;') : '' !!}
                </a>
            </th>
            <th style="width:56px;">Image</th>
            <th>
                <a href="{{ route('orders.index', array_merge(request()->except('sort','dir'), ['sort' => 'firstname', 'dir' => ($sort === 'firstname' && $dir === 'asc') ? 'desc' : 'asc'])) }}">
                    Customer {!! $sort === 'firstname' ? ($dir === 'asc' ? '&#9650;' : '&#9660;') : '' !!}
                </a>
            </th>
            <th>Source</th>
            <th>Status</th>
            <th class="text-right">
                <a href="{{ route('orders.index', array_merge(request()->except('sort','dir'), ['sort' => 'total', 'dir' => ($sort === 'total' && $dir === 'asc') ? 'desc' : 'asc'])) }}">
                    Total {!! $sort === 'total' ? ($dir === 'asc' ? '&#9650;' : '&#9660;') : '' !!}
                </a>
            </th>
            <th>
                <a href="{{ route('orders.index', array_merge(request()->except('sort','dir'), ['sort' => 'date_added', 'dir' => ($sort === 'date_added' && $dir === 'asc') ? 'desc' : 'asc'])) }}">
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
            @endphp
            <tr>
                <td><input type="checkbox" class="ord-row-check" name="ids[]" value="{{ (int)$o->order_id }}" form="ord-bulk-form"></td>
                <td>{{ $o->order_id }}</td>
                <td>
                    @if($imgSrc)
                        <img src="{{ $imgSrc }}" alt="" class="thumb thumb-sm" loading="lazy" decoding="async" onerror="this.style.display='none';">
                    @else
                        <span class="thumb-placeholder" style="width:42px;height:42px;"></span>
                    @endif
                </td>
                <td>{{ $o->firstname }} {{ $o->lastname }}</td>
                <td>
                    @if($o->marketplace_source === 'lazada')
                        <span class="badge badge-indigo">Lazada</span>
                    @elseif($o->marketplace_source !== '')
                        <span class="badge badge-dark">{{ ucfirst($o->marketplace_source) }}</span>
                    @else
                        <span class="badge badge-default">Manual</span>
                    @endif
                </td>
                <td>{{ $o->status->name ?? '-' }}</td>
                <td class="text-right">{{ number_format($o->total, 2) }}</td>
                <td class="text-nowrap">{{ $o->date_added }}</td>
                <td>
                    <div class="d-flex gap-6 items-center">
                        <a class="btn small" href="{{ route('orders.show', $o->order_id) }}">View</a>
                        <a class="btn small secondary" href="{{ route('orders.edit', $o->order_id) }}">Edit</a>
                        <form method="POST" action="{{ route('orders.destroy', $o->order_id) }}" onsubmit="return confirm('Delete this order?')">
                            @csrf
                            @method('DELETE')
                            <button class="btn danger small" type="submit">Delete</button>
                        </form>
                    </div>
                </td>
            </tr>
        @empty
            <tr><td colspan="9" class="text-center text-muted" style="padding:24px;">No orders found.</td></tr>
        @endforelse
        </tbody>
    </table>
    </div>

    <script>
        (function () {
            var selectAll = document.getElementById('ord-select-all');
            var checks = function () { return Array.prototype.slice.call(document.querySelectorAll('.ord-row-check')); };

            function updateSelectAllState() {
                var boxes = checks();
                if (!selectAll) return;
                if (boxes.length === 0) {
                    selectAll.checked = false;
                    selectAll.indeterminate = false;
                    return;
                }
                var checkedCount = boxes.filter(function (b) { return b.checked; }).length;
                selectAll.checked = checkedCount === boxes.length;
                selectAll.indeterminate = checkedCount > 0 && checkedCount < boxes.length;
            }

            if (selectAll) {
                selectAll.addEventListener('change', function () {
                    checks().forEach(function (b) { b.checked = selectAll.checked; });
                    updateSelectAllState();
                });
            }

            checks().forEach(function (b) {
                b.addEventListener('change', updateSelectAllState);
            });

            window.__confirmOrdBulkDelete = function () {
                var selected = checks().filter(function (b) { return b.checked; }).length;
                if (selected === 0) {
                    alert('Please select at least one order.');
                    return false;
                }
                return confirm('Delete selected orders? This cannot be undone.');
            };

            updateSelectAllState();
        })();
    </script>

    <div class="mt-16">
        {{ $orders->onEachSide(1)->links('vendor.pagination.simple') }}
    </div>
</div>
@endsection