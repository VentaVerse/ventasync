@extends('layouts.app')
@section('breadcrumb', 'Settings / Edit User Group')

@section('content')
<div class="card">
    <div class="page-header">
        <h2>Edit User Group #{{ $group->id }}</h2>
        <a class="btn secondary" href="{{ route('user_groups.index') }}">Back</a>
    </div>

    <form id="user-group-update-form" method="POST" action="{{ route('user_groups.update', $group->id) }}">
        @csrf
        @method('PUT')

        <div class="form-grid">
            <div class="full">
                <label>Group Name</label>
                <input class="input" name="name" value="{{ old('name', $group->name) }}" required>
            </div>

            <div class="full">
                <label>Permissions</label>
                <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap:10px;">
                    @foreach($permissions as $perm)
                        <label style="display:flex; align-items:center; gap:10px; padding:10px; border:1px solid #e5e7eb; border-radius:12px;">
                            <input type="checkbox" name="permissions[]" value="{{ $perm->id }}"
                                {{ in_array($perm->id, $selected) ? 'checked' : '' }}>
                            <div>
                                <div class="font-bold">{{ $perm->label }}</div>
                                <div class="hint">{{ $perm->key }}</div>
                            </div>
                        </label>
                    @endforeach
                </div>
            </div>
        </div>

    </form>

    <form id="user-group-delete-form" method="POST" action="{{ route('user_groups.destroy', $group->id) }}" onsubmit="return confirm('Delete this group?')" class="hidden">
        @csrf
        @method('DELETE')
    </form>

    <div class="mt-16 d-flex gap-10 items-center">
        <button class="btn" type="submit" form="user-group-update-form">Update</button>
        <button class="btn danger" type="submit" form="user-group-delete-form">Delete</button>
    </div>
</div>
@endsection
