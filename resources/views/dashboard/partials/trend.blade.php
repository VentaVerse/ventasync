{{--
    One measure over time, one column per period.

    This is half of a pair. The dashboard used to draw revenue and order
    count on ONE plot with two y-scales (revenue bars on 'y', an orders
    line on 'y1'). Two scales on one plot is the worst thing a chart can
    do: where the two lines cross is decided by how the axes were scaled,
    not by anything in the data, so the chart invents a relationship. Two
    instances of this partial, stacked, sharing the same periods and the
    same column positions, say the true thing instead: you still read the
    two shapes against each other, but each keeps its own honest baseline
    and its own labelled units.

    Drawn as inline SVG on the design tokens. No canvas, no library, no
    network request, and both themes work for free because every colour is
    a var(). One <svg> PER COLUMN rather than one for the series: a single
    stretched SVG scales its bars to whatever the container is, so a five
    year range would draw five wide slabs. Per-column SVGs let CSS cap the
    drawn width (see .db-col in pages/dashboard.css).

    The bar's height is the one thing about it that comes from the data, so
    it lives in the rect's `height` geometry attribute. It is not a style
    attribute: this project puts no CSS in Blade, and the suite enforces it.

    Bars are square-ended rather than round-ended. The column stretches
    vertically only (preserveAspectRatio="none"), which is what lets a
    fixed-height plot keep its height from 1440 down to 390; under that
    non-uniform stretch an `rx` renders as a lopsided ellipse. A square end
    is the honest version of the same mark.

    Reading the values: hovering a column shows its figure, and every
    figure is also in the table under "Show the numbers", so nothing is
    reachable only by hover.

    Props:
      title   string  what is plotted, in words (also names the series, so
                      a one-series chart needs no legend box)
      labels  array   period labels, in order
      values  array   the measure, same order and length as $labels
      tone    string  'money' or 'count' - which of the two chart series
                      colours this is (see the CHART PALETTE block in
                      base/tokens.css). Colour says which measure, always.
      unit    string  'money' or 'count' - how to format a figure
--}}
@props([
    'title' => '',
    'labels' => [],
    'values' => [],
    'tone' => 'money',
    'unit' => 'money',
])

@php
    $labels = array_values($labels);
    $values = array_map(fn ($v) => (float) $v, array_values($values));
    $count = count($labels);

    /*
     * Full precision, for the tooltips, the peak/latest labels and the
     * table. A figure a person might act on is never abbreviated.
     */
    $fmt = fn (float $v) => $unit === 'money'
        ? \App\Support\Money::base($v)
        : number_format($v, $v == floor($v) ? 0 : 1);

    /*
     * Axis ticks only. A peso figure written out is about ninety pixels
     * wide, which is a fifth of the plot on a phone, so the scale is
     * compacted there and only there. The half-way tick keeps a decimal
     * when the scale's midpoint genuinely is one (an axis topping out at
     * 25 orders has its middle at 12.5, and printing "13" on a line drawn
     * at 12.5 would be a small lie in the one place a chart must not
     * tell one).
     */
    $tick = fn (float $v) => $unit === 'money'
        ? \App\Support\Money::compactAxis($v)
        : number_format($v, $v == floor($v) ? 0 : 1);

    $peak = $values === [] ? 0.0 : max($values);

    /*
     * Axis top. Rounded up to a clean number (1 / 2 / 5 x a power of ten)
     * so the tick reads as a round figure rather than "12,347" and so the
     * tallest bar never touches the top of the plot.
     */
    if ($peak <= 0) {
        $axisTop = 1.0;
    } else {
        $pow = 10 ** floor(log10($peak));
        $axisTop = (float) (collect([1, 2, 2.5, 5, 10])
            ->first(fn ($m) => $peak <= $m * $pow, 10) * $pow);
    }

    $peakIndex = $values === [] ? null : array_search($peak, $values, true);
    $latest = $values === [] ? 0.0 : end($values);
