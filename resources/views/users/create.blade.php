@extends('layouts.app')
@section('breadcrumb', 'Settings / Add User')

@section('content')
<div class="card">
    <div class="page-header">
        <h2>Add User</h2>
        <a class="btn secondary" href="{{ route('users.index') }}">Back</a>
    </div>

    <form method="POST" action="{{ route('users.store') }}">
        @csrf

        <div class="form-grid">
            <div>
                <label class="required">Name</label>
                <input class="input" name="name" value="{{ old('name') }}">
            </div>

            <div>
                <label class="required">Username</label>
                <input class="input" name="username" value="{{ old('username') }}">
            </div>

            <div class="full">
                <label class="required">Email</label>
                <input class="input" type="email" name="email" value="{{ old('email') }}">
            </div>

            <div>
                <label class="required">Password</label>
                <input class="input" type="password" name="password">
            </div>

            <div>
                <label class="required">Confirm Password</label>
                <input class="input" type="password" name="password_confirmation">
            </div>

            <div class="full">
                <label class="required">User Group</label>
                <select class="input" name="user_group_id">
                    @foreach($groups as $g)
                        <option value="{{ $g->id }}" {{ old('user_group_id') == $g->id ? 'selected' : '' }}>{{ $g->name }}</option>
                    @endforeach
                </select>
            </div>
        </div>

        <div class="mt-16" style="display:flex; justify-content:flex-end;">
            <button class="btn" type="submit">Save</button>
        </div>
    </form>
</div>
@endsection
