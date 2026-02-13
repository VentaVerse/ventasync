@extends('layouts.app')

@section('content')
<div class="card">
    <div class="page-header">
        <h2>Manufacturers</h2>
        <a class="btn" href="{{ route('manufacturers.create') }}">Add Manufacturer</a>
    </div>

    <div class="toolbar">
        <form method="GET" action="{{ route('manufacturers.index') }}">
            <div class="ac-wrap" style="position:relative; max-width:420px; width:100%;">
    <input id="manufacturer-search" class="input" name="q" value="{{ $q ?? '' }}" placeholder="Search manufacturer">
    <div id="manufacturer-search-dd" class="ac-dd" style="display:none;">
        <ul id="manufacturer-search-list"></ul>
    </div>
</div>
            <button class="btn secondary" type="submit">Search</button>
        </form>
    </div>

    <table>
        <thead>
        <tr>
            <th style="width:90px;">ID</th>
            <th>Name</th>
            <th style="width:140px;">Sort</th>
            <th style="width:160px;">Actions</th>
        </tr>
        </thead>
        <tbody>
        @foreach($manufacturers as $m)
            <tr>
                <td>{{ $m->manufacturer_id }}</td>
                <td>{{ $m->name ?? '' }}</td>
                <td>{{ (int)$m->sort_order }}</td>
                <td style="display:flex; gap:8px; align-items:center;">
                    <a class="btn small" href="{{ route('manufacturers.edit', $m->manufacturer_id) }}">Edit</a>
                    <form method="POST" action="{{ route('manufacturers.destroy', $m->manufacturer_id) }}" onsubmit="return confirm('Delete this manufacturer?')">
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
        {{ $manufacturers->onEachSide(1)->links('vendor.pagination.simple') }}
    </div>
</div>
@endsection