@endphp

<section class="db-chart">
    <div class="db-chart__head">
        <h3 class="db-chart__title">{{ $title }}</h3>
        {{--
            The two direct labels this chart gets: its extreme and its
            endpoint. They sit in the head rather than on the marks because
            a peso figure is about fifty pixels wide and a daily column is
            about eighteen, so an on-mark label would overlap its
            neighbours. A label that does not fit belongs outside the mark,
            never clipped inside it.
        --}}
        @if($count > 0)
        <p class="db-chart__note">
            <span class="db-chart__note-k">Peak</span>
            <span class="db-chart__note-v x-num">{{ $fmt($peak) }}</span>
            <span class="db-chart__note-k">{{ $labels[$peakIndex] ?? '' }}</span>
            <span class="db-chart__note-sep" aria-hidden="true"></span>
            <span class="db-chart__note-k">Latest</span>
            <span class="db-chart__note-v x-num">{{ $fmt($latest) }}</span>
        </p>
        @endif
    </div>

    @if($count === 0)
        <p class="db-chart__empty">No orders in this range yet.</p>
    @else
    <div class="db-plot">
        <div class="db-plot__axis" aria-hidden="true">
            <span class="db-plot__tick x-num">{{ $tick($axisTop) }}</span>
            <span class="db-plot__tick x-num">{{ $tick($axisTop / 2) }}</span>
            <span class="db-plot__tick x-num">{{ $tick(0) }}</span>
        </div>

        {{--
            role="img" with a written summary: the shape of the series is
            the information here, and the exact figures are in the table
            below, which is the accessible twin rather than an afterthought.
        --}}
        <div class="db-trend db-trend--{{ $tone }}" role="img"
             aria-label="{{ $title }}, {{ $count }} {{ \Illuminate\Support\Str::plural('period', $count) }} from {{ $labels[0] }} to {{ $labels[$count - 1] }}. Peak {{ $fmt($peak) }} at {{ $labels[$peakIndex] ?? '' }}, latest {{ $fmt($latest) }}. Every figure is listed in the table below this chart.">
            @foreach($labels as $i => $label)
                @php
                    $value = $values[$i] ?? 0.0;
                    $pct = $axisTop > 0 ? round($value / $axisTop * 100, 3) : 0;
                @endphp
                <div class="db-col">
                    <svg class="db-col__svg" viewBox="0 0 1 100" preserveAspectRatio="none">
                        <title>{{ $label }}: {{ $fmt($value) }}</title>
                        @if($pct > 0)
                            <rect class="db-col__bar" x="0" y="{{ 100 - $pct }}" width="1" height="{{ $pct }}"/>
                        @endif
                    </svg>
                    <span class="db-col__tip" aria-hidden="true">
                        <span class="db-col__tip-k">{{ $label }}</span>
                        <span class="db-col__tip-v x-num">{{ $fmt($value) }}</span>
                    </span>
                </div>
            @endforeach
        </div>

        {{-- Inside the plot grid, second row of the columns' own track, so
             the first tick lands under the first column instead of under
             the value gutter. --}}
        <div class="db-ticks" aria-hidden="true">
            <span>{{ $labels[0] }}</span>
            @if($count > 2)<span>{{ $labels[intdiv($count, 2)] }}</span>@endif
            @if($count > 1)<span>{{ $labels[$count - 1] }}</span>@endif
        </div>
    </div>

    <details class="db-table-twin">
        <summary class="db-table-twin__summary">Show the numbers</summary>
        <table class="db-table-twin__table">
            <caption class="x-sr">{{ $title }}, every period</caption>
            <thead>
                <tr><th scope="col">Period</th><th scope="col">{{ $title }}</th></tr>
            </thead>
            <tbody>
                @foreach($labels as $i => $label)
                    <tr>
                        <th scope="row">{{ $label }}</th>
                        <td class="x-num">{{ $fmt($values[$i] ?? 0) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </details>
    @endif
</section>
