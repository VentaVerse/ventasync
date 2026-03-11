@extends('layouts.app')
@section('breadcrumb', 'Settings / Edit User')

@section('content')
<div class="card">
    <div class="page-header">
        <h2>Edit User #{{ $user->id }}</h2>
        <div class="page-header-actions">
            <button class="btn" type="submit" form="edit-user-form">Update</button>
            <a class="btn secondary" href="{{ route('users.index') }}">Back</a>
        </div>
    </div>

    <form id="edit-user-form" method="POST" action="{{ route('users.update', $user->id) }}">
        @csrf
        @method('PUT')

        <div class="form-grid">
            <div>
                <label class="required">Name</label>
                <input class="input" name="name" value="{{ old('name', $user->name) }}">
            </div>

            <div>
                <label class="required">Username</label>
                <input class="input" name="username" value="{{ old('username', $user->username) }}">
            </div>

            <div class="full">
                <label class="required">Email</label>
                <input class="input" type="email" name="email" value="{{ old('email', $user->email) }}">
            </div>

            <div>
                <label>New Password (optional)</label>
                <input class="input" type="password" name="password" autocomplete="new-password">
            </div>

            <div>
                <label>Confirm New Password</label>
                <input class="input" type="password" name="password_confirmation" autocomplete="new-password">
            </div>

            <div class="full">
                <label class="required">User Group</label>
                <select class="input" name="user_group_id">
                    @foreach($groups as $g)
                        <option value="{{ $g->id }}" {{ (string)old('user_group_id', $user->user_group_id) === (string)$g->id ? 'selected' : '' }}>
                            {{ $g->name }}
                        </option>
                    @endforeach
                </select>
            </div>
        </div>

        <div class="mt-16" style="display:flex; justify-content:flex-end; gap:8px;">
            @if(Route::has('ext.audit.activity-log.index'))
            <a class="btn secondary" href="{{ route('ext.audit.activity-log.index', ['user_id' => $user->id]) }}">View Activity Log</a>
            @endif
            <button class="btn danger" type="button"
                data-confirm="Delete this user?" data-confirm-submit="delete-user-form">
                Delete
            </button>
        </div>
    </form>

    <form id="delete-user-form" method="POST" action="{{ route('users.destroy', $user->id) }}" class="hidden">
        @csrf
        @method('DELETE')
    </form>
</div>
@endsection
