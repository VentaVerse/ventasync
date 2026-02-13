@extends('layouts.app')

@section('content')
<div class="card">
    <div class="page-header">
        <h2>Products</h2>
        <div style="display:flex; gap:10px; align-items:center;">
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
        <form method="GET" action="{{ route('products.index') }}">
            <input class="input" name="q" value="{{ $q ?? '' }}" placeholder="Search name / SKU / model">
            <button class="btn secondary" type="submit">Search</button>
        </form>
    </div>

    <form id="product-bulk-form" method="POST" action="{{ route('products.bulk') }}" onsubmit="return window.__confirmProductBulkAction();">
        @csrf
    </form>

    <table>
        <thead>
@php
    $currentSort = $sort ?? 'product_id';
    $currentDir = $dir ?? 'asc';
    function sort_link($field, $label, $currentSort, $currentDir, $q) {
        $dir = 'asc';
        if ($currentSort === $field) {
            $dir = $currentDir === 'asc' ? 'desc' : 'asc';
        }
        $params = ['sort' => $field, 'dir' => $dir];
        if (!empty($q)) $params['q'] = $q;
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
    <th style="width:90px;">{!! sort_link('product_id','ID',$currentSort,$currentDir,$q ?? '') !!}</th>
    <th>{!! sort_link('name','Name',$currentSort,$currentDir,$q ?? '') !!}</th>
    <th style="width:180px;">{!! sort_link('manufacturer','Manufacturer',$currentSort,$currentDir,$q ?? '') !!}</th>
    <th style="width:140px;">{!! sort_link('model','Model',$currentSort,$currentDir,$q ?? '') !!}</th>
    <th style="width:140px;">{!! sort_link('sku','SKU',$currentSort,$currentDir,$q ?? '') !!}</th>
    <th style="width:120px;">{!! sort_link('price','Price',$currentSort,$currentDir,$q ?? '') !!}</th>
    <th style="width:120px;">{!! sort_link('quantity','Qty',$currentSort,$currentDir,$q ?? '') !!}</th>
    <th style="width:110px;">{!! sort_link('status','Status',$currentSort,$currentDir,$q ?? '') !!}</th>
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
                <td>{{ $p->name ?? '-' }}</td>
                <td>{{ $p->manufacturer ?? '-' }}</td>
                <td>{{ $p->model }}</td>
                <td>{{ $p->sku }}</td>
                <td>{{ number_format((float)$p->price, 2) }}</td>
                <td>{{ (int)$p->quantity }}</td>
                <td>{{ (int)$p->status === 1 ? 'Enabled' : 'Disabled' }}</td>
                <td style="display:flex; gap:8px; align-items:center;">
                    <a class="btn small" href="{{ route('products.edit', $p->product_id) }}">Edit</a>
                    <form method="POST" action="{{ route('products.destroy', $p->product_id) }}" onsubmit="return confirm('Delete this product?')">
                        @csrf
                        @method('DELETE')
                        <button class="btn danger small" type="submit">Delete</button>
                    </form>
                </td>
            </tr>
        @endforeach
        </tbody>
    </table>

    <div style="margin-top:14px;">
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

        window.__confirmProductBulkAction = function () {
            var actionEl = document.querySelector('select[name="action"][form="product-bulk-form"]');
            var action = actionEl ? actionEl.value : '';
            var selected = checks().filter(function (b) { return b.checked; }).length;

            if (!action) {
                alert('Please choose an action first.');
                return false;
            }
            if (selected === 0) {
                alert('Please select at least one product.');
                return false;
            }
            if (action === 'delete') {
                return confirm('Delete selected products? This cannot be undone.');
            }
            return true;
        };

        updateSelectAllState();
    })();
</script>
@endsection
