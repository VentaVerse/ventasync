<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    @if(\App\Support\Fulfilment\PackingCheck::enabled())
        <meta name="packing-check" content="{{ url('/channels/fulfilment/packing-check') }}">
    @endif
    <title>@yield('title', config('app.name', 'VentaSync'))</title>
    @include('partials.theme-boot')
    @vite(['resources/css/blotter.css', 'resources/js/app.js'])
    @stack('styles')
</head>
<body class="bl-app min-h-screen @yield('body-class')" @hasSection('body-attrs')@yield('body-attrs')@endif>
<div class="x-progress" id="x-progress" aria-hidden="true"></div>
<a class="x-skip" href="#main">Skip to content</a>
<div x-data="{ nav: false }" class="lg:grid lg:grid-cols-[248px_minmax(0,1fr)] min-h-screen">

    <aside @keydown.escape.window="nav = false"
           :class="nav && '!flex'"
           class="fixed inset-y-0 left-0 z-40 hidden w-[248px] flex-col border-r border-side-rule bg-side
                  lg:sticky lg:top-0 lg:z-auto lg:flex lg:h-screen lg:self-start">
        <div class="flex items-center gap-2.5 border-b border-side-rule px-4 py-3">
            @php $blCompany = $appSetting->company_name ?? config('app.name', 'VentaSync'); @endphp
            @php $blLogo = \App\Services\Media\BrandImage::show($appSetting->logo_path ?? null, \App\Services\Media\BrandImage::NAV); @endphp
            <img class="bl-brand__logo" src="{{ $blLogo['url'] }}"
                 width="{{ $blLogo['width'] }}" height="{{ $blLogo['height'] }}"
                 alt="{{ $blCompany }}">
            <button type="button" @click="nav = false"
                    class="ml-auto text-side-ink hover:text-white lg:hidden" aria-label="Close navigation">
                <x-ui.icon name="x" size="16" />
            </button>
        </div>

        <div class="flex-1 overflow-y-auto">
            @include('partials.nav-blotter', ['navGroups' => \App\Support\Navigation::groups()])
        </div>

        <p class="bl-version">{{ \App\Support\AppVersion::label() }}</p>

    </aside>

    <div x-show="nav" x-cloak @click="nav = false"
         class="fixed inset-0 z-30 bg-ink/40 lg:hidden" aria-hidden="true"></div>

    <div class="flex min-w-0 flex-col">
        <header class="flex items-center gap-3.5 border-b border-rule-2 px-gutter py-2.5">
            <button type="button" @click="nav = true"
                    class="grid h-[30px] w-8 place-items-center rounded-[2px] border border-rule-2 bg-sheet lg:hidden"
                    aria-label="Open navigation">
                <x-ui.icon name="menu" size="16" />
            </button>

            <div class="hidden min-w-0 sm:block">
                <div class="x-topbar__crumb">
                    @include('partials.breadcrumb')
                </div>
            </div>

            <button type="button" class="x-search-trigger"
                    x-data="{ mac: /Mac|iPod|iPhone|iPad/.test(navigator.platform || navigator.userAgent || '') }"
                    @click="$dispatch('ventasync-open-palette')"
                    aria-label="Search. Opens the command palette.">
                <x-ui.icon name="search" size="14" />
                <span class="x-search-trigger__label">Search products, orders, SKUs</span>
                <kbd class="x-search-trigger__hint" x-text="mac ? '&#8984;K' : 'Ctrl K'"></kbd>
            </button>

            <div class="ml-auto flex shrink-0 items-center gap-4">
                @isset($headerStatus)
                    <div class="hidden sm:block">{{ $headerStatus }}</div>
                @endisset

                <div class="flex items-center gap-px" x-data="{ mode: (window.ventasyncTheme && window.ventasyncTheme.get()) || 'light' }">
                    <button type="button" @click="mode='light'; window.ventasyncTheme.set('light')"
                            :class="mode === 'light' ? 'bg-ink text-paper' : 'text-ink-3 hover:text-ink'"
                            class="grid h-6 w-6 place-items-center rounded-[2px]" aria-label="Light theme">
                        <x-ui.icon name="sun" size="13" />
                    </button>
                    <button type="button" @click="mode='dark'; window.ventasyncTheme.set('dark')"
                            :class="mode === 'dark' ? 'bg-ink text-paper' : 'text-ink-3 hover:text-ink'"
                            class="grid h-6 w-6 place-items-center rounded-[2px]" aria-label="Dark theme">
                        <x-ui.icon name="moon" size="13" />
                    </button>
                    <button type="button" @click="mode='system'; window.ventasyncTheme.set('system')"
                            :class="mode === 'system' ? 'bg-ink text-paper' : 'text-ink-3 hover:text-ink'"
                            class="grid h-6 w-6 place-items-center rounded-[2px]" aria-label="Match system theme">
                        <x-ui.icon name="monitor" size="13" />
                    </button>
                </div>

                @auth
                    @php
                        $blUser = auth()->user();
                        $blName = $blUser->name ?? $blUser->username ?? 'Account';
                    @endphp
                    <div class="x-account" x-data="{ open: false }" @keydown.escape="open = false">
                        <button type="button" @click="open = !open" :aria-expanded="open ? 'true' : 'false'"
                                class="flex items-center gap-2 text-[13px] font-medium">
                            <span class="x-account__avatar">
                                {{ strtoupper(mb_substr($blName, 0, 2)) }}
                            </span>
                            <span class="hidden sm:inline">{{ $blName }}</span>
                        </button>
                        <div x-show="open" x-cloak @click.outside="open = false"
                             class="absolute right-0 z-50 mt-1.5 w-56 border border-rule-2 bg-sheet py-1">
                            <div class="border-b border-rule px-3.5 pb-2 pt-1">
                                <div class="text-[13px] font-semibold">{{ $blName }}</div>
                                @if($blUser->email ?? null)
                                    <div class="text-[12px] text-ink-3">{{ $blUser->email }}</div>
                                @endif
                            </div>
                            <form method="POST" action="{{ route('logout') }}">
                                @csrf
                                <button type="submit"
                                        class="flex w-full items-center gap-2 px-3.5 py-1.5 text-left text-[13px] text-ink-2 hover:bg-chrome-2 hover:text-ink">
                                    <x-ui.icon name="log-out" size="14" />
                                    Log out
                                </button>
                            </form>
                        </div>
                    </div>
                @endauth
            </div>
        </header>

        @yield('shell-band')

        <main id="main" tabindex="-1" class="x-content min-w-0 flex-1 @yield('content-class', '')">
            @yield('main-prelude')
            {{ $slot ?? '' }}
            @yield('content')
        </main>
    </div>
</div>
@include('partials.confirm-modal')
<x-ui.palette />
@stack('scripts')
</body>
</html>
