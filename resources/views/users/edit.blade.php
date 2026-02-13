@extends('layouts.app')

@section('content')
<div class="card">
    <div class="page-header">
        <h2>Edit User #{{ $user->id }}</h2>
        <a class="btn secondary" href="{{ route('users.index') }}">Back</a>
    </div>

    @if(session('status'))
        <div class="alert success" style="margin-bottom:12px;">{{ session('status') }}</div>
    @endif

    @if($errors->any())
        <div class="alert danger" style="margin-bottom:12px;">
            <ul style="margin:0; padding-left:18px;">
                @foreach($errors->all() as $e)
                    <li>{{ $e }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form method="POST" action="{{ route('users.update', $user->id) }}">
        @csrf
        @method('PUT')

        <div class="form-grid">
            <div>
                <label class="required">Name</label>
                <input class="input" name="name" value="{{ old('name', $user->name) }}" required>
            </div>

            <div>
                <label class="required">Username</label>
                <input class="input" name="username" value="{{ old('username', $user->username) }}" required>
            </div>

            <div class="full">
                <label class="required">Email</label>
                <input class="input" type="email" name="email" value="{{ old('email', $user->email) }}" required>
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
                <select class="input" name="user_group_id" required>
                    @foreach($groups as $g)
                        <option value="{{ $g->id }}" {{ (string)old('user_group_id', $user->user_group_id) === (string)$g->id ? 'selected' : '' }}>
                            {{ $g->name }}
                        </option>
                    @endforeach
                </select>
            </div>
        </div>

        <div style="margin-top:16px; display:flex; gap:10px; align-items:center;">
            <button class="btn" type="submit">Update</button>
            <button class="btn danger" type="button"
                onclick="if(confirm('Delete this user?')) document.getElementById('delete-user-form').submit();">
                Delete
            </button>
        </div>
    </form>

    <form id="delete-user-form" method="POST" action="{{ route('users.destroy', $user->id) }}" style="display:none;">
        @csrf
        @method('DELETE')
    </form>
</div>
@endsection
