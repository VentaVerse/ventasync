@extends('layouts.channel')
@section('breadcrumb', 'Settings')

@section('title', 'Lazada Settings')

{{-- Lazada OAuth URLs and field names are registered with Lazada; do not rename them. --}}
{{-- The sandbox authorize link needs ?sandbox=1 or the production app is authorised. --}}
@section('content')
@php
    $canManageLazada = auth()->user()?->hasPermission('manage_lazada/settings') ?? false;

    $currentMode = $setting->mode ?? 'live';
    $isSandbox = $currentMode === 'sandbox';

    $__hasCredentials = ($setting->region ?? '') !== '' && ($setting->app_key ?? '') !== '' && ($setting->app_secret ?? '') !== '';
    $__hasToken = !empty($setting->access_token ?? '');
    $__tokenExpired = $__hasToken && ($setting->expires_at ?? null) && \Carbon\Carbon::parse($setting->expires_at)->lt(now());

    $__hasSandboxCredentials = ($setting->sandbox_app_key ?? '') !== '' && ($setting->sandbox_app_secret ?? '') !== '';
    $__hasSandboxToken = !empty($setting->sandbox_access_token ?? '');
    $__sandboxTokenExpired = $__hasSandboxToken && ($setting->sandbox_expires_at ?? null) && \Carbon\Carbon::parse($setting->sandbox_expires_at)->lt(now());



    $regions = [
        'ph' => 'Philippines', 'sg' => 'Singapore', 'my' => 'Malaysia',
        'id' => 'Indonesia', 'th' => 'Thailand', 'vn' => 'Vietnam',
    ];
@endphp

@php
    $csKnown = ['connection', 'status', 'logs'];
    if ($canManageLazada) { $csKnown[] = 'explorer'; $csKnown[] = 'automations'; }
    $csSection = in_array(request('tab'), $csKnown, true) ? request('tab') : 'connection';
    $resultTab = in_array(session('settings_tab'), $csKnown, true) ? session('settings_tab') : 'connection';
@endphp
<div class="cs-page"
     data-channel-settings
     data-tab-prefix="lz-tab-"
     data-tab-storage-key="lz-active-tab"
     data-default-tab="">

<div class="x-list-head">
    <div>
        <h1 class="x-page-title">Settings</h1>
        <p class="x-page-sub">{{ ($setting->store_name ?? '') !== '' ? $setting->store_name : 'This store' }} on Lazada, {{ $isSandbox ? 'sandbox' : 'production' }}.</p>
    </div>
    <div class="cs-head-state">
        @if($setting->enabled ?? true)
            <x-ui.badge tone="success">Active</x-ui.badge>
        @else
            <x-ui.badge tone="neutral">Paused</x-ui.badge>
        @endif
    </div>
</div>

@unless($canManageLazada)
<div class="cs-readonly">
    <div>
        <strong>You can look, not change.</strong>
        Settings, status mapping and the API record are readable with the Lazada view permission. Editing credentials, running syncs and clearing logs need the manage tier.
    </div>
</div>
@endunless

@if($result && $resultTab !== 'explorer')
    @include('partials.channel-last-result', ['result' => $result, 'channel' => 'Lazada', 'mode' => 'note'])
@endif

@if($canManageLazada)
    @include('partials.channel-setup', [
        'channelLabel' => 'Lazada',
        'storeName' => $setting->store_name ?? '',
        'run' => (bool) session('settings_setup'),
        'steps' => [
            ['label' => 'Categories', 'url' => route('ext.lazada.setup_step', ['step' => 'categories'])],
            ['label' => 'Brands', 'url' => route('ext.lazada.setup_step', ['step' => 'brands'])],
        ],
    ])
    @include('partials.channel-delete-store', [
        'action' => route('ext.lazada.stores.destroy'),
        'storeName' => $setting->store_name ?? '',
        'channelLabel' => 'Lazada',
    ])
@endif

