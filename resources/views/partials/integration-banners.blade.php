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

        /* ONE SOURCE FOR "IS THIS CHANNEL CONNECTED".
         *
         * Each marketplace used to answer that question itself, in its own
         * layoutBanners(), by reading settings.expires_at directly - and got
         * it wrong, because that column belongs to the LIVE credential while
         * these installs run on `mode`. Shopee is the proof: mode=sandbox
         * with a working sandbox token and no sandbox expiry, an EMPTY live
         * access_token, and a live expires_at left over from an old
         * authorisation. The banner read that leftover and announced an
         * expired token for a credential that no longer exists, while the
         * card, the head and the sidebar - all reading ChannelWorkspace,
         * which selects by mode - correctly said connected. Four answers on
         * one screen and the loudest one was the wrong one.
         *
         * ChannelWorkspace::stateWithReason() already resolves this properly
         * for the cards, the head and the channel sidebar, and
         * ChannelWorkspace::badge() already owns the words. The banner now
         * asks the same question of the same object, so it cannot drift from
         * the three things beside it.
         *
         * Only credential reasons raise a banner. A stale sync is a fact the
         * card already carries, and a channel nobody has set up yet is not a
         * fault worth an alarm across every page. */
        /* Severity, and the consequence each reason carries. The banners
           this replaced each named what stops when the credential dies -
           "Order sync is paused" - and that sentence is why an operator acts
           on the banner rather than dismissing it, so it survives the move.
           The label itself comes from badge(), so the banner and the card
           beside it cannot word the same fault differently. */
        $__credentialReasons = [
            'expired' => ['severity' => 'error', 'then' => 'Syncing is paused until you reconnect.'],
            'expiring' => ['severity' => 'warning', 'then' => 'Reconnect before it lapses or syncing will stop.'],
        ];
        $__workspace = app(\App\Support\ChannelWorkspace::class);

        // visibleCards, so a channel the operator holds no permission for is
        // not announced to them across every page.
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

        /* Whatever else a channel wants to say. layoutBanners() keeps the
           operational notices only - Shopee's paused sync, for one - now that
           the credential half is answered in one place. */
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
