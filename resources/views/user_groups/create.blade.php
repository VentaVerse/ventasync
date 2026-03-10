@extends('layouts.app')
@section('breadcrumb', 'Settings / Add User Group')

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
                <input class="input" name="name" value="{{ old('name') }}">
            </div>

            <div class="full">
                @include('user_groups.partials.permissions', [
                    'permissionGroups' => $permissionGroups,
                    'selected' => old('permissions', []),
                ])
            </div>
        </div>

        <div class="mt-16" style="display:flex; justify-content:flex-end;">
            <button class="btn" type="submit">Save</button>
        </div>
    </form>
</div>
@endsection