<div id="lz-tab-connection" class="cs-panel" @if($csSection !== 'connection') hidden @endif>


    @if($canManageLazada)
        <form method="POST" action="{{ route('ext.lazada.toggle_mode') }}" data-cs-env-form hidden>
            @csrf
            <input type="hidden" name="mode" value="{{ $currentMode }}" data-cs-env-input>
        </form>
    @endif

    @php
        $csCategories = \Extensions\lazada\Models\LazadaCategory::query()->count();
        $csBrands = \Extensions\lazada\Models\LazadaBrand::query()->where('region', (string) ($setting->region ?? ''))->count();
        $csSetUp = $csCategories > 0 && $csBrands > 0;
        $csSetupState = $csSetUp || $csCategories > 0 || $csBrands > 0
            ? number_format($csCategories) . ' ' . \Illuminate\Support\Str::plural('category', $csCategories) . ' and ' . number_format($csBrands) . ' ' . \Illuminate\Support\Str::plural('brand', $csBrands) . ' on file'
            : 'Nothing fetched yet';
    @endphp
    <div class="cs-env-panel" data-cs-env-panel="live" @if($isSandbox) hidden @endif>
    <div class="cs-stack">

        <section class="fm-section">
            <div class="fm-section__head">
                <div>
                    <h2 class="fm-section__title">Keys and token</h2>
                    <p class="fm-section__note">Each environment keeps its own keys and its own token.</p>
                </div>
                @if($canManageLazada)
                    <div class="x-segment cs-envseg" role="tablist" aria-label="Lazada environment">
                        <button type="button" class="x-segment__item {{ $isSandbox ? 'is-active' : '' }}"
                                role="tab" aria-selected="{{ $isSandbox ? 'true' : 'false' }}"
                                data-cs-env-option="sandbox">Sandbox</button>
                        <button type="button" class="x-segment__item {{ $isSandbox ? '' : 'is-active' }}"
                                role="tab" aria-selected="{{ $isSandbox ? 'false' : 'true' }}"
                                data-cs-env-option="live">Production</button>
                    </div>
                @else
                    <span class="cs-env__label">{{ $isSandbox ? 'Sandbox' : 'Production' }}</span>
                @endif
            </div>

            <form method="POST" action="{{ route('ext.lazada.save') }}" id="lz-save-live">
                @csrf
                <input type="hidden" name="env" value="live">

                <div class="fm-fields">
                    <x-ui.field label="Store name" for="lz-store-name" name="store_name" :required="true" wide
                                hint="How this store is named across the ERP - the sidebar, the fulfilment tabs, the channels board.">
                        <x-ui.input id="lz-store-name" name="store_name" type="text" autocomplete="off" :disabled="!$canManageLazada"
                                    value="{{ old('store_name', $setting->store_name ?? '') }}" />
                    </x-ui.field>

                    <x-ui.field label="Active" for="lz-enabled" name="enabled" wide
                                hint="An active store syncs on the crons and shows its tabs. Switch off to pause the store without losing anything.">
                        <input type="hidden" name="enabled" value="0">
                        <label class="fm-switch cs-store-switch">
                            <input type="checkbox" class="fm-switch__input" id="lz-enabled" name="enabled" value="1" @checked((bool) ($setting->enabled ?? true)) @disabled(!$canManageLazada)>
                            <span class="fm-switch__track" aria-hidden="true"></span>
                        </label>
                    </x-ui.field>

                    <x-ui.field label="Region" for="lz-region" name="region" wide :required="true"
                                hint="Which Lazada country site this shop trades on. Production and sandbox share this one setting.">
                        <div class="x-select-wrap">
                            <select id="lz-region" name="region" class="x-input" @disabled(!$canManageLazada)>
                                <option value="">Choose a region</option>
                                @foreach($regions as $code => $name)
                                    <option value="{{ $code }}" @selected(strtolower((string) ($setting->region ?? '')) === $code)>{{ $name }} ({{ strtoupper($code) }})</option>
                                @endforeach
                            </select>
                            <x-ui.icon name="chevron-down" size="14" class="x-select-wrap__chevron" />
                        </div>
                    </x-ui.field>

                    <x-ui.field label="App Key" for="lz-app-key" name="app_key" wide
                                hint="The app identifier from your Lazada Open Platform app. Not a secret, so it is shown in full.">
                        <x-ui.input id="lz-app-key" name="app_key" class="fm-input--num" type="text" autocomplete="off" :disabled="!$canManageLazada"
                                    value="{{ $setting->app_key ?? '' }}" />
                    </x-ui.field>

                    <x-ui.credential
                        name="app_secret"
                        id="lz-app-secret"
                        label="App Secret"
                        channel="lazada"
                        hint="Stored encrypted. Read-only until you choose to edit it."
                        :length="\App\Support\Credentials::length($setting->app_secret ?? null)"
                        :can-reveal="$canManageLazada"
                        :disabled="!$canManageLazada" />

                    <x-ui.field label="Redirect URI" for="lz-redirect" wide
                                hint="Register this exact URL in your Lazada Open Platform production app. It is always https. To change it, edit APP_URL in the environment file.">
                        <x-ui.input id="lz-redirect" type="text" readonly value="{{ $defaultRedirect }}" data-select-on-click />
                    </x-ui.field>
                </div>

                <div class="cs-connect__foot">
                    <div class="cs-state">
                        @if($__hasToken && !$__tokenExpired)
                            <x-ui.badge tone="success">Token active</x-ui.badge>
                            @if($setting->expires_at ?? null)
                                <span class="cs-state__when">Expires {{ \Carbon\Carbon::parse($setting->expires_at)->diffForHumans() }}</span>
                            @endif
                        @elseif($__hasToken && $__tokenExpired)
                            <x-ui.badge tone="danger">Token expired</x-ui.badge>
                            <span class="cs-state__note">Refresh it under Do it by hand, or authorise again.</span>
                        @else
                            <x-ui.badge tone="warning">No token</x-ui.badge>
                            <span class="cs-state__note">Authorise above.</span>
                        @endif
                        <span class="cs-state__fact">{{ $csSetupState }}</span>
                    </div>
                    @if($canManageLazada)
                    <div class="cs-connect__acts">
                        <x-ui.button type="button" variant="secondary" size="sm" data-setup-open>Set up store</x-ui.button>
                        <x-ui.button variant="secondary" :href="route('ext.lazada.authorize', ['store' => $setting->id ?? null])" target="_blank" rel="noopener">Authorize shop</x-ui.button>
                        <x-ui.button type="submit" variant="primary">Save connection</x-ui.button>
                    </div>
                    @endif
                </div>
            </form>

        <details class="cs-hand">
            <summary>Do it by hand</summary>


            @if($canManageLazada)
            <div class="cs-steps">

                <div class="cs-step">
                    <div class="cs-step__body">
                        <span class="cs-step__title">Exchange an auth code by hand <x-ui.hint label="About this step">Only needed when the automatic exchange did not run, for example if you copied the redirect URL out of the address bar.</x-ui.hint></span>
                        <form method="POST" action="{{ route('ext.lazada.token_create') }}" class="cs-exchange">
                            @csrf
                            <x-ui.field label="Auth code" for="lz-code" name="code">
                                <x-ui.input id="lz-code" name="code" value="{{ old('code', $setting->auth_code ?? session('lazada_last_auth_code') ?? '') }}" placeholder="Fills in after the callback, or paste it here" />
                            </x-ui.field>
                            <x-ui.button type="submit" size="sm">Exchange</x-ui.button>
                        </form>
                    </div>
                </div>

                <div class="cs-step">
                    <div class="cs-step__body">
                        <span class="cs-step__title">Refresh an expired token <x-ui.hint label="About this step">Lazada access tokens last about two days. The scheduled job renews them every six hours, so this is here for when that job has not run.</x-ui.hint></span>
                        <form method="POST" action="{{ route('ext.lazada.token_refresh') }}">
                            @csrf
                            <x-ui.button type="submit" size="sm">Refresh access token</x-ui.button>
                        </form>
                    </div>
                </div>
            </div>

            <div class="cs-danger">
                <div>
                    <span class="cs-danger__title">Remove this store <x-ui.hint label="What removal does">Everything this store holds in the ERP goes with it: listings, groups, Lazada orders and returns. Sales already imported stay under its name. There is no undo.</x-ui.hint></span>
                </div>
                <x-ui.button type="button" variant="danger" size="sm" data-delete-open>Delete this store</x-ui.button>
            </div>
            @else
            <p class="cs-step__note">Authorising and refreshing tokens need the Lazada manage permission.</p>
            @endif
        </details>
        </section>
    </div>
    </div>

    <div class="cs-env-panel" data-cs-env-panel="sandbox" @unless($isSandbox) hidden @endunless>
    <div class="cs-stack">

        <section class="fm-section">
            <div class="fm-section__head">
                <div>
                    <h2 class="fm-section__title">Keys and token</h2>
                    <p class="fm-section__note">Each environment keeps its own keys and its own token.</p>
                </div>
                @if($canManageLazada)
                    <div class="x-segment cs-envseg" role="tablist" aria-label="Lazada environment">
                        <button type="button" class="x-segment__item {{ $isSandbox ? 'is-active' : '' }}"
                                role="tab" aria-selected="{{ $isSandbox ? 'true' : 'false' }}"
                                data-cs-env-option="sandbox">Sandbox</button>
                        <button type="button" class="x-segment__item {{ $isSandbox ? '' : 'is-active' }}"
                                role="tab" aria-selected="{{ $isSandbox ? 'false' : 'true' }}"
                                data-cs-env-option="live">Production</button>
                    </div>
                @else
                    <span class="cs-env__label">{{ $isSandbox ? 'Sandbox' : 'Production' }}</span>
                @endif
            </div>

            <form method="POST" action="{{ route('ext.lazada.save') }}" id="lz-save-sandbox">
                @csrf
                <input type="hidden" name="env" value="sandbox">

                <div class="fm-fields">
                    <x-ui.field label="Store name" for="lz-sb-store-name" name="store_name" :required="true" wide
                                hint="How this store is named across the ERP - the sidebar, the fulfilment tabs, the channels board.">
                        <x-ui.input id="lz-sb-store-name" name="store_name" type="text" autocomplete="off" :disabled="!$canManageLazada"
                                    value="{{ old('store_name', $setting->store_name ?? '') }}" />
                    </x-ui.field>

                    <x-ui.field label="Active" for="lz-sb-enabled" name="enabled" wide
                                hint="An active store syncs on the crons and shows its tabs. Switch off to pause the store without losing anything.">
                        <input type="hidden" name="enabled" value="0">
                        <label class="fm-switch cs-store-switch">
                            <input type="checkbox" class="fm-switch__input" id="lz-sb-enabled" name="enabled" value="1" @checked((bool) ($setting->enabled ?? true)) @disabled(!$canManageLazada)>
                            <span class="fm-switch__track" aria-hidden="true"></span>
                        </label>
                    </x-ui.field>

                    {{-- Keep the field name region: save() writes one shared region column for both environments. --}}
                    <x-ui.field label="Region" for="lz-sb-region" name="region" wide
                                hint="Shared with production. Choosing here also changes the live form.">
                        <div class="x-select-wrap">
                            <select id="lz-sb-region" name="region" class="x-input" @disabled(!$canManageLazada)>
                                <option value="">Choose a region</option>
                                @foreach($regions as $code => $name)
                                    <option value="{{ $code }}" @selected(strtolower((string) ($setting->region ?? '')) === $code)>{{ $name }} ({{ strtoupper($code) }})</option>
                                @endforeach
                            </select>
                            <x-ui.icon name="chevron-down" size="14" class="x-select-wrap__chevron" />
                        </div>
                    </x-ui.field>

                    <x-ui.field label="Sandbox App Key" for="lz-sb-app-key" name="sandbox_app_key" wide>
                        <x-ui.input id="lz-sb-app-key" name="sandbox_app_key" class="fm-input--num" type="text" autocomplete="off" :disabled="!$canManageLazada"
                                    value="{{ $setting->sandbox_app_key ?? '' }}" />
                    </x-ui.field>

                    <x-ui.credential
                        name="sandbox_app_secret"
                        id="lz-sb-app-secret"
                        label="Sandbox App Secret"
                        channel="lazada"
                        hint="Stored encrypted."
                        :length="\App\Support\Credentials::length($setting->sandbox_app_secret ?? null)"
                        :can-reveal="$canManageLazada"
                        :disabled="!$canManageLazada" />

                    <x-ui.field label="Redirect URI" for="lz-sb-redirect" wide
                                hint="Register this exact URL in your Lazada Open Platform sandbox app. It is always https. To change it, edit APP_URL in the environment file.">
                        <x-ui.input id="lz-sb-redirect" type="text" readonly value="{{ $defaultRedirect }}" data-select-on-click />
                    </x-ui.field>
                </div>

                <div class="cs-connect__foot">
                    <div class="cs-state">
                        @if($__hasSandboxToken && !$__sandboxTokenExpired)
                            <x-ui.badge tone="success">Token active</x-ui.badge>
                            @if($setting->sandbox_expires_at ?? null)
                                <span class="cs-state__when">Expires {{ \Carbon\Carbon::parse($setting->sandbox_expires_at)->diffForHumans() }}</span>
                            @endif
                        @elseif($__hasSandboxToken && $__sandboxTokenExpired)
                            <x-ui.badge tone="danger">Token expired</x-ui.badge>
                        @else
                            <x-ui.badge tone="warning">No token</x-ui.badge>
                            <span class="cs-state__note">Save the sandbox keys, then authorise above.</span>
                        @endif
                        <span class="cs-state__fact">{{ $csSetupState }}</span>
                    </div>
                    @if($canManageLazada)
                    <div class="cs-connect__acts">
                        <x-ui.button type="button" variant="secondary" size="sm" data-setup-open>Set up store</x-ui.button>
                        <x-ui.button variant="secondary" :href="route('ext.lazada.authorize', ['store' => $setting->id ?? null, 'sandbox' => 1])" target="_blank" rel="noopener">Authorize shop</x-ui.button>
                        <x-ui.button type="submit" variant="primary">Save connection</x-ui.button>
                    </div>
                    @endif
                </div>
            </form>

        <details class="cs-hand">
            <summary>Do it by hand</summary>


            @if($canManageLazada)
            <div class="cs-steps">

                <div class="cs-step">
                    <div class="cs-step__body">
                        <span class="cs-step__title">Exchange an auth code by hand</span>
                        <form method="POST" action="{{ route('ext.lazada.token_create') }}" class="cs-exchange">
                            @csrf
                            <input type="hidden" name="sandbox" value="1">
                            <x-ui.field label="Auth code" for="lz-sb-code" name="code">
                                <x-ui.input id="lz-sb-code" name="code" value="{{ old('code', $setting->sandbox_auth_code ?? '') }}" placeholder="Paste the sandbox code here" />
                            </x-ui.field>
                            <x-ui.button type="submit" size="sm">Exchange</x-ui.button>
                        </form>
                    </div>
                </div>

                <div class="cs-step">
                    <div class="cs-step__body">
                        <span class="cs-step__title">Refresh an expired token</span>
                        <form method="POST" action="{{ route('ext.lazada.token_refresh') }}">
                            @csrf
                            <input type="hidden" name="sandbox" value="1">
                            <x-ui.button type="submit" size="sm">Refresh sandbox token</x-ui.button>
                        </form>
                    </div>
                </div>
            </div>

            <div class="cs-danger">
                <div>
                    <span class="cs-danger__title">Remove this store <x-ui.hint label="What removal does">Everything this store holds in the ERP goes with it: listings, groups, Lazada orders and returns. Sales already imported stay under its name. There is no undo.</x-ui.hint></span>
                </div>
                <x-ui.button type="button" variant="danger" size="sm" data-delete-open>Delete this store</x-ui.button>
            </div>
            @else
            <p class="cs-step__note">Authorising and refreshing tokens need the Lazada manage permission.</p>
            @endif
        </details>
        </section>

        @if($canManageLazada)
        <section class="fm-section cs-span">
            <div class="fm-section__head">
                <h2 class="fm-section__title">Paste a token instead</h2>
            </div>
            <p class="fm-section__note">If you cannot sign in as the sandbox test seller, Lazada Open Platform's own API Explorer has a Get Token button that hands you a sandbox access token directly. Paste it here and the OAuth steps above can be skipped entirely. Saving switches the shop to sandbox.</p>

            <form method="POST" action="{{ route('ext.lazada.sandbox_token_save') }}">
                @csrf
                <div class="fm-fields fm-fields--2">
                    <x-ui.credential
                        name="sandbox_access_token"
                        id="lz-sb-paste-token"
                        label="Sandbox access token"
                        channel="lazada"
                        :length="\App\Support\Credentials::length($setting->sandbox_access_token ?? null)"
                        :can-reveal="$canManageLazada"
                        placeholder="Paste the token from the Get Token button"
                        required />

                    <x-ui.field label="Good for" for="lz-sb-expires" name="expires_in_days"
                                hint="Anything from 1 to 365 days. Lazada does not say how long a pasted token lasts, so this is what the ERP will assume.">
                        <div class="cs-inline">
                            <x-ui.input id="lz-sb-expires" name="expires_in_days" type="number" value="7" min="1" max="365"
                                        class="fm-input--num cs-days" />
                            <span class="cs-inline__unit">days</span>
                        </div>
                    </x-ui.field>
                </div>

                <div class="cs-actions">
                    <x-ui.button type="submit" variant="primary">Save sandbox token</x-ui.button>
                </div>
            </form>
        </section>
        @endif
    </div>
    </div>
