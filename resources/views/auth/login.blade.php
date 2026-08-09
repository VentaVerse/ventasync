<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    @php
        // AppServiceProvider shares $appSetting with every view; the login
        // controller's own $setting is not always populated, and
        // config('app.name') is still the scaffold default on a fresh
        // install. Prefer the record the rest of the app reads.
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
    <title>Sign in - {{ $company }}</title>
    <link rel="icon" type="image/png" href="{{ $favicon }}">
    @include('partials.theme-boot')
    @vite(['resources/css/blotter.css'])
</head>
<body class="bl-app">
<div class="auth">
    <div class="auth__brand">
        <img class="auth__logo" src="{{ $logo }}" alt="{{ $company }}">
        <p class="auth__name">{{ $company }}</p>
    </div>

    <div class="auth__card">
        <h1 class="auth__title">Sign in</h1>

        {{-- One message, naming the problem. The controller returns a single
             failure for a bad username or a bad password deliberately, so the
             form never confirms which half was right. --}}
        @if ($errors->any())
            <p class="auth__error">{{ $errors->first() }}</p>
        @endif

        @if (session('status'))
            <p class="auth__status">{{ session('status') }}</p>
        @endif

        <form class="auth__form" method="POST" action="{{ route('login') }}">
            @csrf

            <div class="fm-field">
                <label class="fm-label" for="username">
                    Username
                    <span class="fm-req">Required</span>
                </label>
                <input class="x-input" id="username" type="text" name="username"
                       value="{{ old('username') }}" required autofocus
                       autocomplete="username" placeholder="Your assigned username">
            </div>

            <div class="fm-field">
                <label class="fm-label" for="password">
                    Password
                    <span class="fm-req">Required</span>
                </label>
                <input class="x-input" id="password" type="password" name="password"
                       required autocomplete="current-password" placeholder="Your password">
            </div>

            <div class="auth__row">
                <label class="auth__check">
                    <input type="checkbox" name="remember">
                    Remember me
                </label>
                <a class="auth__link" href="{{ route('password.request') }}">Forgot password?</a>
            </div>

            <button class="x-btn x-btn--primary auth__submit" type="submit">Sign in</button>
        </form>

        <p class="auth__note">Use your assigned username, not your email address.</p>
    </div>
</div>
</body>
</html>
