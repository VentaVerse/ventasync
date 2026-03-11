@extends('layouts.app')
@section('breadcrumb', 'Settings / Users')

@section('content')
<div class="card">
    <div class="page-header">
        <h2>Users</h2>
        <a class="btn" href="{{ route('users.create') }}">Add User</a>
    </div>

    <div class="toolbar">
        <form method="GET" action="{{ route('users.index') }}">
            <input class="input" name="q" value="{{ $q ?? '' }}" placeholder="Search name / username / email">
            <button class="btn" type="submit">Search</button>
        </form>
    </div>

    <div class="table-wrap">
        <table>
            <thead>
            <tr>
                <th style="width:90px;">ID</th>
                <th>Name</th>
                <th style="width:180px;">Username</th>
                <th style="width:260px;">Email</th>
                <th style="width:180px;">Group</th>
                <th style="width:140px;">Last Login</th>
                <th style="width:200px;">Actions</th>
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
                    <td class="text-xs text-secondary">
                        @if($u->last_login_at)
                            {{ $u->last_login_at->diffForHumans() }}
                        @else
                            Never
                        @endif
                    </td>
                    <td class="d-flex gap-8 items-center">
                        <a class="btn small" href="{{ route('users.edit', $u->id) }}">Edit</a>
                        @if(Route::has('ext.audit.activity-log.index'))
                        <a class="btn small secondary" href="{{ route('ext.audit.activity-log.index', ['user_id' => $u->id]) }}">Activity</a>
                        @endif
                        @if(($u->userGroup->name ?? '') !== 'Administrator')

                        <form method="POST" action="{{ route('users.destroy', $u->id) }}" data-confirm="Delete this user?">
                            @csrf
                            @method('DELETE')
                            <button class="btn danger small" type="submit">Delete</button>
                        </form>

                        @else

                        <span class="badge badge-gray">Admin protected</span>

                        @endif
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>

    <div class="mt-16">
        {{ $users->onEachSide(1)->links('vendor.pagination.simple') }}
    </div>
</div>
@endsection