</div>

<div id="lz-tab-status" class="cs-panel" @if($csSection !== 'status') hidden @endif>

    <section class="fm-section">
        <div class="fm-section__head">
            <h2 class="fm-section__title">Order status mapping</h2>
        </div>
        <p class="fm-section__note">When a Lazada order is synced, its Lazada status becomes the sales order status chosen here.</p>

        <form method="POST" action="{{ route('ext.lazada.order_status_map') }}">
            @csrf
            <x-ui.table>
                <x-slot:head>
                    <tr>
                        <th scope="col" class="cs-col-src">Lazada status</th>
                        <th scope="col">Sales order status</th>
                    </tr>
                </x-slot:head>
                @foreach($lazadaStatuses as $key => $label)
                    <tr>
                        <td class="cs-col-src" data-label="Lazada status">
                            <span class="cs-code">{{ $key }}</span>
                        </td>
                        <td data-label="Sales order status">
                            <div class="x-select-wrap cs-map-select">
                                <select name="map[{{ $key }}]" class="x-input" aria-label="Sales order status for {{ $key }}" @disabled(!$canManageLazada)>
                                    @foreach($erpOrderStatuses as $os)
                                        <option value="{{ $os->order_status_id }}" @selected((int) ($orderStatusMap[$key] ?? 0) === (int) $os->order_status_id)>{{ $os->name }}</option>
                                    @endforeach
                                </select>
                                <x-ui.icon name="chevron-down" size="14" class="x-select-wrap__chevron" />
                            </div>
                        </td>
                    </tr>
                @endforeach
            </x-ui.table>

            @if($canManageLazada)
            <div class="cs-actions">
                <x-ui.button type="submit" variant="primary">Save order mapping</x-ui.button>
            </div>
            @endif
        </form>
    </section>

    <section class="fm-section">
        <div class="fm-section__head">
            <h2 class="fm-section__title">Return status mapping</h2>
        </div>
        <p class="fm-section__note">When a Lazada return is synced, its status becomes the sales order status chosen here. Leave a row unmapped to make it change nothing.</p>

        <form method="POST" action="{{ route('ext.lazada.reverse_status_map') }}">
            @csrf
            <x-ui.table>
                <x-slot:head>
                    <tr>
                        <th scope="col" class="cs-col-src">Lazada return status</th>
                        <th scope="col">Sales order status</th>
                    </tr>
                </x-slot:head>
                @foreach($reverseStatuses as $key => $label)
                    <tr>
                        <td class="cs-col-src" data-label="Lazada return status">
                            <span class="cs-code">{{ $key }}</span>
                        </td>
                        <td data-label="Sales order status">
                            <div class="x-select-wrap cs-map-select">
                                <select name="map[{{ $key }}]" class="x-input" aria-label="Sales order status for {{ $key }}" @disabled(!$canManageLazada)>
                                    <option value="">Not mapped</option>
                                    @foreach($erpOrderStatuses as $os)
                                        <option value="{{ $os->order_status_id }}" @selected((int) ($reverseStatusMap[$key] ?? 0) === (int) $os->order_status_id)>{{ $os->name }}</option>
                                    @endforeach
                                </select>
                                <x-ui.icon name="chevron-down" size="14" class="x-select-wrap__chevron" />
                            </div>
                        </td>
                    </tr>
                @endforeach
            </x-ui.table>

            @if($canManageLazada)
            <div class="cs-actions">
                <x-ui.button type="submit" variant="primary">Save return mapping</x-ui.button>
            </div>
            @endif
        </form>
    </section>
