@extends('layouts.blotter')
@section('title', 'New user group')
@section('breadcrumb', 'New')

@section('content')
<form method="POST" action="{{ route('user_groups.store') }}" class="fm-page st-form--wide" data-guard-unsaved>
    @csrf

    @include('partials.flash')

    @if($errors->any())
        <div class="fm-flash" role="status" aria-live="polite">
            <div class="fm-note fm-note--fail">
                <div class="fm-note__body">Nothing was saved. Check the fields marked below.</div>
            </div>
        </div>
    @endif

    <div class="fm-head">
        <div class="fm-head__main">
            <a class="fm-back" href="{{ route('user_groups.index') }}" data-guard-leave>
                <x-ui.icon name="chevron-left" size="14" /> User groups
            </a>
            <h1 class="fm-title">New user group</h1>
        </div>
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

    <x-ui.form-bar :cancel="route('user_groups.index')">
        <x-slot:note>Nothing is saved until you create the group.</x-slot:note>
        <x-slot:primary><x-ui.button type="submit" variant="primary">Create group</x-ui.button></x-slot:primary>
    </x-ui.form-bar>
</form>
@endsection
