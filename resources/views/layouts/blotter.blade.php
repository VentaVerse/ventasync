<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', config('app.name', 'VentaSync'))</title>
    @include('partials.theme-boot')
    @vite(['resources/css/blotter.css', 'resources/js/app.js'])
    @stack('styles')
</head>
{{-- bl-app marks a page as converted to the v2.2 shell, the same way x-app
     marked the shell before it. The suite asserts on it. --}}
<body class="bl-app min-h-screen">
{{-- The bypass block (WCAG 2.4.1). A keyboard user passed 15 to 17 stops
     through the shell before reaching the first control inside the content
     on every page of this application, on every page load. Visible only on
     focus, so it costs the mouse nothing. --}}
<a class="x-skip" href="#main">Skip to content</a>
<!--
    IMPECCABLE DIRECTION CONTRACT (v2.3)
    THESIS: Money and work-in-hand are stated loudly in colour, everything else is a quiet white sheet. Refuses the terminal arrangement v2.2 shipped, where restraint was total and the product read as unfinished from across the room.
    OWN-WORLD: Tinted cool ground; white sheets raised on soft shadow at 16px corners; five saturated identity fills (cyan, violet, amber, green, rose) carrying headline figures in white; navy sidebar; Archivo with tabular figures, JetBrains Mono for identifiers; drawn icons at one stroke weight. State colour (amber/green/red) is separate from identity colour and never appears on a fill.
    STORY: The operator reads today's money and today's queue in one glance, sees which channels are stale, and acts on a row without leaving the page.
    FIRST VIEWPORT: Five filled metric blocks across the top; a row of channel chips under them; then latest orders beside inventory and purchasing. Zero-value blocks drop to white so absence never shouts.
    FORM: Colour-forward admin, pinned by the owner from CoreUI Pro, Purple React and Devias. User-pinned direction, so no concept roll; session seed a9ccf491.
    FINISH: unreviewed and undocumented is unfinished; this build ends with the finish review, the verdict, and DESIGN.md
-->
<div x-data="{ nav: false }" class="lg:grid lg:grid-cols-[216px_minmax(0,1fr)] min-h-screen">

    {{-- Sidebar. Off-canvas below lg, permanent above it. --}}
    <aside @keydown.escape.window="nav = false"
           :class="nav && '!flex'"
           class="fixed inset-y-0 left-0 z-40 hidden w-[216px] flex-col border-r border-side-rule bg-side
                  lg:static lg:flex">
        <div class="flex items-center gap-2.5 border-b border-side-rule px-4 py-3.5">
            {{-- The mark is the only place lime appears. It never carries meaning. --}}
            <span class="grid h-[22px] w-[22px] place-items-center rounded-[--radius-ctrl] bg-lime text-[12px] font-bold tracking-tight text-side">V</span>
            @php $blCompany = $appSetting->company_name ?? config('app.name', 'VentaSync'); @endphp
            <span class="min-w-0 truncate text-[14.5px] font-semibold tracking-tight text-white"
                  title="{{ $blCompany }}">{{ $blCompany }}</span>
            <button type="button" @click="nav = false"
                    class="ml-auto text-side-ink hover:text-white lg:hidden" aria-label="Close navigation">
                <x-ui.icon name="x" size="16" />
            </button>
        </div>

        <div class="flex-1 overflow-y-auto">
            @include('partials.nav-blotter', ['navGroups' => \App\Support\Navigation::groups()])
        </div>

        <div class="flex items-center justify-between gap-2 border-t border-side-rule px-4 py-2.5 text-[12px] text-side-ink">
            <span class="min-w-0 truncate" title="{{ $blCompany }}">{{ $blCompany }}</span>
            <span class="shrink-0">v{{ trim(@file_get_contents(base_path('VERSION'))) ?: '2.2' }}</span>
        </div>
    </aside>

    {{-- Scrim, mobile only. --}}
    <div x-show="nav" x-cloak @click="nav = false"
         class="fixed inset-0 z-30 bg-ink/40 lg:hidden" aria-hidden="true"></div>

    <div class="flex min-w-0 flex-col">
        <header class="flex items-center gap-3.5 border-b border-rule-2 px-gutter py-2.5">
            <button type="button" @click="nav = true"
                    class="grid h-[30px] w-8 place-items-center rounded-[2px] border border-rule-2 bg-sheet lg:hidden"
                    aria-label="Open navigation">
                <x-ui.icon name="menu" size="16" />
            </button>

            {{-- The trail, derived from the URL (App\Support\Breadcrumbs), the
                 same partial the channel workspace carries. The settings pages
                 had no way back to Settings but the sidebar (live fix
                 2026-08-26). --}}
            <div class="hidden min-w-0 sm:block">
                @include('partials.breadcrumb')
            </div>

            {{-- Opens the command palette, which owns search for the whole
                 application. Cmd+K and Ctrl+K reach the same component; a
                 keyboard shortcut nobody can see is not discoverable, so the
                 control states it. --}}
            <button type="button" class="x-search-trigger"
                    x-data="{ mac: /Mac|iPod|iPhone|iPad/.test(navigator.platform || navigator.userAgent || '') }"
                    @click="$dispatch('xenon-open-palette')"
                    aria-label="Search. Opens the command palette.">
                <x-ui.icon name="search" size="14" />
                <span class="x-search-trigger__label">Search products, orders, SKUs</span>
                <kbd class="x-search-trigger__hint" x-text="mac ? '&#8984;K' : 'Ctrl K'"></kbd>
            </button>

            {{-- shrink-0 on the cluster and min-w-0 on the search beside it.
                 Without them the search field refused to give up width at
                 390px and pushed the theme switch and the account menu 67px
                 past the right edge of the window - the same overflow the
                 channel topbar had, in the layout that was not touched when
                 that one was fixed. --}}
            <div class="ml-auto flex shrink-0 items-center gap-4">
                @isset($headerStatus)
                    <div class="hidden sm:block">{{ $headerStatus }}</div>
                @endisset

                <div class="flex items-center gap-px" x-data="{ mode: (window.xenonTheme && window.xenonTheme.get()) || 'light' }">
                    <button type="button" @click="mode='light'; window.xenonTheme.set('light')"
                            :class="mode === 'light' ? 'bg-ink text-paper' : 'text-ink-3 hover:text-ink'"
                            class="grid h-6 w-6 place-items-center rounded-[2px]" aria-label="Light theme">
                        <x-ui.icon name="sun" size="13" />
                    </button>
                    <button type="button" @click="mode='dark'; window.xenonTheme.set('dark')"
                            :class="mode === 'dark' ? 'bg-ink text-paper' : 'text-ink-3 hover:text-ink'"
                            class="grid h-6 w-6 place-items-center rounded-[2px]" aria-label="Dark theme">
                        <x-ui.icon name="moon" size="13" />
                    </button>
                    <button type="button" @click="mode='system'; window.xenonTheme.set('system')"
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

        {{-- x-content names the content region, as it did on the shell before
             this one. It is not a style hook here: the suite slices the page
             on it to prove a panel rendered inside the content area rather
             than anywhere else on the document. --}}
        <main id="main" tabindex="-1" class="x-content min-w-0 flex-1">
            {{ $slot ?? '' }}
            @yield('content')
        </main>
    </div>
</div>
{{-- The project bans native confirm()/alert(); every destructive control uses
     the data-confirm attribute this partial listens for, so it must ship on
     any layout that renders one. --}}
@include('partials.confirm-modal')
<x-ui.palette />
@stack('scripts')
</body>
</html>
