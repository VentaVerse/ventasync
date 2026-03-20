@extends('layouts.app')
@section('breadcrumb', 'Settings')

@section('content')
<div class="page-header">
    <h2>Settings</h2>
    <a class="btn secondary" href="{{ route('dashboard') }}">Back</a>
</div>

{{-- Tabs --}}
<div class="tabs mb-16">
    <button class="tab active" data-tab="tab-general">General</button>
    <button class="tab" data-tab="tab-website">Website Settings</button>
    <button class="tab" data-tab="tab-maintenance">Maintenance</button>
</div>

<form method="POST" action="{{ route('settings.update') }}" enctype="multipart/form-data">
    @csrf

    {{-- General Tab --}}
    <div class="tab-panel active" id="tab-general">
        <div class="card">
            <h3 class="section-title mt-0">Company Information</h3>
            <div class="form-grid">
                <div>
                    <label class="required">Company Name</label>
                    <input class="input" name="company_name" value="{{ old('company_name', $setting->company_name) }}">
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

            <div class="d-flex justify-end mt-16">
                <button class="btn" type="submit">Save Settings</button>
            </div>
        </div>
    </div>

    {{-- Website Settings Tab --}}
    <div class="tab-panel" id="tab-website">
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
                    <label>Company Logo (optional)</label>
                    <input class="input" type="file" name="logo" accept="image/*" onchange="previewLogo(this, 'logo-preview')">
                    <div class="mt-12" id="logo-preview" style="{{ $setting->logo_path ? '' : 'display:none;' }}">
                        <div class="text-xs text-muted" style="margin-bottom:6px;">{{ $setting->logo_path ? 'Current logo:' : 'Preview:' }}</div>
                        <img src="{{ $setting->logo_path ? asset('storage/'.$setting->logo_path) : '' }}" alt="Company Logo" style="max-height:120px; border-radius:var(--radius-sm);" id="logo-preview-img">
                    </div>
                </div>

                <div class="full">
                    <label>Software Logo (White-label / Login logo)</label>
                    <input class="input" type="file" name="software_logo" accept="image/*" onchange="previewLogo(this, 'sw-logo-preview')">
                    <div class="hint">If you don't upload one, the default Xenon Suite logo will be used.</div>
                    <div class="mt-12" id="sw-logo-preview">
                        <div class="text-xs text-muted" style="margin-bottom:6px;">{{ $setting->software_logo_path ? 'Current software logo:' : 'Default software logo:' }}</div>
                        <img src="{{ $setting->software_logo_path ? asset('storage/'.$setting->software_logo_path) : asset('images/brand/xenon-suite.svg') }}" alt="Software Logo" style="max-height:140px; border-radius:var(--radius-sm);" id="sw-logo-preview-img">
                    </div>
                </div>
            </div>

            <div class="d-flex justify-end mt-16">
                <button class="btn" type="submit">Save Settings</button>
            </div>
        </div>
    </div>
</form>

{{-- Maintenance Tab --}}
<div class="tab-panel" id="tab-maintenance">
    <div class="card">
        <h3 class="section-title mt-0">Database Maintenance</h3>
        <div class="form-grid">
            <div class="full">
                <label>Purge Marketplace Raw Data</label>
                <div class="hint" style="margin-bottom:12px;">
                    Clears the raw API response data from Lazada and Shopee order tables for orders older than the specified number of days. This frees up database space without affecting synced order data.
                </div>
                <div class="d-flex items-center gap-12">
                    <input class="input" type="number" id="purgeDays" value="30" min="1" style="width:120px;">
                    <span class="text-secondary">days</span>
                    <button type="button" class="btn danger" id="btnPurgeRaw">Purge Raw Data</button>
                </div>
                <div id="purgeResult" style="margin-top:12px; display:none;"></div>
            </div>
        </div>
    </div>
</div>

@push('scripts')
<script>
    // Tab switching
    document.querySelectorAll('.tabs .tab').forEach(function(tab) {
        tab.addEventListener('click', function() {
            document.querySelectorAll('.tabs .tab').forEach(function(t) { t.classList.remove('active'); });
            document.querySelectorAll('.tab-panel').forEach(function(p) { p.classList.remove('active'); });
            tab.classList.add('active');
            var panel = document.getElementById(tab.dataset.tab);
            if (panel) panel.classList.add('active');
        });
    });

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

    document.getElementById('btnPurgeRaw').addEventListener('click', function () {
        var days = document.getElementById('purgeDays').value;
        if (!days || days < 1) { showFlashError('Please enter a valid number of days.'); return; }

        var btn = this;

        confirmModal('Purge raw API data older than ' + days + ' days? This cannot be undone.').then(function (ok) {
            if (!ok) return;

            var resultEl = document.getElementById('purgeResult');
            btn.classList.add('is-loading');
            btn.disabled = true;
            resultEl.style.display = 'none';

            fetch('{{ route("settings.purge_raw") }}', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
                },
                body: JSON.stringify({ days: parseInt(days) })
            })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                btn.classList.remove('is-loading');
                btn.disabled = false;
                resultEl.style.display = '';
                resultEl.textContent = '';
                var div = document.createElement('div');
                div.className = 'alert ' + (data.ok ? 'success' : 'danger');
                var span = document.createElement('span');
                span.textContent = data.ok ? data.message : (data.message || 'An error occurred.');
                div.appendChild(span);
                resultEl.appendChild(div);
            })
            .catch(function (err) {
                btn.classList.remove('is-loading');
                btn.disabled = false;
                resultEl.style.display = '';
                resultEl.textContent = '';
                var div = document.createElement('div');
                div.className = 'alert danger';
                var span = document.createElement('span');
                span.textContent = 'Network error: ' + err.message;
                div.appendChild(span);
                resultEl.appendChild(div);
            });
        });
    });
</script>
@endpush
@endsection
