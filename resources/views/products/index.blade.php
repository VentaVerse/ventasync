@extends('layouts.app')

@section('content')
<div class="card">
    <div class="page-header">
        <h2>Products</h2>
        <a class="btn" href="{{ route('products.create') }}">Add Product</a>
    </div>

    <div class="toolbar">
        <form method="GET" action="{{ route('products.index') }}">
            <input class="input" name="q" value="{{ $q ?? '' }}" placeholder="Search name / SKU / model">
            <button class="btn secondary" type="submit">Search</button>
        </form>
    </div>

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
@endsection
