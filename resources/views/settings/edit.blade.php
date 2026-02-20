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
</script>
@endpush
@endsection
