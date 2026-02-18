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
                <input class="input" name="name" value="{{ old('name', $group->name) }}" required>
            </div>

            <div class="full">
                <label>Permissions</label>
                @foreach($permissionGroups as $pg)
                    <div style="margin-bottom:16px;">
                        <div style="font-weight:700; font-size:13px; color:var(--text-primary); margin-bottom:8px; padding-bottom:6px; border-bottom:1px solid var(--border-light);">
                            {{ $pg['label'] }}
                        </div>
                        <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap:8px;">
                            @foreach($pg['permissions'] as $perm)
                                <label style="display:flex; align-items:center; gap:10px; padding:10px; border:1px solid #e5e7eb; border-radius:12px; cursor:pointer; transition:background .15s ease;" onmouseover="this.style.background='#f8fafc'" onmouseout="this.style.background=''">
                                    <input type="checkbox" name="permissions[]" value="{{ $perm->id }}"
                                        {{ in_array($perm->id, $selected) ? 'checked' : '' }}
                                        @if(in_array($perm->key, ['manage_catalog','manage_sales','manage_marketplace_api','manage_settings']))
                                            class="perm-parent" data-group="{{ $pg['label'] }}"
                                        @else
                                            class="perm-child" data-group="{{ $pg['label'] }}"
                                        @endif
                                    >
                                    <div>
                                        <div class="font-bold" style="font-size:13px;">{{ $perm->label }}</div>
                                        <div class="hint">{{ $perm->key }}</div>
                                    </div>
                                </label>
                            @endforeach
                        </div>
                    </div>
                @endforeach
            </div>
        </div>

    </form>

    <form id="user-group-delete-form" method="POST" action="{{ route('user_groups.destroy', $group->id) }}" onsubmit="return confirm('Delete this group?')" class="hidden">
        @csrf
        @method('DELETE')
    </form>

    <div class="mt-16" style="display:flex; justify-content:flex-end;">
        <button class="btn danger" type="submit" form="user-group-delete-form">Delete</button>
    </div>
</div>

@push('scripts')
<script>
(function(){
    document.querySelectorAll('.perm-parent').forEach(function(parent) {
        parent.addEventListener('change', function() {
            var group = parent.dataset.group;
            document.querySelectorAll('.perm-child[data-group="' + group + '"]').forEach(function(child) {
                child.checked = parent.checked;
            });
        });
    });
    document.querySelectorAll('.perm-child').forEach(function(child) {
        child.addEventListener('change', function() {
            var group = child.dataset.group;
            var parent = document.querySelector('.perm-parent[data-group="' + group + '"]');
            if (!parent) return;
            var children = document.querySelectorAll('.perm-child[data-group="' + group + '"]');
            var allChecked = Array.from(children).every(function(c) { return c.checked; });
            parent.checked = allChecked;
        });
    });
})();
</script>
@endpush
@endsection
