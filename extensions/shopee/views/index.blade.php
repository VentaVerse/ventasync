@extends('layouts.channel')
@section('breadcrumb', 'Settings')

@section('title', 'Shopee Settings')

{{-- Leaves the .x-legacy content boundary, rebuilt on the token set.

     READ THIS BEFORE EDITING ANY FORM BELOW.

     This URL is Shopee's OAuth callback landing. `route('ext.shopee.index')`
     is the exact string registered as the redirect URI in the Shopee Open
     Platform console, and ShopeeController::index() reads ?code= and
     ?shop_id= off this request and exchanges them for a token BEFORE this
     view is ever rendered. Nothing in this file participates in that
     exchange, and nothing in this file may change these four surfaces:

       - the authorize link         route('ext.shopee.authorize'), a GET in a
                                    new tab, rel="noopener"
       - the auth-code exchange     POST route('ext.shopee.token_get'),
                                    one field named `code`
       - the token refresh          POST route('ext.shopee.token_refresh')
       - the redirect URI display   a readonly input with NO name attribute,
                                    so it is shown and never submitted

     The credential save (POST route('ext.shopee.save')) carries a hidden
     `env` plus exactly the ten field names ShopeeController::save()
     validates. Blank means "keep the stored value", which is what the
     •••••••• masks plus resources/js/credential-mask.js are for. --}}
@section('content')
@php
    // Read tier is `view_shopee|manage_shopee` on this route; every write
    // below (save, toggle mode, token get/refresh, explorer, packs, status
    // maps, logging, log delete, purge) is `manage_shopee`. Replaces a
    // hardcoded `true`, which offered a read-only operator every one of
    // those controls and let each of them 403.
    $canManageShopee = auth()->user()?->hasPermission('manage_shopee/settings') ?? false;

    $currentMode = $setting->mode ?? 'sandbox';
    $isSandbox = $currentMode === 'sandbox';

    // Live credentials
    $__hasToken = !empty($setting->access_token ?? '');
    $__tokenExpired = $__hasToken && ($setting->expires_at ?? null) && \Carbon\Carbon::parse($setting->expires_at)->lt(now());

    // Sandbox credentials
    $__hasSandboxCredentials = ($setting->sandbox_partner_id ?? '') !== '' && ($setting->sandbox_partner_key ?? '') !== '';
    $__hasSandboxToken = !empty($setting->sandbox_access_token ?? '');
    $__sandboxTokenExpired = $__hasSandboxToken && ($setting->sandbox_expires_at ?? null) && \Carbon\Carbon::parse($setting->sandbox_expires_at)->lt(now());



    $regions = [
        'sg' => 'Singapore', 'my' => 'Malaysia', 'th' => 'Thailand', 'vn' => 'Vietnam',
        'id' => 'Indonesia', 'ph' => 'Philippines', 'tw' => 'Taiwan', 'br' => 'Brazil',
        'mx' => 'Mexico', 'co' => 'Colombia', 'cl' => 'Chile', 'pl' => 'Poland',
    ];
@endphp

{{-- The section shown, from the menubar's own ?tab= links (the
     in-page tab strip retired 2026-09-03: the channel menubar carries the
     sections now). Unknown or unpermitted sections fall to the default,
     so a hand-typed URL can never land on a blank page. --}}
@php
    $csKnown = ['connection', 'status', 'logs'];
    if ($canManageShopee) { $csKnown[] = 'explorer'; $csKnown[] = 'automations'; }
    // A flashed result (a token fetch, a test, a save) used to force
    // the Logs tab, teleporting the operator there on any outcome
    // (the owner, 2026-09-04). The result banner is page-level now, so
    // the default tab stays Connection where the actions live.
    $csSection = in_array(request('tab'), $csKnown, true) ? request('tab') : 'connection';
@endphp
<div class="cs-page"
     data-channel-settings
     data-tab-prefix="sp-tab-"
     data-tab-storage-key="sp-active-tab"
     data-default-tab="">

