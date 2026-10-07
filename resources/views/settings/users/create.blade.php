@extends('layouts.blotter')
@section('title', 'New user')
@section('breadcrumb', 'New')

@section('content')
<form method="POST" action="{{ route('users.store') }}" class="fm-page" data-guard-unsaved>
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
            <a class="fm-back" href="{{ route('users.index') }}" data-guard-leave>
                <x-ui.icon name="chevron-left" size="14" /> Users
            </a>
            <h1 class="fm-title">New user</h1>
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
                                    autocomplete="name" value="{{ old('name') }}" />
                    </x-ui.field>

                    <x-ui.field label="Username" for="st-user-username" name="username" :required="true"
                                hint="What they type to sign in. Must be unique.">
                        <x-ui.input id="st-user-username" name="username" maxlength="255" required
                                    autocomplete="off" value="{{ old('username') }}" />
                    </x-ui.field>

                    <x-ui.field label="Email" for="st-user-email" name="email" :required="true" wide>
                        <x-ui.input type="email" id="st-user-email" name="email" maxlength="255" required
                                    autocomplete="email" value="{{ old('email') }}" />
                    </x-ui.field>
                </div>
            </section>

            <section class="fm-section">
                <div class="fm-section__head">
                    <h2 class="fm-section__title">Password</h2>
                </div>
                <p class="fm-section__note">
                    At least 6 characters. Both boxes have to match. Nothing is ever shown back
                    here afterwards, so if it is forgotten the only way forward is to set a new one.
                </p>

                <div class="fm-fields fm-fields--2">
                    {{-- Never set a value attribute: a password box must not repopulate from old(). --}}
                    <x-ui.field label="Password" for="st-user-password" name="password" :required="true">
                        <x-ui.input type="password" id="st-user-password" name="password"
                                    required autocomplete="new-password" />
                    </x-ui.field>

                    <x-ui.field label="Confirm password" for="st-user-password2" name="password_confirmation" :required="true">
                        <x-ui.input type="password" id="st-user-password2" name="password_confirmation"
                                    required autocomplete="new-password" />
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
                                <option value="{{ $g->id }}" @selected((string) old('user_group_id') === (string) $g->id)>{{ $g->name }}</option>
                            @endforeach
                        </x-ui.select>
                    </x-ui.field>
                </div>
            </div>
        </div>
    </div>

    <x-ui.form-bar :cancel="route('users.index')">
        <x-slot:note>Nothing is saved until you create the user.</x-slot:note>
        <x-slot:primary><x-ui.button type="submit" variant="primary">Create user</x-ui.button></x-slot:primary>
    </x-ui.form-bar>
</form>
@endsection
