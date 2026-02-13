@extends('layouts.app')

@section('content')
<div class="card">
    <div class="page-header">
        <h2>Add User Group</h2>
        <a class="btn secondary" href="{{ route('user_groups.index') }}">Back</a>
    </div>

    <form method="POST" action="{{ route('user_groups.store') }}">
        @csrf

        <div class="form-grid">
            <div class="full">
                <label>Group Name</label>
                <input class="input" name="name" value="{{ old('name') }}" required>
            </div>

            <div class="full">
    <label>Permissions</label>
    <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap:10px;">
        @foreach($permissions as $perm)
            <label style="display:flex; align-items:center; gap:10px; padding:10px; border:1px solid #e5e7eb; border-radius:12px;">
                <input type="checkbox" name="permissions[]" value="{{ $perm->id }}"
                    {{ in_array($perm->id, old('permissions', [])) ? 'checked' : '' }}>
                <div>
                    <div style="font-weight:700;">{{ $perm->label }}</div>
                    <div class="hint">{{ $perm->key }}</div>
                </div>
            </label>
        @endforeach
    </div>
</div>
        </div>

        <div style="margin-top:16px;">
            <button class="btn" type="submit">Save</button>
        </div>
    </form>
</div>
@endsection
