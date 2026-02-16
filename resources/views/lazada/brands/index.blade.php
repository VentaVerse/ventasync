@extends('layouts.app')

@section('content')
<div class="page-header">
    <div style="display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap;">
        <h1 style="margin:0;">Lazada Brands</h1>
        <div style="display:flex; gap:8px;">
            <a class="btn" href="{{ route('lazada.index') }}">Lazada API</a>
            <a class="btn" href="{{ route('lazada.listings.index') }}">Listings</a>
        </div>
    </div>
</div>

@if(session('status'))
    <div class="alert success">{{ session('status') }}</div>
@endif
@if(session('error'))
    <div class="alert danger">{{ session('error') }}</div>
@endif

<div class="card">
    <div style="display:flex; align-items:end; justify-content:space-between; gap:12px; flex-wrap:wrap;">
        <form method="GET" action="{{ route('lazada.brands.index') }}" style="display:flex; gap:8px; align-items:end;">
            <div>
                <label class="label" style="font-size:12px;">Search</label>
                <input class="input" name="q" value="{{ $q }}" placeholder="Brand name or ID" style="width:260px;">
            </div>
            <button class="btn" type="submit">Search</button>
        </form>

        <form method="POST" action="{{ route('lazada.brands.fetch') }}">
            @csrf
            <button class="btn btn-primary" type="submit">Fetch All Brands</button>
        </form>
    </div>

    <div class="divider"></div>

    <div style="overflow:auto;">
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

    <div style="margin-top:10px;">
        {{ $brands->links() }}
    </div>
</div>
@endsection
