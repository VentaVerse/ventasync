@extends('layouts.shell')
@section('title', 'New user group')
@section('breadcrumb', 'New')

@section('content')
<form method="POST" action="{{ route('user_groups.store') }}" class="fm-page st-form--wide">
    @csrf

    <div class="fm-flash" role="status" aria-live="polite">
        @if($errors->any())
            <div class="fm-note fm-note--fail">
                <div class="fm-note__body">Nothing was saved. Check the fields marked below.</div>
            </div>
        @endif
    </div>

    <div class="fm-head">
        <div class="fm-head__main">
            <a class="fm-back" href="{{ route('user_groups.index') }}">
                <x-ui.icon name="chevron-left" size="14" /> User groups
            </a>
            <h1 class="fm-title">New user group</h1>
        </div>
    </div>

    {{-- One column, not the usual main plus side split: the permission editor
         is the page, and a 320px side rail would squeeze every pill row. --}}
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
                                    value="{{ old('name') }}" placeholder="Operator" />
                    </x-ui.field>
                </div>
            </section>

            <section class="fm-section">
                <div class="fm-section__head">
                    <h2 class="fm-section__title">What this group may do</h2>
                </div>
                <p class="fm-section__note">
                    Every area has its own level. Off means the area does not appear at all.
                    View means read but not change. Manage means full control, and includes view.
                </p>

                <div class="st-perm">
                    @include('settings.user_groups.partials.permissions', [
                        'permissionGroups' => $permissionGroups,
                        'selected' => old('permissions', []),
                    ])
                </div>
            </section>
        </div>
    </div>

    <div class="fm-bar">
        <span class="fm-bar__meta">Nothing is saved until you create the group.</span>
        <div class="fm-bar__actions">
            <a class="fm-cancel" href="{{ route('user_groups.index') }}">Cancel</a>
            <x-ui.button type="submit" variant="primary">Create group</x-ui.button>
        </div>
    </div>
</form>
@endsection
