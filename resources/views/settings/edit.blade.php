@extends('layouts.app')
@section('breadcrumb', 'Settings / Website Settings')

@section('content')
<div class="card">
    <div class="page-header">
        <h2>Website Settings</h2>
        <a class="btn secondary" href="{{ route('dashboard') }}">Back</a>
    </div>

    <form method="POST" action="{{ route('settings.update') }}" enctype="multipart/form-data">
        @csrf

        <div class="form-grid">
            <div class="full">
                <label class="required">Company Name</label>
                <input class="input" name="company_name" value="{{ old('company_name', $setting->company_name) }}" required>
            </div>

            <div class="full">
                <label class="required">From Email</label>
                <input class="input" type="email" name="from_email" value="{{ old('from_email', $setting->from_email) }}" required>
            </div>

            <div class="full">
                <label class="required">Timezone</label>
                <select name="timezone" class="input" required>
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
                       min="7" max="3650" required style="width:160px;">
                <div class="hint">Logs older than this many days will be cleared when using "Clear Old Logs" or via the scheduled cleanup.</div>
            </div>

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
                <div class="hint">
                    If you don't upload one, the default Xenon Suite logo will be used.
                </div>

                <div class="mt-12" id="sw-logo-preview">
                    <div class="text-xs text-muted" style="margin-bottom:6px;" id="sw-logo-label">{{ $setting->software_logo_path ? 'Current software logo:' : 'Default software logo:' }}</div>
                    <img src="{{ $setting->software_logo_path ? asset('storage/'.$setting->software_logo_path) : asset('images/brand/xenon-suite.svg') }}" alt="Software Logo" style="max-height:140px; border-radius:var(--radius-sm);" id="sw-logo-preview-img">
                </div>
            </div>

            <div class="full" style="display:flex; justify-content:flex-end;">
                <button class="btn primary" type="submit">Save Settings</button>
            </div>
        </div>
    </form>
</div>
<div class="card" style="margin-top:24px;">
    <div class="page-header">
        <h2>Database Maintenance</h2>
    </div>

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

@push('scripts')
<script>
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
        if (!days || days < 1) { alert('Please enter a valid number of days.'); return; }
        if (!confirm('Purge raw API data older than ' + days + ' days? This cannot be undone.')) return;

        var btn = this;
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
            if (data.ok) {
                resultEl.innerHTML = '<div class="alert success"><span>' + data.message + '</span><button class="alert-close" onclick="this.parentElement.remove()">&times;</button></div>';
            } else {
                resultEl.innerHTML = '<div class="alert danger"><span>' + (data.message || 'An error occurred.') + '</span><button class="alert-close" onclick="this.parentElement.remove()">&times;</button></div>';
            }
        })
        .catch(function (err) {
            btn.classList.remove('is-loading');
            btn.disabled = false;
            resultEl.style.display = '';
            resultEl.innerHTML = '<div class="alert danger"><span>Network error: ' + err.message + '</span><button class="alert-close" onclick="this.parentElement.remove()">&times;</button></div>';
        });
    });
</script>
@endpush
@endsection
