<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Xenon')</title>
    @include('partials.theme-boot')
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="x-app">
<div class="x-shell">
    <aside class="x-sidebar">
        <div class="x-sidebar__brand">
            <span class="x-sidebar__mark"></span>
            <span class="x-sidebar__name" title="{{ $appSetting->company_name ?? 'Xenon' }}">{{ $appSetting->company_name ?? 'Xenon' }}</span>
        </div>
        @php
            // I4: each nav item is gated on the same permission its target
            // route requires (see routes/web.php), so a restricted user
            // never sees a link that would just bounce them back with a
            // "You don't have permission" flash. Not auth()->user()->can():
            // this app registers no Gates or Policies, so can() always
            // returns false, including for admins. Authorization runs
            // through User::hasPermission() instead, the same method the
            // `perm:` route middleware and SettingsHubController::allowed()
            // delegate to, with the same pipe-delimited any-match parsing
            // ('view_x|manage_x' - either one held by the user's group
            // grants the link). $permission === null means "no gate beyond
            // auth", which is all the dashboard route requires.
            $navUser = auth()->user();
            $navAllowed = function (?string $permission) use ($navUser) {
                if ($permission === null) {
                    return true;
                }
                if (!$navUser) {
                    return false;
                }
                foreach (explode('|', $permission) as $key) {
                    $key = trim($key);
                    if ($key !== '' && $navUser->hasPermission($key)) {
                        return true;
                    }
                }
                return false;
            };
        @endphp
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
</body>
</html>
