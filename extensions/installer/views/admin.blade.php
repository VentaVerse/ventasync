@extends('ext-installer::layout')

@section('title', 'Admin Account')

@section('content')
    <div class="content-header">
        <h1 class="content-title">Create your admin account</h1>
        <p class="content-subtitle">This is the first user account with full access to your VentaSync install. Add more users (with custom roles) from Settings → Users after install.</p>
    </div>

    <div class="content-body">

        @if($errors->any())
            <div class="alert alert-danger">
                @foreach($errors->all() as $error)
                    <div>{{ $error }}</div>
                @endforeach
            </div>
        @endif

        <form method="POST" action="{{ route('install.admin') }}" class="step-form" autocomplete="off">
            @csrf

            <div class="form-grid form-grid-2">
                <div class="form-field">
                    <label for="adm_name">Full name <span class="required-mark">*</span></label>
                    <input id="adm_name" type="text" name="name" value="{{ old('name') }}" required>
                </div>
                <div class="form-field">
                    <label for="adm_username">Username <span class="required-mark">*</span></label>
                    <input id="adm_username" type="text" name="username" value="{{ old('username') }}" required pattern="[a-zA-Z0-9_.\-]+" minlength="3" maxlength="64">
                    <div class="hint">Letters, numbers, dots, hyphens, underscores. Used for login.</div>
                </div>
            </div>

            <div class="form-field">
                <label for="adm_email">Email <span class="required-mark">*</span></label>
                <input id="adm_email" type="email" name="email" value="{{ old('email') }}" required>
            </div>

            <div class="form-grid form-grid-2">
                <div class="form-field">
                    <label for="adm_password">Password <span class="required-mark">*</span></label>
                    <input id="adm_password" type="password" name="password" required minlength="8" autocomplete="new-password">
                    <div class="hint">Minimum 8 characters.</div>
                </div>
                <div class="form-field">
                    <label for="adm_password_confirmation">Confirm password <span class="required-mark">*</span></label>
                    <input id="adm_password_confirmation" type="password" name="password_confirmation" required autocomplete="new-password">
                </div>
            </div>

            <div class="alert alert-info">
                <strong>Save these credentials.</strong> You'll log in with the username + password above. There's no password reset until your SMTP is configured (Settings → Mail).
            </div>

            <div class="step-actions step-actions-split">
                <a href="{{ route('install.config') }}" class="btn btn-secondary">Back</a>
                <button type="submit" class="btn btn-primary">
                    Create admin &amp; finish
                    <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="margin-left: 6px;">
                        <line x1="5" y1="12" x2="19" y2="12"/>
                        <polyline points="12 5 19 12 12 19"/>
                    </svg>
                </button>
            </div>
        </form>
    </div>
@endsection
