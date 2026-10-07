@extends('layouts.blotter')
@section('title', 'New API application')
@section('breadcrumb', 'New')

@section('content')
@php
    $noExpiry = (bool) old('no_expiry', '1');
@endphp

<form method="POST" action="{{ route('api_clients.store') }}" class="fm-page">
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
            <a class="fm-back" href="{{ route('api_clients.index') }}">
                <x-ui.icon name="chevron-left" size="14" /> API applications
            </a>
            <h1 class="fm-title">New API application</h1>
        </div>
    </div>

    <div class="fm-body">
        <div class="fm-col fm-col--main">
            <section class="fm-section">
                <div class="fm-section__head">
                    <h2 class="fm-section__title">Application</h2>
                </div>

                <div class="fm-fields">
                    <x-ui.field label="Name" for="st-api-name" name="name" :required="true" wide
                                hint="Name the software, not the person who set it up. It is what appears in the request log.">
                        <x-ui.input id="st-api-name" name="name" maxlength="255" required
                                    value="{{ old('name') }}" placeholder="Pricing assistant" />
                    </x-ui.field>
                </div>
            </section>

            <section class="fm-section">
                <div class="fm-section__head">
                    <h2 class="fm-section__title">Permissions</h2>
                </div>
                <p class="fm-section__note">The token opens exactly what is granted here and nothing else.</p>

                @include('settings.api.partials.scopes', ['scopes' => $scopes, 'current' => []])
            </section>
        </div>

        <div class="fm-col fm-col--side">
            <div class="fm-card">
                <div class="fm-card__head"><h2 class="fm-card__title">Expiry</h2></div>
                <div class="fm-card__body st-expiry">
                    <label class="fm-switch">
                        <input type="checkbox" class="fm-switch__input" name="no_expiry" value="1"
                               data-toggle-disables="st-api-expires" @checked($noExpiry)>
                        <span class="fm-switch__track" aria-hidden="true"></span>
                        <span class="fm-switch__text">
                            <span class="fm-switch__label">No expiry</span>
                            <span class="fm-switch__note">The token keeps working until it is rotated or the application is revoked.</span>
                        </span>
                    </label>
                    @error('no_expiry')<div class="fm-error">{{ $message }}</div>@enderror

                    <x-ui.field label="Stop working on" for="st-api-expires" name="expires_at" class="st-expiry-date"
                                hint="The token stops working at the end of this day.">
                        <x-ui.input type="date" id="st-api-expires" name="expires_at"
                                    class="st-date" value="{{ old('expires_at') }}" :disabled="$noExpiry" />
                    </x-ui.field>
                </div>
            </div>

            @include('settings.api.partials.limits', ['allowedIps' => '', 'callsPerMinute' => ''])
        </div>
    </div>

    <x-ui.form-bar :cancel="route('api_clients.index')">
        <x-slot:primary><x-ui.button type="submit" variant="primary">Create and issue token</x-ui.button></x-slot:primary>
    </x-ui.form-bar>
</form>
@endsection
