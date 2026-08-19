@php
    /**
     * Channel faults, stated where the operator is standing.
     *
     * Extracted from partials/legacy-page-contract so the fulfilment hub can
     * show it too. The hub extends layouts.blotter, which never rendered the
     * contract partial, so the one page that shows every channel at once was
     * the only page that could not say why any of them had stopped - it had
     * "not syncing" on a card and nothing else. The channel's own page, which
     * you only reach by already knowing which channel to open, had the full
     * sentence and the link to fix it.
     *
     * $bannerOnly narrows to one channel (base id, so "venta:2" passes
     * "venta"). Absent, every contributing channel is shown, which is the
     * right reading for a cross-channel surface.
     *
     * A marketplace with a missing table or an expired credential must not
     * take the page down to announce itself, hence the catch.
     */
    $__banners = [];

    try {
        $__only = isset($bannerOnly) && $bannerOnly !== null
            ? explode(':', (string) $bannerOnly, 2)[0]
            : null;

        foreach (app(\App\Integrations\IntegrationRegistry::class)->layoutBannerContributorsById() as $__id => $__c) {
            if ($__only !== null && $__id !== $__only) {
                continue;
            }

            foreach ($__c->layoutBanners() as $__b) {
                $__banners[] = $__b;
            }
        }
    } catch (\Throwable $e) {
        $__banners = [];
    }
@endphp

@foreach($__banners as $__banner)
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
