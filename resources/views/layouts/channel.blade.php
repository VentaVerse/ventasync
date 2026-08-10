<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    @php
        // Same fallbacks layouts/app.blade.php uses, reading the $appSetting
        // AppServiceProvider already shares with every view rather than
        // issuing a second query for the same row.
        $__companyName = $appSetting->company_name ?? 'VentaSync';
        $__faviconUrl = ($appSetting->favicon_path ?? null)
            ? asset('storage/' . $appSetting->favicon_path)
            : asset('images/brand/ventasync-icon.png');
    @endphp
    {{-- The company name belongs in every title: this layout hosts pages that
         used to live in layouts/app.blade.php, where the tab read as the
         company's own product. The page's own leaf leads, because that is what
         distinguishes one open tab from another. --}}
    <title>@yield('title', 'Xenon') - {{ $__companyName }}</title>
    <link rel="icon" type="image/png" href="{{ $__faviconUrl }}">
    <link rel="shortcut icon" href="{{ $__faviconUrl }}">
    <link rel="apple-touch-icon" href="{{ $__faviconUrl }}">
    @include('partials.theme-boot')
    @vite(['resources/css/blotter.css', 'resources/js/app.js'])
    @stack('styles')
</head>
<body class="x-app bl-app">
{{-- The channel shell is off-canvas below 1024px and permanent above it, the
     same arrangement layouts/blotter.blade.php already runs for the global
     shell. It is a drawer rather than the horizontal strip the legacy
     stylesheet reached for, because a channel's nav is a back link, a head
     carrying the store and its state, and up to three labelled groups: a
     single scrolling row cannot hold that without hiding most of it, and
     mixing two shell behaviours in one application is worse than either.

     Until this existed the sidebar held a hard 216px at every width, so a
     390px phone gave the page 174px to render into and every channel page -
     orders, listings, settings, logistics - scrolled sideways. --}}