<div class="x-list-head">
    <div>
        <h1 class="x-page-title">Settings</h1>
        <p class="x-page-sub">Credentials, status mapping and the API record for this Shopee shop.</p>
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

{{-- ================================================================
     CONNECTION
     ================================================================ --}}
{{-- The result of the last action (token fetch, test, save), shown
     page-level so it is visible on whatever tab the operator is on -
     it no longer drags them to the Logs tab (the owner, 2026-09-04). --}}
    @if($result)
        @php $ok = (bool) ($result['ok'] ?? false); @endphp
        <section class="fm-section">
            <div class="fm-section__head">
                <h2 class="fm-section__title">Last result</h2>
                <div class="fm-section__aside">
                    <x-ui.badge :tone="$ok ? 'success' : 'danger'">{{ $ok ? 'Succeeded' : 'Failed' }}</x-ui.badge>
                </div>
            </div>
            <p class="fm-section__note">{{ $result['title'] ?? 'Result' }}</p>
            {{-- The REASON is the visible text; the payload is one click
                 away, never the message (the owner, 2026-09-03). A success keeps
                 its confirmation data in view - that is what was asked for. --}}
            @if(!$ok)
                @php
                    $answerRaw = is_array($result['data'] ?? null) ? $result['data'] : ['body' => ['message' => (string) ($result['data'] ?? '')], 'ok' => false];
                    $answerText = \App\Support\MarketplaceAnswer::errorText('Shopee', $answerRaw);
                @endphp
                <p class="fm-section__note fm-section__note--strong">@include('partials.channel-answer', ['channel' => 'Shopee', 'raw' => $answerText, 'settingsRoute' => null])</p>
                <details class="fm-note__more">
                    <summary>Full response</summary>
                    <pre class="cs-pre">{{ json_encode($result['data'] ?? $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                </details>
            @else
                <pre class="cs-pre">{{ json_encode($result['data'] ?? $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
            @endif
        </section>
    @endif

<div id="sp-tab-connection" class="cs-panel" @if($csSection !== 'connection') hidden @endif>

    <section class="fm-section">
        <div class="fm-section__head">
            <h2 class="fm-section__title">Environment</h2>
        </div>

        <div class="cs-env">
            @if($canManageShopee)
                {{-- Wire unchanged: one hidden `mode` field posted to
                     toggle-mode, exactly as the old knob did. What changed is
                     that the control now names both environments instead of
                     being an on/off switch you had to read the label to
                     decode, and it no longer paints itself green and grey
                     with inline styles. --}}
                <form method="POST" action="{{ route('ext.shopee.toggle_mode') }}" data-cs-env-form>
                    @csrf
                    <input type="hidden" name="mode" value="{{ $currentMode }}" data-cs-env-input>
                    <div class="x-segment" role="tablist" aria-label="Shopee environment">
                        <button type="button" class="x-segment__item {{ $isSandbox ? '' : 'is-active' }}"
                                role="tab" aria-selected="{{ $isSandbox ? 'false' : 'true' }}"
                                data-cs-env-option="live">Production</button>
                        <button type="button" class="x-segment__item {{ $isSandbox ? 'is-active' : '' }}"
                                role="tab" aria-selected="{{ $isSandbox ? 'true' : 'false' }}"
                                data-cs-env-option="sandbox">Sandbox</button>
                    </div>
                </form>
            @else
                <span class="cs-env__label">{{ $isSandbox ? 'Sandbox' : 'Production' }}</span>
            @endif

            <p class="cs-env__note">
                Every sync, push and order fetch uses the credentials of the environment selected here. Production and sandbox keep separate credentials and separate tokens.
            </p>
        </div>
    </section>

    {{-- ---- PRODUCTION ---------------------------------------------- --}}
    <div class="cs-grid" data-cs-env-panel="live" @if($isSandbox) hidden @endif>

        <section class="fm-section">
            <div class="fm-section__head">
                <h2 class="fm-section__title">Production credentials</h2>
            </div>

            <form method="POST" action="{{ route('ext.shopee.save') }}">
                @csrf
                <input type="hidden" name="env" value="live">

                <div class="fm-fields">
                    <x-ui.field label="Store name" for="sp-store-name-1" name="store_name" wide
                                hint="How this store is named across the ERP - the sidebar, the fulfilment tabs, the channels board.">
                        <x-ui.input id="sp-store-name-1" name="store_name" type="text" autocomplete="off" :disabled="!$canManageShopee"
                                    value="{{ $setting->store_name ?? '' }}" />
                    </x-ui.field>

                    <x-ui.field label="Store is active" for="sp-enabled-1" name="enabled" wide
                                hint="An active store syncs on the crons and shows its tabs. Switch off to pause the store without losing anything.">
                        <input type="hidden" name="enabled" value="0">
                        <label class="fm-check"><input type="checkbox" id="sp-enabled-1" name="enabled" value="1" @checked((bool) ($setting->enabled ?? true)) @disabled(!$canManageShopee)> Active</label>
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

                    {{-- No name attribute, deliberately: this is shown so it
                         can be copied into the Shopee console, and it must
                         never be posted back as a settable field. --}}
                    <x-ui.field label="Redirect URI" for="sp-redirect" wide
                                hint="Register this exact URL in your Shopee Open Platform production app. To change it, edit APP_URL in the environment file.">
                        <x-ui.input id="sp-redirect" type="text" readonly value="{{ $defaultRedirect }}" data-select-on-click />
                    </x-ui.field>

                    <x-ui.field label="Region" for="sp-region" name="region" wide>
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
                </div>

                @if($canManageShopee)
                <div class="cs-actions">
                    <x-ui.button type="submit" variant="primary">Save production settings</x-ui.button>
                    <span class="cs-actions__note">Leave a masked field untouched to keep what is already stored.</span>
                </div>
                @endif
            </form>
        </section>

        <section class="fm-section">
            <div class="fm-section__head">
                <h2 class="fm-section__title">Production authorisation</h2>
            </div>

            <div class="cs-state">
                @if($__hasToken && !$__tokenExpired)
                    <x-ui.badge tone="success">Token active</x-ui.badge>
                    @if($setting->expires_at ?? null)
                        <span class="cs-state__when">Expires {{ \Carbon\Carbon::parse($setting->expires_at)->diffForHumans() }}</span>
                    @endif
                @elseif($__hasToken && $__tokenExpired)
                    <x-ui.badge tone="danger">Token expired</x-ui.badge>
                    <span class="cs-state__note">Use step 3 to renew it without authorising again.</span>
                @else
                    <x-ui.badge tone="warning">No token</x-ui.badge>
                    <span class="cs-state__note">Work through the three steps below.</span>
                @endif
            </div>

            @if($canManageShopee)
            <div class="cs-steps">
                <div class="cs-step">
                    <span class="cs-step__n">1</span>
                    <div class="cs-step__body">
                        <span class="cs-step__title">Authorise with Shopee</span>
                        <div>
                            <x-ui.button size="sm" :href="route('ext.shopee.authorize', ['store' => $setting->id ?? null])" target="_blank" rel="noopener">Open Shopee authorisation</x-ui.button>
                        </div>
                        <p class="cs-step__note">Opens Shopee sign-in in a new tab. After you grant access Shopee sends you back here with an auth code, and the code is exchanged for a token automatically.</p>
                    </div>
                </div>

                <div class="cs-step">
                    <span class="cs-step__n">2</span>
                    <div class="cs-step__body">
                        <span class="cs-step__title">Exchange an auth code by hand</span>
                        <form method="POST" action="{{ route('ext.shopee.token_get') }}" class="cs-exchange">
                            @csrf
                            <x-ui.field label="Auth code" for="sp-code" name="code">
                                <x-ui.input id="sp-code" name="code" value="{{ old('code', '') }}" placeholder="Paste the code= value here" />
                            </x-ui.field>
                            <x-ui.button type="submit" size="sm">Exchange</x-ui.button>
                        </form>
                        <p class="cs-step__note">Only needed when the automatic exchange did not run, for example if you copied the redirect URL out of the address bar.</p>
                    </div>
                </div>

                <div class="cs-step">
                    <span class="cs-step__n">3</span>
                    <div class="cs-step__body">
                        <span class="cs-step__title">Refresh an expired token</span>
                        <form method="POST" action="{{ route('ext.shopee.token_refresh') }}">
                            @csrf
                            <x-ui.button type="submit" size="sm">Refresh access token</x-ui.button>
                        </form>
                        <p class="cs-step__note">Shopee access tokens last about four hours. The scheduled job renews them every two hours, so this is here for when that job has not run.</p>
                    </div>
                </div>
            </div>
            @else
            <p class="cs-step__note">Authorising and refreshing tokens need the Shopee manage permission.</p>
            @endif
        </section>
    </div>

    {{-- ---- SANDBOX ------------------------------------------------- --}}
    <div class="cs-grid" data-cs-env-panel="sandbox" @unless($isSandbox) hidden @endunless>

        <section class="fm-section">
            <div class="fm-section__head">
                <h2 class="fm-section__title">Sandbox credentials</h2>
                <div class="fm-section__aside">
                    @if($__hasSandboxCredentials)
                        <x-ui.badge tone="success">Configured</x-ui.badge>
                    @else
                        <x-ui.badge tone="neutral">Not configured</x-ui.badge>
                    @endif
                </div>
            </div>

            <form method="POST" action="{{ route('ext.shopee.save') }}">
                @csrf
                <input type="hidden" name="env" value="sandbox">

                <div class="fm-fields">
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

                    {{-- Shown in full, like the production Shop ID directly
                         above it. A shop id is an identifier, not a secret: it
                         appears in Shopee's own console and in every API log
                         line, and masking it only stopped the operator
                         confirming which shop they were pointed at. --}}
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

                    {{-- No name attribute, same as the production copy. --}}
                    <x-ui.field label="Redirect URI" for="sp-sb-redirect" wide
                                hint="Register this exact URL in your Shopee Open Platform sandbox app. To change it, edit APP_URL in the environment file.">
                        <x-ui.input id="sp-sb-redirect" type="text" readonly value="{{ $defaultRedirect }}" data-select-on-click />
                    </x-ui.field>

                    <x-ui.field label="Region" for="sp-sb-region" name="sandbox_region" wide>
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
                </div>

                @if($canManageShopee)
                <div class="cs-actions">
                    <x-ui.button type="submit" variant="primary">Save sandbox settings</x-ui.button>
                    <span class="cs-actions__note">Leave a masked field untouched to keep what is already stored.</span>
                </div>
                @endif
            </form>
        </section>

        <section class="fm-section">
            <div class="fm-section__head">
                <h2 class="fm-section__title">Sandbox authorisation</h2>
            </div>

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
                    <span class="cs-state__note">Fill in the sandbox credentials first, then authorise.</span>
                @endif
            </div>

            @if($canManageShopee)
            <div class="cs-steps">
                <div class="cs-step">
                    <span class="cs-step__n">1</span>
                    <div class="cs-step__body">
                        <span class="cs-step__title">Authorise with Shopee sandbox</span>
                        <div>
                            <x-ui.button size="sm" :href="route('ext.shopee.authorize', ['store' => $setting->id ?? null])" target="_blank" rel="noopener">Open sandbox authorisation</x-ui.button>
                        </div>
                        <p class="cs-step__note">Opens the Shopee test environment in a new tab, using the sandbox credentials above.</p>
                    </div>
                </div>

                <div class="cs-step">
                    <span class="cs-step__n">2</span>
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
                    <span class="cs-step__n">3</span>
                    <div class="cs-step__body">
                        <span class="cs-step__title">Refresh an expired token</span>
                        <form method="POST" action="{{ route('ext.shopee.token_refresh') }}">
                            @csrf
                            <x-ui.button type="submit" size="sm">Refresh sandbox token</x-ui.button>
                        </form>
                    </div>
                </div>
            </div>
            @else
            <p class="cs-step__note">Authorising and refreshing tokens need the Shopee manage permission.</p>
            @endif
        </section>
    </div>

    @if($canManageShopee)
    <section class="fm-section">
        <div class="fm-section__head">
            <h2 class="fm-section__title">Couriers</h2>
        </div>
        <p class="fm-section__note">Reads the courier list Shopee has switched on for this shop and caches it. The full list, with weight and size limits, is on the Logistics page.</p>
        <div class="cs-actions">
            <form method="POST" action="{{ route('ext.shopee.logistics_channels') }}">
                @csrf
                <x-ui.button type="submit">Fetch couriers</x-ui.button>
            </form>
            <a class="fm-cancel" href="{{ route('ext.shopee.logistics.index') }}">Open Logistics</a>
        </div>
    </section>
    @endif
</div>

{{-- ================================================================
     STATUS MAPPING
     ================================================================ --}}
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
                            <span class="cs-map-label">{{ $label }}</span>
                        </td>
                        <td data-label="Sales order status">
                            <div class="x-select-wrap cs-map-select">
                                <select name="map[{{ $key }}]" class="x-input" aria-label="Sales order status for {{ $label }}" @disabled(!$canManageShopee)>
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
                            <span class="cs-map-label">{{ $label }}</span>
                        </td>
                        <td data-label="Sales order status">
                            <div class="x-select-wrap cs-map-select">
                                <select name="map[{{ $key }}]" class="x-input" aria-label="Sales order status for {{ $label }}" @disabled(!$canManageShopee)>
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

{{-- ================================================================
     API EXPLORER
     ================================================================ --}}
@if($canManageShopee)
<div id="sp-tab-explorer" class="cs-panel" @if($csSection !== 'explorer') hidden @endif>
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

{{-- ================================================================
     API LOG
     ================================================================ --}}
<div id="sp-tab-logs" class="cs-panel" @if($csSection !== 'logs') hidden @endif>



    {{-- #200: the shared API log panel - the three-way mode, All/Errors
         and path filters that survive a refresh, pages of 50, and the
         retention stated in words. --}}
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
            {{-- Explanation to a tooltip (the owner, 2026-09-04): the
                 control is self-evident, the caveat is one tap away. --}}
            <x-ui.hint label="What clearing does">Every synced Shopee order and return keeps the full API response it came from. Clearing those payloads on older rows frees database space and changes nothing about the orders themselves.</x-ui.hint>
        </div>

        <form method="POST" action="{{ route('ext.shopee.purge_raw') }}"
              data-confirm="Clear the stored API payloads on Shopee rows older than the number of days given? The orders and returns stay, only the raw payloads go, and this cannot be undone.">
            @csrf
            <div class="fm-field">
                <label class="fm-label" for="sp-purge-days">Clear payloads older than</label>
                <div class="cs-inline">
                    <x-ui.input id="sp-purge-days" name="days" type="number" value="30" min="1" max="3650" required
                                class="fm-input--num cs-days" aria-describedby="sp-purge-hint" />
                    <span class="cs-inline__unit">days</span>
                    <x-ui.button type="submit" variant="danger">Clear stored payloads</x-ui.button>
                </div>
                <div class="fm-hint" id="sp-purge-hint">Anything from 1 to 3650 days.</div>
                @error('days')<div class="fm-error">{{ $message }}</div>@enderror
            </div>
        </form>
    </section>
    @endif
</div>

{{-- ================================================================
     AUTOMATIONS
     ================================================================ --}}
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

</div>{{-- /.cs-page --}}
@endsection
