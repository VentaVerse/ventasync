@extends('layouts.app')
@section('breadcrumb', 'Settings')

@section('content')
@php
    // Phase 4.5: read-tier (view_website_settings) can browse the settings
    // form but cannot save. All write controls (Save buttons, Test Mail)
    // are hidden — inputs render but become read-only-ish via the missing
    // submit. The underlying routes still 403 if probed.
    $canManageSettings = true;
@endphp
<div class="page-header">
    <h2>Settings</h2>
    <a class="btn secondary" href="{{ route('dashboard') }}">Back</a>
</div>
@unless($canManageSettings)
<div class="alert" style="background:#fef3c7; color:#92400e; border:1px solid #fcd34d; padding:10px 14px; border-radius:var(--radius-md); margin-bottom:16px; font-size:13px;">
    <strong>Read-only view.</strong> You can browse settings. To save changes or send test mail, your user group needs the <code>manage_website_settings</code> permission tier.
</div>
@endunless

{{-- Tabs --}}
<div class="tabs mb-16">
    <button class="tab active" data-tab="tab-general">General</button>
    <button class="tab" data-tab="tab-website">Website Settings</button>
    <button class="tab" data-tab="tab-mail">Mail</button>
    <button class="tab" data-tab="tab-maintenance">Maintenance</button>
</div>

