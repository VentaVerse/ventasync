@extends('layouts.channel')
@section('breadcrumb', 'Settings')

@section('title', 'Shopee Settings')

@section('content')
@php
    $canManageShopee = auth()->user()?->hasPermission('manage_shopee/settings') ?? false;

    $currentMode = $setting->mode ?? 'sandbox';
    $isSandbox = $currentMode === 'sandbox';

    $__hasToken = !empty($setting->access_token ?? '');
    $__tokenExpired = $__hasToken && ($setting->expires_at ?? null) && \Carbon\Carbon::parse($setting->expires_at)->lt(now());

    $__hasSandboxCredentials = ($setting->sandbox_partner_id ?? '') !== '' && ($setting->sandbox_partner_key ?? '') !== '';
    $__hasSandboxToken = !empty($setting->sandbox_access_token ?? '');
    $__sandboxTokenExpired = $__hasSandboxToken && ($setting->sandbox_expires_at ?? null) && \Carbon\Carbon::parse($setting->sandbox_expires_at)->lt(now());



    $regions = [
        'sg' => 'Singapore', 'my' => 'Malaysia', 'th' => 'Thailand', 'vn' => 'Vietnam',
        'id' => 'Indonesia', 'ph' => 'Philippines', 'tw' => 'Taiwan', 'br' => 'Brazil',
        'mx' => 'Mexico', 'co' => 'Colombia', 'cl' => 'Chile', 'pl' => 'Poland',
    ];
@endphp

@php
    $csKnown = ['connection', 'status', 'logs'];
    if ($canManageShopee) { $csKnown[] = 'explorer'; $csKnown[] = 'automations'; }
    $csSection = in_array(request('tab'), $csKnown, true) ? request('tab') : 'connection';
    $resultTab = in_array(session('settings_tab'), $csKnown, true) ? session('settings_tab') : 'connection';
@endphp
<div class="cs-page"
     data-channel-settings
     data-tab-prefix="sp-tab-"
     data-tab-storage-key="sp-active-tab"
     data-default-tab="">

<div class="x-list-head">
    <div>
        <h1 class="x-page-title">Settings</h1>
        <p class="x-page-sub">{{ ($setting->store_name ?? '') !== '' ? $setting->store_name : 'This store' }} on Shopee, {{ $isSandbox ? 'sandbox' : 'production' }}.</p>
    </div>
    <div class="cs-head-state">
        @if($setting->enabled ?? true)
            <x-ui.badge tone="success">Active</x-ui.badge>
        @else
            <x-ui.badge tone="neutral">Paused</x-ui.badge>
        @endif
    </div>
</div>

@unless($canManageShopee)
<div class="cs-readonly">
    <div>
        <strong>You can look, not change.</strong>
        Settings, status mapping and the API record are readable with the Shopee view permission. Editing credentials, running syncs and clearing logs need the manage tier.
    </div>
</div>
@endunless

@if($result && $resultTab !== 'explorer')
    @include('partials.channel-last-result', ['result' => $result, 'channel' => 'Shopee', 'mode' => 'note'])
@endif

@if($canManageShopee)
    @include('partials.channel-setup', [
        'channelLabel' => 'Shopee',
        'storeName' => $setting->store_name ?? '',
        'run' => (bool) session('settings_setup'),
        'steps' => [
            ['label' => 'Categories', 'url' => route('ext.shopee.setup_step', ['step' => 'categories'])],
            ['label' => 'Couriers', 'url' => route('ext.shopee.setup_step', ['step' => 'couriers'])],
        ],
    ])
    @include('partials.channel-delete-store', [
        'action' => route('ext.shopee.stores.destroy'),
        'storeName' => $setting->store_name ?? '',
        'channelLabel' => 'Shopee',
    ])
@endif