<div class="x-shell" x-data="{ nav: false }" @keydown.escape.window="nav = false">
    <aside class="x-sidebar x-sidebar--channel" :class="nav && 'is-open'" id="channel-nav">
        @include('partials.channel-nav')
    </aside>

    {{-- Scrim. Below the drawer in the stack and above everything else, so a
         tap outside the nav closes it and nothing behind it is reachable
         while it is open. aria-hidden because the drawer's own close button
         and the escape key are what a keyboard uses. --}}
    <div class="x-scrim" x-show="nav" x-cloak @click="nav = false" aria-hidden="true"></div>

    <div class="x-main">
        <header class="x-topbar">
            {{-- The toggle and the trail are ONE flex child, not two.

                 The bar distributes with space-between, so a third child sent
                 the trail to the middle of the row and, once the controls
                 wrapped to a second line on a phone, left it stranded above
                 the account menu with the whole page width between it and the
                 button it belongs beside. Grouped, the bar is back to the two
                 children space-between was written for: where you are on the
                 left, what you can do on the right. --}}
            <div class="x-topbar__left">
                {{-- Opens the channel nav below 1024px, where the sidebar is a
                     drawer. aria-expanded and aria-controls are what make it a
                     disclosure rather than a mystery button to a screen
                     reader. --}}
                <button type="button" class="x-navtoggle"
                        @click="nav = true"
                        :aria-expanded="nav ? 'true' : 'false'"
                        aria-controls="channel-nav"
                        aria-label="Open channel navigation">
                    <x-ui.icon name="menu" size="16" />
                </button>

                {{-- Integrations / Channel [/ Store] / Leaf, same shape as ever,
                 now built by App\Support\Breadcrumbs from the workspace
                 payload the composer above already resolved rather than
                 assembled by hand here. The two decisions this markup used to
                 encode survive intact inside that builder and the shared
                 partial:

                 - a multi-store workspace names its store in the trail, not
                   only in the sidebar, because the trail is what stays on
                   screen while the page scrolls;
                 - the last segment is the current one, and below 768px it is
                   the only one shown, so exactly one of the three carries
                   --current: the leaf when the page supplies one, else the
                   store, else the channel. Every crumb here is plain text, so
                   "last" and "last unlinked" are the same crumb. --}}
                <div class="x-topbar__crumb">
                    @include('partials.breadcrumb', ['crumbClass' => 'x-crumb--channel'])
                </div>
            </div>

            <div class="x-topbar__right">
                {{-- Command palette affordance. Cmd+K/Ctrl+K already opens
                     <x-ui.palette /> (bound at the bottom of this layout) via
                     its own keydown handlers, but a keyboard shortcut nobody
                     can see is not discoverable. Rather than duplicate the
                     palette's open logic here, this button dispatches a
                     plain window CustomEvent that palette.blade.php listens
                     for (@xenon-open-palette.window) and hands straight to
                     the same openPalette() the keyboard shortcut already
                     uses, so there is exactly one way the palette opens. --}}
                <button type="button" class="x-search-trigger"
                        x-data="{ mac: /Mac|iPod|iPhone|iPad/.test(navigator.platform || navigator.userAgent || '') }"
                        @click="$dispatch('xenon-open-palette')"
                        aria-label="Search. Opens the command palette.">
                    <x-ui.icon name="search" size="14" />
                    <span class="x-search-trigger__label">Search</span>
                    <kbd class="x-search-trigger__hint" x-text="mac ? '&#8984;K' : 'Ctrl K'"></kbd>
                </button>

                <div class="x-theme" x-data="{ mode: (window.xenonTheme && window.xenonTheme.get()) || 'light' }">
                    <button type="button" class="x-theme__btn" :class="mode === 'light' && 'is-on'" @click="mode='light'; window.xenonTheme.set('light')" aria-label="Light theme"><x-ui.icon name="sun" size="14" /></button>
                    <button type="button" class="x-theme__btn" :class="mode === 'dark' && 'is-on'" @click="mode='dark'; window.xenonTheme.set('dark')" aria-label="Dark theme"><x-ui.icon name="moon" size="14" /></button>
                    <button type="button" class="x-theme__btn" :class="mode === 'system' && 'is-on'" @click="mode='system'; window.xenonTheme.set('system')" aria-label="Match system theme"><x-ui.icon name="monitor" size="14" /></button>
                </div>

                @auth
                    @php
                        // Same fallback chain as layouts/app.blade.php's sidebar-footer
                        // (name, then username, then a generic label) so a user with no
                        // display name set still gets a sane initial and label instead
                        // of an empty avatar. Blade's {{ }} escapes both before they
                        // ever reach the DOM, so a display name containing markup can't
                        // inject anything here.
                        $shellUser = auth()->user();
                        $shellUserName = $shellUser->name ?? $shellUser->username ?? 'Account';
                        $shellUserInitial = strtoupper(mb_substr($shellUserName, 0, 1));
                    @endphp
                    <x-ui.menu class="x-account" label="{{ $shellUserName }} - account menu">
                        <x-slot:trigger>
                            <span class="x-account__avatar" aria-hidden="true">{{ $shellUserInitial }}</span>
                            <span class="x-account__name" title="{{ $shellUserName }}">{{ $shellUserName }}</span>
                            <x-ui.icon name="chevron-down" size="12" />
                        </x-slot:trigger>

                        <div class="x-account__card">
                            <div class="x-account__card-name">{{ $shellUserName }}</div>
                            @if($shellUser->email ?? null)
                                <div class="x-account__card-email">{{ $shellUser->email }}</div>
                            @endif
                        </div>
                        <div class="x-menu__sep"></div>
                        {{-- POST through the named route with CSRF, same mechanics as
                             layouts/app.blade.php's sidebar-footer logout form - a GET
                             link would let a stray prefetch or middle-click log the
                             user out. --}}
                        <form method="POST" action="{{ route('logout') }}">
                            @csrf
                            <button type="submit" class="x-menu__item">
                                <x-ui.icon name="log-out" size="14" />
                                Logout
                            </button>
                        </form>
                    </x-ui.menu>
                @endauth
            </div>
        </header>
        {{-- The views hosted here were written against layouts/app.blade.php
             and depend on the flash, validation-error and integration-banner
             regions it renders around @yield('content'), plus the
             #js-flash-container that showFlashError()/showFlashSuccess()
             write into. Reproducing that contract is what makes this a
             layout those views can actually live in. --}}
        <main class="x-content @yield('content-class', '')">
            @include('partials.legacy-page-contract')
            @yield('content')
        </main>
    </div>
</div>
@include('partials.confirm-modal')
<x-ui.palette />
@stack('scripts')
</body>
</html>
