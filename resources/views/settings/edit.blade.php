@extends('layouts.app')

@section('content')
<div class="card">
    <div class="page-header">
        <h2>Website Settings</h2>
        <a class="btn secondary" href="{{ route('dashboard') }}">Back</a>
    </div>

    @if(session('success'))
        <div class="alert success" style="margin-bottom:12px;">{{ session('success') }}</div>
    @endif

    @if($errors->any())
        <div class="alert danger" style="margin-bottom:12px;">
            <ul style="margin:0; padding-left:18px;">
                @foreach($errors->all() as $e)
                    <li>{{ $e }}</li>
                @endforeach
            </ul>
        </div>
    @endif

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
                    <div style="margin-top:10px;">
                        <div style="font-size:12px; opacity:.8; margin-bottom:6px;">Current logo:</div>
                        <img src="{{ asset('storage/'.$setting->logo_path) }}" alt="Company Logo" style="max-height:120px;">
                    </div>
                @endif
            </div>


            <div class="full">
                <label>Software Logo (White-label / Login logo)</label>
                <input class="input" type="file" name="software_logo" accept="image/*">
                <div style="font-size:12px; opacity:.8; margin-top:6px;">
                    If you don't upload one, the default Xenon Suite logo will be used.
                </div>

                @if($setting->software_logo_path)
                    <div style="margin-top:10px;">
                        <div style="font-size:12px; opacity:.8; margin-bottom:6px;">Current software logo:</div>
                        <img src="{{ asset('storage/'.$setting->software_logo_path) }}" alt="Software Logo" style="max-height:140px;">
                    </div>
                @else
                    <div style="margin-top:10px;">
                        <div style="font-size:12px; opacity:.8; margin-bottom:6px;">Default software logo:</div>
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
