@php
    $__banners = [];

    try {
        $__only = isset($bannerOnly) && $bannerOnly !== null
            ? explode(':', (string) $bannerOnly, 2)[0]
            : null;

        $__credentialReasons = [
            'expired' => ['severity' => 'error', 'then' => 'Syncing is paused until you reconnect.'],
            'expiring' => ['severity' => 'warning', 'then' => 'Reconnect before it lapses or syncing will stop.'],
        ];
        $__workspace = app(\App\Support\ChannelWorkspace::class);

        foreach (app(\App\Integrations\IntegrationRegistry::class)->visibleCards(auth()->user()) as $__card) {
            if ($__only !== null && $__card->id !== $__only) {
                continue;
            }

            $__s = $__workspace->stateWithReason($__card, $__card->id);
            $__spec = $__credentialReasons[$__s['reason'] ?? ''] ?? null;

            if ($__spec === null) {
                continue;
            }

            $__settings = 'ext.' . $__card->id . '.index';

            $__banners[] = [
                'label' => $__card->name . ': '
                    . \App\Support\ChannelWorkspace::badge($__s['state'], $__s['reason'])['label']
                    . '. ' . $__spec['then'],
                'severity' => $__spec['severity'],
                'href' => \Illuminate\Support\Facades\Route::has($__settings) ? route($__settings) : null,
            ];
        }

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
