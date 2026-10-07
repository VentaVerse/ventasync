<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    @php
        $__companyName = $appSetting->company_name ?? 'VentaSync';
        $__faviconUrl = ($appSetting->favicon_path ?? null)
            ? asset('storage/' . $appSetting->favicon_path)
            : asset('images/brand/ventasync-icon.png');
    @endphp
    <title>@yield('title', 'VentaSync') - {{ $__companyName }}</title>
    <link rel="icon" type="image/png" href="{{ $__faviconUrl }}">
    <link rel="shortcut icon" href="{{ $__faviconUrl }}">
    <link rel="apple-touch-icon" href="{{ $__faviconUrl }}">
    @include('partials.theme-boot')
    @vite(['resources/css/blotter.css', 'resources/js/app.js'])
    @stack('styles')
</head>
<body class="x-app">
<a class="x-skip" href="#main">Skip to content</a>
<div class="x-standalone">
    <header class="x-standalone__head">
        <span class="x-standalone__brand">{{ $__companyName }}</span>
    </header>

    <main id="main" tabindex="-1" class="x-content x-standalone__body @yield('content-class', '')">
        @include('partials.flash')
        @yield('content')
    </main>
</div>
@include('partials.confirm-modal')
@stack('scripts')
</body>
</html>
