@extends('layouts.app')

@section('title', 'Lazada Listings')

@section('content')
    <div class="page-head">
        <div>
            <h1>Lazada Listings</h1>
            <div class="muted">Phase 2: map ERP products to Lazada category + required attributes.</div>
        </div>
        <div class="page-actions" style="display:flex; gap:8px; align-items:center;">
            <a class="btn" href="{{ route('lazada.index') }}">Back to Lazada</a>
            <a class="btn" href="{{ route('lazada.listings.create') }}">Add Listing</a>
        </div>
    </div>

    @if(session('status'))
        <div class="alert success">{{ session('status') }}</div>
    @endif

    <div class="card">
        <div class="card-body">
            <table class="table">
                <thead>
                <tr>
                    <th>ID</th>
                    <th>Product</th>
                    <th>Primary Category ID</th>
                    <th>Brand ID</th>
                    <th>Item ID</th>
                    <th>Status</th>
                    <th style="width: 120px;">Actions</th>
                </tr>
                </thead>
                <tbody>
                @forelse($listings as $l)
                    @php
                        $p = $productsById->get($l->product_id);
                        $pName = $p ? html_entity_decode((string)($p->name ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8') : '';
                        $pSku = $p ? (string)($p->sku ?? '') : '';
                        $pModel = $p ? (string)($p->model ?? '') : '';
                    @endphp
                    <tr>
                        <td>{{ $l->id }}</td>
                        <td>
                            <div><strong>#{{ $l->product_id }}</strong> {{ $pName }}</div>
                            <div class="muted" style="font-size: 12px;">SKU: {{ $pSku }} @if($pModel) • Model: {{ $pModel }} @endif</div>
                        </td>
                        <td>{{ $l->primary_category_id ?? '-' }}</td>
                        <td>{{ $l->brand_id ?? '-' }}</td>
                        <td>{{ $l->lazada_item_id ?? '-' }}</td>
                        <td>{{ $l->status ?? '-' }}</td>
                        <td>
                            <a class="btn small" href="{{ route('lazada.listings.edit', $l->id) }}">Edit</a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="muted">No listings yet.</td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
@endsection
