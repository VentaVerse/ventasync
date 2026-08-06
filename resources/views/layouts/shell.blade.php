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
            <div class="x-theme" x-data="{ mode: (window.xenonTheme && window.xenonTheme.get()) || 'light' }">
                <button type="button" class="x-theme__btn" :class="mode === 'light' && 'is-on'" @click="mode='light'; window.xenonTheme.set('light')" aria-label="Light theme"><x-ui.icon name="sun" size="14" /></button>
                <button type="button" class="x-theme__btn" :class="mode === 'dark' && 'is-on'" @click="mode='dark'; window.xenonTheme.set('dark')" aria-label="Dark theme"><x-ui.icon name="moon" size="14" /></button>
                <button type="button" class="x-theme__btn" :class="mode === 'system' && 'is-on'" @click="mode='system'; window.xenonTheme.set('system')" aria-label="Match system theme"><x-ui.icon name="monitor" size="14" /></button>
            </div>
        </header>
        <main class="x-content">@yield('content')</main>
    </div>
</div>
@include('partials.confirm-modal')
<x-ui.palette />
</body>
</html>
