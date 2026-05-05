{{-- Card-style tab strip for the unified Orders surface. Each card represents
     one marketplace; clicking it lands on that marketplace's order
     fulfillment page. The first card is "Summary" — it goes to
     /integrations/orders and is active when no marketplace is selected.

     Include from any orders page, passing the active id:

         @include('integrations.partials._tab_strip', ['activeTabId' => 'shopee'])
         @include('integrations.partials._tab_strip', ['activeTabId' => null]) // Summary

     Cards self-render their stats via SummaryProvider, gated by
     enabled+licensed+permission via IntegrationRegistry. --}}
@php
    $__registry = app(\App\Integrations\IntegrationRegistry::class);
    $__user = auth()->user();
    $__providers = $__registry->visibleSummaryProviders($__user);
    $__orderTabs = collect($__registry->visibleOrderTabs($__user))->keyBy('id');
    $__activeId = $activeTabId ?? null;
@endphp

@if(!empty($__providers))
<nav class="ord-tab-strip" aria-label="Marketplace orders">
    {{-- Summary card — neutral slate accent so it reads as a different
         kind of surface from the marketplace cards. --}}
    <a href="{{ route('integrations.orders') }}"
       class="ord-tab-card ord-tab-summary {{ $__activeId === null ? 'active' : '' }}"
       style="--card-accent: #475569;">
        <div class="ord-tab-head">
            <div class="ord-tab-icon" style="background: #475569;">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>
            </div>
            <div class="ord-tab-title">Summary</div>
        </div>
        <div class="ord-tab-sub">All marketplaces</div>
    </a>

    @foreach($__providers as $__providerId => $__provider)
        @php
            $__card = $__provider->integrationCard();
            $__tab = $__orderTabs->get($__card->id ?? '');
            if ($__card === null || $__tab === null) continue;

            $__url = route($__tab->routeName, $__tab->routeParams);
            $__isActive = $__activeId === $__card->id;
            $__unprocessed = $__tab->unprocessedCount();
            $__todayOrders = $__provider->dailyOrdersCount();
            $__todayRevenue = $__provider->dailyRevenue();
        @endphp
        <a href="{{ $__url }}"
           class="ord-tab-card {{ $__isActive ? 'active' : '' }}"
           style="--card-accent: {{ $__card->accent }};">
            <div class="ord-tab-head">
                <div class="ord-tab-icon" style="background: {{ $__card->accent }};">
                    @include('integrations.partials._icon', ['icon' => $__card->icon, 'name' => $__card->name])
                </div>
                <div class="ord-tab-title">{{ $__card->name }}</div>
            </div>
            <div class="ord-tab-stats">
                <div class="ord-tab-metric">
                    <div class="ord-tab-num {{ ($__unprocessed ?? 0) > 0 ? 'warn' : '' }}">{{ number_format((int) ($__unprocessed ?? 0)) }}</div>
                    <div class="ord-tab-lbl">Unprocessed</div>
                </div>
                <div class="ord-tab-metric">
                    <div class="ord-tab-num">{{ number_format((int) $__todayOrders) }}</div>
                    <div class="ord-tab-lbl">Today</div>
                </div>
                <div class="ord-tab-metric">
                    <div class="ord-tab-num small">₱{{ number_format((float) $__todayRevenue, 0) }}</div>
                    <div class="ord-tab-lbl">Revenue</div>
                </div>
            </div>
        </a>
    @endforeach
</nav>
@endif
