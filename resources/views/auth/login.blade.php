
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
    <title>{{ $setting->company_name ?? 'VentaSync' }} — Login</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg: #0b1120;
            --bg2: #0f172a;
            --text: #e2e8f0;
            --text2: #94a3b8;
            --accent: #3b82f6;
            --accent-hover: #2563eb;
            --danger: #ef4444;
        }
        *, *::before, *::after { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: 'Inter', system-ui, -apple-system, sans-serif;
            background: var(--bg);
            background-image:
                radial-gradient(ellipse 1200px 600px at 20% 10%, rgba(17,28,52,.9) 0%, transparent 70%),
                radial-gradient(ellipse 800px 400px at 80% 80%, rgba(59,130,246,.06) 0%, transparent 70%);
            color: var(--text);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px;
            -webkit-font-smoothing: antialiased;
        }
        .wrap { width: 100%; max-width: 420px; animation: fadeUp .5s cubic-bezier(.4,0,.2,1); }
        @keyframes fadeUp {
            from { opacity: 0; transform: translateY(16px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .brand {
            display: flex;
            flex-direction: column;
            gap: 4px;
            align-items: center;
            text-align: center;
            margin-bottom: 24px;
        }
        .brand-badge {
            width: auto;
            height: auto;
            border-radius: 0;
            background: transparent;
            border: none;
            padding: 0;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .brand h1 { margin: 0; font-size: 20px; font-weight: 700; letter-spacing: -.01em; }
        .brand p { margin: 4px 0 0; color: var(--text2); font-size: 13.5px; }

        .card {
            background: rgba(15, 23, 42, 0.65);
            border: 1px solid rgba(148, 163, 184, 0.12);
            padding: 28px 24px;
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.35);
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
        }

        .field { margin-top: 16px; }
        .field:first-child { margin-top: 0; }
        label { display: block; font-size: 13px; font-weight: 500; color: var(--text2); margin-bottom: 8px; }
        label.required::after { content: " *"; color: var(--danger); font-weight: 700; }

        .input {
            width: 100%;
            height: 44px;
            padding: 0 14px;
            border-radius: 12px;
            border: 1px solid rgba(148, 163, 184, 0.18);
            background: rgba(2, 6, 23, 0.4);
            color: var(--text);
            font-family: inherit;
            font-size: 14px;
            outline: none;
            transition: border-color 200ms ease, box-shadow 200ms ease;
        }
        .input:focus {
            border-color: rgba(59, 130, 246, 0.7);
            box-shadow: 0 0 0 4px rgba(59, 130, 246, 0.15);
        }
        .input::placeholder { color: rgba(148, 163, 184, 0.5); }

        .row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-top: 16px;
            gap: 12px;
        }
        .muted-link {
            color: var(--text2);
            text-decoration: none;
            font-size: 13px;
            transition: color 150ms ease;
        }
        .muted-link:hover { color: var(--text); }

        .btn {
            width: 100%;
            height: 44px;
            margin-top: 20px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: var(--accent);
            color: #fff;
            padding: 0 16px;
            text-decoration: none;
            border-radius: 12px;
            border: none;
            cursor: pointer;
            font-family: inherit;
            font-size: 14px;
            font-weight: 600;
            transition: background 150ms ease, box-shadow 150ms ease, transform 150ms ease;
        }
        .btn:hover {
            background: var(--accent-hover);
            box-shadow: 0 4px 16px rgba(59,130,246,.3);
            transform: translateY(-1px);
        }
        .btn:active { transform: translateY(0) scale(.98); }

        .error {
            margin-top: 12px;
            background: rgba(239, 68, 68, 0.1);
            border: 1px solid rgba(239, 68, 68, 0.25);
            padding: 12px 14px;
            border-radius: 12px;
            color: #fecaca;
            font-size: 13px;
            animation: fadeUp .3s ease;
        }
        .error.success-msg {
            border-color: rgba(34,197,94,0.3);
            background: rgba(34,197,94,0.08);
            color: #bbf7d0;
        }

        .footer-note {
            margin-top: 16px;
            color: rgba(148, 163, 184, 0.6);
            font-size: 12px;
            text-align: center;
        }

        .checkbox-label {
            display: flex;
            align-items: center;
            gap: 8px;
            margin: 0;
            color: var(--text2);
            font-size: 13px;
            cursor: pointer;
        }
        .checkbox-label input { accent-color: var(--accent); cursor: pointer; }

        @media (max-width: 480px) {
            .card { padding: 22px 18px; }
            .brand h1 { font-size: 18px; }
        }
    </style>
</head>
<body>
<div class="wrap">
    <div class="brand">
        <div class="brand-badge">
            <img
                src="{{ !empty($setting->logo_path) ? asset('storage/'.$setting->logo_path) : asset('images/brand/ventasync-legacy.png') }}"
                alt="{{ $setting->company_name ?? 'VentaSync' }}"
                style="height:130px;width:auto;object-fit:contain;"
            >
        </div>
        <p style="margin:0;">{{ $setting->company_name ?? 'VentaSync' }}</p>
    </div>

    <div class="card">
        @if ($errors->any())
            <div class="error">
                {{ $errors->first() }}
            </div>
        @endif

        @if (session('status'))
            <div class="error success-msg">
                {{ session('status') }}
            </div>
        @endif

        <form method="POST" action="{{ route('login') }}">
            @csrf

            <div class="field">
                <label class="required">Username</label>
                <input class="input" type="text" name="username" value="{{ old('username') }}" required autofocus autocomplete="username" placeholder="Enter your username">
            </div>

            <div class="field">
                <label class="required">Password</label>
                <input class="input" type="password" name="password" required autocomplete="current-password" placeholder="Enter your password">
            </div>

            <div class="row">
                <label class="checkbox-label">
                    <input type="checkbox" name="remember">
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