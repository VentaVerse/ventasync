{{-- The scope strip (the owner, 2026-08-28), shared since cleanup point 4
     (2026-09-04) by the workbench and every group page - "the product group
     IS the same as the store's list, only filtered by group", so both read
     the same strip over the same mirror. Callers pass $stripRoute (+
     $stripParams) so each tab links back to its own page, and member-scoped
     $catalogueTotal / $liveCounts / $liveCheckedAt.

     One group since #201 (2026-09-05): the "Your catalogue / All products"
     framing went with the catalogue-as-table. "All" is this store's own list,
     the stored view, pushed or not; the rest narrow to what Shopee holds
     right now. The bucket counts and every row's chip come from the mirror
     on the link rows, refreshed every 30 minutes, on demand here, and by
     every push, delist and publish. The strip says when. --}}
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
                    {{-- The one button (the owner, 2026-09-07): Refresh checks every link against
                         the store and re-reads every status, the old Repair links folded in. --}}
                    <form method="POST" action="{{ route('ext.shopee.products.refresh_status') }}" class="x-segment-group__refresh" data-slow-action>
                        @csrf
                        <x-ui.button type="submit"><x-ui.icon name="refresh-cw" size="14" /> Refresh from Shopee</x-ui.button>
                    </form>
                @endif
            </div>
        </div>
    </div>
@endif