<div id="sp-tab-connection" class="cs-panel" @if($csSection !== 'connection') hidden @endif>



    @if($canManageShopee)
        <form method="POST" action="{{ route('ext.shopee.toggle_mode') }}" data-cs-env-form hidden>
            @csrf
            <input type="hidden" name="mode" value="{{ $currentMode }}" data-cs-env-input>
        </form>
    @endif

    @php
        $csCategories = \Extensions\shopee\Models\ShopeeCategory::query()->count();
        $csCouriers = \Extensions\shopee\Models\ShopeeLogistic::query()->count();
        $csSetUp = $csCategories > 0 && $csCouriers > 0;
        $csSetupState = $csSetUp || $csCategories > 0 || $csCouriers > 0
            ? number_format($csCategories) . ' ' . \Illuminate\Support\Str::plural('category', $csCategories) . ' and ' . number_format($csCouriers) . ' ' . \Illuminate\Support\Str::plural('courier', $csCouriers) . ' on file'
            : 'Nothing fetched yet';
        $csLiveKeys = ($setting->partner_id ?? '') !== '' && ($setting->partner_key ?? '') !== '';
    @endphp
    <div class="cs-env-panel" data-cs-env-panel="live" @if($isSandbox) hidden @endif>
    <div class="cs-stack">

        <section class="fm-section">
            <div class="fm-section__head">
                <div>
                    <h2 class="fm-section__title">Keys and token</h2>
                    <p class="fm-section__note">Each environment keeps its own keys and its own token.</p>
                </div>
                @if($canManageShopee)
                    <div class="x-segment cs-envseg" role="tablist" aria-label="Shopee environment">
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

            <form method="POST" action="{{ route('ext.shopee.save') }}" id="sp-save-live">
                @csrf
                <input type="hidden" name="env" value="live">

                <div class="fm-fields">
                    <x-ui.field label="Store name" for="sp-store-name" name="store_name" :required="true" wide
                                hint="How this store is named across the ERP - the sidebar, the fulfilment tabs, the channels board.">
                        <x-ui.input id="sp-store-name" name="store_name" type="text" autocomplete="off" :disabled="!$canManageShopee"
                                    value="{{ old('store_name', $setting->store_name ?? '') }}" />
                    </x-ui.field>

                    <x-ui.field label="Active" for="sp-enabled" name="enabled" wide
                                hint="An active store syncs on the crons and shows its tabs. Switch off to pause the store without losing anything.">
                        <input type="hidden" name="enabled" value="0">
                        <label class="fm-switch cs-store-switch">
                            <input type="checkbox" class="fm-switch__input" id="sp-enabled" name="enabled" value="1" @checked((bool) ($setting->enabled ?? true)) @disabled(!$canManageShopee)>
                            <span class="fm-switch__track" aria-hidden="true"></span>
                        </label>
                    </x-ui.field>

                    <x-ui.field label="Region" for="sp-region" name="region" wide
                                hint="Which Shopee country site this shop trades on.">
                        <div class="x-select-wrap">
                            <select id="sp-region" name="region" class="x-input" @disabled(!$canManageShopee)>
                                <option value="">Not set</option>
                                @foreach($regions as $code => $name)
                                    <option value="{{ $code }}" @selected(strtolower((string) ($setting->region ?? '')) === $code)>{{ $name }} ({{ strtoupper($code) }})</option>
                                @endforeach
                            </select>
                            <x-ui.icon name="chevron-down" size="14" class="x-select-wrap__chevron" />
                        </div>
                    </x-ui.field>

                    <x-ui.field label="Partner ID" for="sp-partner-id" name="partner_id" wide
                                hint="The numeric app identifier from your Shopee Open Platform app. Not a secret, so it is shown in full.">
                        <x-ui.input id="sp-partner-id" name="partner_id" class="fm-input--num" type="text" autocomplete="off" :disabled="!$canManageShopee"
                                    value="{{ $setting->partner_id ?? '' }}" />
                    </x-ui.field>

                    <x-ui.credential
                        name="partner_key"
                        id="sp-partner-key"
                        label="Partner Key"
                        channel="shopee"
                        hint="Stored encrypted. Read-only until you choose to edit it."
                        :length="\App\Support\Credentials::length($setting->partner_key ?? null)"
                        :can-reveal="$canManageShopee"
                        :disabled="!$canManageShopee" />

                    <x-ui.field label="Shop ID" for="sp-shop-id" name="shop_id" wide>
                        <x-ui.input id="sp-shop-id" name="shop_id" class="fm-input--num" type="text" autocomplete="off" :disabled="!$canManageShopee"
                                    value="{{ $setting->shop_id ?? '' }}" />
                    </x-ui.field>

                    <x-ui.credential
                        name="access_token"
                        id="sp-access-token"
                        label="Access Token"
                        channel="shopee"
                        hint="Stored encrypted. Normally filled in for you by the authorisation steps beside this."
                        :length="\App\Support\Credentials::length($setting->access_token ?? null)"
                        :can-reveal="$canManageShopee"
                        :disabled="!$canManageShopee" />

                    <x-ui.field label="Redirect URI" for="sp-redirect" wide
                                hint="Register this exact URL in your Shopee Open Platform production app. To change it, edit APP_URL in the environment file.">
                        <x-ui.input id="sp-redirect" type="text" readonly value="{{ $defaultRedirect }}" data-select-on-click />
                    </x-ui.field>

                    <x-ui.field label="Webhook URL" for="sp-webhook" wide>
                        <x-ui.input id="sp-webhook" type="text" readonly value="{{ $webhookUrl }}" data-select-on-click />
                    </x-ui.field>

                    <x-ui.credential
                        name="push_partner_key"
                        id="sp-push-partner-key"
                        label="Push Partner Key"
                        channel="shopee"
                        :length="\App\Support\Credentials::length($setting->push_partner_key ?? null)"
                        :can-reveal="$canManageShopee"
                        :disabled="!$canManageShopee" />

                    <x-ui.field label="Apply order pushes" for="sp-apply-pushes" name="apply_order_pushes" wide
                                hint="One switch for this store in either mode. On: a new order, a status change, a tracking number or a return reaches the ERP the moment Shopee sends its push, by the same path as the order sync, stock included. Off: pushes are only recorded and the order sync brings every change in.">
                        <input type="hidden" name="apply_order_pushes" value="0">
                        <label class="fm-switch cs-store-switch">
                            <input type="checkbox" class="fm-switch__input" id="sp-apply-pushes" name="apply_order_pushes" value="1" @checked((bool) ($setting->apply_order_pushes ?? false)) @disabled(!$canManageShopee)>
                            <span class="fm-switch__track" aria-hidden="true"></span>
                        </label>
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
                            <span class="cs-state__note">Refresh it under Do it by hand to renew it without authorising again.</span>
                        @else
                            <x-ui.badge tone="warning">No token</x-ui.badge>
                            <span class="cs-state__note">Save the keys, then authorise.</span>
                        @endif
                        <span class="cs-state__fact">{{ $csSetupState }}</span>
                    </div>
                    @if($canManageShopee)
                    <div class="cs-connect__acts">
                        <x-ui.button type="button" variant="secondary" size="sm" data-setup-open>Set up store</x-ui.button>
                        <x-ui.button variant="secondary" :href="route('ext.shopee.authorize', ['store' => $setting->id ?? null])" target="_blank" rel="noopener">Authorize shop</x-ui.button>
                        <x-ui.button type="submit" variant="primary">Save connection</x-ui.button>
                    </div>
                    @endif
                </div>
            </form>

        <details class="cs-hand">
            <summary>Do it by hand</summary>


            @if($canManageShopee)
            <div class="cs-steps">
                <div class="cs-step">
                    <div class="cs-step__body">
                        <span class="cs-step__title">Exchange an auth code by hand <x-ui.hint label="About this step">Only needed when the automatic exchange did not run, for example if you copied the redirect URL out of the address bar.</x-ui.hint></span>
                        <form method="POST" action="{{ route('ext.shopee.token_get') }}" class="cs-exchange">
                            @csrf
                            <x-ui.field label="Auth code" for="sp-code" name="code">
                                <x-ui.input id="sp-code" name="code" value="{{ old('code', '') }}" placeholder="Paste the code= value here" />
                            </x-ui.field>
                            <x-ui.button type="submit" size="sm">Exchange</x-ui.button>
                        </form>
                    </div>
                </div>

                <div class="cs-step">
                    <div class="cs-step__body">
                        <span class="cs-step__title">Refresh an expired token <x-ui.hint label="About this step">Shopee access tokens last about four hours. The scheduled job renews them every two hours, so this is here for when that job has not run.</x-ui.hint></span>
                        <form method="POST" action="{{ route('ext.shopee.token_refresh') }}">
                            @csrf
                            <x-ui.button type="submit" size="sm">Refresh access token</x-ui.button>
                        </form>
                    </div>
                </div>
            </div>

            <div class="cs-danger">
                <div>
                    <span class="cs-danger__title">Remove this store <x-ui.hint label="What removal does">Everything this store holds in the ERP goes with it: listings, links, groups, couriers, Shopee orders and returns, and its API log. Sales already imported stay under its name. There is no undo.</x-ui.hint></span>
                </div>
                <x-ui.button type="button" variant="danger" size="sm" data-delete-open>Delete this store</x-ui.button>
            </div>
            @else
            <p class="cs-step__note">Authorising and refreshing tokens need the Shopee manage permission.</p>
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
                @if($canManageShopee)
                    <div class="x-segment cs-envseg" role="tablist" aria-label="Shopee environment">
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

            <form method="POST" action="{{ route('ext.shopee.save') }}" id="sp-save-sandbox">
                @csrf
                <input type="hidden" name="env" value="sandbox">

                <div class="fm-fields">
                    <x-ui.field label="Store name" for="sp-sb-store-name" name="store_name" :required="true" wide
                                hint="How this store is named across the ERP - the sidebar, the fulfilment tabs, the channels board.">
                        <x-ui.input id="sp-sb-store-name" name="store_name" type="text" autocomplete="off" :disabled="!$canManageShopee"
                                    value="{{ old('store_name', $setting->store_name ?? '') }}" />
                    </x-ui.field>

                    <x-ui.field label="Active" for="sp-sb-enabled" name="enabled" wide
                                hint="An active store syncs on the crons and shows its tabs. Switch off to pause the store without losing anything.">
                        <input type="hidden" name="enabled" value="0">
                        <label class="fm-switch cs-store-switch">
                            <input type="checkbox" class="fm-switch__input" id="sp-sb-enabled" name="enabled" value="1" @checked((bool) ($setting->enabled ?? true)) @disabled(!$canManageShopee)>
                            <span class="fm-switch__track" aria-hidden="true"></span>
                        </label>
                    </x-ui.field>

                    <x-ui.field label="Region" for="sp-sb-region" name="sandbox_region" wide
                                hint="Which Shopee country site this sandbox shop trades on.">
                        <div class="x-select-wrap">
                            <select id="sp-sb-region" name="sandbox_region" class="x-input" @disabled(!$canManageShopee)>
                                <option value="">Not set</option>
                                @foreach($regions as $code => $name)
                                    <option value="{{ $code }}" @selected(strtolower((string) ($setting->sandbox_region ?? '')) === $code)>{{ $name }} ({{ strtoupper($code) }})</option>
                                @endforeach
                            </select>
                            <x-ui.icon name="chevron-down" size="14" class="x-select-wrap__chevron" />
                        </div>
                    </x-ui.field>

                    <x-ui.field label="Sandbox Partner ID" for="sp-sb-partner-id" name="sandbox_partner_id" wide>
                        <x-ui.input id="sp-sb-partner-id" name="sandbox_partner_id" class="fm-input--num" type="text" autocomplete="off" :disabled="!$canManageShopee"
                                    value="{{ $setting->sandbox_partner_id ?? '' }}" />
                    </x-ui.field>

                    <x-ui.credential
                        name="sandbox_partner_key"
                        id="sp-sb-partner-key"
                        label="Sandbox Partner Key"
                        channel="shopee"
                        hint="Stored encrypted. Read-only until you choose to edit it."
                        :length="\App\Support\Credentials::length($setting->sandbox_partner_key ?? null)"
                        :can-reveal="$canManageShopee"
                        :disabled="!$canManageShopee" />

                    <x-ui.field label="Sandbox Shop ID" for="sp-sb-shop-id" name="sandbox_shop_id" wide
                                hint="The sandbox shop this connects to. Not a secret, so it is shown in full.">
                        <x-ui.input id="sp-sb-shop-id" name="sandbox_shop_id" class="fm-input--num" type="text" autocomplete="off" :disabled="!$canManageShopee"
                                    value="{{ $setting->sandbox_shop_id ?? '' }}" />
                    </x-ui.field>

                    <x-ui.credential
                        name="sandbox_access_token"
                        id="sp-sb-access-token"
                        label="Sandbox Access Token"
                        channel="shopee"
                        hint="Stored encrypted."
                        :length="\App\Support\Credentials::length($setting->sandbox_access_token ?? null)"
                        :can-reveal="$canManageShopee"
                        :disabled="!$canManageShopee" />

                    <x-ui.field label="Redirect URI" for="sp-sb-redirect" wide
                                hint="Register this exact URL in your Shopee Open Platform sandbox app. To change it, edit APP_URL in the environment file.">
                        <x-ui.input id="sp-sb-redirect" type="text" readonly value="{{ $defaultRedirect }}" data-select-on-click />
                    </x-ui.field>

                    <x-ui.field label="Webhook URL" for="sp-sb-webhook" wide>
                        <x-ui.input id="sp-sb-webhook" type="text" readonly value="{{ $webhookUrl }}" data-select-on-click />
                    </x-ui.field>

                    <x-ui.credential
                        name="sandbox_push_partner_key"
                        id="sp-sb-push-partner-key"
                        label="Sandbox Push Partner Key"
                        channel="shopee"
                        :length="\App\Support\Credentials::length($setting->sandbox_push_partner_key ?? null)"
                        :can-reveal="$canManageShopee"
                        :disabled="!$canManageShopee" />

                    <x-ui.field label="Apply order pushes" for="sp-sb-apply-pushes" name="apply_order_pushes" wide
                                hint="One switch for this store in either mode. On: a new order, a status change, a tracking number or a return reaches the ERP the moment Shopee sends its push, by the same path as the order sync, stock included. Off: pushes are only recorded and the order sync brings every change in.">
                        <input type="hidden" name="apply_order_pushes" value="0">
                        <label class="fm-switch cs-store-switch">
                            <input type="checkbox" class="fm-switch__input" id="sp-sb-apply-pushes" name="apply_order_pushes" value="1" @checked((bool) ($setting->apply_order_pushes ?? false)) @disabled(!$canManageShopee)>
                            <span class="fm-switch__track" aria-hidden="true"></span>
                        </label>
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
                            <span class="cs-state__note">Save the keys, then authorise.</span>
                        @endif
                        <span class="cs-state__fact">{{ $csSetupState }}</span>
                    </div>
                    @if($canManageShopee)
                    <div class="cs-connect__acts">
                        <x-ui.button type="button" variant="secondary" size="sm" data-setup-open>Set up store</x-ui.button>
                        <x-ui.button variant="secondary" :href="route('ext.shopee.authorize', ['store' => $setting->id ?? null])" target="_blank" rel="noopener">Authorize shop</x-ui.button>
                        <x-ui.button type="submit" variant="primary">Save connection</x-ui.button>
                    </div>
                    @endif
                </div>
            </form>

        <details class="cs-hand">
            <summary>Do it by hand</summary>


            @if($canManageShopee)
            <div class="cs-steps">
                <div class="cs-step">
                    <div class="cs-step__body">
                        <span class="cs-step__title">Exchange an auth code by hand</span>
                        <form method="POST" action="{{ route('ext.shopee.token_get') }}" class="cs-exchange">
                            @csrf
                            <x-ui.field label="Auth code" for="sp-sb-code" name="code">
                                <x-ui.input id="sp-sb-code" name="code" value="{{ old('code', '') }}" placeholder="Paste the sandbox code here" />
                            </x-ui.field>
                            <x-ui.button type="submit" size="sm">Exchange</x-ui.button>
                        </form>
                    </div>
                </div>

                <div class="cs-step">
                    <div class="cs-step__body">
                        <span class="cs-step__title">Refresh an expired token</span>
                        <form method="POST" action="{{ route('ext.shopee.token_refresh') }}">
                            @csrf
                            <x-ui.button type="submit" size="sm">Refresh sandbox token</x-ui.button>
                        </form>
                    </div>
                </div>
            </div>

            <div class="cs-danger">
                <div>
                    <span class="cs-danger__title">Remove this store <x-ui.hint label="What removal does">Everything this store holds in the ERP goes with it: listings, links, groups, couriers, Shopee orders and returns, and its API log. Sales already imported stay under its name. There is no undo.</x-ui.hint></span>
                </div>
                <x-ui.button type="button" variant="danger" size="sm" data-delete-open>Delete this store</x-ui.button>
            </div>
            @else
            <p class="cs-step__note">Authorising and refreshing tokens need the Shopee manage permission.</p>
            @endif
        </details>
        </section>
    </div>
    </div>

