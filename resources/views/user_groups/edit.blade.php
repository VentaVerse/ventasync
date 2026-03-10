@extends('layouts.app')
@section('breadcrumb', 'Settings / Edit User Group')

@section('content')
<div class="card">
    <div class="page-header">
        <h2>Edit User Group #{{ $group->id }}</h2>
        <div class="page-header-actions">
            <button class="btn" type="submit" form="user-group-update-form">Update</button>
            <a class="btn secondary" href="{{ route('user_groups.index') }}">Back</a>
        </div>
    </div>

    <form id="user-group-update-form" method="POST" action="{{ route('user_groups.update', $group->id) }}">
        @csrf
        @method('PUT')

        <div class="form-grid">
            <div class="full">
                <label>Group Name</label>
                <input class="input" name="name" value="{{ old('name', $group->name) }}">
            </div>

            <div class="full">
                @include('user_groups.partials.permissions', [
                    'permissionGroups' => $permissionGroups,
                    'selected' => old('permissions', $selected),
                ])
            </div>
        </div>

    </form>

    <form id="user-group-delete-form" method="POST" action="{{ route('user_groups.destroy', $group->id) }}" data-confirm="Delete this group?" class="hidden">
        @csrf
        @method('DELETE')
    </form>

    <div class="mt-16" style="display:flex; justify-content:flex-end;">
        <button class="btn danger" type="submit" form="user-group-delete-form">Delete</button>
    </div>
</div>
@endsection
