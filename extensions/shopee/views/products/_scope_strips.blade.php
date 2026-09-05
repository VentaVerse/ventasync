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
     every push, unlist and relist. The strip says when. --}}
@if(($liveCounts ?? null) !== null)
    @php
        $liveTabDefs = [
            'live' => 'Live',
            'unlisted' => 'Unlisted',
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
                    {{-- Icon only (the owner, 2026-09-03): the statuses refresh
                         themselves every 30 minutes (Automations); this is
                         the on-demand twin, page furniture rather than a
                         page action. The title carries the words. --}}
                    <form method="POST" action="{{ route('ext.shopee.products.refresh_status') }}" class="x-segment-group__refresh" data-slow-action>
                        @csrf
                        <button type="submit" class="cc-iconbtn" title="Refresh statuses from Shopee now (they refresh themselves every 30 minutes)" aria-label="Refresh statuses from Shopee">
                            <x-ui.icon name="refresh-cw" size="13" />
                        </button>
                    </form>
                    {{-- The link audit, renamed from Check against Shopee:
                         confirms every linked item still exists, adopts
                         strays by SKU, drops dead links. --}}
                    <form method="POST" action="{{ route('ext.shopee.products.check') }}" class="cc-repair"
                          data-confirm="Repair the links against Shopee? Nothing is sent or changed on Shopee; the ERP confirms every linked item still exists, adopts strays by SKU and drops dead links, 200 products at a time." data-confirm-tone="primary" data-confirm-verb="Repair links">
                        @csrf
                        <button type="submit" class="cc-quiet">Repair links</button>
                    </form>
                @endif
            </div>
        </div>
    </div>
@endif
