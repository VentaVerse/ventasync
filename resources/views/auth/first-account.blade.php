<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    @php
        $logo = \App\Services\Media\BrandImage::show($appSetting->logo_path ?? null, \App\Services\Media\BrandImage::LOGIN);
        $favicon = !empty($appSetting->favicon_path ?? null)
            ? asset('storage/' . $appSetting->favicon_path)
            : asset('images/brand/ventasync-icon.png');
    @endphp
    <title>Create your account - VentaSync</title>
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
        <h1 class="auth__title">Create your account</h1>

        @if ($errors->any())
            <p class="auth__error">{{ $errors->first() }}</p>
        @endif

        <form class="auth__form" method="POST" action="{{ route('first-account.store') }}">
            @csrf

            <div class="fm-field">
                <label class="fm-label" for="company_name">Business name</label>
                <input class="x-input" id="company_name" type="text" name="company_name"
                       value="{{ old('company_name') }}" autocomplete="organization" autofocus>
            </div>

            <div class="fm-field">
                <label class="fm-label" for="name">Your name</label>
                <input class="x-input" id="name" type="text" name="name"
                       value="{{ old('name') }}" required autocomplete="name">
            </div>

            <div class="auth__pair">
                <div class="fm-field">
                    <label class="fm-label" for="username">Username</label>
                    <input class="x-input" id="username" type="text" name="username"
                           value="{{ old('username') }}" required autocomplete="username">
                </div>
                <div class="fm-field">
                    <label class="fm-label" for="email">Email</label>
                    <input class="x-input" id="email" type="email" name="email"
                           value="{{ old('email') }}" required autocomplete="email">
                </div>
            </div>

            <div class="auth__pair">
                <div class="fm-field">
                    <label class="fm-label" for="password">Password</label>
                    <input class="x-input" id="password" type="password" name="password"
                           required autocomplete="new-password">
                </div>
                <div class="fm-field">
                    <label class="fm-label" for="password_confirmation">Confirm password</label>
                    <input class="x-input" id="password_confirmation" type="password" name="password_confirmation"
                           required autocomplete="new-password">
                </div>
            </div>

            <button class="x-btn x-btn--primary auth__submit" type="submit">Create account</button>
        </form>
    </div>
</div>
</body>
</html>
