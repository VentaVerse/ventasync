@extends('install.layout')

@section('title', 'Welcome')

@section('content')
    <div class="content-header">
        <h1 class="content-title">Welcome to VentaSync</h1>
        <p class="content-subtitle">A self-hosted multi-marketplace ERP for sellers across Lazada, Shopee, TikTok, Shopify, OpenCart and more.</p>
    </div>

    <div class="content-body">
        <div class="welcome-callout">
            <div class="welcome-callout-icon">
                <svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"/>
                </svg>
            </div>
            <div>
                <strong>This wizard will get you running in 5-10 minutes.</strong>
                <p style="margin: 6px 0 0; color: var(--text-secondary); font-size: 14px;">We'll check your server requirements, set up your database, configure site basics, and create your admin account.</p>
            </div>
        </div>

        <h3 class="section-heading">License</h3>
        <p style="color: var(--text-secondary); margin-bottom: 14px; font-size: 14px;">
            VentaSync Community is distributed under the <strong>GNU Affero General Public License v3.0 or later</strong> (AGPL-3.0-or-later).
        </p>

        <div class="license-summary">
            <div class="license-item">
                <span class="license-tag license-tag-allow">✓ You may</span>
                use, modify, and distribute VentaSync under AGPL-3.0 terms
            </div>
            <div class="license-item">
                <span class="license-tag license-tag-allow">✓ You may</span>
                run it for any purpose, including commercial use
            </div>
            <div class="license-item">
                <span class="license-tag license-tag-must">⚠ You must</span>
                make modified source available to users you serve over a network
            </div>
            <div class="license-item">
                <span class="license-tag license-tag-must">⚠ You must</span>
                preserve copyright notices and the AGPL license in derivatives
            </div>
        </div>

        <p style="color: var(--text-secondary); font-size: 13px; margin-top: 14px;">
            Read the full license at <a href="https://www.gnu.org/licenses/agpl-3.0.txt" target="_blank" rel="noopener">https://www.gnu.org/licenses/agpl-3.0.txt</a>.
            Plus modules sold via <a href="https://example.com" target="_blank" rel="noopener">example.com</a> are distributed under separate commercial licenses.
        </p>

        <form method="POST" action="{{ route('install.welcome') }}" class="step-form">
            @csrf

            @if($errors->any())
                <div class="alert alert-danger">
                    @foreach($errors->all() as $error)
                        <div>{{ $error }}</div>
                    @endforeach
                </div>
            @endif

            <label class="checkbox-row">
                <input type="checkbox" name="accept_license" value="1" {{ old('accept_license') ? 'checked' : '' }}>
                <span>I have read and accept the AGPL-3.0-or-later license terms.</span>
            </label>

            <div class="step-actions">
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