<form method="POST" action="{{ route('settings.update') }}" enctype="multipart/form-data">
    @csrf

    {{-- General Tab --}}
    <div class="" id="tab-general">
        <div class="card">
            <h3 class="section-title mt-0">Company Information</h3>
            <div class="form-grid">
                <div>
                    <label class="required">Company Name</label>
                    <input class="input" name="company_name" value="{{ old('company_name', $setting->company_name) }}">
                </div>

                <div>
                    <label>Email</label>
                    <input class="input" type="email" name="company_email" value="{{ old('company_email', $setting->company_email) }}" maxlength="255" placeholder="Company contact email">
                </div>

                <div>
                    <label>Phone</label>
                    <input class="input" name="phone" value="{{ old('phone', $setting->phone) }}" maxlength="32">
                </div>
            </div>

            <h3 class="section-title mt-24">Address</h3>
            <div class="form-grid">
                <div class="full">
                    <label>Address Line 1</label>
                    <input class="input" name="address_line1" value="{{ old('address_line1', $setting->address_line1) }}" maxlength="255">
                </div>

                <div class="full">
                    <label>Address Line 2</label>
                    <input class="input" name="address_line2" value="{{ old('address_line2', $setting->address_line2) }}" maxlength="255">
                </div>

                <div>
                    <label>City</label>
                    <input class="input" name="city" value="{{ old('city', $setting->city) }}" maxlength="128">
                </div>

                <div>
                    <label>State / Province</label>
                    <input class="input" name="state" value="{{ old('state', $setting->state) }}" maxlength="128">
                </div>

                <div>
                    <label>Postal Code</label>
                    <input class="input" name="postal_code" value="{{ old('postal_code', $setting->postal_code) }}" maxlength="20">
                </div>

                <div>
                    <label>Country</label>
                    <input class="input" name="country" value="{{ old('country', $setting->country) }}" maxlength="128">
                </div>
            </div>

            @if($canManageSettings)
            <div class="d-flex justify-end mt-16">
                <button class="btn" type="submit">Save Settings</button>
            </div>
            @endif
        </div>
    </div>

    {{-- Website Settings Tab --}}
    <div class="hidden" id="tab-website">
        <div class="card">
            <h3 class="section-title mt-0">Email & System</h3>
            <div class="form-grid">
                <div class="full">
                    <label class="required">From Email</label>
                    <input class="input" type="email" name="from_email" value="{{ old('from_email', $setting->from_email) }}">
                </div>

                <div class="full">
                    <label class="required">Timezone</label>
                    <select name="timezone" class="input">
                        @foreach(timezone_identifiers_list() as $tz)
                            <option value="{{ $tz }}" {{ old('timezone', $setting->timezone ?? 'Asia/Manila') === $tz ? 'selected' : '' }}>{{ $tz }}</option>
                        @endforeach
                    </select>
                    <div class="hint">Used for displaying dates and times throughout the system.</div>
                </div>

                <div class="full">
                    <label class="required">Activity Log Retention (days)</label>
                    <input class="input" type="number" name="activity_log_retention_days"
                           value="{{ old('activity_log_retention_days', $setting->activity_log_retention_days ?? 90) }}"
                           min="7" max="3650" style="width:160px;">
                    <div class="hint">Logs older than this many days will be cleared when using "Clear Old Logs" or via the scheduled cleanup.</div>
                </div>
            </div>

            <h3 class="section-title mt-24">Branding</h3>
            <div class="form-grid">
                <div class="full">
                    <label>Logo</label>
                    <input class="input" type="file" name="logo" accept="image/*" onchange="previewLogo(this, 'logo-preview')">
                    <div class="hint">Shown in the sidebar and on the login screen. If not uploaded, the default VentaSync logo is used.</div>
                    <div class="mt-12" id="logo-preview">
                        <div class="text-xs text-muted" style="margin-bottom:6px;">{{ $setting->logo_path ? 'Current logo:' : 'Default logo:' }}</div>
                        <img src="{{ $setting->logo_path ? asset('storage/'.$setting->logo_path) : asset('images/brand/ventasync-legacy.png') }}" alt="Logo" style="max-height:140px; border-radius:var(--radius-sm);" id="logo-preview-img">
                    </div>
                </div>
            </div>

            @if($canManageSettings)
            <div class="d-flex justify-end mt-16">
                <button class="btn" type="submit">Save Settings</button>
            </div>
            @endif
        </div>
    </div>

    {{-- Mail Tab --}}
    <div class="hidden" id="tab-mail">
        <div class="card">
            <h3 class="section-title mt-0">Mail Transport</h3>
            <div class="form-grid">
                <div class="full">
                    <label class="required">Mailer</label>
                    <select name="mail_mailer" class="input" id="mailMailer">
                        <option value="sendmail" {{ old('mail_mailer', $setting->mail_mailer ?? 'sendmail') === 'sendmail' ? 'selected' : '' }}>PHP</option>
                        <option value="smtp" {{ old('mail_mailer', $setting->mail_mailer ?? 'sendmail') === 'smtp' ? 'selected' : '' }}>SMTP</option>
                    </select>
                    <div class="hint">PHP uses the server's local mail binary. SMTP is recommended for higher deliverability and inbox placement.</div>
                </div>
            </div>

            <div id="smtpFields" class="form-grid mt-16">
                <div>
                    <label>SMTP Host</label>
                    <input class="input" type="text" name="mail_host" value="{{ old('mail_host', $setting->mail_host) }}" placeholder="smtp.example.com">
                </div>
                <div>
                    <label>SMTP Port</label>
                    <input class="input" type="number" name="mail_port" value="{{ old('mail_port', $setting->mail_port) }}" placeholder="587" min="1" max="65535">
                </div>
                <div>
                    <label>Username</label>
                    <input class="input" type="text" name="mail_username" value="{{ old('mail_username', $setting->mail_username) }}" autocomplete="off">
                </div>
                <div>
                    <label>Password</label>
                    <input class="input" type="password" name="mail_password" value="" placeholder="{{ $setting->mail_password ? '•••••••• (leave blank to keep)' : '' }}" autocomplete="new-password">
                    @if($setting->mail_password)
                        <label class="text-xs text-muted mt-8 d-flex items-center gap-8">
                            <input type="checkbox" name="clear_mail_password" value="1"> Clear stored password
                        </label>
                    @endif
                </div>
                <div>
                    <label>Encryption</label>
                    <select name="mail_encryption" class="input">
                        <option value="" {{ old('mail_encryption', $setting->mail_encryption) === null || old('mail_encryption', $setting->mail_encryption) === '' ? 'selected' : '' }}>None</option>
                        <option value="tls" {{ old('mail_encryption', $setting->mail_encryption) === 'tls' ? 'selected' : '' }}>TLS</option>
                        <option value="ssl" {{ old('mail_encryption', $setting->mail_encryption) === 'ssl' ? 'selected' : '' }}>SSL</option>
                    </select>
                </div>
            </div>

            <h3 class="section-title mt-24">From</h3>
            <div class="form-grid">
                <div>
                    <label>From Address</label>
                    <input class="input" type="email" name="mail_from_address" value="{{ old('mail_from_address', $setting->mail_from_address) }}" placeholder="no-reply@example.com">
                </div>
                <div>
                    <label>From Name</label>
                    <input class="input" type="text" name="mail_from_name" value="{{ old('mail_from_name', $setting->mail_from_name) }}" placeholder="{{ config('app.name') }}">
                </div>
            </div>

            @if($canManageSettings)
            <div class="d-flex justify-end mt-16">
                <button class="btn" type="submit">Save Settings</button>
            </div>
            @endif
        </div>

        @if($canManageSettings)
        <div class="card mt-16">
            <h3 class="section-title mt-0">Send Test Email</h3>
            <div class="hint" style="margin-bottom:12px;">
                Save your settings first. Then send a quick test message to verify the configuration.
            </div>
            <div class="form-grid">
                <div class="full">
                    <label>Send To</label>
                    <div class="d-flex items-center gap-12">
                        <input class="input" type="email" id="testMailTo" value="{{ auth()->user()->email ?? '' }}" placeholder="you@example.com" style="max-width:380px;">
                        <button type="button" class="btn" id="btnSendTestMail">Send test email</button>
                    </div>
                    <div id="testMailResult" style="margin-top:12px; display:none;"></div>
                </div>
            </div>
        </div>
        @endif
    </div>
</form>