</div>

@if($canManageLazada)
<div id="lz-tab-explorer" class="cs-panel" @if($csSection !== 'explorer') hidden @endif>
    <section class="fm-section">
        <div class="fm-section__head">
            <h2 class="fm-section__title">API explorer</h2>
        </div>
        <p class="fm-section__note">Calls the Lazada API directly with this shop's credentials. Signing and timestamps are generated for you. Anything you run here is recorded in the API log.</p>

        <div class="api-explorer" data-lz-explorer>
            <div class="api-panel">
                <div class="api-search">
                    <x-ui.input id="api-endpoint-search" data-lz-ex-search placeholder="Search endpoints" aria-label="Search endpoints" />
                </div>

                <div class="api-list" id="api-endpoint-list">
                    <div class="api-cat">Seller</div>
                    <button type="button" class="api-endpoint" data-ep='{"name":"Get Seller","method":"GET","auth":true,"path":"/seller/get","desc":"Fetch seller/account info.","params":[]}'><span class="api-badge get">GET</span><span class="api-code">/seller/get</span><div class="api-hint">Seller and account info</div></button>

                    <div class="api-cat">Catalog</div>
                    <button type="button" class="api-endpoint" data-ep='{"name":"Category Tree","method":"GET","auth":false,"path":"/category/tree/get","desc":"Fetch category tree.","params":[]}'><span class="api-badge get">GET</span><span class="api-code">/category/tree/get</span><div class="api-hint">Public, no token needed</div></button>
                    <button type="button" class="api-endpoint" data-ep='{"name":"Category Attributes","method":"GET","auth":false,"path":"/category/attributes/get","desc":"Get attributes for a primary category.","params":[{"k":"primary_category_id","req":true,"ph":"9257"}]}'><span class="api-badge get">GET</span><span class="api-code">/category/attributes/get</span><div class="api-hint">primary_category_id</div></button>
                    <button type="button" class="api-endpoint" data-ep='{"name":"Brands Query","method":"GET","auth":false,"path":"/category/brands/query","desc":"Brands list (uses startRow/pageSize internally).","params":[{"k":"page_no","req":false,"ph":"1"},{"k":"page_size","req":false,"ph":"50"},{"k":"brand_name","req":false,"ph":"Morley"}]}'><span class="api-badge get">GET</span><span class="api-code">/category/brands/query</span><div class="api-hint">Paging, optional brand_name</div></button>
                    <button type="button" class="api-endpoint" data-ep='{"name":"Products (List)","method":"GET","auth":true,"path":"/products/get","desc":"List products.","params":[{"k":"filter","req":false,"ph":"all"},{"k":"limit","req":false,"ph":"10"},{"k":"offset","req":false,"ph":"0"}]}'><span class="api-badge get">GET</span><span class="api-code">/products/get</span><div class="api-hint">Token required</div></button>
                    <button type="button" class="api-endpoint" data-ep='{"name":"QC Status","method":"GET","auth":true,"path":"/product/qc/status/get","desc":"QC status by seller_sku.","params":[{"k":"seller_sku","req":true,"ph":"MY-SKU"}]}'><span class="api-badge get">GET</span><span class="api-code">/product/qc/status/get</span><div class="api-hint">seller_sku</div></button>

                    <div class="api-cat">Products</div>
                    <button type="button" class="api-endpoint" data-ep='{"name":"Create Product","method":"POST","auth":true,"path":"/product/create","desc":"Create product. Lazada expects payload as a JSON or XML string.","params":[{"k":"payload","req":true,"type":"payload"}]}'><span class="api-badge post">POST</span><span class="api-code">/product/create</span><div class="api-hint">payload required</div></button>
                    <button type="button" class="api-endpoint" data-ep='{"name":"Update Product","method":"POST","auth":true,"path":"/product/update","desc":"Update product. payload required.","params":[{"k":"payload","req":true,"type":"payload"}]}'><span class="api-badge post">POST</span><span class="api-code">/product/update</span><div class="api-hint">payload required</div></button>
                    <button type="button" class="api-endpoint" data-ep='{"name":"Remove Product","method":"POST","auth":true,"path":"/product/remove","desc":"Remove product or SKUs. Lazada expects seller_sku_list and/or sku_id_list as JSON-array strings (max 50).","params":[{"k":"seller_sku_list","req":false,"ph":"[\\\"test00111\\\",\\\"test00222\\\"]"},{"k":"sku_id_list","req":false,"ph":"[\\\"SkuId_123_456\\\",\\\"SkuId_123_789\\\"]"}]}'><span class="api-badge post">POST</span><span class="api-code">/product/remove</span><div class="api-hint">seller_sku_list, sku_id_list</div></button>
                    <button type="button" class="api-endpoint" data-ep='{"name":"Update Price/Qty","method":"POST","auth":true,"path":"/product/price_quantity/update","desc":"Update price + quantity.","params":[{"k":"seller_sku","req":true,"ph":"MY-SKU"},{"k":"price","req":true,"ph":"999"},{"k":"quantity","req":true,"ph":"10"}]}'><span class="api-badge post">POST</span><span class="api-code">/product/price_quantity/update</span><div class="api-hint">seller_sku, price, quantity</div></button>

                    <div class="api-cat">Orders</div>
                    <button type="button" class="api-endpoint" data-ep='{"name":"Orders (Range)","method":"GET","auth":true,"path":"/orders/get","desc":"Orders list.","params":[{"k":"update_after","req":false,"ph":""},{"k":"sort_direction","req":false,"ph":"DESC"},{"k":"limit","req":false,"ph":"10"},{"k":"offset","req":false,"ph":"0"}]}'><span class="api-badge get">GET</span><span class="api-code">/orders/get</span><div class="api-hint">update_after, limit, offset</div></button>
                    <button type="button" class="api-endpoint" data-ep='{"name":"Order (Single)","method":"GET","auth":true,"path":"/order/get","desc":"Fetch one order.","params":[{"k":"order_id","req":true,"ph":"123"}]}'><span class="api-badge get">GET</span><span class="api-code">/order/get</span><div class="api-hint">order_id</div></button>
                    <button type="button" class="api-endpoint" data-ep='{"name":"Order Items","method":"GET","auth":true,"path":"/order/items/get","desc":"Items for an order.","params":[{"k":"order_id","req":true,"ph":"123"}]}'><span class="api-badge get">GET</span><span class="api-code">/order/items/get</span><div class="api-hint">order_id</div></button>
                    <button type="button" class="api-endpoint" data-ep='{"name":"Pack Order","method":"POST","auth":true,"path":"/order/pack","desc":"Pack order items.","params":[{"k":"delivery_type","req":true,"ph":"dropship"},{"k":"shipping_provider","req":true,"ph":""},{"k":"order_item_ids","req":true,"ph":"comma separated"}]}'><span class="api-badge post">POST</span><span class="api-code">/order/pack</span><div class="api-hint">delivery_type, shipping_provider, order_item_ids</div></button>
                    <button type="button" class="api-endpoint" data-ep='{"name":"Ready To Ship (RTS)","method":"POST","auth":true,"path":"/order/rts","desc":"Mark packed items as ready to ship.","params":[{"k":"delivery_type","req":true,"ph":"dropship"},{"k":"shipping_provider","req":true,"ph":""},{"k":"tracking_number","req":false,"ph":""},{"k":"order_item_ids","req":true,"ph":"comma separated"}]}'><span class="api-badge post">POST</span><span class="api-code">/order/rts</span><div class="api-hint">delivery_type, shipping_provider, order_item_ids</div></button>
                    <button type="button" class="api-endpoint" data-ep='{"name":"Get Shipping Label (AWB)","method":"GET","auth":true,"path":"/order/package/document/get","desc":"Fetch shipping label or document for an order.","params":[{"k":"order_id","req":true,"ph":"123"},{"k":"doc_type","req":false,"ph":"shippingLabel"}]}'><span class="api-badge get">GET</span><span class="api-code">/order/package/document/get</span><div class="api-hint">order_id, doc_type</div></button>
                    <button type="button" class="api-endpoint" data-ep='{"name":"Cancel Order Item","method":"POST","auth":true,"path":"/order/cancel","desc":"Cancel an order item.","params":[{"k":"reason_id","req":true,"ph":""},{"k":"order_item_id","req":true,"ph":""},{"k":"reason_detail","req":false,"ph":""}]}'><span class="api-badge post">POST</span><span class="api-code">/order/cancel</span><div class="api-hint">reason_id, order_item_id</div></button>
                    <button type="button" class="api-endpoint" data-ep='{"name":"Validate Cancel","method":"GET","auth":true,"path":"/order/reverse/cancel/validate","desc":"Validate whether an order can be cancelled.","params":[{"k":"order_id","req":true,"ph":"123"}]}'><span class="api-badge get">GET</span><span class="api-code">/order/reverse/cancel/validate</span><div class="api-hint">order_id</div></button>

                    <div class="api-cat">Images</div>
                    <button type="button" class="api-endpoint" data-ep='{"name":"Image Upload (Base64)","method":"POST","auth":true,"path":"/image/upload","desc":"Upload base64 image (returns a Lazada-hosted link).","params":[{"k":"image","req":true,"ph":"base64 data"}]}'><span class="api-badge post">POST</span><span class="api-code">/image/upload</span><div class="api-hint">image, base64</div></button>
                    <button type="button" class="api-endpoint" data-ep='{"name":"Images Migrate","method":"POST","auth":true,"path":"/images/migrate","desc":"Migrate public URLs to Lazada-hosted images. payload required.","params":[{"k":"payload","req":true,"type":"payload"}]}'><span class="api-badge post">POST</span><span class="api-code">/images/migrate</span><div class="api-hint">payload required</div></button>
                    <button type="button" class="api-endpoint" data-ep='{"name":"Image Response Get","method":"GET","auth":true,"path":"/image/response/get","desc":"Fetch migrate results by batch_id.","params":[{"k":"batch_id","req":true,"ph":"batch id"}]}'><span class="api-badge get">GET</span><span class="api-code">/image/response/get</span><div class="api-hint">batch_id</div></button>

                    <div class="api-cat">Finance</div>
                    <button type="button" class="api-endpoint" data-ep='{"name":"Transaction Details","method":"GET","auth":true,"path":"/finance/transaction/details/get","desc":"Transaction and fee details.","params":[{"k":"start_time","req":true,"ph":""},{"k":"end_time","req":true,"ph":""},{"k":"limit","req":false,"ph":"10"},{"k":"offset","req":false,"ph":"0"}]}'><span class="api-badge get">GET</span><span class="api-code">/finance/transaction/details/get</span><div class="api-hint">start_time, end_time</div></button>

                    <div class="api-cat">Reviews</div>
                    <button type="button" class="api-endpoint" data-ep='{"name":"Review History List","method":"GET","auth":true,"path":"/review/seller/history/list","desc":"List review history for the seller within a date range (7-day window at most).","params":[{"k":"start_date","req":true,"ph":"2026-02-23"},{"k":"end_date","req":true,"ph":"2026-03-02"},{"k":"page_no","req":false,"ph":"1"},{"k":"page_size","req":false,"ph":"50"}]}'><span class="api-badge get">GET</span><span class="api-code">/review/seller/history/list</span><div class="api-hint">start_date, end_date, page_no, page_size</div></button>
                    <button type="button" class="api-endpoint" data-ep='{"name":"Review Detail (Batch)","method":"GET","auth":true,"path":"/review/seller/list/v2","desc":"Get detailed review info by review ID list (JSON array of integers, max 20).","params":[{"k":"review_id_list","req":true,"ph":"[123456]"}]}'><span class="api-badge get">GET</span><span class="api-code">/review/seller/list/v2</span><div class="api-hint">review_id_list, a JSON array</div></button>

                    <div class="api-cat">Returns</div>
                    <button type="button" class="api-endpoint" data-ep='{"name":"Reverse Orders List","method":"GET","auth":true,"path":"/reverse/getreverseordersforseller","desc":"List reverse orders (returns and refunds) for the seller, with pagination.","params":[{"k":"pageNo","req":false,"ph":"1"},{"k":"pageSize","req":false,"ph":"50"},{"k":"create_time_start","req":false,"ph":""},{"k":"create_time_end","req":false,"ph":""}]}'><span class="api-badge get">GET</span><span class="api-code">/reverse/getreverseordersforseller</span><div class="api-hint">pageNo, pageSize, date filters</div></button>
                    <button type="button" class="api-endpoint" data-ep='{"name":"Reverse Order Detail","method":"GET","auth":true,"path":"/order/reverse/return/detail/list","desc":"Get detail for a specific reverse order.","params":[{"k":"reverseOrderId","req":true,"ph":""}]}'><span class="api-badge get">GET</span><span class="api-code">/order/reverse/return/detail/list</span><div class="api-hint">reverseOrderId</div></button>
                    <button type="button" class="api-endpoint" data-ep='{"name":"Reverse Order History","method":"GET","auth":true,"path":"/order/reverse/return/history/list","desc":"Communication history for a reverse order line.","params":[{"k":"reverseOrderLineId","req":true,"ph":""},{"k":"pageSize","req":false,"ph":"10"},{"k":"pageNumber","req":false,"ph":"1"}]}'><span class="api-badge get">GET</span><span class="api-code">/order/reverse/return/history/list</span><div class="api-hint">reverseOrderLineId</div></button>
                </div>
            </div>

            <div class="api-panel">
                <div class="api-split">
                    <div>
                        <span class="fm-label">Selected endpoint</span>
                        <div class="api-mini" data-lz-ex-name>None selected</div>
                        <div class="api-hint" data-lz-ex-desc></div>
                    </div>
                    <div>
                        <span class="fm-label">If a call comes back wrong</span>
                        <div class="api-mini">
                            <div>A payload has to be one string. Paste a JSON object and it is encoded for you, but Lazada still expects its own structure inside.</div>
                            <div>E005, Invalid Request Format, usually means the endpoint wanted the payload in a shape it did not get.</div>
                            <div>Repeated timestamps in the log mean you are reading an old row. The newest is at the top.</div>
                        </div>
                    </div>
                </div>

                @if($result && $resultTab === 'explorer')
                    @include('partials.channel-last-result', ['result' => $result, 'channel' => 'Lazada'])
                @endif
                <form method="POST" action="{{ route('ext.lazada.explorer_run') }}" data-lz-ex-form>
                    @csrf

                    <div class="fm-fields fm-fields--2">
                        <x-ui.field label="Method" for="api-explorer-method" name="method">
                            <div class="x-select-wrap">
                                <select id="api-explorer-method" name="method" class="x-input" data-lz-ex-method>
                                    <option value="GET" @selected(old('method', 'GET') === 'GET')>GET</option>
                                    <option value="POST" @selected(old('method') === 'POST')>POST</option>
                                </select>
                                <x-ui.icon name="chevron-down" size="14" class="x-select-wrap__chevron" />
                            </div>
                        </x-ui.field>

                        <div class="fm-field">
                            <div class="fm-label-row">
                                <span class="fm-label">Send with the request</span>
                                <x-ui.hint class="fm-label__hint" label="About the access token">Needed for seller, product, order and finance endpoints. The category and brand endpoints are public.</x-ui.hint>
                            </div>
                            <label class="cs-check">
                                <input type="checkbox" name="auth_required" value="1" @checked(old('auth_required')) data-lz-ex-auth>
                                Access token
                            </label>
                        </div>

                        <x-ui.field label="API path" for="api-explorer-path" name="api_path" wide
                                    hint="A leading slash is added for you.">
                            <x-ui.input id="api-explorer-path" name="api_path" class="api-code" data-lz-ex-path
                                        value="{{ old('api_path', '/seller/get') }}" placeholder="/seller/get" />
                        </x-ui.field>

                        <div class="fm-field fm-field--wide">
                            <span class="fm-label">Parameters</span>
                            <div class="api-mini">Fill the table and the JSON below is written for you. A value that parses as JSON is sent as JSON.</div>
                            <table class="api-kv">
                                <thead>
                                    <tr>
                                        <th scope="col" class="cs-kv-name">Name</th>
                                        <th scope="col">Value</th>
                                        <th scope="col" class="cs-kv-act"><span class="x-sr">Remove</span></th>
                                    </tr>
                                </thead>
                                <tbody data-lz-ex-kv></tbody>
                            </table>
                            <div class="api-row-actions">
                                <x-ui.button type="button" size="sm" data-lz-ex-add>Add parameter</x-ui.button>
                                <x-ui.button type="button" size="sm" data-lz-ex-sync>Read from JSON</x-ui.button>
                                <x-ui.button type="button" size="sm" data-lz-ex-sample hidden>Insert sample payload</x-ui.button>
                            </div>
                        </div>

                        <x-ui.field label="Parameters as JSON" for="api-explorer-params" name="params_json_pretty" wide
                                    hint="Edit here or in the table above, whichever is easier. A payload is shown expanded here and flattened to the single string Lazada wants when the request is sent.">
                            <x-ui.textarea id="api-explorer-params" name="params_json_pretty" rows="10" class="api-code" data-lz-ex-pretty placeholder='{"limit":10,"offset":0}'>{{ old('params_json_pretty', "{\n  \"payload\": {\n    \"Request\": {}\n  }\n}") }}</x-ui.textarea>
                        </x-ui.field>

                        <input type="hidden" name="params_json" data-lz-ex-hidden value="{{ old('params_json', '{"payload":"<xml_or_json_payload_here>"}') }}">
                    </div>

                    <div class="cs-actions">
                        <x-ui.button type="submit" variant="primary">Run request</x-ui.button>
                        <span class="cs-actions__note">The response lands on the API log tab.</span>
                    </div>
                </form>
            </div>
        </div>
    </section>
