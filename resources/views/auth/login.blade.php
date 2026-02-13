
@php
    $setting = null;
    try {
        $setting = \App\Models\Setting::singleton();
    } catch (\Throwable $e) {
        $setting = (object)[];
    }
@endphp

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Xenon Suite - Login</title>
    <style>
        :root {
            --sidebar-w: 240px;
            --bg: #0b1220;
            --bg2: #0f172a;
            --text: #e2e8f0;
            --text2: #94a3b8;
            --accent: #2563eb;
            --danger: #ef4444;
        }
        * { box-sizing: border-box; }
        body {
            margin:0;
            font-family: Arial, sans-serif;
            background: radial-gradient(1200px 600px at 20% 10%, #111c34 0%, #0b1220 40%, #070b14 100%);
            color: var(--text);
            min-height: 100vh;
            display:flex;
            align-items:center;
            justify-content:center;
            padding: 24px;
        }
        .wrap { width: 100%; max-width: 420px; }
        .brand {
            display:flex;
            flex-direction: column;
            gap:14px;
            align-items:center;
            text-align:center;
            margin-bottom: 18px;
        }
        /* Logo container: keep transparent (PNG alpha stays alpha) and don't constrain logo size */
        .brand-badge {
            width: auto;
            height: auto;
            border-radius: 0;
            background: transparent;
            border: none;
            padding: 0;
            display:flex;
            align-items:center;
            justify-content:center;
        }
        .brand h1 { margin:0; font-size: 18px; letter-spacing: 0.2px; }
        .brand p { margin:2px 0 0; color: var(--text2); font-size: 13px; }

        .card {
            background: rgba(15, 23, 42, 0.70);
            border: 1px solid rgba(148, 163, 184, 0.18);
            padding: 18px;
            border-radius: 16px;
            box-shadow: 0 16px 50px rgba(0,0,0,0.35);
            backdrop-filter: blur(10px);
        }

        .field { margin-top: 12px; }
        label { display:block; font-size: 13px; color: var(--text2); margin-bottom: 6px; }
        label.required::after { content: " *"; color: var(--danger); font-weight: 700; }

        .input {
            width: 100%;
            padding: 10px 12px;
            border-radius: 12px;
            border: 1px solid rgba(148, 163, 184, 0.25);
            background: rgba(2, 6, 23, 0.35);
            color: var(--text);
            outline: none;
        }
        .input:focus {
            border-color: rgba(37, 99, 235, 0.8);
            box-shadow: 0 0 0 4px rgba(37, 99, 235, 0.20);
        }
        .row {
            display:flex;
            align-items:center;
            justify-content:space-between;
            margin-top: 12px;
            gap: 12px;
        }
        .muted-link {
            color: var(--text2);
            text-decoration:none;
            font-size: 13px;
        }
        .muted-link:hover { color: var(--text); text-decoration: underline; }

        .btn {
            width: 100%;
            margin-top: 14px;
            display:inline-block;
            background: var(--accent);
            color:#fff;
            padding: 10px 12px;
            text-decoration:none;
            border-radius: 12px;
            border: none;
            cursor: pointer;
            font-weight: 700;
        }
        .btn:hover { filter: brightness(1.05); }

        .error {
            margin-top: 10px;
            background: rgba(239, 68, 68, 0.14);
            border: 1px solid rgba(239, 68, 68, 0.35);
            padding: 10px 12px;
            border-radius: 12px;
            color: #fecaca;
            font-size: 13px;
        }

        .footer-note {
            margin-top: 14px;
            color: rgba(148, 163, 184, 0.75);
            font-size: 12px;
            text-align:center;
        }
    /* PATCH: remove square background behind logo */
.brand-badge {
    background: transparent !important;
    background-color: transparent !important;
    box-shadow: none !important;
    border: none !important;
    outline: none !important;
}
.brand-badge::before,
.brand-badge::after {
    content: none !important;
    background: transparent !important;
    box-shadow: none !important;
}
</style>
</head>
<body>
<div class="wrap">
    

    <div class="brand">
                <div class="brand-badge">
            <img
                src="{{ !empty($setting->software_logo_path) ? asset('storage/'.$setting->software_logo_path) : asset('images/brand/xenon-suite.svg') }}"
                alt="{{ $setting->company_name ?? 'Xenon Suite' }}"
                style="height:130px;width:auto;object-fit:contain;background:transparent;border:none;box-shadow:none;"
                class="img-fluid"
            >
        </div>
        <div>
            <h1>Sign in</h1>
            <p>{{ $setting->company_name ?? 'Xenon Suite' }}</p>
        </div>
    </div>

    <div class="card">
        @if ($errors->any())
            <div class="error">
                {{ $errors->first() }}
            </div>
        @endif

        @if (session('status'))
            <div class="error" style="border-color: rgba(34,197,94,0.35); background: rgba(34,197,94,0.12); color:#bbf7d0;">
                {{ session('status') }}
            </div>
        @endif

        <form method="POST" action="{{ route('login') }}">
            @csrf

            <div class="field">
                <label class="required">Username</label>
                <input class="input" type="text" name="username" value="{{ old('username') }}" required autofocus autocomplete="username">
            </div>

            <div class="field">
                <label class="required">Password</label>
                <input class="input" type="password" name="password" required autocomplete="current-password">
            </div>

            <div class="row">
                <label style="display:flex;align-items:center;gap:8px;margin:0;color:var(--text2);font-size:13px;">
                    <input type="checkbox" name="remember" style="accent-color: var(--accent);">
                    Remember me
                </label>
                <a class="muted-link" href="{{ route('password.request') }}">Forgot password?</a>
            </div>

            <button class="btn" type="submit">Sign in</button>
        </form>

        <div class="footer-note">
            Use your assigned username to login.
        </div>
    </div>
</div>
</body>
</html>