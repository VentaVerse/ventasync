@extends('install.layout')

@section('title', 'All set')

@section('content')
    <div class="content-header">
        <h1 class="content-title">All set!</h1>
        <p class="content-subtitle">VentaSync is installed and ready to use. A few finishing touches below.</p>
    </div>

    <div class="content-body">
        <div class="welcome-callout">
            <div class="welcome-callout-icon">
                <svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                    <polyline points="20 6 9 17 4 12"/>
                </svg>
            </div>
            <div>
                <strong>Installation complete.</strong>
                <p style="margin: 6px 0 0; color: var(--text-secondary); font-size: 14px;">Click below to finish the setup and log in to your new ERP.</p>
            </div>
        </div>

        <h3 class="section-heading">One last thing — set up the cron job</h3>
        <p style="color: var(--text-secondary); font-size: 14px; margin-bottom: 12px;">
            VentaSync runs scheduled tasks (license refresh, marketplace sync) via cron. Add this single line to your hosting panel's Cron Jobs section:
        </p>
        <pre class="cron-snippet">* * * * * {{ PHP_BINARY }} {{ base_path('artisan') }} schedule:run >> /dev/null 2>&1</pre>

        <p style="color: var(--text-secondary); font-size: 13px; margin-top: 12px;">
            See the <a href="https://example.com/install-guides" target="_blank" rel="noopener">install guides</a> for per-panel screenshots (cPanel, HestiaCP, CyberPanel, CWP, Plesk).
        </p>

        <form method="POST" action="{{ route('install.complete') }}" class="step-form">
            @csrf
            <div class="step-actions">
                <button type="submit" class="btn btn-primary btn-large">
                    Finish &amp; Go to Login
                    <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="margin-left: 6px;">
                        <line x1="5" y1="12" x2="19" y2="12"/>
                        <polyline points="12 5 19 12 12 19"/>
                    </svg>
                </button>
            </div>
        </form>
    </div>
@endsection
