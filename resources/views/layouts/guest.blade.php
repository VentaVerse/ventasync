<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    @php
        $company = $appSetting->company_name
            ?? ($setting->company_name ?? null)
            ?: 'VentaSync';
        $logo = !empty($appSetting->logo_path ?? null)
            ? asset('storage/' . $appSetting->logo_path)
            : asset('images/brand/ventasync.png');
        $favicon = !empty($appSetting->favicon_path ?? null)
            ? asset('storage/' . $appSetting->favicon_path)
            : asset('images/brand/ventasync-icon.png');
    @endphp
    <title>{{ $company }}</title>
    <link rel="icon" type="image/png" href="{{ $favicon }}">
    @include('partials.theme-boot')
    @vite(['resources/css/blotter.css', 'resources/js/app.js'])
</head>
<body class="bl-app">
<div class="auth">
    <a class="auth__brand" href="/">
        <img class="auth__logo" src="{{ $logo }}" alt="{{ $company }}">
        <p class="auth__name">{{ $company }}</p>
    </a>

    <div class="auth__card">
        {{ $slot }}
    </div>
</div>
</body>
</html>
