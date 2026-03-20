@extends('layouts.app')
@section('breadcrumb', 'Catalog / Products')

@section('content')
<div class="card">
    <div class="page-header">
        <h2>Products</h2>
        <div class="page-header-actions">
            <a class="btn" href="{{ route('products.create') }}">Add Product</a>
            <select class="input" name="action" form="product-bulk-form" style="width:180px;">
                <option value="">-- Action --</option>
                <option value="enable">Enable</option>
                <option value="disable">Disable</option>
                <option value="delete">Delete</option>
            </select>
            <button class="btn" type="submit" form="product-bulk-form" id="product-apply-btn">Apply To Selected</button>
        </div>
    </div>

    <div class="toolbar">
        <form method="GET" action="{{ route('products.index') }}" class="d-flex gap-8 items-center">
            <input class="input" name="q" value="{{ $q ?? '' }}" placeholder="Search name" style="width:220px;">
            <select class="input" name="status" style="width:130px;">
                <option value="">All Status</option>
                <option value="1" {{ ($status ?? '') === '1' ? 'selected' : '' }}>Enabled</option>
                <option value="0" {{ ($status ?? '') === '0' ? 'selected' : '' }}>Disabled</option>
            </select>
            <button class="btn" type="submit">Search</button>
        </form>
    </div>

    <form id="product-bulk-form" method="POST" action="{{ route('products.bulk') }}">
        @csrf
    </form>

    <div class="table-wrap">
    <table>
        <thead>
@php
    $currentSort = $sort ?? 'product_id';
    $currentDir = $dir ?? 'asc';
    function sort_link($field, $label, $currentSort, $currentDir, $q, $status = '') {
        $dir = 'asc';
        if ($currentSort === $field) {
            $dir = $currentDir === 'asc' ? 'desc' : 'asc';
        }
        $params = ['sort' => $field, 'dir' => $dir];
        if (!empty($q)) $params['q'] = $q;
        if ($status !== '') $params['status'] = $status;
        $arrow = '';
        if ($currentSort === $field) {
            $arrow = $currentDir === 'asc' ? ' ▲' : ' ▼';
        }
        return '<a href="'.e(route('products.index', $params)).'">'.e($label).$arrow.'</a>';
    }
@endphp
<tr>
    <th style="width:36px;">
        <input type="checkbox" id="product-select-all" title="Select all">
    </th>
    <th style="width:90px;">{!! sort_link('product_id','ID',$currentSort,$currentDir,$q ?? '',$status ?? '') !!}</th>
    <th style="width:70px;">Image</th>
    <th>{!! sort_link('name','Name',$currentSort,$currentDir,$q ?? '',$status ?? '') !!}</th>
    <th style="width:140px;">SKU</th>
    <th style="width:130px;">{!! sort_link('quantity','Quantity',$currentSort,$currentDir,$q ?? '',$status ?? '') !!}</th>
    <th style="width:120px;">{!! sort_link('price','Price',$currentSort,$currentDir,$q ?? '',$status ?? '') !!}</th>
    <th style="width:110px;">{!! sort_link('status','Status',$currentSort,$currentDir,$q ?? '',$status ?? '') !!}</th>
    <th style="width:150px;">Actions</th>
