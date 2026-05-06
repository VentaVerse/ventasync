@extends('layouts.app')
@section('breadcrumb', 'Marketplace / Lazada / Brands')

@section('content')
<div class="page-header">
    <h1>Lazada Brands</h1>
    <div class="page-header-actions">
        <a class="btn" href="{{ route('ext.lazada.index') }}">Lazada API</a>
        <a class="btn" href="{{ route('ext.lazada.product-groups.index') }}">Product Groups</a>
    </div>
</div>

<div class="card">
    <div class="d-flex items-center justify-between gap-12 flex-wrap">
        <form method="GET" action="{{ route('ext.lazada.brands.index') }}" class="d-flex gap-8 items-center">
            <div>
                <label class="text-xs">Search</label>
                <input class="input" name="q" value="{{ $q }}" placeholder="Brand name or ID" style="width:260px;">
            </div>
            <button class="btn" type="submit">Search</button>
        </form>

        <form method="POST" action="{{ route('ext.lazada.brands.fetch') }}">
            @csrf
            <button class="btn" type="submit">Fetch All Brands</button>
        </form>
    </div>

    <div style="height:1px; background:var(--border); margin:14px 0;"></div>

    <div class="table-wrap">
        <table class="table">
            <thead>
                <tr>
                    <th style="width:140px;">Brand ID</th>
                    <th>Name</th>
                    <th style="width:90px;">Region</th>
                </tr>
            </thead>
            <tbody>
                @foreach($brands as $b)
                    <tr>
                        <td>{{ (int)$b->brand_id }}</td>
                        <td>{{ $b->name }}</td>
                        <td>{{ $b->region }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <div class="mt-12">
        {{ $brands->onEachSide(1)->links('vendor.pagination.simple') }}
    </div>
</div>
@endsection
