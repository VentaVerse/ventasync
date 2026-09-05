@extends('layouts.channel')
@section('breadcrumb', 'Settings')

@section('title', 'TikTok Shop Settings')

{{-- Leaves the .x-legacy content boundary, rebuilt on the token set.

     READ THIS BEFORE EDITING ANY FORM BELOW.

     TikTok Shop's OAuth surfaces are contracts registered in the TikTok Shop
     Partner Center and compared byte-for-byte on every consent round trip.
     This page is NOT the callback landing: TikTok redirects to the separate,
     permanently exempt `/tiktok/callback` route, and
     TikTokController::computedRedirectUri() returns exactly
     route('ext.tiktok.callback') forced to https. That URL is what the
     readonly Redirect URI boxes below display, and nothing in this file may
     change any of these surfaces:

       - the redirect URI display     a readonly input with NO name attribute,
                                      so it is shown and never submitted
       - the authorize link           route('ext.tiktok.authorize'), a GET in a
                                      new tab, rel="noopener". Production and
                                      sandbox use the SAME URL with NO query
                                      parameter, unlike Lazada: TikTokController
                                      ::redirectToAuth() reads the stored `mode`
                                      column to pick which app key it sends, so
                                      adding a ?sandbox flag here would be
                                      inventing a parameter nothing reads
       - the auth-code exchange       POST route('ext.tiktok.token_get'), one
                                      field named `code`, on both environments,
                                      with no hidden environment flag for the
                                      same reason
       - the token refresh            POST route('ext.tiktok.token_refresh'),
                                      no fields
       - the shop lookup              POST route('ext.tiktok.shops'), no fields.
                                      This is what writes shop_cipher, which
                                      most TikTok API calls need

     The credential save (POST route('ext.tiktok.save')) carries a hidden `env`
     plus exactly the field names TikTokController::save() reads for that
     environment. Blank means "keep the stored value" for the two secrets,
     which is what the •••••••• masks plus resources/js/credential-mask.js are
     for.

     This form has never carried a region field - `region` is written by
     getShops() instead. save() now leaves it untouched when the form does
     not carry it, the same way it already leaves app_key/app_secret
     untouched on the environment it is not saving. --}}
@section('content')
@php
    // Read tier on this route is `view_tiktok|manage_tiktok`; every write below
    // (save, toggle mode, token get/refresh, shops, explorer, packs, status
    // map, logging, log delete, purge) is `manage_tiktok`. Replaces a hardcoded
    // `true`, which offered a read-only operator every one of those controls
    // and let each of them be refused.
    $canManageTiktok = auth()->user()?->hasPermission('manage_tiktok/settings') ?? false;

    $currentMode = $setting->mode ?? 'live';
    $isSandbox = $currentMode === 'sandbox';

    // Live credentials
    $hasCredentials = ($setting->app_key ?? '') !== '' && ($setting->app_secret ?? '') !== '';
    $hasToken = !empty($setting->access_token ?? '');
    $tokenExpired = $hasToken && ($setting->expires_at ?? null) && \Carbon\Carbon::parse($setting->expires_at)->lt(now());
    $hasShop = !empty($setting->shop_cipher ?? '');

    // Sandbox credentials
    $hasSandboxCredentials = ($setting->sandbox_app_key ?? '') !== '' && ($setting->sandbox_app_secret ?? '') !== '';
    $hasSandboxToken = !empty($setting->sandbox_access_token ?? '');
    $sandboxTokenExpired = $hasSandboxToken && ($setting->sandbox_expires_at ?? null) && \Carbon\Carbon::parse($setting->sandbox_expires_at)->lt(now());



    // Exactly the six TikTokController::packsRun() validates, no more.
    $packs = [
        'shops' => 'Shops', 'products' => 'Products', 'orders' => 'Orders',
        'logistics' => 'Logistics', 'finance' => 'Finance', 'full' => 'Everything',
    ];
@endphp

{{-- The section shown, from the menubar's own ?tab= links (the
     in-page tab strip retired 2026-09-03: the channel menubar carries the
     sections now). Unknown or unpermitted sections fall to the default,
     so a hand-typed URL can never land on a blank page. --}}
@php
    $csKnown = ['connection', 'status', 'logs'];
    if ($canManageTiktok) { $csKnown[] = 'explorer'; $csKnown[] = 'automations'; }
    // Every action redirects to the tab its form lives on and flashes it
    // (ReturnsToSettingsTab, 2026-09-05); the result is drawn inside that
    // section - the explorer's answer in the Explorer panel, a token fetch
    // on Connection - never on a tab the operator did not press it on.
    $csSection = in_array(request('tab'), $csKnown, true) ? request('tab') : 'connection';
    $resultTab = in_array(session('settings_tab'), $csKnown, true) ? session('settings_tab') : 'connection';
@endphp
<div class="cs-page"
     data-channel-settings
     data-tab-prefix="tt-tab-"
     data-tab-storage-key="tt-active-tab"
     data-default-tab="">