</tr>
</thead>
        <tbody>
        @foreach($products as $p)
            <tr>
                <td>
                    <input type="checkbox" class="product-row-check" name="ids[]" value="{{ (int)$p->product_id }}" form="product-bulk-form">
                </td>
                <td>{{ $p->product_id }}</td>
                <td>
                    @php
                        $img = trim((string)($p->image ?? ''));
                        $src = '';
                        if ($img !== '') {
                            $src = $img !== '' ? asset('storage/' . ltrim($img, '/')) : '';
                        }
                    @endphp
                    @if($src)
                        <img src="{{ $src }}" alt="" class="thumb" loading="lazy" decoding="async" onerror="this.style.display='none';">
                    @else
                        <span class="text-muted">—</span>
                    @endif
                </td>
                <td>
                    <div class="font-semibold truncate">{{ $p->name ?? '-' }}</div>
                    @php
                        $optRows = is_array($p->option_rows ?? null) ? $p->option_rows : (is_iterable($p->option_rows ?? null) ? $p->option_rows : []);
                    @endphp
                </td>
                <td class="text-xs text-secondary">{{ $p->sku ?: '—' }}</td>
                <td>{{ (int)$p->quantity }}</td>
                <td>{{ number_format((float)$p->price, 2) }}</td>
                <td>{{ (int)$p->status === 1 ? 'Enabled' : 'Disabled' }}</td>
                <td>
                    <div class="d-flex gap-8 items-center">
                        <a class="btn small secondary" href="{{ route('products.sales', $p->product_id) }}?back={{ urlencode(request()->fullUrl()) }}">Sales</a>
                        <a class="btn small secondary" href="{{ route('products.stock_history', $p->product_id) }}?back={{ urlencode(request()->fullUrl()) }}">Stock</a>
                        <a class="btn small" href="{{ route('products.edit', $p->product_id) }}">Edit</a>
                        <form method="POST" action="{{ route('products.destroy', $p->product_id) }}" data-confirm="Delete this product?">
                            @csrf
                            @method('DELETE')
                            <button class="btn danger small" type="submit">Delete</button>
                        </form>
                    </div>
                </td>
            </tr>

            @if(!empty($optRows))
                @foreach($optRows as $or)
                    @php
                        $ovImg = trim((string)($or->option_image ?? ''));
                        $ovSrc = $ovImg !== '' ? asset('storage/' . ltrim($ovImg, '/')) : '';

                        $final = (float)($or->absolute_price ?? 0);
                    @endphp
                    <tr class="option-row">
                        <td></td>
                        <td></td>
                        <td>
                            @if($ovSrc)
                                <img src="{{ $ovSrc }}" alt="" class="thumb thumb-sm" loading="lazy" decoding="async" onerror="this.style.display='none';">
                            @else
                                <span class="text-muted">—</span>
                            @endif
                        </td>
                        <td style="padding-left:28px;">
                            <div class="font-semibold text-sm">{{ $or->option_value_name ?? '' }}</div>
                            <div class="text-xs text-muted" style="margin-top:2px;">{{ $or->option_name ?? '' }}</div>
                        </td>
                        <td class="text-xs text-secondary">{{ $or->sku ?: '—' }}</td>
                        <td class="font-semibold">{{ (int)($or->quantity ?? 0) }}</td>
                        <td>{{ number_format($final, 2) }}</td>
                        <td></td>
                        <td></td>
                    </tr>
                @endforeach
            @endif
        @endforeach
        </tbody>
    </table>
    </div>

    <div class="mt-16">
        {{ $products->onEachSide(1)->links('vendor.pagination.simple') }}
    </div>
</div>

<script>
    (function () {
        var selectAll = document.getElementById('product-select-all');
        var checks = function () { return Array.prototype.slice.call(document.querySelectorAll('.product-row-check')); };
        var applyBtn = document.getElementById('product-apply-btn');

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

        var bulkForm = document.getElementById('product-bulk-form');
        var bulkConfirmed = false;
        if (bulkForm) {
            bulkForm.addEventListener('submit', function(e) {
                var actionEl = document.querySelector('select[name="action"][form="product-bulk-form"]');
                var action = actionEl ? actionEl.value : '';
                var selected = checks().filter(function (b) { return b.checked; }).length;

                if (!action) {
                    e.preventDefault();
                    showFlashError('Please choose an action first.');
                    return;
                }
                if (selected === 0) {
                    e.preventDefault();
                    showFlashError('Please select at least one product.');
                    return;
                }
                if (action === 'delete' && !bulkConfirmed) {
                    e.preventDefault();
                    confirmModal('Delete selected products? This cannot be undone.').then(function(ok) {
                        if (!ok) return;
                        bulkConfirmed = true;
                        bulkForm.submit();
                    });
                    return;
                }
                bulkConfirmed = false;
            });
        }

        updateSelectAllState();
    })();
</script>
@endsection