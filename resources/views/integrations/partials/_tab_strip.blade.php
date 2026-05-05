{{-- Shared tab strip for the unified Orders surface.
     Include from any marketplace's Orders index view, passing the active tab id:

         @include('integrations.partials._tab_strip', ['activeTabId' => 'shopee'])

     The strip pulls visible tabs from IntegrationRegistry, applies the same
     license + enabled + permission gates as the Integrations page, and renders
     each tab with an unprocessed-count badge (when the tab provides one). --}}
@php
    $__tabs = app(\App\Integrations\IntegrationRegistry::class)->visibleOrderTabs(auth()->user());
    $__activeId = $activeTabId ?? null;
@endphp

@if(!empty($__tabs))
<nav class="int-tab-strip" aria-label="Marketplace orders">
    @foreach($__tabs as $__tab)
        @php
            $__count = $__tab->unprocessedCount();
            $__url = route($__tab->routeName, $__tab->routeParams);
            $__isActive = $__activeId === $__tab->id;
        @endphp
        <a href="{{ $__url }}"
           class="int-tab {{ $__isActive ? 'active' : '' }}"
           style="--tab-accent: {{ $__tab->accent }};">
            <span>{{ $__tab->label }}</span>
            @if($__count !== null && $__count > 0)
                <span class="int-tab-badge">{{ $__count }}</span>
            @endif
        </a>
    @endforeach
</nav>
@endif
