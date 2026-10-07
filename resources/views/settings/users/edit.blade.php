@extends('layouts.blotter')
@section('title', 'Edit ' . $user->name)
@section('breadcrumb', $user->name)

@section('content')
@php
    $isAdminAccount = ($user->userGroup->name ?? '') === 'Administrator';
    $isSelf = auth()->check() && (int) auth()->id() === (int) $user->id;
    $canDelete = !$isAdminAccount && !$isSelf;
@endphp

<form id="st-user-form" method="POST" action="{{ route('users.update', $user->id) }}" class="fm-page" data-guard-unsaved>
    @csrf
    @method('PUT')

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
            <a class="fm-back" href="{{ route('users.index') }}" data-guard-leave>
                <x-ui.icon name="chevron-left" size="14" /> Users
            </a>
            <h1 class="fm-title">{{ $user->name }}</h1>
            <div class="fm-stamps">
                <span class="fm-stamp">
                    <span class="fm-stamp__k">ID</span>
                    <span class="fm-stamp__v">{{ $user->id }}</span>
                </span>
                <span class="fm-stamp">
                    <span class="fm-stamp__k">Last sign-in</span>
                    <span class="fm-stamp__v">{{ $user->last_login_at ? $user->last_login_at->format('Y-m-d H:i') : 'Never' }}</span>
                </span>
            </div>
        </div>

        <div class="fm-head__actions">
            <x-ui.menu label="{{ $user->name }} actions">
                @if(Route::has('ext.audit.activity-log.index'))
                    <a class="x-menu__item" href="{{ route('ext.audit.activity-log.index', ['user_id' => $user->id]) }}">Activity log</a>
                @endif
                @if($canDelete)
                    @if(Route::has('ext.audit.activity-log.index'))<div class="x-menu__sep"></div>@endif
                    <button type="button" class="x-menu__item x-menu__item--danger"
                            data-confirm="Delete {{ $user->name }}? This cannot be undone."
                            data-confirm-submit="st-user-delete-form">Delete user</button>
                @endif
            </x-ui.menu>
        </div>
    </div>

    <div class="fm-body">
        <div class="fm-col fm-col--main">

            <section class="fm-section">
                <div class="fm-section__head">
                    <h2 class="fm-section__title">Who they are</h2>
                </div>

                <div class="fm-fields fm-fields--2">
                    <x-ui.field label="Name" for="st-user-name" name="name" :required="true">
                        <x-ui.input id="st-user-name" name="name" maxlength="255" required
                                    autocomplete="name" value="{{ old('name', $user->name) }}" />
                    </x-ui.field>

                    <x-ui.field label="Username" for="st-user-username" name="username" :required="true"
                                hint="What they type to sign in. Must be unique.">
                        <x-ui.input id="st-user-username" name="username" maxlength="255" required
                                    autocomplete="off" value="{{ old('username', $user->username) }}" />
                    </x-ui.field>

                    <x-ui.field label="Email" for="st-user-email" name="email" :required="true" wide>
                        <x-ui.input type="email" id="st-user-email" name="email" maxlength="255" required
                                    autocomplete="email" value="{{ old('email', $user->email) }}" />
                    </x-ui.field>
                </div>
            </section>

            <section class="fm-section">
                <div class="fm-section__head">
                    <h2 class="fm-section__title">Password</h2>
                </div>
                <p class="fm-section__note">
                    Leave both boxes empty to keep the current password. To change it, type the
                    new one twice. At least 6 characters.
                </p>

                <div class="fm-fields fm-fields--2">
                    <x-ui.field label="New password" for="st-user-password" name="password">
                        <x-ui.input type="password" id="st-user-password" name="password"
                                    autocomplete="new-password" />
                    </x-ui.field>

                    <x-ui.field label="Confirm new password" for="st-user-password2" name="password_confirmation">
                        <x-ui.input type="password" id="st-user-password2" name="password_confirmation"
                                    autocomplete="new-password" />
                    </x-ui.field>
                </div>
            </section>
        </div>

        <div class="fm-col fm-col--side">
            <div class="fm-card">
                <div class="fm-card__head"><h2 class="fm-card__title">Access</h2></div>
                <div class="fm-card__body">
                    <x-ui.field label="User group" for="st-user-group" name="user_group_id" :required="true" wide
                                hint="The group decides what this person may open and change.">
                        <x-ui.select id="st-user-group" name="user_group_id" required>
                            @foreach($groups as $g)
                                <option value="{{ $g->id }}" @selected((string) old('user_group_id', $user->user_group_id) === (string) $g->id)>{{ $g->name }}</option>
                            @endforeach
                        </x-ui.select>
                    </x-ui.field>

                    @if($isAdminAccount)
                        <p class="st-note">
                            Administrator accounts cannot be deleted. Move this person to another
                            group first if you need to remove their access.
                        </p>
                    @elseif($isSelf)
                        <p class="st-note">This is the account you are signed in with, so it cannot delete itself.</p>
                    @endif
                </div>
            </div>
        </div>
    </div>

    <x-ui.form-bar :cancel="route('users.index')">
        <x-slot:note>User {{ $user->id }}</x-slot:note>
        <x-slot:primary><x-ui.button type="submit" variant="primary">Save changes</x-ui.button></x-slot:primary>
    </x-ui.form-bar>
</form>

@if($canDelete)
<form id="st-user-delete-form" method="POST" action="{{ route('users.destroy', $user->id) }}" class="x-sr">
    @csrf
    @method('DELETE')
</form>
@endif
@endsection
