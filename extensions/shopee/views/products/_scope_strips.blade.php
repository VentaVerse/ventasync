@if(($liveCounts ?? null) !== null)
    @php
        $liveTabDefs = [
            'live' => 'Live',
            'unlisted' => 'Unpublished',
            'violation' => 'Violation',
            'reviewing' => 'Under review',
        ];
        $tabQuery = request()->except('page', 'shopee_tab');
    @endphp
    <div class="x-segment-bar">
        <div class="x-segment-groups">
            <div class="x-segment-group">
                <nav class="x-segment" aria-label="Shopee listing status">
                    <a href="{{ route($stripRoute, array_merge($stripParams ?? [], $tabQuery)) }}"
                       class="x-segment__item {{ ($shopeeTab ?? 'all') === 'all' ? 'is-active' : '' }}"
                       @if(($shopeeTab ?? 'all') === 'all') aria-current="true" @endif>
                        <span>All</span>
                        <span class="x-segment__count">{{ number_format((int) ($catalogueTotal ?? $products->total())) }}</span>
                    </a>
                    @foreach($liveTabDefs as $tabKey => $tabLabel)
                        @php $isActive = ($shopeeTab ?? 'all') === $tabKey; @endphp
                        <a href="{{ route($stripRoute, array_merge($stripParams ?? [], $tabQuery, ['shopee_tab' => $tabKey])) }}"
                           class="x-segment__item {{ $isActive ? 'is-active' : '' }}"
                           @if($isActive) aria-current="true" @endif>
                            <span>{{ $tabLabel }}</span>
                            <span class="x-segment__count">{{ number_format((int) ($liveCounts[$tabKey] ?? 0)) }}</span>
                        </a>
                    @endforeach
                </nav>
                <span class="x-segment-group__meta">
                    @if($liveCheckedAt ?? null)
                        as of {{ $liveCheckedAt->format('H:i, M j') }}
                    @else
                        not refreshed yet
                    @endif
                </span>
                @if($canManageShopee)
                    <form method="POST" action="{{ route('ext.shopee.products.refresh_status') }}" class="x-segment-group__refresh" data-slow-action>
                        @csrf
                        <x-ui.button type="submit"><x-ui.icon name="refresh-cw" size="14" /> Refresh from Shopee</x-ui.button>
                    </form>
                @endif
            </div>
        </div>
    </div>
@endif