</div>

<div id="sp-tab-status" class="cs-panel" @if($csSection !== 'status') hidden @endif>

    <section class="fm-section">
        <div class="fm-section__head">
            <h2 class="fm-section__title">Order status mapping</h2>
        </div>
        <p class="fm-section__note">When a Shopee order is synced, its Shopee status becomes the sales order status chosen here.</p>

        <form method="POST" action="{{ route('ext.shopee.order_status_map') }}">
            @csrf
            <x-ui.table>
                <x-slot:head>
                    <tr>
                        <th scope="col" class="cs-col-src">Shopee status</th>
                        <th scope="col">Sales order status</th>
                    </tr>
                </x-slot:head>
                @foreach($shopeeStatuses as $key => $label)
                    <tr>
                        <td class="cs-col-src" data-label="Shopee status">
                            <span class="cs-code">{{ $key }}</span>
                        </td>
                        <td data-label="Sales order status">
                            <div class="x-select-wrap cs-map-select">
                                <select name="map[{{ $key }}]" class="x-input" aria-label="Sales order status for {{ $key }}" @disabled(!$canManageShopee)>
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

            @if($canManageShopee)
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
        <p class="fm-section__note">When a Shopee return is synced, its status becomes the sales order status chosen here. Leave a row unmapped to make it change nothing.</p>

        <form method="POST" action="{{ route('ext.shopee.return_status_map') }}">
            @csrf
            <x-ui.table>
                <x-slot:head>
                    <tr>
                        <th scope="col" class="cs-col-src">Shopee return status</th>
                        <th scope="col">Sales order status</th>
                    </tr>
                </x-slot:head>
                @foreach($shopeeReturnStatuses as $key => $label)
                    <tr>
                        <td class="cs-col-src" data-label="Shopee return status">
                            <span class="cs-code">{{ $key }}</span>
                        </td>
                        <td data-label="Sales order status">
                            <div class="x-select-wrap cs-map-select">
                                <select name="map[{{ $key }}]" class="x-input" aria-label="Sales order status for {{ $key }}" @disabled(!$canManageShopee)>
                                    <option value="">Not mapped</option>
                                    @foreach($erpOrderStatuses as $os)
                                        <option value="{{ $os->order_status_id }}" @selected((int) ($returnStatusMap[$key] ?? 0) === (int) $os->order_status_id)>{{ $os->name }}</option>
                                    @endforeach
                                </select>
                                <x-ui.icon name="chevron-down" size="14" class="x-select-wrap__chevron" />
                            </div>
                        </td>
                    </tr>
                @endforeach
            </x-ui.table>

            @if($canManageShopee)
            <div class="cs-actions">
                <x-ui.button type="submit" variant="primary">Save return mapping</x-ui.button>
            </div>
            @endif
        </form>
    </section>
