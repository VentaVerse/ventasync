{{-- A dual-series revenue/orders plot over a labelled period.

     Extracted so the channel Overview and the dashboard draw the same chart
     from the same geometry rather than from two copies that drift. The
     dashboard still carries its own inline copy today; migrating it is a
     follow-up, and until then this file is the definition to change.

     Expects: $labels (string[]), $revenue (float[]), $orders (int[]).
     Renders nothing when there is not enough to draw.

     The honest cost, same as on the dashboard: revenue runs to hundreds of
     thousands and orders to a couple of dozen, so the two cannot share one
     y-scale. Each is drawn against its OWN peak, which means the lines
     crossing says nothing about the business - it is decided by the scaling.
     The shapes are comparable; the crossings are not. Each series prints its
     own peak in the key so a reader sees the two scales rather than infers
     one. --}}
@php
    use App\Support\Money;
    use Illuminate\Support\Str;

    $rev = array_map('floatval', $revenue ?? []);
    $ord = array_map('floatval', $orders ?? []);
    $n = min(count($rev), count($ord));
    $revPeak = $rev === [] ? 0.0 : max($rev);
    $ordPeak = $ord === [] ? 0.0 : max($ord);
    $hasPlot = $n > 1 && ($revPeak > 0 || $ordPeak > 0);

    $pw = 1000; $ph = 200; $pad = 12;
    $trace = function (array $v, float $peak) use ($n, $pw, $ph, $pad) {
        $d = '';
        for ($i = 0; $i < $n; $i++) {
            $x = round($i / ($n - 1) * $pw, 2);
            $y = $peak <= 0 ? $ph - $pad
               : round($ph - $pad - ($v[$i] / $peak) * ($ph - $pad * 2), 2);
            $d .= ($i === 0 ? 'M' : 'L') . $x . ',' . $y . ' ';
        }
        return rtrim($d);
    };

    $revLine = $hasPlot ? $trace($rev, $revPeak) : '';
    $revArea = $revLine === '' ? '' : $revLine . ' L' . $pw . ',' . $ph . ' L0,' . $ph . ' Z';
    $ordLine = $hasPlot ? $trace($ord, $ordPeak) : '';

    $tickStep = max(1, (int) ceil(($n - 1) / 7));
    $ticks = [];
    for ($t = 0; $t < $n; $t += $tickStep) { $ticks[] = $t; }
    if ($ticks !== [] && end($ticks) !== $n - 1) { $ticks[] = $n - 1; }

    // Every gradient on the page needs its own id, or a second plot reuses
    // the first one's fill. Derived from the data so it is stable across
    // renders rather than random.
    $gradId = 'plot-rev-' . substr(md5(implode(',', $labels ?? [])), 0, 8);
@endphp

@if($hasPlot)
    {{-- The series ride on the element as pre-formatted strings. Currency
         formatting belongs to App\Support\Money, not to a reimplementation of
         it in JavaScript that would drift the first time a second currency
         appeared. --}}
    <div class="bl-plotcard"
         data-plot="{{ json_encode([
             'labels' => array_map(fn ($i) => $labels[$i] ?? '', range(0, $n - 1)),
             'money'  => array_map(fn ($v) => Money::base((float) $v), array_slice($rev, 0, $n)),
             'counts' => array_map(fn ($v) => number_format($v) . ' ' . Str::plural('order', $v), array_slice($ord, 0, $n)),
             'revenue' => array_slice($rev, 0, $n),
             'orders'  => array_slice($ord, 0, $n),
             'peaks'   => ['revenue' => $revPeak, 'orders' => $ordPeak],
         ]) }}">
        <div class="bl-key">
            <span class="bl-key__item">
                <svg class="bl-key__sw" width="14" height="4" viewBox="0 0 14 4" aria-hidden="true" focusable="false">
                    <rect class="bl-key__money" width="14" height="4" rx="2"></rect>
                </svg>
                Revenue
                <b class="bl-key__peak">peak {{ Money::base($revPeak) }}</b>
            </span>
            <span class="bl-key__item">
                <svg class="bl-key__sw" width="14" height="4" viewBox="0 0 14 4" aria-hidden="true" focusable="false">
                    <rect class="bl-key__count" width="14" height="4" rx="2"></rect>
                </svg>
                Orders
                <b class="bl-key__peak">peak {{ number_format($ordPeak) }}</b>
            </span>
        </div>

        {{-- The reading is drawn by plot-readout.js rather than left to the
             SVG's native <title>, which the browser holds for about a second
             before showing. The titles stay for assistive technology. --}}
        <div class="bl-plotwrap">
            <svg class="bl-plot bl-plot--dual" viewBox="0 0 {{ $pw }} {{ $ph }}"
                 preserveAspectRatio="none" role="img"
                 aria-label="Revenue and orders over the period, each against its own scale">
                <defs>
                    <linearGradient id="{{ $gradId }}" x1="0" y1="0" x2="0" y2="1">
                        <stop class="bl-plot__from" offset="0%"></stop>
                        <stop class="bl-plot__to" offset="100%"></stop>
                    </linearGradient>
                </defs>

                @foreach([0.25, 0.5, 0.75] as $g)
                    <line class="bl-plot__grid" x1="0" x2="{{ $pw }}"
                          y1="{{ $ph * $g }}" y2="{{ $ph * $g }}"></line>
                @endforeach

                <path class="bl-trace__area" d="{{ $revArea }}" fill="url(#{{ $gradId }})"></path>
                <path class="bl-trace bl-trace--money" d="{{ $revLine }}"></path>
                <path class="bl-trace bl-trace--count" d="{{ $ordLine }}"></path>

                @for($i = 0; $i < $n; $i++)
                    @php
                        $hx = round($i / ($n - 1) * $pw, 2);
                        $hw = $pw / ($n - 1);
                    @endphp
                    <rect class="bl-plot__hit" x="{{ max(0, $hx - $hw / 2) }}" y="0"
                          width="{{ $hw }}" height="{{ $ph }}">
                        <title>{{ $labels[$i] ?? '' }} &middot; {{ Money::base($rev[$i]) }} &middot; {{ number_format($ord[$i]) }} {{ Str::plural('order', $ord[$i]) }}</title>
                    </rect>
                @endfor
            </svg>

            <span class="bl-guide" data-plot-guide aria-hidden="true"></span>
            <span class="bl-dot bl-dot--money" data-plot-dot="revenue" aria-hidden="true"></span>
            <span class="bl-dot bl-dot--count" data-plot-dot="orders" aria-hidden="true"></span>

            <div class="bl-readout" data-plot-readout aria-hidden="true">
                <span class="bl-readout__d" data-plot-date></span>
                <span class="bl-readout__r">
                    <i class="bl-readout__k bl-readout__k--money"></i>
                    <span data-plot-money></span>
                </span>
                <span class="bl-readout__r">
                    <i class="bl-readout__k bl-readout__k--count"></i>
                    <span data-plot-count></span>
                </span>
            </div>
        </div>

        <div class="bl-axis">
            @foreach($ticks as $t)
                <span>{{ $labels[$t] ?? '' }}</span>
            @endforeach
        </div>
    </div>
@endif