{{-- Maintenance Tab --}}
<div class="hidden" id="tab-maintenance">
    <div class="card">
        <h3 class="section-title mt-0">Scheduled Tasks (Cron)</h3>
        <div class="hint" style="margin-bottom:12px;">
            One OS-level cron entry drives every scheduled task across this app. Add the line below
            to your server's crontab; per-job cadence is then editable from each integration's
            <strong>Automations</strong> tab — no need to touch the system crontab again.
        </div>
        @php
            $cronLine = '* * * * * /usr/bin/php ' . base_path('artisan') . ' schedule:run >> /dev/null 2>&1';
        @endphp
        <div style="display:flex; align-items:center; gap:8px; background:#0f172a; border:1px solid #334155; border-radius:var(--radius-sm); padding:10px 12px; overflow-x:auto;">
            <code id="ws-global-cron" style="flex:1; font-family:ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace; font-size:12.5px; color:#e2e8f0; white-space:nowrap; user-select:all;">{{ $cronLine }}</code>
            <button type="button" class="btn small secondary" onclick="(function(b){var c=document.getElementById('ws-global-cron');navigator.clipboard.writeText(c.innerText);var o=b.innerText;b.innerText='Copied';setTimeout(function(){b.innerText=o;},1200);})(this)" style="flex-shrink:0; height:28px; padding:0 10px; font-size:11px;">Copy</button>
        </div>
        <details style="margin-top:10px;">
            <summary style="cursor:pointer; font-size:12.5px; font-weight:600; color:var(--text-secondary); user-select:none;">How to set this up</summary>
            <ol style="margin:10px 0 0; padding-left:20px; font-size:13px; color:var(--text-secondary); line-height:1.8;">
                <li>Open your hosting panel (HestiaCP, cPanel, etc.) and find <strong>Cron Jobs</strong> / <strong>Scheduled Tasks</strong>.</li>
                <li>Paste the command above. Set the interval to every minute (<code>* * * * *</code>).</li>
                <li>Save. From now on, every job listed in each integration's <strong>Automations</strong> tab runs at its configured cadence.</li>
            </ol>
        </details>
    </div>

</div>

@push('scripts')
<script>
    // Tab switching with URL sync (?tab=...)
    var settingsTabs = ['tab-general', 'tab-website', 'tab-mail', 'tab-maintenance'];

    function activateSettingsTab(id) {
        if (settingsTabs.indexOf(id) === -1) id = settingsTabs[0];
        document.querySelectorAll('.tabs .tab').forEach(function(t) {
            t.classList.toggle('active', t.dataset.tab === id);
        });
        settingsTabs.forEach(function(tabId) {
            var el = document.getElementById(tabId);
            if (el) el.classList.toggle('hidden', tabId !== id);
        });
    }

    document.querySelectorAll('.tabs .tab').forEach(function(tab) {
        tab.addEventListener('click', function() {
            var id = tab.dataset.tab;
            activateSettingsTab(id);
            var slug = id.replace(/^tab-/, '');
            var url = new URL(window.location.href);
            url.searchParams.set('tab', slug);
            history.replaceState({}, '', url.toString());
        });
    });

    // On load: pick tab from ?tab=... query param (defaults to general)
    (function() {
        var requested = new URLSearchParams(window.location.search).get('tab');
        if (requested) {
            activateSettingsTab('tab-' + requested);
        }
    })();

    function previewLogo(input, previewId) {
        var wrap = document.getElementById(previewId);
        var img = wrap.querySelector('img');
        var label = wrap.querySelector('.text-xs');

        if (input.files && input.files[0]) {
            var reader = new FileReader();
            reader.onload = function(e) {
                img.src = e.target.result;
                wrap.style.display = '';
                if (label) label.textContent = 'Preview:';
            };
            reader.readAsDataURL(input.files[0]);
        }
    }

    // Toggle SMTP fields visibility based on mailer dropdown
    (function() {
        var mailerSel = document.getElementById('mailMailer');
        var smtpBlock = document.getElementById('smtpFields');
        if (!mailerSel || !smtpBlock) return;
        function sync() {
            smtpBlock.style.display = mailerSel.value === 'smtp' ? '' : 'none';
        }
        mailerSel.addEventListener('change', sync);
        sync();
    })();

    // Send test email
    (function() {
        var btn = document.getElementById('btnSendTestMail');
        var input = document.getElementById('testMailTo');
        var result = document.getElementById('testMailResult');
        if (!btn || !input || !result) return;

        btn.addEventListener('click', function() {
            var to = (input.value || '').trim();
            if (!to) { return; }
            btn.disabled = true;
            var originalText = btn.textContent;
            btn.textContent = 'Sending...';
            result.style.display = 'none';
            result.className = '';

            fetch('{{ route('settings.mail.test') }}', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': '{{ csrf_token() }}',
                },
                body: JSON.stringify({ to: to })
            })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                result.style.display = '';
                result.className = data.ok ? 'alert alert-success' : 'alert alert-danger';
                result.textContent = data.message || (data.ok ? 'Sent.' : 'Failed.');
            })
            .catch(function(err) {
                result.style.display = '';
                result.className = 'alert alert-danger';
                result.textContent = err.message || 'Network error';
            })
            .finally(function() {
                btn.disabled = false;
                btn.textContent = originalText;
            });
        });
    })();
</script>
@endpush
@endsection