</div>

@if($canManageShopee)
<div id="sp-tab-explorer" class="cs-panel" @if($csSection !== 'explorer') hidden @endif>
    @if($result && $resultTab === 'explorer')
        @include('partials.channel-last-result', ['result' => $result, 'channel' => 'Shopee'])
    @endif
    <section class="fm-section">
        <div class="fm-section__head">
            <h2 class="fm-section__title">API explorer</h2>
        </div>
        <p class="fm-section__note">Calls the Shopee API directly with this shop's credentials. Signing and timestamps are generated for you. Anything you run here is recorded in the API log.</p>

        <div class="api-explorer" data-cs-explorer>
            <div class="api-panel">
                <div class="api-search">
                    <x-ui.input id="api-endpoint-search" data-cs-ex-search placeholder="Search endpoints" aria-label="Search endpoints" />
                </div>

                <div class="api-list" id="api-endpoint-list">
                    <div class="api-cat">Shop</div>
                    <button type="button" class="api-endpoint" data-ep='{"name":"Get Shop Info","method":"GET","auth":true,"shop":true,"path":"/api/v2/shop/get_shop_info","desc":"Fetch shop info.","params":[]}'><span class="api-badge get">GET</span><span class="api-code">/api/v2/shop/get_shop_info</span><div class="api-hint">Shop info</div></button>
                    <button type="button" class="api-endpoint" data-ep='{"name":"Get Profile","method":"GET","auth":true,"shop":true,"path":"/api/v2/shop/get_profile","desc":"Fetch shop profile.","params":[]}'><span class="api-badge get">GET</span><span class="api-code">/api/v2/shop/get_profile</span><div class="api-hint">Shop profile</div></button>

                    <div class="api-cat">Product</div>
                    <button type="button" class="api-endpoint" data-ep='{"name":"Get Item List","method":"GET","auth":true,"shop":true,"path":"/api/v2/product/get_item_list","desc":"List products. Requires update_time_from and update_time_to (Unix timestamps).","params":[{"k":"offset","req":false,"ph":"0"},{"k":"page_size","req":false,"ph":"50"},{"k":"update_time_from","req":true,"ph":""},{"k":"update_time_to","req":true,"ph":""},{"k":"item_status","req":false,"ph":"NORMAL"}]}'><span class="api-badge get">GET</span><span class="api-code">/api/v2/product/get_item_list</span><div class="api-hint">offset, page_size, update_time_from/to, item_status</div></button>
                    <button type="button" class="api-endpoint" data-ep='{"name":"Get Item Base Info","method":"GET","auth":true,"shop":true,"path":"/api/v2/product/get_item_base_info","desc":"Get base info for item(s).","params":[{"k":"item_id_list","req":true,"ph":"123,456"}]}'><span class="api-badge get">GET</span><span class="api-code">/api/v2/product/get_item_base_info</span><div class="api-hint">item_id_list (comma-separated)</div></button>
                    <button type="button" class="api-endpoint" data-ep='{"name":"Get Model List","method":"GET","auth":true,"shop":true,"path":"/api/v2/product/get_model_list","desc":"Get model/variant list for an item.","params":[{"k":"item_id","req":true,"ph":"123"}]}'><span class="api-badge get">GET</span><span class="api-code">/api/v2/product/get_model_list</span><div class="api-hint">item_id</div></button>
                    <button type="button" class="api-endpoint" data-ep='{"name":"Get Category","method":"GET","auth":true,"shop":true,"path":"/api/v2/product/get_category","desc":"Fetch category tree.","params":[{"k":"language","req":false,"ph":"en"}]}'><span class="api-badge get">GET</span><span class="api-code">/api/v2/product/get_category</span><div class="api-hint">language</div></button>
                    <button type="button" class="api-endpoint" data-ep='{"name":"Get Attributes","method":"GET","auth":true,"shop":true,"path":"/api/v2/product/get_attribute_tree","desc":"Get attributes for a category.","params":[{"k":"category_id","req":true,"ph":"100001"},{"k":"language","req":false,"ph":"en"}]}'><span class="api-badge get">GET</span><span class="api-code">/api/v2/product/get_attribute_tree</span><div class="api-hint">category_id</div></button>
                    <button type="button" class="api-endpoint" data-ep='{"name":"Get Brand List","method":"GET","auth":true,"shop":true,"path":"/api/v2/product/get_brand_list","desc":"Get brands for a category.","params":[{"k":"category_id","req":true,"ph":"100001"},{"k":"offset","req":false,"ph":"0"},{"k":"page_size","req":false,"ph":"100"},{"k":"status","req":false,"ph":"1"}]}'><span class="api-badge get">GET</span><span class="api-code">/api/v2/product/get_brand_list</span><div class="api-hint">category_id, offset, page_size</div></button>
                    <button type="button" class="api-endpoint" data-ep='{"name":"Update Stock","method":"POST","auth":true,"shop":true,"path":"/api/v2/product/update_stock","desc":"Update stock for an item.","params":[{"k":"item_id","req":true,"ph":"123"},{"k":"stock_list","req":true,"ph":"[{\"model_id\":0,\"normal_stock\":10}]"}]}'><span class="api-badge post">POST</span><span class="api-code">/api/v2/product/update_stock</span><div class="api-hint">item_id, stock_list</div></button>
                    <button type="button" class="api-endpoint" data-ep='{"name":"Update Price","method":"POST","auth":true,"shop":true,"path":"/api/v2/product/update_price","desc":"Update price for an item.","params":[{"k":"item_id","req":true,"ph":"123"},{"k":"price_list","req":true,"ph":"[{\"model_id\":0,\"original_price\":100}]"}]}'><span class="api-badge post">POST</span><span class="api-code">/api/v2/product/update_price</span><div class="api-hint">item_id, price_list</div></button>
                    <button type="button" class="api-endpoint" data-ep='{"name":"Category Recommend","method":"GET","auth":true,"shop":true,"path":"/api/v2/product/category_recommend","desc":"Get recommended categories for an item name.","params":[{"k":"item_name","req":true,"ph":"Guitar Pedal"}]}'><span class="api-badge get">GET</span><span class="api-code">/api/v2/product/category_recommend</span><div class="api-hint">item_name</div></button>

                    <div class="api-cat">Order</div>
                    <button type="button" class="api-endpoint" data-ep='{"name":"Get Order List","method":"GET","auth":true,"shop":true,"path":"/api/v2/order/get_order_list","desc":"List orders.","params":[{"k":"order_status","req":false,"ph":"READY_TO_SHIP"},{"k":"time_range_field","req":true,"ph":"create_time"},{"k":"time_from","req":true,"ph":""},{"k":"time_to","req":true,"ph":""},{"k":"page_size","req":false,"ph":"20"}]}'><span class="api-badge get">GET</span><span class="api-code">/api/v2/order/get_order_list</span><div class="api-hint">order_status, time range</div></button>
                    <button type="button" class="api-endpoint" data-ep='{"name":"Get Order Detail","method":"GET","auth":true,"shop":true,"path":"/api/v2/order/get_order_detail","desc":"Get order details.","params":[{"k":"order_sn_list","req":true,"ph":"2502011234ABCD"}]}'><span class="api-badge get">GET</span><span class="api-code">/api/v2/order/get_order_detail</span><div class="api-hint">order_sn_list</div></button>
                    <button type="button" class="api-endpoint" data-ep='{"name":"Cancel Order","method":"POST","auth":true,"shop":true,"path":"/api/v2/order/cancel_order","desc":"Cancel an order.","params":[{"k":"order_sn","req":true,"ph":""},{"k":"cancel_reason","req":true,"ph":"OUT_OF_STOCK"},{"k":"item_list","req":false,"ph":""}]}'><span class="api-badge post">POST</span><span class="api-code">/api/v2/order/cancel_order</span><div class="api-hint">order_sn, cancel_reason</div></button>

                    <div class="api-cat">Logistics</div>
                    <button type="button" class="api-endpoint" data-ep='{"name":"Get Channel List","method":"GET","auth":true,"shop":true,"path":"/api/v2/logistics/get_channel_list","desc":"Fetch available logistics channels.","params":[]}'><span class="api-badge get">GET</span><span class="api-code">/api/v2/logistics/get_channel_list</span><div class="api-hint">Logistics channels</div></button>
                    <button type="button" class="api-endpoint" data-ep='{"name":"Get Shipping Parameter","method":"GET","auth":true,"shop":true,"path":"/api/v2/logistics/get_shipping_parameter","desc":"Get shipping parameter for an order.","params":[{"k":"order_sn","req":true,"ph":""}]}'><span class="api-badge get">GET</span><span class="api-code">/api/v2/logistics/get_shipping_parameter</span><div class="api-hint">order_sn</div></button>
                    <button type="button" class="api-endpoint" data-ep='{"name":"Ship Order","method":"POST","auth":true,"shop":true,"path":"/api/v2/logistics/ship_order","desc":"Ship an order. Use pickup with address_id from get_shipping_parameter, or dropoff with empty object.","params":[{"k":"order_sn","req":true,"ph":""},{"k":"pickup","req":false,"ph":"{\"address_id\":0}"}]}'><span class="api-badge post">POST</span><span class="api-code">/api/v2/logistics/ship_order</span><div class="api-hint">order_sn, pickup/dropoff</div></button>
                    <button type="button" class="api-endpoint" data-ep='{"name":"Get Tracking Number","method":"GET","auth":true,"shop":true,"path":"/api/v2/logistics/get_tracking_number","desc":"Get tracking number.","params":[{"k":"order_sn","req":true,"ph":""}]}'><span class="api-badge get">GET</span><span class="api-code">/api/v2/logistics/get_tracking_number</span><div class="api-hint">order_sn</div></button>
                    <button type="button" class="api-endpoint" data-ep='{"name":"Get Tracking Info","method":"GET","auth":true,"shop":true,"path":"/api/v2/logistics/get_tracking_info","desc":"Get tracking info for an order.","params":[{"k":"order_sn","req":true,"ph":""}]}'><span class="api-badge get">GET</span><span class="api-code">/api/v2/logistics/get_tracking_info</span><div class="api-hint">order_sn</div></button>
                    <button type="button" class="api-endpoint" data-ep='{"name":"Get Shipping Doc Parameter","method":"POST","auth":true,"shop":true,"path":"/api/v2/logistics/get_shipping_document_parameter","desc":"Get shipping document parameter (doc type, package number).","params":[{"k":"order_list","req":true,"ph":"[{\"order_sn\":\"\"}]"}]}'><span class="api-badge post">POST</span><span class="api-code">/api/v2/logistics/get_shipping_document_parameter</span><div class="api-hint">order_list</div></button>
                    <button type="button" class="api-endpoint" data-ep='{"name":"Create Shipping Document","method":"POST","auth":true,"shop":true,"path":"/api/v2/logistics/create_shipping_document","desc":"Create shipping document.","params":[{"k":"order_list","req":true,"ph":"[{\"order_sn\":\"\",\"shipping_document_type\":\"THERMAL_AIR_WAYBILL\",\"tracking_number\":\"\"}]"}]}'><span class="api-badge post">POST</span><span class="api-code">/api/v2/logistics/create_shipping_document</span><div class="api-hint">order_list</div></button>
                    <button type="button" class="api-endpoint" data-ep='{"name":"Get Shipping Doc Result","method":"POST","auth":true,"shop":true,"path":"/api/v2/logistics/get_shipping_document_result","desc":"Poll document creation status. Status: READY, FAILED, or PROCESSING.","params":[{"k":"order_list","req":true,"ph":"[{\"order_sn\":\"\"}]"}]}'><span class="api-badge post">POST</span><span class="api-code">/api/v2/logistics/get_shipping_document_result</span><div class="api-hint">order_list, check READY or FAILED</div></button>
                    <button type="button" class="api-endpoint" data-ep='{"name":"Download Shipping Document","method":"POST","auth":true,"shop":true,"path":"/api/v2/logistics/download_shipping_document","desc":"Download shipping document as PDF. Response is binary and shows as raw data in the explorer.","params":[{"k":"order_list","req":true,"ph":"[{\"order_sn\":\"\"}]"},{"k":"shipping_document_type","req":false,"ph":"THERMAL_AIR_WAYBILL"}]}'><span class="api-badge post">POST</span><span class="api-code">/api/v2/logistics/download_shipping_document</span><div class="api-hint">order_list, returns a binary PDF</div></button>

                    <div class="api-cat">Media</div>
                    <button type="button" class="api-endpoint" data-ep='{"name":"Upload Image","method":"POST","auth":true,"shop":true,"path":"/api/v2/media_space/upload_image","desc":"Upload an image.","params":[]}'><span class="api-badge post">POST</span><span class="api-code">/api/v2/media_space/upload_image</span><div class="api-hint">Multipart upload</div></button>

                    <div class="api-cat">Reviews</div>
                    <button type="button" class="api-endpoint" data-ep='{"name":"Get Item Comments","method":"GET","auth":true,"shop":true,"path":"/api/v2/product/get_comment","desc":"Get reviews/comments for a specific item. Uses cursor-based pagination via comment_id.","params":[{"k":"item_id","req":true,"ph":"10076385934"},{"k":"comment_id","req":false,"ph":"0"},{"k":"page_size","req":false,"ph":"50"}]}'><span class="api-badge get">GET</span><span class="api-code">/api/v2/product/get_comment</span><div class="api-hint">item_id, comment_id, page_size</div></button>

                    <div class="api-cat">Returns</div>
                    <button type="button" class="api-endpoint" data-ep='{"name":"Get Return List","method":"GET","auth":true,"shop":true,"path":"/api/v2/returns/get_return_list","desc":"List returns.","params":[{"k":"page_no","req":false,"ph":"1"},{"k":"page_size","req":false,"ph":"20"}]}'><span class="api-badge get">GET</span><span class="api-code">/api/v2/returns/get_return_list</span><div class="api-hint">page_no, page_size</div></button>
                    <button type="button" class="api-endpoint" data-ep='{"name":"Get Return Detail","method":"GET","auth":true,"shop":true,"path":"/api/v2/returns/get_return_detail","desc":"Get return details.","params":[{"k":"return_sn","req":true,"ph":""}]}'><span class="api-badge get">GET</span><span class="api-code">/api/v2/returns/get_return_detail</span><div class="api-hint">return_sn</div></button>
                </div>
            </div>

            <div class="api-panel">
                <div class="api-split">
                    <div>
                        <span class="fm-label">Selected endpoint</span>
                        <div class="api-mini" data-cs-ex-name>None selected</div>
                        <div class="api-hint" data-cs-ex-desc></div>
                    </div>
                    <div>
                        <span class="fm-label">Run a preset</span>
                        <div class="api-row-actions">
                            @foreach(['shop_info' => 'Shop info', 'catalog' => 'Catalog', 'orders' => 'Orders', 'logistics' => 'Logistics', 'full' => 'Everything'] as $pack => $packLabel)
                                <form method="POST" action="{{ route('ext.shopee.packs_run') }}">
                                    @csrf
                                    <input type="hidden" name="pack" value="{{ $pack }}">
                                    <x-ui.button type="submit" size="sm">{{ $packLabel }}</x-ui.button>
                                </form>
                            @endforeach
                        </div>
                    </div>
                </div>

                <form method="POST" action="{{ route('ext.shopee.explorer_run') }}" data-cs-ex-form>
                    @csrf

                    <div class="fm-fields fm-fields--2">
                        <x-ui.field label="Method" for="api-explorer-method" name="method">
                            <div class="x-select-wrap">
                                <select id="api-explorer-method" name="method" class="x-input" data-cs-ex-method>
                                    <option value="GET">GET</option>
                                    <option value="POST">POST</option>
                                </select>
                                <x-ui.icon name="chevron-down" size="14" class="x-select-wrap__chevron" />
                            </div>
                        </x-ui.field>

                        <div class="fm-field">
                            <span class="fm-label">Send with the request</span>
                            <label class="cs-check">
                                <input type="checkbox" name="use_access_token" value="1" checked data-cs-ex-auth>
                                Access token
                            </label>
                            <label class="cs-check">
                                <input type="checkbox" name="use_shop_id" value="1" checked data-cs-ex-shop>
                                Shop ID
                            </label>
                        </div>

                        <x-ui.field label="API path" for="api-explorer-path" name="api_path" wide>
                            <x-ui.input id="api-explorer-path" name="api_path" class="api-code" data-cs-ex-path
                                        value="/api/v2/shop/get_shop_info" placeholder="/api/v2/shop/get_shop_info" />
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
                                <tbody data-cs-ex-kv></tbody>
                            </table>
                            <div class="api-row-actions">
                                <x-ui.button type="button" size="sm" data-cs-ex-add>Add parameter</x-ui.button>
                                <x-ui.button type="button" size="sm" data-cs-ex-sync>Read from JSON</x-ui.button>
                                <x-ui.button type="button" size="sm" data-cs-ex-sample>Fill sample values</x-ui.button>
                            </div>
                        </div>

                        <x-ui.field label="Parameters as JSON" for="api-explorer-params" name="params_json" wide>
                            <x-ui.textarea id="api-explorer-params" name="params_json" rows="6" class="api-code" data-cs-ex-params placeholder='{"page_size":10}'>{}</x-ui.textarea>
                        </x-ui.field>
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

