{{--
    Where the orders came from, ranked.

    This replaced a doughnut chart whose seven slices were painted in the
    seven marketplaces' own brand colours. Two things were wrong with that.

    A doughnut is a bad shape for this question: seven long channel names
    ("TikTok Shop", "OpenCart - Main Store") do not fit on slices, so the
    old one needed a legend beside it, and comparing two slices of similar
    size by eye is something people are measurably bad at. Ranked bars sort
    themselves, put the name next to its own bar, and make "which is
    biggest" a one-glance answer.

    And the brand colours do not work as a chart palette. Measured as a
    set: Lazada's #0f146d sits outside the usable lightness band and
    Shopify's #95bf47 contrasts 2.08:1 against the light surface, under the
    3:1 floor for a mark that has to carry meaning on its own. Rather than
    re-tune seven hues that each belong to somebody else, identity comes
    from the written channel name and magnitude from bar length in ONE
    colour. Nothing on this page asks a reader to tell seven colours apart.

    Beyond the eighth channel the tail folds into one "Other channels" row.
    A ninth colour, or a ninth bar, is not information at this size.

    Props:
      title   string    panel heading
      slices  array     [['label' =>, 'orders' =>, 'revenue' =>], ...]
                        exactly as DashboardController::buildPlatformData()
                        returns it, already sorted by orders descending
      empty   string    what to say when the range holds no orders
--}}
@props([
    'title' => 'Orders by channel',
    'slices' => [],
    'empty' => 'No orders in this range yet.',
])

@php
    $slices = array_values($slices);

    // Fold the tail. Eight rows is the point past which the ranking stops
    // being scannable and starts being a table.
    $limit = 8;
    if (count($slices) > $limit) {
        $head = array_slice($slices, 0, $limit - 1);
        $tail = array_slice($slices, $limit - 1);
        $head[] = [
            'label'   => 'Other channels (' . count($tail) . ')',
            'orders'  => array_sum(array_column($tail, 'orders')),
            'revenue' => array_sum(array_column($tail, 'revenue')),
        ];
        $slices = $head;
    }

    $totalOrders = array_sum(array_column($slices, 'orders'));
    $totalRevenue = array_sum(array_column($slices, 'revenue'));
    $scale = $slices === [] ? 1 : max(1, max(array_column($slices, 'orders')));
@endphp

<section class="db-chart">
    <div class="db-chart__head">
        <h3 class="db-chart__title">{{ $title }}</h3>
        @if($slices !== [])
        <p class="db-chart__note">
            <span class="db-chart__note-v x-num">{{ number_format($totalOrders) }}</span>
            <span class="db-chart__note-k">orders</span>
            <span class="db-chart__note-sep" aria-hidden="true"></span>
            <span class="db-chart__note-v x-num">{{ \App\Support\Money::base((float) $totalRevenue) }}</span>
        </p>
        @endif
    </div>

    @if($slices === [])
        <p class="db-chart__empty">{{ $empty }}</p>
    @else
    <ol class="db-rank">
        @foreach($slices as $slice)
            @php
                $orders = (int) $slice['orders'];
                $pct = round($orders / $scale * 100, 3);
                $share = $totalOrders > 0 ? round($orders / $totalOrders * 100) : 0;
            @endphp
            <li class="db-rank__row">
                <span class="db-rank__name">{{ $slice['label'] }}</span>
                <span class="db-rank__v x-num">{{ number_format($orders) }}<span class="db-rank__share">{{ $share }}%</span></span>
                {{-- The bar's length is data, so it is a geometry attribute
                     on a rect, never a style. --}}
                <svg class="db-rank__track" viewBox="0 0 100 6" preserveAspectRatio="none" aria-hidden="true">
                    <rect class="db-rank__bar" x="0" y="0" width="{{ $pct }}" height="6"/>
                </svg>
                <span class="db-rank__rev x-num">{{ \App\Support\Money::base((float) $slice['revenue']) }}</span>
            </li>
        @endforeach
    </ol>
    @endif
</section>
