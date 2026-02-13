@extends('layouts.app')

@section('content')
<div class="card">
    <div class="page-header">
        <h2>Categories</h2>
        <a class="btn" href="{{ route('categories.create') }}">Add Category</a>
    </div>

    <div class="toolbar">
        <form method="GET" action="{{ route('categories.index') }}">
            <input class="input" name="q" value="{{ $q ?? '' }}" placeholder="Search category">
            <button class="btn secondary" type="submit">Search</button>
        </form>
    </div>

    <table>
        <thead>
        <tr>
            <th style="width:90px;">ID</th>
            <th>Name</th>
            <th style="width:220px;">Parent</th>
            <th style="width:140px;">Sort</th>
            <th style="width:110px;">Status</th>
            <th style="width:160px;">Actions</th>
        </tr>
        </thead>
        <tbody>
        @foreach($categories as $c)
            <tr>
                <td>{{ $c->category_id }}</td>
                <td>{{ $c->name ?? '-' }}</td>
                <td>{{ $c->parent_name ?? '-' }}</td>
                <td>{{ (int)$c->sort_order }}</td>
                <td>{{ (int)$c->status === 1 ? 'Enabled' : 'Disabled' }}</td>
                <td style="display:flex; gap:8px; align-items:center;">
                    <a class="btn small" href="{{ route('categories.edit', $c->category_id) }}">Edit</a>
                    <form method="POST" action="{{ route('categories.destroy', $c->category_id) }}" onsubmit="return confirm('Delete this category?')">
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
        {{ $categories->onEachSide(1)->links('vendor.pagination.simple') }}
    </div>
</div>
@endsection
