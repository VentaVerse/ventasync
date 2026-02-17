@extends('layouts.app')

@section('content')
<style>
    .ord-thumb{width:42px;height:42px;object-fit:cover;border-radius:6px;border:1px solid rgba(0,0,0,0.08);display:block;background:#f8fafc;}
</style>
<div class="card">
    <div class="page-header">
        <h2>Orders</h2>
        <div style="display:flex; gap:10px; align-items:center;">
            <a class="btn" href="{{ route('orders.create') }}">Add Order</a>
            <button class="btn danger" type="submit" form="ord-bulk-form" id="ord-bulk-delete-btn">Delete Selected</button>
        </div>
    </div>

    <div class="toolbar">
        <form method="GET" action="{{ route('orders.index') }}" style="flex-wrap:wrap;">
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

    <table>
        <thead>
        <tr>
            <th style="width:36px;"><input type="checkbox" id="ord-select-all" title="Select all"></th>
            <th style="width:70px;">ID</th>
            <th style="width:56px;">Image</th>
            <th>Customer</th>
            <th>Source</th>
            <th>Status</th>
            <th style="text-align:right;">Total</th>
            <th>Date Added</th>
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
                        <img src="{{ $imgSrc }}" alt="" class="ord-thumb" loading="lazy" decoding="async" onerror="this.style.display='none';">
                    @else
                        <span style="display:inline-block;width:42px;height:42px;border-radius:6px;background:#f1f5f9;border:1px solid #e2e8f0;"></span>
                    @endif
                </td>
                <td>{{ $o->firstname }} {{ $o->lastname }}</td>
                <td>
                    @if($o->marketplace_source === 'lazada')
                        <span style="display:inline-block;padding:2px 8px;border-radius:4px;background:#0f1689;color:#fff;font-size:11px;font-weight:600;">Lazada</span>
                    @elseif($o->marketplace_source !== '')
                        <span style="display:inline-block;padding:2px 8px;border-radius:4px;background:#64748b;color:#fff;font-size:11px;font-weight:600;">{{ ucfirst($o->marketplace_source) }}</span>
                    @else
                        <span style="display:inline-block;padding:2px 8px;border-radius:4px;background:#e2e8f0;color:#475569;font-size:11px;font-weight:600;">Manual</span>
                    @endif
                </td>
                <td>{{ $o->status->name ?? '-' }}</td>
                <td style="text-align:right;">{{ number_format($o->total, 2) }}</td>
                <td style="white-space:nowrap;">{{ $o->date_added }}</td>
                <td style="display:flex; gap:6px; align-items:center;">
                    <a class="btn small" href="{{ route('orders.show', $o->order_id) }}">View</a>
                    <a class="btn small secondary" href="{{ route('orders.edit', $o->order_id) }}">Edit</a>
                    <form method="POST" action="{{ route('orders.destroy', $o->order_id) }}" onsubmit="return confirm('Delete this order?')">
                        @csrf
                        @method('DELETE')
                        <button class="btn danger small" type="submit">Delete</button>
                    </form>
                </td>
            </tr>
        @empty
            <tr><td colspan="9" style="text-align:center; padding:24px; color:#94a3b8;">No orders found.</td></tr>
        @endforelse
        </tbody>
    </table>

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

    <div style="margin-top:14px;">
        {{ $orders->onEachSide(1)->links('vendor.pagination.simple') }}
    </div>
</div>
@endsection
