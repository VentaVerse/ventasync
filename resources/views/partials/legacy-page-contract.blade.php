{{-- The four things layouts/app.blade.php renders around @yield('content')
     that the views it hosts depend on: the session flash region, the
     validation error region, the integration banner region, and the
     #js-flash-container that window.showFlashError/showFlashSuccess write
     into (defined in resources/js/legacy-flash.js).

     This markup is a faithful copy of layouts/app.blade.php's. That file is
     protected and must stay byte-identical, so the duplication is deliberate
     rather than an oversight: any layout that hosts a view written against
     the old one has to honour the same contract, and a copy is the only way
     to do that without editing the protected original. When app.blade.php is
     eventually retired, this partial should become the single definition and
     be included from every layout, not deleted.

     Include this inside the element that wraps page content, before
     @yield('content'), so the alerts sit where the views expect them. --}}

@if(session('status'))
    <div class="alert success">
        <svg class="alert__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
        <span>{{ session('status') }}</span>
        <button class="alert-close" onclick="this.parentElement.remove()" aria-label="Close">&times;</button>
    </div>
@endif

@if(session('error'))
    <div class="alert danger">
        <svg class="alert__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
        <span>{{ session('error') }}</span>
        <button class="alert-close" onclick="this.parentElement.remove()" aria-label="Close">&times;</button>
    </div>
@endif

@if($errors->any())
    <div class="alert danger">
        <svg class="alert__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
        <div>
            <ul class="alert__list">
                @foreach($errors->all() as $e)
                    <li>{{ $e }}</li>
                @endforeach
            </ul>
        </div>
        <button class="alert-close" onclick="this.parentElement.remove()" aria-label="Close">&times;</button>
    </div>
@endif

@php
    // Layout banners contributed by every enabled+licensed integration.
    // Token expiry warnings, sync pauses, etc. each marketplace knows
    // its own state and contributes via LayoutBannerContributor.
    $__layoutBanners = [];
    try {
        foreach (app(\App\Integrations\IntegrationRegistry::class)->layoutBannerContributors() as $__c) {
            foreach ($__c->layoutBanners() as $__b) { $__layoutBanners[] = $__b; }
        }
    } catch (\Throwable $e) {}
@endphp

@foreach($__layoutBanners as $__banner)
    <div class="alert {{ ($__banner['severity'] ?? 'info') === 'error' ? 'danger' : (($__banner['severity'] ?? 'info') === 'warning' ? 'warning' : 'info') }}">
        <svg class="alert__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
        <span>
            {{ $__banner['label'] ?? '' }}
            @if(!empty($__banner['href']))
                <a href="{{ $__banner['href'] }}">Open settings</a>
            @endif
        </span>
    </div>
@endforeach

<div id="js-flash-container"></div>
