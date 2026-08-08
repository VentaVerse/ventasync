<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Xenon')</title>
    @include('partials.theme-boot')
    @include('partials.nav-boot')
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="x-app">
<div class="x-shell">
    <aside class="x-sidebar">
        <div class="x-sidebar__brand">
            <span class="x-sidebar__mark"></span>
            <span class="x-sidebar__name" title="{{ $appSetting->company_name ?? 'Xenon' }}">{{ $appSetting->company_name ?? 'Xenon' }}</span>
        </div>
        {{-- Each nav item is gated on the same permission its target route
             requires (see routes/web.php), so a restricted user never sees a
             link that would just bounce them back with a "You don't have
             permission" flash. That filtering happens inside
             Navigation::groups() (via Navigation::allows(), which checks
             User::hasPermission() - never auth()->user()->can(), since this
             app registers no Gates or Policies and can() always returns
             false, including for admins), so every entry it returns is
             already safe to render here without a second gate. --}}
        @include('partials.nav', ['navGroups' => \App\Support\Navigation::groups()])
    </aside>

    <div class="x-main">
        <header class="x-topbar">
            <div class="x-topbar__crumb">@include('partials.breadcrumb')</div>
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
        <main class="x-content">@yield('content')</main>
    </div>
</div>
@include('partials.confirm-modal')
<x-ui.palette />
{{-- Page-level scripts and any dialog markup that has to render outside the
     page's own <form> (the product image picker, for one). layouts.app and
     layouts.channel have carried this stack all along; the shell had no page
     that pushed to it until the catalogue forms landed, so a @push in a view
     on this layout silently rendered nothing. --}}
@stack('scripts')
</body>
</html>
