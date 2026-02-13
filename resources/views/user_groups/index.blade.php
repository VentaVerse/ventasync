@extends('layouts.app')

@section('content')
<div class="card">
    <div class="page-header">
        <h2>User Groups</h2>
        <a class="btn" href="{{ route('user_groups.create') }}">Add Group</a>
    </div>

    <div class="toolbar">
        <form method="GET" action="{{ route('user_groups.index') }}">
            <input class="input" name="q" value="{{ $q ?? '' }}" placeholder="Search group">
            <button class="btn secondary" type="submit">Search</button>
        </form>
    </div>

    <table>
        <thead>
        <tr>
            <th style="width:90px;">ID</th>
            <th>Name</th>
            <th style="width:160px;">Actions</th>
        </tr>
        </thead>
        <tbody>
        @foreach($groups as $g)
            <tr>
                <td>{{ $g->id }}</td>
                <td>{{ $g->name }}</td>
                <td style="display:flex; gap:8px; align-items:center;">
                    <a class="btn small" href="{{ route('user_groups.edit', $g->id) }}">Edit</a>
                    <form method="POST" action="{{ route('user_groups.destroy', $g->id) }}" onsubmit="return confirm('Delete this group?')">
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
        {{ $groups->onEachSide(1)->links('vendor.pagination.simple') }}
    </div>
</div>
@endsection