<div id="sp-tab-logs" class="cs-panel" @if($csSection !== 'logs') hidden @endif>



    @include('partials.channel-api-log', [
        'logChannel' => 'Shopee',
        'canManage' => $canManageShopee,
        'logMode' => $setting->api_log_mode ?? 'all',
        'modeUrl' => route('ext.shopee.api_log_mode'),
        'logs' => $logs,
        'clearUrl' => route('ext.shopee.clear_api_logs'),
        'map' => [
            'path' => 'api_path', 'status' => 'response_status',
            'okFn' => fn ($r) => (bool) $r->ok,
            'ms' => null, 'pack' => 'pack', 'signed' => 'auth_required',
            'request' => 'request_params', 'response' => 'response_body',
            'requestLabel' => 'Request parameters',
        ],
    ])

    @if($canManageShopee)
    <section class="fm-section">
        <div class="fm-section__head">
            <h2 class="fm-section__title">Free up space</h2>
            <x-ui.hint label="What clearing does">Every synced Shopee order and return keeps the full API response it came from. Clearing those payloads on older rows frees database space and changes nothing about the orders themselves.</x-ui.hint>
        </div>

        <form method="POST" action="{{ route('ext.shopee.purge_raw') }}"
              data-confirm="Clear the stored API payloads on Shopee rows older than the number of days given? The orders and returns stay, only the raw payloads go, and this cannot be undone.">
            @csrf
            <div class="fm-field">
                <div class="fm-label-row">
                    <label class="fm-label" for="sp-purge-days">Clear payloads older than</label>
                    <x-ui.hint class="fm-label__hint" bubble-id="sp-purge-hint" label="About the days">Anything from 1 to 3650 days.</x-ui.hint>
                </div>
                <div class="cs-inline">
                    <x-ui.input id="sp-purge-days" name="days" type="number" value="30" min="1" max="3650" required
                                class="fm-input--num cs-days" aria-describedby="sp-purge-hint" />
                    <span class="cs-inline__unit">days</span>
                    <x-ui.button type="submit" variant="danger">Clear stored payloads</x-ui.button>
                </div>
                @error('days')<div class="fm-error">{{ $message }}</div>@enderror
            </div>
        </form>
    </section>
    @endif
</div>

@if($canManageShopee)
<div id="sp-tab-automations" class="cs-panel" @if($csSection !== 'automations') hidden @endif>
    <section class="fm-section">
        <div class="fm-section__head">
            <h2 class="fm-section__title">Scheduled jobs</h2>
            <x-ui.hint label="About the scheduled jobs">How often each Shopee sync runs, and how far back it looks. Changes here take effect the next time the scheduler wakes up.</x-ui.hint>
        </div>
        @include('partials._automations_tab', ['integration' => 'shopee', 'storeId' => null])
    </section>
</div>
@endif

</div>
@endsection
