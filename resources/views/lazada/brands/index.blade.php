@extends('layouts.app')
@section('breadcrumb', 'Marketplace / Lazada / Brands')

@section('content')
<div class="page-header">
    <h1>Lazada Brands</h1>
    <div class="page-header-actions">
        <a class="btn" href="{{ route('lazada.index') }}">Lazada API</a>
        <a class="btn" href="{{ route('lazada.products.index') }}">Products</a>
    </div>
</div>

@if(session('status'))
    <div class="alert success">{{ session('status') }}</div>
@endif
@if(session('error'))
    <div class="alert danger">{{ session('error') }}</div>
@endif

<div class="card">
    <div class="d-flex items-center justify-between gap-12 flex-wrap">
        <form method="GET" action="{{ route('lazada.brands.index') }}" class="d-flex gap-8 items-center">
            <div>
                <label class="text-xs">Search</label>
                <input class="input" name="q" value="{{ $q }}" placeholder="Brand name or ID" style="width:260px;">
            </div>
            <button class="btn" type="submit">Search</button>
        </form>

        <form method="POST" action="{{ route('lazada.brands.fetch') }}">
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
        {{ $brands->links() }}
    </div>
</div>
@endsection
