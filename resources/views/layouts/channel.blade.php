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
    {{-- Inter, for the .x-legacy content boundary only. base/reset.css and the
         .x-legacy rule both ask for 'Inter', and layouts/app.blade.php is
         where the seven pages hosted here used to get it. Without this they
         fall back to system-ui and their type metrics shift. The workspace
         chrome around them still uses Geist, which tokens.css self-hosts. --}}
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @stack('styles')
</head>
<body class="x-app">
<div class="x-shell">
    <aside class="x-sidebar x-sidebar--channel">
        @include('partials.channel-nav')
    </aside>

    <div class="x-main">
        @php
            // Which store, when the channel has them: a multi-store workspace
            // must say so in the trail, not only in the sidebar, because the
            // trail is what stays on screen while the page scrolls.
            $__channelStore = $channelStoreLabel ?? null;
            // The same test @hasSection makes, read once so the "current"
            // marker can land on the store segment when a page supplies no
            // leaf of its own. Reading a section does not consume it, so the
            // @yield below still prints it.
            $__channelLeaf = trim($__env->yieldContent('breadcrumb'));
        @endphp
        <header class="x-topbar">
            <div class="x-topbar__crumb">
                <span class="x-crumb x-crumb--channel">
                    <span class="x-crumb__part">Integrations</span>
                    <span class="x-crumb__sep">/</span>
                    {{-- The last segment is the current one, and below 768px
                         it is the only one shown, so exactly one of these
                         three must carry the marker: the leaf when the page
                         supplies one, else the store, else the channel. --}}
                    <span class="x-crumb__part @if($__channelLeaf === '' && ! $__channelStore) x-crumb__part--current @endif">{{ $channelCard->name }}</span>
                    @if($__channelStore)
                        <span class="x-crumb__sep">/</span>
                        <span class="x-crumb__part @if($__channelLeaf === '') x-crumb__part--current @endif">{{ $__channelStore }}</span>
                    @endif
                    @if($__channelLeaf !== '')
                        <span class="x-crumb__sep">/</span>
                        <span class="x-crumb__part x-crumb__part--current">@yield('breadcrumb')</span>
                    @endif
                </span>
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
        <main class="x-content @yield('content-class', 'x-legacy')">
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
