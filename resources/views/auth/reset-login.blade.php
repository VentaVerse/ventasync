<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="referrer" content="no-referrer">
    @php
        $logo = \App\Services\Media\LogoImage::show($appSetting->logo_path ?? null, \App\Services\Media\LogoImage::LOGIN);
        $favicon = !empty($appSetting->favicon_path ?? null)
            ? asset('storage/' . $appSetting->favicon_path)
            : asset('images/brand/ventasync-icon.png');
    @endphp
    <title>Reset your sign-in - VentaSync</title>
    <link rel="icon" type="image/png" href="{{ $favicon }}">
    @include('partials.theme-boot')
    @vite(['resources/css/blotter.css'])
</head>
<body class="bl-app">
<div class="auth">
    <div class="auth__brand">
        <img class="auth__logo" src="{{ $logo['url'] }}"
             width="{{ $logo['width'] }}" height="{{ $logo['height'] }}" alt="VentaSync">
    </div>

    <div class="auth__card">
        <h1 class="auth__title">Reset your sign-in</h1>

        @if ($expired)
            <p class="auth__error">This link has expired or was already used.</p>
            <a class="auth__link" href="{{ route('login') }}">Sign in</a>
        @else
            @if ($errors->any())
                <p class="auth__error">{{ $errors->first() }}</p>
            @endif

            <form class="auth__form" method="POST" action="{{ request()->getRequestUri() }}">
                @csrf

                <div class="fm-field">
                    <label class="fm-label" for="username">Username</label>
                    <input class="x-input" id="username" type="text" name="username"
                           value="{{ old('username', $user->username) }}" required autocomplete="username">
                </div>

                <div class="auth__pair">
                    <div class="fm-field">
                        <label class="fm-label" for="password">New password</label>
                        <input class="x-input" id="password" type="password" name="password"
                               required autocomplete="new-password" autofocus>
                    </div>
                    <div class="fm-field">
                        <label class="fm-label" for="password_confirmation">Confirm password</label>
                        <input class="x-input" id="password_confirmation" type="password" name="password_confirmation"
                               required autocomplete="new-password">
                    </div>
                </div>

                <button class="x-btn x-btn--primary auth__submit" type="submit">Save</button>
            </form>
        @endif
    </div>
</div>
</body>
</html>
