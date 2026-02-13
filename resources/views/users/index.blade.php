@extends('layouts.app')

@section('content')
<div class="card">
    <div class="page-header">
        <h2>Users</h2>
        <a class="btn" href="{{ route('users.create') }}">Add User</a>
    </div>

    <div class="toolbar">
        <form method="GET" action="{{ route('users.index') }}">
            <input class="input" name="q" value="{{ $q ?? '' }}" placeholder="Search name / username / email">
            <button class="btn secondary" type="submit">Search</button>
        </form>
    </div>

    <table>
        <thead>
        <tr>
            <th style="width:90px;">ID</th>
            <th>Name</th>
            <th style="width:180px;">Username</th>
            <th style="width:260px;">Email</th>
            <th style="width:180px;">Group</th>
            <th style="width:160px;">Actions</th>
        </tr>
        </thead>
        <tbody>
        @foreach($users as $u)
            <tr>
                <td>{{ $u->id }}</td>
                <td>{{ $u->name }}</td>
                <td>{{ $u->username }}</td>
                <td>{{ $u->email }}</td>
                <td>{{ $u->userGroup?->name ?? '-' }}</td>
                <td style="display:flex; gap:8px; align-items:center;">
                    <a class="btn small" href="{{ route('users.edit', $u->id) }}">Edit</a>
                    @if(($u->userGroup->name ?? '') !== 'Administrator')

                    <form method="POST" action="{{ route('users.destroy', $u->id) }}" onsubmit="return confirm('Delete this user?')">
                        @csrf
                        @method('DELETE')
                        <button class="btn danger small" type="submit">Delete</button>
                    </form>

                    @else

                    <span class=\"badge\" style=\"display:inline-block;padding:6px 10px;border-radius:999px;background:#eee;\">Admin protected</span>

                    @endif
                </td>
            </tr>
        @endforeach
        </tbody>
    </table>

    <div style="margin-top:14px;">
        {{ $users->onEachSide(1)->links('vendor.pagination.simple') }}
    </div>
</div>
@endsection
