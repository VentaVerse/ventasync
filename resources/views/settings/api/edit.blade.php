@extends('layouts.blotter')
@section('title', 'Edit ' . $client->name)
@section('breadcrumb', $client->name)

@section('content')
@php
    // Same derivation the pre-redesign view used: the date box shows the
    // token's own expiry, and "No expiry" starts on only when there is none.
    $expiresValue = old('expires_at', $expiresAt?->format('Y-m-d'));
    $noExpiry = (bool) old('no_expiry', $expiresAt ? null : '1');
@endphp

<form method="POST" action="{{ route('api_clients.update', $client->id) }}" class="fm-page">
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
            <a class="fm-back" href="{{ route('api_clients.index') }}">
                <x-ui.icon name="chevron-left" size="14" /> API applications
            </a>
            <h1 class="fm-title">{{ $client->name }}</h1>
            <div class="fm-stamps">
                <span class="fm-stamp">
                    <span class="fm-stamp__k">Created</span>
                    <span class="fm-stamp__v">{{ $client->created_at?->format('Y-m-d') ?? 'Unknown' }}</span>
                </span>
                <span class="fm-stamp">
                    <span class="fm-stamp__k">Last used</span>
                    <span class="fm-stamp__v">{{ $client->last_used_at?->format('Y-m-d H:i') ?? 'Never' }}</span>
                </span>
                <span class="fm-stamp">
                    <span class="fm-stamp__k">Permissions</span>
                    <span class="fm-stamp__v">{{ count($current) }}</span>
                </span>
            </div>
        </div>

        <div class="fm-head__actions">
            <x-ui.menu label="{{ $client->name }} actions">
                <button type="button" class="x-menu__item"
                        data-confirm="Rotate the token for {{ $client->name }}? The current token stops working the moment the new one is issued."
                        data-confirm-submit="st-api-rotate-form">Rotate token</button>
                <div class="x-menu__sep"></div>
                <button type="button" class="x-menu__item x-menu__item--danger"
                        data-confirm="Revoke {{ $client->name }}? Its token stops working immediately. This cannot be undone."
                        data-confirm-submit="st-api-delete-form">Revoke application</button>
            </x-ui.menu>
        </div>
    </div>

    <div class="fm-body">
        <div class="fm-col fm-col--main">
            <section class="fm-section">
                <div class="fm-section__head">
                    <h2 class="fm-section__title">Name</h2>
                </div>

                <div class="fm-fields">
                    <x-ui.field label="Name" for="st-api-name" name="name" :required="true" wide
                                hint="Name the software, not the person who set it up. It is what appears in the request log.">
                        <x-ui.input id="st-api-name" name="name" maxlength="255" required
                                    value="{{ old('name', $client->name) }}" />
                    </x-ui.field>
                </div>
            </section>

            <section class="fm-section">
                <div class="fm-section__head">
                    <h2 class="fm-section__title">What it may reach</h2>
                </div>
                <p class="fm-section__note">
                    Read lets it fetch. Write lets it change. Changing these takes effect on the
                    existing token straight away. The token value itself does not change, and it is
                    never shown again. If you need a new one, rotate it.
                </p>

                @include('settings.api.partials.scopes', ['scopes' => $scopes, 'current' => $current])
            </section>
        </div>

        <div class="fm-col fm-col--side">
            <div class="fm-card">
                <div class="fm-card__head"><h2 class="fm-card__title">Expiry</h2></div>
                <div class="fm-card__body">
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

                    <x-ui.field label="Stop working on" for="st-api-expires" name="expires_at"
                                hint="Turn off No expiry to set a date. It has to be in the future.">
                        <x-ui.input type="date" id="st-api-expires" name="expires_at"
                                    class="st-date" value="{{ $expiresValue }}" :disabled="$noExpiry" />
                    </x-ui.field>
                </div>
            </div>

            @include('settings.api.partials.mcp', ['mcpEnabled' => (bool) old('mcp_enabled', $client->mcp_enabled), 'allowedIps' => implode("\n", (array) ($client->allowed_ips ?? [])), 'callsPerMinute' => $client->calls_per_minute])
        </div>
    </div>

    <x-ui.form-bar :cancel="route('api_clients.index')">
        <x-slot:note>{{ $client->name }}</x-slot:note>
        <x-slot:primary><x-ui.button type="submit" variant="primary">Save changes</x-ui.button></x-slot:primary>
    </x-ui.form-bar>
</form>

<form id="st-api-rotate-form" method="POST" action="{{ route('api_clients.rotate', $client->id) }}" class="x-sr">
    @csrf
</form>
<form id="st-api-delete-form" method="POST" action="{{ route('api_clients.destroy', $client->id) }}" class="x-sr">
    @csrf
    @method('DELETE')
</form>
@endsection
