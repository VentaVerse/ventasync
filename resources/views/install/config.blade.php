@extends('install.layout')

@section('title', 'Site Config')

@section('content')
    <div class="content-header">
        <h1 class="content-title">Site configuration</h1>
        <p class="content-subtitle">Basic info that appears in your ERP and on email notifications. You can change all of this later in Settings.</p>
    </div>

    <div class="content-body">

        @if(session('status'))
            <div class="alert alert-success">{{ session('status') }}</div>
        @endif

        @if($errors->any())
            <div class="alert alert-danger">
                @foreach($errors->all() as $error)
                    <div>{{ $error }}</div>
                @endforeach
            </div>
        @endif

        <form method="POST" action="{{ route('install.config') }}" class="step-form">
            @csrf

            <h3 class="section-heading">Company</h3>
            <div class="form-grid form-grid-2">
                <div class="form-field">
                    <label for="company_name">Company name <span class="required-mark">*</span></label>
                    <input id="company_name" type="text" name="company_name" value="{{ old('company_name') }}" required placeholder="Your Company Name">
                    <div class="hint">Shown in the app header and on emails.</div>
                </div>
                <div class="form-field">
                    <label for="company_email">Company email</label>
                    <input id="company_email" type="email" name="company_email" value="{{ old('company_email') }}" placeholder="hello@yourcompany.com">
                </div>
            </div>

            <div class="form-grid form-grid-2">
                <div class="form-field">
                    <label for="app_url">App URL <span class="required-mark">*</span></label>
                    <input id="app_url" type="url" name="app_url" value="{{ old('app_url', request()->getSchemeAndHttpHost()) }}" required>
                    <div class="hint">The URL where this VentaSync is reached. Used in emails + redirects.</div>
                </div>
                <div class="form-field">
                    <label for="timezone">Timezone <span class="required-mark">*</span></label>
                    <select id="timezone" name="timezone" required>
                        @php
                            $defaultTz = old('timezone', 'Asia/Manila');
                            $zones = [
                                'Asia/Manila' => 'Asia/Manila — Philippines',
                                'Asia/Singapore' => 'Asia/Singapore',
                                'Asia/Tokyo' => 'Asia/Tokyo',
                                'Asia/Jakarta' => 'Asia/Jakarta',
                                'Asia/Bangkok' => 'Asia/Bangkok',
                                'Asia/Hong_Kong' => 'Asia/Hong Kong',
                                'UTC' => 'UTC',
                                'America/Los_Angeles' => 'America/Los Angeles',
                                'America/New_York' => 'America/New York',
                                'Europe/London' => 'Europe/London',
                            ];
                        @endphp
                        @foreach($zones as $tz => $label)
                            <option value="{{ $tz }}" {{ $defaultTz === $tz ? 'selected' : '' }}>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
            </div>

            <h3 class="section-heading">Mail (optional)</h3>
            <p class="hint" style="margin-bottom: 14px; margin-top: -6px;">Configure SMTP so the ERP can send order confirmations, password resets, and PO emails. <strong>Skip this</strong> if you'd rather configure mail later in Settings.</p>

            <div class="form-grid form-grid-2">
                <div class="form-field">
                    <label for="mail_host">SMTP host</label>
                    <input id="mail_host" type="text" name="mail_host" value="{{ old('mail_host') }}" placeholder="smtp.example.com">
                </div>
                <div class="form-field">
                    <label for="mail_port">SMTP port</label>
                    <input id="mail_port" type="number" name="mail_port" value="{{ old('mail_port', 587) }}" min="1" max="65535">
                </div>
            </div>

            <div class="form-grid form-grid-2">
                <div class="form-field">
                    <label for="mail_username">SMTP username</label>
                    <input id="mail_username" type="text" name="mail_username" value="{{ old('mail_username') }}" autocomplete="off">
                </div>
                <div class="form-field">
                    <label for="mail_password">SMTP password</label>
                    <input id="mail_password" type="password" name="mail_password" value="{{ old('mail_password') }}" autocomplete="new-password">
                </div>
            </div>

            <div class="form-grid form-grid-2">
                <div class="form-field">
                    <label for="mail_encryption">Encryption</label>
                    <select id="mail_encryption" name="mail_encryption">
                        <option value="tls" {{ old('mail_encryption', 'tls') === 'tls' ? 'selected' : '' }}>TLS</option>
                        <option value="ssl" {{ old('mail_encryption') === 'ssl' ? 'selected' : '' }}>SSL</option>
                        <option value="" {{ old('mail_encryption') === '' ? 'selected' : '' }}>None</option>
                    </select>
                </div>
                <div class="form-field">
                    <label for="mail_from_address">From email</label>
                    <input id="mail_from_address" type="email" name="mail_from_address" value="{{ old('mail_from_address') }}" placeholder="erp@yourcompany.com">
                </div>
            </div>

            <div class="step-actions step-actions-split">
                <a href="{{ route('install.database') }}" class="btn btn-secondary">Back</a>
                <button type="submit" class="btn btn-primary">
                    Continue
                    <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="margin-left: 6px;">
                        <line x1="5" y1="12" x2="19" y2="12"/>
                        <polyline points="12 5 19 12 12 19"/>
                    </svg>
                </button>
            </div>
        </form>
    </div>
@endsection