<div class="x-list-head">
    <div>
        <h1 class="x-page-title">Settings</h1>
        <p class="x-page-sub">Credentials, status mapping and the API record for this TikTok Shop.</p>
    </div>
</div>

@unless($canManageTiktok)
<div class="cs-readonly">
    <div>
        <strong>You can look, not change.</strong>
        Settings, status mapping and the API record are readable with the TikTok view permission. Editing credentials, authorising the shop and clearing logs need the manage tier.
    </div>
</div>
@endunless

{{-- ================================================================
     CONNECTION
     ================================================================ --}}
{{-- The result of the last Connection action (token fetch, save): drawn
     here, above the panel it came from. An explorer answer is drawn inside
     the Explorer panel instead (the owner, 2026-09-05). --}}
@if($result && $resultTab !== 'explorer')
    @include('partials.channel-last-result', ['result' => $result, 'channel' => 'TikTok Shop', 'mode' => 'note'])
@endif

<div id="tt-tab-connection" class="cs-panel" @if($csSection !== 'connection') hidden @endif>

    <section class="fm-section">
        <div class="fm-section__head">
            <h2 class="fm-section__title">Environment</h2>
        </div>

        <div class="cs-env">
            @if($canManageTiktok)
                {{-- Wire unchanged: one hidden `mode` field posted to
                     toggle-mode, exactly as the old knob did. What changed is
                     that the control names both environments instead of being
                     an on/off switch you had to read the label to decode, and
                     it no longer paints itself green and grey with inline
                     styles. --}}
                <form method="POST" action="{{ route('ext.tiktok.toggle_mode') }}" data-cs-env-form>
                    @csrf
                    <input type="hidden" name="mode" value="{{ $currentMode }}" data-cs-env-input>
                    <div class="x-segment" role="tablist" aria-label="TikTok environment">
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

            <x-ui.hint class="cs-env__hint" label="About the environment">Every sync, push and order fetch uses the credentials of the environment selected here. It is also what the Authorise button sends, so switch first and authorise second.</x-ui.hint>
        </div>
    </section>

    {{-- ---- THIS STORE ---------------------------------------------- --}}
    {{-- The store's own name and switch (multi-store), outside both
         environment panels: they belong to the store, not to a set of
         credentials, so they show whichever environment the store stands in
         (the owner, 2026-09-05). --}}
    <section class="fm-section">
        <div class="fm-section__head">
            <h2 class="fm-section__title">This store</h2>
            @if($canManageTiktok)
                <div class="fm-section__aside">
                    {{-- Hard delete, no restore: the sheet asks for the name. --}}
                    <x-ui.button type="button" size="sm" variant="ghost" class="cs-delete__open" data-delete-open>Delete this store</x-ui.button>
                </div>
            @endif
        </div>
        @if($canManageTiktok)
            @include('partials.channel-delete-store', [
                'action' => route('ext.tiktok.stores.destroy'),
                'storeName' => $setting->store_name ?? '',
                'channelLabel' => 'TikTok',
            ])
        @endif
        {{-- One Save for the store and its credentials (the owner, 2026-09-05):
             these fields belong to the credentials form of the environment
             the store stands in, through the form attribute, so the section
             carries no button of its own. --}}
        @php $csStoreForm = $isSandbox ? 'tt-save-sandbox' : 'tt-save-live'; @endphp
        <div class="fm-fields">
            <x-ui.field label="Store name" for="tt-store-name" name="store_name" :required="true" wide
                        hint="How this store is named across the ERP - the sidebar, the fulfilment tabs, the channels board.">
                <x-ui.input id="tt-store-name" name="store_name" type="text" autocomplete="off" :disabled="!$canManageTiktok" :form="$csStoreForm"
                            value="{{ old('store_name', $setting->store_name ?? '') }}" />
            </x-ui.field>

            {{-- The system's one switch (.fm-switch), not a checkbox with
                 a caption: the field's label names the state, the track
                 shows it. --}}
            <x-ui.field label="Active" for="tt-enabled" name="enabled" wide
                        hint="An active store syncs on the crons and shows its tabs. Switch off to pause the store without losing anything.">
                <input type="hidden" name="enabled" value="0" form="{{ $csStoreForm }}">
                <label class="fm-switch cs-store-switch">
                    <input type="checkbox" class="fm-switch__input" id="tt-enabled" name="enabled" value="1" form="{{ $csStoreForm }}" @checked((bool) ($setting->enabled ?? true)) @disabled(!$canManageTiktok)>
                    <span class="fm-switch__track" aria-hidden="true"></span>
                </label>
            </x-ui.field>
        </div>
    </section>

    {{-- ---- PRODUCTION ---------------------------------------------- --}}
    <div class="cs-grid" data-cs-env-panel="live" @if($isSandbox) hidden @endif>

        <section class="fm-section">
            <div class="fm-section__head">
                <h2 class="fm-section__title">Production credentials</h2>
                <div class="fm-section__aside">
                    @if($hasCredentials)
                        <x-ui.badge tone="success">Configured</x-ui.badge>
                    @else
                        <x-ui.badge tone="neutral">Not configured</x-ui.badge>
                    @endif
                </div>
            </div>

            <form method="POST" action="{{ route('ext.tiktok.save') }}" id="tt-save-live">
                @csrf
                <input type="hidden" name="env" value="live">

                <div class="fm-fields">
                    <x-ui.field label="App Key" for="tt-app-key" name="app_key" wide
                                hint="The app identifier from TikTok Shop Partner Center. Not a secret, so it is shown in full.">
                        <x-ui.input id="tt-app-key" name="app_key" class="fm-input--num" type="text" autocomplete="off" :disabled="!$canManageTiktok"
                                    value="{{ $setting->app_key ?? '' }}" />
                    </x-ui.field>

                    <x-ui.credential
                        name="app_secret"
                        id="tt-app-secret"
                        label="App Secret"
                        channel="tiktok"
                        hint="Stored encrypted. Read-only until you choose to edit it."
                        :length="\App\Support\Credentials::length($setting->app_secret ?? null)"
                        :can-reveal="$canManageTiktok"
                        :disabled="!$canManageTiktok" />

                    {{-- No name attribute, deliberately: this is shown so it can
                         be copied into the TikTok Shop Partner Center, and it
                         must never be posted back as a settable field. --}}
                    <x-ui.field label="Redirect URI" for="tt-redirect" wide
                                hint="Register this exact URL in your TikTok Shop app. It is always https. To change it, edit APP_URL in the environment file.">
                        <x-ui.input id="tt-redirect" type="text" readonly value="{{ $defaultRedirect }}" data-select-on-click />
                    </x-ui.field>
                </div>

                @if($canManageTiktok)
                <div class="cs-actions">
                    <x-ui.button type="submit" variant="primary">Save production settings</x-ui.button>
                    <span class="cs-actions__note">Leave a masked field untouched to keep what is already stored. Saving also switches the shop to production.</span>
                </div>
                @endif
            </form>
        </section>

        <section class="fm-section">
            <div class="fm-section__head">
                <h2 class="fm-section__title">Production authorisation</h2>
            </div>

            <div class="cs-state">
                @if($hasToken && !$tokenExpired)
                    <x-ui.badge tone="success">Token active</x-ui.badge>
                    @if($setting->expires_at ?? null)
                        <span class="cs-state__when">Expires {{ \Carbon\Carbon::parse($setting->expires_at)->diffForHumans() }}</span>
                    @endif
                @elseif($hasToken && $tokenExpired)
                    <x-ui.badge tone="danger">Token expired</x-ui.badge>
                    <span class="cs-state__note">Use step 4 to renew it without authorising again.</span>
                @else
                    <x-ui.badge tone="warning">No token</x-ui.badge>
                    <span class="cs-state__note">Work through the four steps below.</span>
                @endif
            </div>

            @if($canManageTiktok)
            <div class="cs-steps">
                <div class="cs-step">
                    <span class="cs-step__n">1</span>
                    <div class="cs-step__body">
                        <span class="cs-step__title">Authorise with TikTok Shop</span>
                        <div>
                            <x-ui.button size="sm" :href="route('ext.tiktok.authorize', ['store' => $setting->id ?? null])" target="_blank" rel="noopener">Open TikTok Shop authorisation</x-ui.button>
                        </div>
                        <p class="cs-step__note">Opens TikTok Shop sign-in in a new tab, using whichever environment is selected above. After you grant access TikTok sends you back to the callback URL with an auth code, and the code is exchanged for a token automatically.</p>
                    </div>
                </div>

                <div class="cs-step">
                    <span class="cs-step__n">2</span>
                    <div class="cs-step__body">
                        <span class="cs-step__title">Exchange an auth code by hand</span>
                        <form method="POST" action="{{ route('ext.tiktok.token_get') }}" class="cs-exchange">
                            @csrf
                            <x-ui.field label="Auth code" for="tt-code" name="code">
                                <x-ui.input id="tt-code" name="code" value="{{ old('code') }}" placeholder="Fills in after the callback, or paste it here" />
                            </x-ui.field>
                            <x-ui.button type="submit" size="sm">Exchange</x-ui.button>
                        </form>
                        <p class="cs-step__note">Only needed when the automatic exchange did not run, for example if you copied the redirect URL out of the address bar.</p>
                    </div>
                </div>

                <div class="cs-step">
                    <span class="cs-step__n">3</span>
                    <div class="cs-step__body">
                        <span class="cs-step__title">Fetch the authorised shop</span>
                        <form method="POST" action="{{ route('ext.tiktok.shops') }}">
                            @csrf
                            <x-ui.button type="submit" size="sm">Fetch shop</x-ui.button>
                        </form>
                        <p class="cs-step__note">Saves the shop cipher, which nearly every TikTok API call needs. Without it products and orders will not sync even with a valid token.</p>

                        @if($hasShop)
                            <dl class="cs-facts">
                                <div class="cs-fact"><dt>Shop</dt><dd>{{ $setting->shop_name ?? 'Not named' }}</dd></div>
                                <div class="cs-fact"><dt>Shop ID</dt><dd class="cs-fact__mono">{{ $setting->shop_id ?? 'Not set' }}</dd></div>
                                <div class="cs-fact"><dt>Shop code</dt><dd class="cs-fact__mono">{{ $setting->shop_code ?? 'Not set' }}</dd></div>
                                <div class="cs-fact"><dt>Shop cipher</dt><dd class="cs-fact__mono">{{ $setting->shop_cipher ?? 'Not set' }}</dd></div>
                                <div class="cs-fact"><dt>Region</dt><dd class="cs-fact__mono">{{ ($setting->region ?? '') !== '' ? strtoupper($setting->region) : 'Not set' }}</dd></div>
                                <div class="cs-fact"><dt>Warehouse</dt><dd class="cs-fact__mono">{{ $setting->warehouse_id ?? 'Not set' }}</dd></div>
                            </dl>
                        @endif
                    </div>
                </div>

                <div class="cs-step">
                    <span class="cs-step__n">4</span>
                    <div class="cs-step__body">
                        <span class="cs-step__title">Refresh an expired token</span>
                        <form method="POST" action="{{ route('ext.tiktok.token_refresh') }}">
                            @csrf
                            <x-ui.button type="submit" size="sm">Refresh access token</x-ui.button>
                        </form>
                        <p class="cs-step__note">TikTok access tokens last about seven days. The scheduled job renews them, so this is here for when that job has not run.</p>
                    </div>
                </div>
            </div>
            @else
            <p class="cs-step__note">Authorising, fetching the shop and refreshing tokens need the TikTok manage permission.</p>
            @endif
        </section>
    </div>

    {{-- ---- SANDBOX ------------------------------------------------- --}}
    <div class="cs-grid" data-cs-env-panel="sandbox" @unless($isSandbox) hidden @endunless>

        <section class="fm-section">
            <div class="fm-section__head">
                <h2 class="fm-section__title">Sandbox credentials</h2>
                <div class="fm-section__aside">
                    @if($hasSandboxCredentials)
                        <x-ui.badge tone="success">Configured</x-ui.badge>
                    @else
                        <x-ui.badge tone="neutral">Not configured</x-ui.badge>
                    @endif
                </div>
            </div>

            <form method="POST" action="{{ route('ext.tiktok.save') }}" id="tt-save-sandbox">
                @csrf
                <input type="hidden" name="env" value="sandbox">

                <div class="fm-fields">
                    <x-ui.field label="Sandbox App Key" for="tt-sb-app-key" name="sandbox_app_key" wide>
                        <x-ui.input id="tt-sb-app-key" name="sandbox_app_key" class="fm-input--num" type="text" autocomplete="off" :disabled="!$canManageTiktok"
                                    value="{{ $setting->sandbox_app_key ?? '' }}" />
                    </x-ui.field>

                    <x-ui.credential
                        name="sandbox_app_secret"
                        id="tt-sb-app-secret"
                        label="Sandbox App Secret"
                        channel="tiktok"
                        hint="Stored encrypted. Read-only until you choose to edit it."
                        :length="\App\Support\Credentials::length($setting->sandbox_app_secret ?? null)"
                        :can-reveal="$canManageTiktok"
                        :disabled="!$canManageTiktok" />

                    {{-- No name attribute, same as the production copy. --}}
                    <x-ui.field label="Redirect URI" for="tt-sb-redirect" wide
                                hint="Register this exact URL in your TikTok Shop sandbox app. It is always https. To change it, edit APP_URL in the environment file.">
                        <x-ui.input id="tt-sb-redirect" type="text" readonly value="{{ $defaultRedirect }}" data-select-on-click />
                    </x-ui.field>
                </div>

                @if($canManageTiktok)
                <div class="cs-actions">
                    <x-ui.button type="submit" variant="primary">Save sandbox settings</x-ui.button>
                    <span class="cs-actions__note">Leave a masked field untouched to keep what is already stored. Saving also switches the shop to sandbox.</span>
                </div>
                @endif
            </form>
        </section>

        <section class="fm-section">
            <div class="fm-section__head">
                <h2 class="fm-section__title">Sandbox authorisation</h2>
            </div>

            <div class="cs-state">
                @if($hasSandboxToken && !$sandboxTokenExpired)
                    <x-ui.badge tone="success">Token active</x-ui.badge>
                    @if($setting->sandbox_expires_at ?? null)
                        <span class="cs-state__when">Expires {{ \Carbon\Carbon::parse($setting->sandbox_expires_at)->diffForHumans() }}</span>
                    @endif
                @elseif($hasSandboxToken && $sandboxTokenExpired)
                    <x-ui.badge tone="danger">Token expired</x-ui.badge>
                @else
                    <x-ui.badge tone="warning">No token</x-ui.badge>
                    <span class="cs-state__note">Fill in the sandbox credentials first, then authorise.</span>
                @endif
            </div>

            @if($canManageTiktok)
            <div class="cs-steps">
                <div class="cs-step">
                    <span class="cs-step__n">1</span>
                    <div class="cs-step__body">
                        <span class="cs-step__title">Authorise with the TikTok test environment</span>
                        <div>
                            {{-- The same URL as the production button, carrying
                                 only the store id, not a mode parameter.
                                 redirectToAuth() reads the stored `mode` column,
                                 which the segmented control above has already set
                                 to sandbox by the time this panel is on screen. --}}
                            <x-ui.button size="sm" :href="route('ext.tiktok.authorize', ['store' => $setting->id ?? null])" target="_blank" rel="noopener">Open sandbox authorisation</x-ui.button>
                        </div>
                        <p class="cs-step__note">Opens the TikTok test environment in a new tab, using the sandbox credentials above.</p>
                    </div>
                </div>

                <div class="cs-step">
                    <span class="cs-step__n">2</span>
                    <div class="cs-step__body">
                        <span class="cs-step__title">Exchange an auth code by hand</span>
                        <form method="POST" action="{{ route('ext.tiktok.token_get') }}" class="cs-exchange">
                            @csrf
                            <x-ui.field label="Auth code" for="tt-sb-code" name="code">
                                <x-ui.input id="tt-sb-code" name="code" placeholder="Paste the sandbox code here" />
                            </x-ui.field>
                            <x-ui.button type="submit" size="sm">Exchange</x-ui.button>
                        </form>
                    </div>
                </div>

                <div class="cs-step">
                    <span class="cs-step__n">3</span>
                    <div class="cs-step__body">
                        <span class="cs-step__title">Fetch the sandbox shop</span>
                        <form method="POST" action="{{ route('ext.tiktok.shops') }}">
                            @csrf
                            <x-ui.button type="submit" size="sm">Fetch shop</x-ui.button>
                        </form>
                    </div>
                </div>

                <div class="cs-step">
                    <span class="cs-step__n">4</span>
                    <div class="cs-step__body">
                        <span class="cs-step__title">Refresh an expired token</span>
                        <form method="POST" action="{{ route('ext.tiktok.token_refresh') }}">
                            @csrf
                            <x-ui.button type="submit" size="sm">Refresh sandbox token</x-ui.button>
                        </form>
                    </div>
                </div>
            </div>
            @else
            <p class="cs-step__note">Authorising, fetching the shop and refreshing tokens need the TikTok manage permission.</p>
            @endif
        </section>
    </div>

    {{-- Full width under the split: this is orientation for a first setup, not
         a control, so it sits underneath both columns rather than competing
         with them. The old page printed the redirect URL a second time here,
         computed a different way (url('/tiktok/callback'), which keeps the
         request's own scheme) than the field above (https, forced). Two URLs
         on one page that must byte-match a console entry is a trap, so the
         step points at the field instead of restating it. --}}
    <section class="fm-section cs-span">
        <div class="fm-section__head">
            <h2 class="fm-section__title">Setting this up for the first time</h2>
        </div>
        <ol class="cs-guide">
            <li>Register a developer account at <span class="cs-code">partner.tiktokshop.com</span>. This is the seller side. <span class="cs-code">developers.tiktok.com</span> is a different platform and will not sync shops, orders or products.</li>
            <li>Create an app in the Partner Center. It gives you an App Key and an App Secret.</li>
            <li>Paste the Redirect URI shown above into that app's settings, exactly as it appears.</li>
            <li>Put the App Key and App Secret in the fields above and save.</li>
            <li>Authorise the shop, then fetch the shop so the shop cipher is stored.</li>
        </ol>
    </section>
</div>

{{-- ================================================================
     STATUS MAPPING
     ================================================================ --}}
<div id="tt-tab-status" class="cs-panel" @if($csSection !== 'status') hidden @endif>

    <section class="fm-section">
        <div class="fm-section__head">
            <h2 class="fm-section__title">Order status mapping</h2>
        </div>
        <p class="fm-section__note">When a TikTok order is synced, its TikTok status becomes the sales order status chosen here.</p>

        <form method="POST" action="{{ route('ext.tiktok.order_status_map') }}">
            @csrf
            <x-ui.table>
                <x-slot:head>
                    <tr>
                        <th scope="col" class="cs-col-src">TikTok status</th>
                        <th scope="col">Sales order status</th>
                    </tr>
                </x-slot:head>
                @foreach($tikTokStatuses as $key => $label)
                    <tr>
                        <td class="cs-col-src" data-label="TikTok status">
                            <span class="cs-code">{{ $key }}</span>
                            <span class="cs-map-label">{{ $label }}</span>
                        </td>
                        <td data-label="Sales order status">
                            <div class="x-select-wrap cs-map-select">
                                <select name="map[{{ $key }}]" class="x-input" aria-label="Sales order status for {{ $label }}" @disabled(!$canManageTiktok)>
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

            @if($canManageTiktok)
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
        <p class="fm-section__note">When a TikTok Shop return is synced, its status becomes the sales order status chosen here. Leave a row unmapped to make it change nothing.</p>

        <form method="POST" action="{{ route('ext.tiktok.return_status_map') }}">
            @csrf
            <x-ui.table>
                <x-slot:head>
                    <tr>
                        <th scope="col" class="cs-col-src">TikTok return status</th>
                        <th scope="col">Sales order status</th>
                    </tr>
                </x-slot:head>
                @foreach(($tikTokReturnStatuses ?? []) as $key => $label)
                    <tr>
                        <td class="cs-col-src" data-label="TikTok return status">
                            <span class="cs-code">{{ $key }}</span>
                            <span class="cs-map-label">{{ $label }}</span>
                        </td>
                        <td data-label="Sales order status">
                            <div class="x-select-wrap cs-map-select">
                                <select name="map[{{ $key }}]" class="x-input" aria-label="Sales order status for {{ $label }}" @disabled(!$canManageTiktok)>
                                    <option value="">Not mapped</option>
                                    @foreach($erpOrderStatuses as $os)
                                        <option value="{{ $os->order_status_id }}" @selected((int) (($returnStatusMap ?? [])[$key] ?? 0) === (int) $os->order_status_id)>{{ $os->name }}</option>
                                    @endforeach
                                </select>
                                <x-ui.icon name="chevron-down" size="14" class="x-select-wrap__chevron" />
                            </div>
                        </td>
                    </tr>
                @endforeach
            </x-ui.table>

            @if($canManageTiktok)
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
@if($canManageTiktok)
<div id="tt-tab-explorer" class="cs-panel" @if($csSection !== 'explorer') hidden @endif>
    <section class="fm-section">
        <div class="fm-section__head">
            <h2 class="fm-section__title">API explorer</h2>
        </div>
        <p class="fm-section__note">Calls the TikTok Shop API directly with this shop's credentials. The signing fields (app key, timestamp, signature and shop cipher) are generated for you. Anything you run here is recorded in the API log.</p>

        <div class="api-explorer" data-cs-explorer>
            <div class="api-panel">
                <div class="api-search">
                    <x-ui.input id="api-endpoint-search" data-cs-ex-search placeholder="Search endpoints" aria-label="Search endpoints" />
                </div>

                <div class="api-list" id="api-endpoint-list">
                    <div class="api-cat">Authorisation</div>
                    <button type="button" class="api-endpoint" data-ep='{"name":"Get Authorized Shops","method":"GET","auth":true,"path":"/authorization/202309/shops","desc":"Fetch shops authorized for your app.","params":[]}'><span class="api-badge get">GET</span><span class="api-code">/authorization/202309/shops</span><div class="api-hint">Returns shop id, cipher and name</div></button>

                    <div class="api-cat">Products</div>
                    <button type="button" class="api-endpoint" data-ep='{"name":"Search Products","method":"POST","auth":true,"path":"/product/202309/products/search","desc":"Search products in your shop.","params":[{"k":"page_size","ph":"10"}]}'><span class="api-badge post">POST</span><span class="api-code">/product/202309/products/search</span><div class="api-hint">page_size, in the body</div></button>
                    <button type="button" class="api-endpoint" data-ep='{"name":"Get Product Detail","method":"GET","auth":true,"path":"/product/202309/products","desc":"Get details of a specific product.","params":[{"k":"ids","ph":"product_id"}]}'><span class="api-badge get">GET</span><span class="api-code">/product/202309/products</span><div class="api-hint">ids, a query parameter</div></button>
                    <button type="button" class="api-endpoint" data-ep='{"name":"Get Product Categories","method":"GET","auth":true,"path":"/product/202309/categories","desc":"Get product category tree.","params":[]}'><span class="api-badge get">GET</span><span class="api-code">/product/202309/categories</span><div class="api-hint">The whole category tree</div></button>
                    <button type="button" class="api-endpoint" data-ep='{"name":"Get Product Attributes","method":"GET","auth":true,"path":"/product/202309/categories/rules","desc":"Get required/optional attributes for a category.","params":[{"k":"category_id","ph":"600001"}]}'><span class="api-badge get">GET</span><span class="api-code">/product/202309/categories/rules</span><div class="api-hint">category_id</div></button>
                    <button type="button" class="api-endpoint" data-ep='{"name":"Get Brands","method":"GET","auth":true,"path":"/product/202309/brands","desc":"Get available brands.","params":[{"k":"page_size","ph":"20"},{"k":"category_id","ph":""}]}'><span class="api-badge get">GET</span><span class="api-code">/product/202309/brands</span><div class="api-hint">page_size, category_id</div></button>

                    <div class="api-cat">Orders</div>
                    <button type="button" class="api-endpoint" data-ep='{"name":"Search Orders","method":"POST","auth":true,"path":"/order/202309/orders/search","desc":"Search orders by date range.","params":[{"k":"page_size","ph":"10"},{"k":"create_time_ge","ph":""},{"k":"create_time_lt","ph":""}]}'><span class="api-badge post">POST</span><span class="api-code">/order/202309/orders/search</span><div class="api-hint">page_size, create_time_ge and create_time_lt as unix seconds</div></button>
                    <button type="button" class="api-endpoint" data-ep='{"name":"Get Order Detail","method":"POST","auth":true,"path":"/order/202309/orders","desc":"Get details of specific orders.","params":[{"k":"order_ids","ph":"[\"order_id\"]"}]}'><span class="api-badge post">POST</span><span class="api-code">/order/202309/orders</span><div class="api-hint">order_ids, in the body</div></button>

                    <div class="api-cat">Logistics</div>
                    <button type="button" class="api-endpoint" data-ep='{"name":"Get Delivery Options","method":"GET","auth":true,"path":"/logistics/202309/delivery_options","desc":"Get available delivery/shipping options.","params":[]}'><span class="api-badge get">GET</span><span class="api-code">/logistics/202309/delivery_options</span><div class="api-hint">Shipping options</div></button>
                    <button type="button" class="api-endpoint" data-ep='{"name":"Get Warehouses","method":"GET","auth":true,"path":"/logistics/202309/warehouses","desc":"Get warehouse list.","params":[]}'><span class="api-badge get">GET</span><span class="api-code">/logistics/202309/warehouses</span><div class="api-hint">Warehouse info</div></button>
                    <button type="button" class="api-endpoint" data-ep='{"name":"Get Shipping Providers","method":"GET","auth":true,"path":"/logistics/202309/shipping_providers","desc":"Get available shipping providers.","params":[{"k":"delivery_option_id","ph":""}]}'><span class="api-badge get">GET</span><span class="api-code">/logistics/202309/shipping_providers</span><div class="api-hint">delivery_option_id</div></button>

                    <div class="api-cat">Finance</div>
                    <button type="button" class="api-endpoint" data-ep='{"name":"Search Settlements","method":"POST","auth":true,"path":"/finance/202309/settlements/search","desc":"Search financial settlement records.","params":[{"k":"page_size","ph":"10"}]}'><span class="api-badge post">POST</span><span class="api-code">/finance/202309/settlements/search</span><div class="api-hint">page_size</div></button>
                    <button type="button" class="api-endpoint" data-ep='{"name":"Get Payments","method":"POST","auth":true,"path":"/finance/202309/payments/search","desc":"Search payment records.","params":[{"k":"page_size","ph":"10"}]}'><span class="api-badge post">POST</span><span class="api-code">/finance/202309/payments/search</span><div class="api-hint">page_size</div></button>

                    <div class="api-cat">Fulfilment</div>
                    <button type="button" class="api-endpoint" data-ep='{"name":"Search Packages","method":"POST","auth":true,"path":"/fulfillment/202309/packages/search","desc":"Search fulfillment packages.","params":[{"k":"page_size","ph":"10"}]}'><span class="api-badge post">POST</span><span class="api-code">/fulfillment/202309/packages/search</span><div class="api-hint">page_size</div></button>
                    <button type="button" class="api-endpoint" data-ep='{"name":"Get Package Detail","method":"GET","auth":true,"path":"/fulfillment/202309/packages","desc":"Get package details.","params":[{"k":"package_id","ph":""}]}'><span class="api-badge get">GET</span><span class="api-code">/fulfillment/202309/packages</span><div class="api-hint">package_id</div></button>
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
                        <span class="fm-label">If a call comes back wrong</span>
                        <div class="api-mini">
                            <div>TikTok versions its paths, so /product/202309/... is the whole path, not a prefix.</div>
                            <div>Most endpoints need the shop cipher. It is added for you once step 3 on the Connection tab has run.</div>
                            <div>A POST sends the parameters as a JSON body. A GET sends them as query parameters.</div>
                        </div>
                    </div>
                </div>

                @if($result && $resultTab === 'explorer')
                    @include('partials.channel-last-result', ['result' => $result, 'channel' => 'TikTok Shop'])
                @endif
                <form method="POST" action="{{ route('ext.tiktok.explorer_run') }}" data-cs-ex-form>
                    @csrf

                    <div class="fm-fields fm-fields--2">
                        <x-ui.field label="Method" for="api-explorer-method" name="method">
                            <div class="x-select-wrap">
                                <select id="api-explorer-method" name="method" class="x-input" data-cs-ex-method>
                                    <option value="GET" @selected(old('method', 'GET') === 'GET')>GET</option>
                                    <option value="POST" @selected(old('method') === 'POST')>POST</option>
                                </select>
                                <x-ui.icon name="chevron-down" size="14" class="x-select-wrap__chevron" />
                            </div>
                        </x-ui.field>

                        <div class="fm-field">
                            <div class="fm-label-row">
                                <span class="fm-label">Send with the request</span>
                                <x-ui.hint class="fm-label__hint" label="About the access token">Needed for every shop-level endpoint: products, orders, logistics, finance and fulfilment.</x-ui.hint>
                            </div>
                            <label class="cs-check">
                                <input type="checkbox" name="auth_required" value="1" @checked(old('auth_required')) data-cs-ex-auth>
                                Access token
                            </label>
                        </div>

                        <x-ui.field label="API path" for="api-explorer-path" name="api_path" wide
                                    hint="A leading slash is added for you.">
                            <x-ui.input id="api-explorer-path" name="api_path" class="api-code" data-cs-ex-path
                                        value="{{ old('api_path', '/authorization/202309/shops') }}" placeholder="/authorization/202309/shops" />
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
                            </div>
                        </div>

                        <x-ui.field label="Parameters as JSON" for="api-explorer-params" name="params_json_pretty" wide
                                    hint="Edit here or in the table above, whichever is easier.">
                            <x-ui.textarea id="api-explorer-params" name="params_json_pretty" rows="8" class="api-code" data-cs-ex-params placeholder='{"page_size": 10}'>{{ old('params_json_pretty', '{}') }}</x-ui.textarea>
                        </x-ui.field>

                        {{-- The field TikTokController::explorerRun() actually
                             validates. The readable copy above is the one the
                             operator edits; this one is kept in step by
                             resources/js/pages/channel-settings.js on submit,
                             which is the pre-redesign wire unchanged. --}}
                        <input type="hidden" name="params_json" data-cs-ex-hidden value="{{ old('params_json', '{}') }}">
                    </div>

                    <div class="cs-actions">
                        <x-ui.button type="submit" variant="primary">Run request</x-ui.button>
                        <span class="cs-actions__note">The response lands on the API log tab.</span>
                    </div>
                </form>
            </div>
        </div>
    </section>

    <section class="fm-section">
        <div class="fm-section__head">
            <h2 class="fm-section__title">Preset runs</h2>
        </div>
        <p class="fm-section__note">Each one runs a small set of related endpoints in order and records every call in the API log. Useful for checking a new connection end to end.</p>

        <div class="cs-packs">
            @foreach($packs as $pack => $label)
                <form method="POST" action="{{ route('ext.tiktok.packs_run') }}">
                    @csrf
                    <input type="hidden" name="pack" value="{{ $pack }}">
                    <x-ui.button type="submit" size="sm">{{ $label }}</x-ui.button>
                </form>
            @endforeach
        </div>
    </section>
</div>
@endif

{{-- ================================================================
     API LOG
     ================================================================ --}}
<div id="tt-tab-logs" class="cs-panel" @if($csSection !== 'logs') hidden @endif>



    {{-- #200: the shared API log panel - the three-way mode, All/Errors
         and path filters that survive a refresh, pages of 50, and the
         retention stated in words. --}}
    @include('partials.channel-api-log', [
        'logChannel' => 'TikTok',
        'canManage' => $canManageTiktok,
        'logMode' => $setting->api_log_mode ?? 'all',
        'modeUrl' => route('ext.tiktok.api_log_mode'),
        'logs' => $logs,
        'clearUrl' => route('ext.tiktok.clear_api_logs'),
        'map' => [
            'path' => 'api_path', 'status' => 'response_status',
            'okFn' => fn ($r) => (bool) $r->ok,
            'ms' => null, 'pack' => 'pack', 'signed' => 'auth_required',
            'request' => 'request_params', 'response' => 'response_body',
            'requestLabel' => 'Request parameters',
        ],
    ])

    @if($canManageTiktok)
    <section class="fm-section">
        <div class="fm-section__head">
            <h2 class="fm-section__title">Free up space</h2>
            {{-- Explanation to a tooltip (the owner, 2026-09-04): the
                 control is self-evident, the caveat is one tap away. --}}
            <x-ui.hint label="What clearing does">Every synced TikTok order and order line keeps the full API response it came from. Clearing those payloads on older rows frees database space and changes nothing about the orders themselves.</x-ui.hint>
        </div>

        <form method="POST" action="{{ route('ext.tiktok.purge_raw') }}"
              data-confirm="Clear the stored API payloads on TikTok rows older than the number of days given? The orders stay, only the raw payloads go, and this cannot be undone.">
            @csrf
            <div class="fm-field">
                <div class="fm-label-row">
                    <label class="fm-label" for="tt-purge-days">Clear payloads older than</label>
                    <x-ui.hint class="fm-label__hint" bubble-id="tt-purge-hint" label="About the days">Anything from 1 to 3650 days.</x-ui.hint>
                </div>
                <div class="cs-inline">
                    <x-ui.input id="tt-purge-days" name="days" type="number" value="30" min="1" max="3650" required
                                class="fm-input--num cs-days" aria-describedby="tt-purge-hint" />
                    <span class="cs-inline__unit">days</span>
                    <x-ui.button type="submit" variant="danger">Clear stored payloads</x-ui.button>
                </div>
                @error('days')<div class="fm-error">{{ $message }}</div>@enderror
            </div>
        </form>
    </section>
    @endif
</div>

{{-- ================================================================
     AUTOMATIONS
     ================================================================ --}}
@if($canManageTiktok)
<div id="tt-tab-automations" class="cs-panel" @if($csSection !== 'automations') hidden @endif>
    <section class="fm-section">
        <div class="fm-section__head">
            <h2 class="fm-section__title">Scheduled jobs</h2>
            <x-ui.hint label="About the scheduled jobs">How often each TikTok sync runs, and how far back it looks. Changes here take effect the next time the scheduler wakes up.</x-ui.hint>
        </div>
        @include('partials._automations_tab', ['integration' => 'tiktok', 'storeId' => null])
    </section>
</div>
@endif

</div>{{-- /.cs-page --}}
@endsection
