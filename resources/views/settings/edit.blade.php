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
                <label>Company Logo (optional)</label>
                <input class="input" type="file" name="logo" accept="image/*">

                @if($setting->logo_path)
                    <div class="mt-12">
                        <div class="text-xs text-muted mb-0" style="margin-bottom:6px;">Current logo:</div>
                        <img src="{{ asset('storage/'.$setting->logo_path) }}" alt="Company Logo" style="max-height:120px;">
                    </div>
                @endif
            </div>


            <div class="full">
                <label>Software Logo (White-label / Login logo)</label>
                <input class="input" type="file" name="software_logo" accept="image/*">
                <div class="hint">
                    If you don't upload one, the default Xenon Suite logo will be used.
                </div>

                @if($setting->software_logo_path)
                    <div class="mt-12">
                        <div class="text-xs text-muted" style="margin-bottom:6px;">Current software logo:</div>
                        <img src="{{ asset('storage/'.$setting->software_logo_path) }}" alt="Software Logo" style="max-height:140px;">
                    </div>
                @else
                    <div class="mt-12">
                        <div class="text-xs text-muted" style="margin-bottom:6px;">Default software logo:</div>
                        <img src="{{ asset('images/brand/xenon-suite.svg') }}" alt="Xenon Suite" style="max-height:140px;">
                    </div>
                @endif
            </div>

            <div class="full">
                <button class="btn primary" type="submit">Save Settings</button>
            </div>
        </div>
    </form>
</div>
@endsection