</div>
@endif

<div id="lz-tab-logs" class="cs-panel" @if($csSection !== 'logs') hidden @endif>



    @include('partials.channel-api-log', [
        'logChannel' => 'Lazada',
        'canManage' => $canManageLazada,
        'logMode' => $setting->api_log_mode ?? 'all',
        'modeUrl' => route('ext.lazada.api_log_mode'),
        'logs' => $logs,
        'clearUrl' => route('ext.lazada.clear_api_logs'),
        'map' => [
            'path' => 'api_path', 'status' => 'response_status',
            'okFn' => fn ($r) => (bool) $r->ok,
            'ms' => null, 'pack' => 'pack', 'signed' => 'auth_required',
            'request' => 'request_params', 'response' => 'response_body',
            'requestLabel' => 'Request parameters',
        ],
    ])

    @if($canManageLazada)
    <section class="fm-section">
        <div class="fm-section__head">
            <h2 class="fm-section__title">Free up space</h2>
            <x-ui.hint label="What clearing does">Every synced Lazada order, order line and return keeps the full API response it came from. Clearing those payloads on older rows frees database space and changes nothing about the orders themselves.</x-ui.hint>
        </div>

        <form method="POST" action="{{ route('ext.lazada.purge_raw') }}"
              data-confirm="Clear the stored API payloads on Lazada rows older than the number of days given? The orders and returns stay, only the raw payloads go, and this cannot be undone.">
            @csrf
            <div class="fm-field">
                <div class="fm-label-row">
                    <label class="fm-label" for="lz-purge-days">Clear payloads older than</label>
                    <x-ui.hint class="fm-label__hint" bubble-id="lz-purge-hint" label="About the days">Anything from 1 to 3650 days.</x-ui.hint>
                </div>
                <div class="cs-inline">
                    <x-ui.input id="lz-purge-days" name="days" type="number" value="30" min="1" max="3650" required
                                class="fm-input--num cs-days" aria-describedby="lz-purge-hint" />
                    <span class="cs-inline__unit">days</span>
                    <x-ui.button type="submit" variant="danger">Clear stored payloads</x-ui.button>
                </div>
                @error('days')<div class="fm-error">{{ $message }}</div>@enderror
            </div>
        </form>
    </section>
    @endif
</div>

@if($canManageLazada)
<div id="lz-tab-automations" class="cs-panel" @if($csSection !== 'automations') hidden @endif>
    <section class="fm-section">
        <div class="fm-section__head">
            <h2 class="fm-section__title">Scheduled jobs</h2>
            <x-ui.hint label="About the scheduled jobs">How often each Lazada sync runs, and how far back it looks. Changes here take effect the next time the scheduler wakes up.</x-ui.hint>
        </div>
        @include('partials._automations_tab', ['integration' => 'lazada', 'storeId' => null])
    </section>
</div>
@endif

</div>
@endsection
