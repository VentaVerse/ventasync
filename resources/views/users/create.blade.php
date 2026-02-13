@extends('layouts.app')

@section('content')
<div class="card">
    <div class="page-header">
        <h2>Add User</h2>
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

    <form method="POST" action="{{ route('users.store') }}">
        @csrf

        <div class="form-grid">
            <div>
                <label class="required">Name</label>
                <input class="input" name="name" value="{{ old('name') }}" required>
            </div>

            <div>
                <label class="required">Username</label>
                <input class="input" name="username" value="{{ old('username') }}" required>
            </div>

            <div class="full">
                <label class="required">Email</label>
                <input class="input" type="email" name="email" value="{{ old('email') }}" required>
            </div>

            <div>
                <label class="required">Password</label>
                <input class="input" type="password" name="password" required>
            </div>

            <div>
                <label class="required">Confirm Password</label>
                <input class="input" type="password" name="password_confirmation" required>
            </div>

            <div class="full">
                <label class="required">User Group</label>
                <select class="input" name="user_group_id" required>
                    @foreach($groups as $g)
                        <option value="{{ $g->id }}" {{ old('user_group_id') == $g->id ? 'selected' : '' }}>{{ $g->name }}</option>
                    @endforeach
                </select>
            </div>
        </div>

        <div style="margin-top:16px;">
            <button class="btn" type="submit">Save</button>
        </div>
    </form>
</div>
@endsection
