{{-- Standalone surface: a page reached from outside the application, with no
     sidebar, no workspace and no assumption of a session.

     Round F Task 1 created this for exactly one page, and stated the rule it
     exists for: `extensions/lazada/views/callback.blade.php`.

     Why it cannot use one of the three layouts that already exist:

       - layouts/channel.blade.php resolves a channel workspace from the route
         NAME and abort(404)s when it cannot. The Lazada OAuth callback is
         registered as `lazada.callback`, not `ext.lazada.*`, because that
         exact string is in the Lazada Open Platform console and is compared
         byte for byte on every consent round trip. It also carries no `auth`
         middleware on purpose, so the answer from Lazada is not lost behind a
         login redirect. Neither of those can change.
       - layouts/shell.blade.php draws the application sidebar, which is a set
         of destinations a signed-out visitor cannot reach.
       - layouts/app.blade.php is what this page used to extend, and Round F
         Task 4 deleted it.

     So: the same tokens, the same fonts, the same theme boot and the same
     bundle as everything else, in a single centred column with nothing in the
     chrome to click. If another external landing surface appears (a payment
     return, a license activation handoff), this is its layout. --}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    @php
        // The same fallbacks the other layouts use, reading the $appSetting
        // AppServiceProvider already shares with every view rather than
        // issuing a second query for the same row. Null-safe throughout,
        // because this layout can render before anyone has signed in.
        $__companyName = $appSetting->company_name ?? 'VentaSync';
        $__faviconUrl = ($appSetting->favicon_path ?? null)
            ? asset('storage/' . $appSetting->favicon_path)
            : asset('images/brand/ventasync-icon.png');
    @endphp
    <title>@yield('title', 'Xenon') - {{ $__companyName }}</title>
    <link rel="icon" type="image/png" href="{{ $__faviconUrl }}">
    <link rel="shortcut icon" href="{{ $__faviconUrl }}">
    <link rel="apple-touch-icon" href="{{ $__faviconUrl }}">
    @include('partials.theme-boot')
    {{-- blotter.css, not app.css. The note above says this layout gives its
         page "the same tokens, the same fonts, the same theme boot and the
         same bundle as everything else" - and it was loading the legacy
         bundle, which is not what everything else loads and has not been
         since the blotter shell arrived. This page's own markup is od-*,
         which the blotter bundle styles. It was also the last view keeping
         resources/css/app.css alive. --}}
    @vite(['resources/css/blotter.css', 'resources/js/app.js'])
    @stack('styles')
</head>
<body class="x-app">
<div class="x-standalone">
    <header class="x-standalone__head">
        <span class="x-standalone__brand">{{ $__companyName }}</span>
    </header>

    <main class="x-content x-standalone__body @yield('content-class', '')">
        {{-- Same flash contract every other converted page has. A page that
             cannot show a redirect message is a page that silently loses
             one. --}}
        @include('partials.flash')
        @yield('content')
    </main>
</div>
@include('partials.confirm-modal')
@stack('scripts')
</body>
</html>
