@extends('layouts.blotter')
@section('title', 'Edit ' . $group->name)
@section('breadcrumb', $group->name)

@section('content')
@php
    $isAdminGroup = strtolower($group->name) === 'administrator';
@endphp

<form id="st-group-form" method="POST" action="{{ route('user_groups.update', $group->id) }}" class="fm-page st-form--wide" data-guard-unsaved>
    @csrf
    @method('PUT')

    @include('partials.flash')

    <div class="fm-flash" role="status" aria-live="polite">
        @if($errors->any())
            <div class="fm-note fm-note--fail">
                <div class="fm-note__body">Nothing was saved. Check the fields marked below.</div>
            </div>
        @endif
        @if($isAdminGroup)
            <div class="fm-note fm-note--warn">
                <div class="fm-note__body">
                    This is the Administrator group. Settings, users and user groups stay switched
                    on for it however you leave them below, and the group cannot be deleted.
                </div>
            </div>
        @endif
    </div>

    <div class="fm-head">
        <div class="fm-head__main">
            <a class="fm-back" href="{{ route('user_groups.index') }}" data-guard-leave>
                <x-ui.icon name="chevron-left" size="14" /> User groups
            </a>
            <h1 class="fm-title">{{ $group->name }}</h1>
            <div class="fm-stamps">
                <span class="fm-stamp">
                    <span class="fm-stamp__k">ID</span>
                    <span class="fm-stamp__v">{{ $group->id }}</span>
                </span>
                @php
                    $areaTotal = 0;
                    $areaGranted = 0;
                    foreach ($permissionGroups as $g) {
                        foreach ($g['rows'] as $r) {
                            $areaTotal++;
                            if ($r['state'] !== 'off') $areaGranted++;
                        }
                    }
                @endphp
                <span class="fm-stamp">
                    <span class="fm-stamp__k">Areas granted</span>
                    <span class="fm-stamp__v">{{ $areaGranted }} of {{ $areaTotal }}</span>
                </span>
            </div>
        </div>

        @unless($isAdminGroup)
        <div class="fm-head__actions">
            <x-ui.menu label="{{ $group->name }} actions">
                <button type="button" class="x-menu__item x-menu__item--danger"
                        data-confirm="Delete {{ $group->name }}? Everyone still in this group has to be moved out first. This cannot be undone."
                        data-confirm-submit="st-group-delete-form">Delete group</button>
            </x-ui.menu>
        </div>
        @endunless
    </div>

    <div class="fm-body">
        <div class="fm-col">

            <section class="fm-section">
                <div class="fm-section__head">
                    <h2 class="fm-section__title">Name</h2>
                </div>

                <div class="fm-fields">
                    <x-ui.field label="Group name" for="st-group-name" name="name" :required="true" wide
                                hint="Name it after the job, not the person. Operator, Warehouse, Accounts.">
                        <x-ui.input id="st-group-name" name="name" maxlength="255" required
                                    value="{{ old('name', $group->name) }}" />
                    </x-ui.field>
                </div>
            </section>

            <section class="fm-section">
                <div class="st-perm">
                    @include('settings.user_groups.partials.permissions', [
                        'permissionGroups' => $permissionGroups,
                        'selected' => old('permissions', $selected),
                    ])
                </div>
            </section>
        </div>
    </div>

    <x-ui.form-bar :cancel="route('user_groups.index')">
        <x-slot:primary><x-ui.button type="submit" variant="primary">Save changes</x-ui.button></x-slot:primary>
    </x-ui.form-bar>
</form>

@unless($isAdminGroup)
<form id="st-group-delete-form" method="POST" action="{{ route('user_groups.destroy', $group->id) }}" class="x-sr">
    @csrf
    @method('DELETE')
</form>
@endunless
@endsection
