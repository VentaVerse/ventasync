@php
    use App\Support\Money;
    use Illuminate\Support\Str;

    $rev = array_map('floatval', $revenue ?? []);
    $ord = array_map('floatval', $orders ?? []);
    $n = min(count($rev), count($ord));
    $rev = array_slice($rev, 0, $n);
    $ord = array_slice($ord, 0, $n);
    $revPeak = $rev === [] ? 0.0 : max($rev);
    $ordPeak = $ord === [] ? 0.0 : max($ord);
    $hasPlot = $n > 1 && ($revPeak > 0 || $ordPeak > 0);

    $slot = 10; $bar = 3.6; $ph = 100; $top = 4;
    $height = fn (float $v, float $peak) => $peak <= 0 ? 0.0 : round(max(0.0, $v) / $peak * ($ph - $top), 2);

    $tickStep = max(1, (int) ceil(($n - 1) / 7));
    $ticks = [];
    for ($t = 0; $t < $n; $t += $tickStep) { $ticks[] = $t; }
    if ($ticks !== [] && end($ticks) !== $n - 1) { $ticks[] = $n - 1; }

    $series = [
        ['key' => 'money', 'values' => $rev, 'peak' => $revPeak, 'x' => 1.2],
        ['key' => 'count', 'values' => $ord, 'peak' => $ordPeak, 'x' => 5.2],
    ];
@endphp

@if($hasPlot)
    <div class="bl-plotcard cw-bars"
         data-bars="{{ json_encode([
             'labels' => array_map(fn ($i) => $labels[$i] ?? '', range(0, $n - 1)),
             'money'  => array_map(fn ($v) => Money::base((float) $v), $rev),
             'counts' => array_map(fn ($v) => number_format($v) . ' ' . Str::plural('order', $v), $ord),
         ]) }}">
        <div class="bl-key">
            <span class="bl-key__item">
                <i class="cw-bars__sw cw-bars__sw--money" aria-hidden="true"></i>
                Revenue
                <b class="bl-key__peak">peak {{ Money::base($revPeak) }}</b>
            </span>
            <span class="bl-key__item">
                <i class="cw-bars__sw cw-bars__sw--count" aria-hidden="true"></i>
                Orders
                <b class="bl-key__peak">peak {{ number_format($ordPeak) }}</b>
            </span>
        </div>

        <div class="cw-bars__wrap" data-bars-wrap>
            <svg class="cw-bars__svg" viewBox="0 0 {{ $n * $slot }} {{ $ph }}" preserveAspectRatio="none" role="img"
                 aria-label="{{ $ariaLabel ?? 'Revenue and orders per day, each against its own peak' }}">
                @foreach([0.25, 0.5, 0.75] as $g)
                    <line class="cw-bars__grid" x1="0" x2="{{ $n * $slot }}" y1="{{ $ph * $g }}" y2="{{ $ph * $g }}"></line>
                @endforeach
                <line class="cw-bars__base" x1="0" x2="{{ $n * $slot }}" y1="{{ $ph }}" y2="{{ $ph }}"></line>
                @for($i = 0; $i < $n; $i++)
                    @foreach($series as $s)
                        @php $h = $height($s['values'][$i], $s['peak']); @endphp
                        @if($h > 0)
                            <rect class="cw-bars__bar cw-bars__bar--{{ $s['key'] }}" data-i="{{ $i }}"
                                  x="{{ $i * $slot + $s['x'] }}" y="{{ $ph - $h }}" width="{{ $bar }}" height="{{ $h }}">
                                <title>{{ $labels[$i] ?? '' }} &middot; {{ $s['key'] === 'money' ? Money::base($s['values'][$i]) : number_format($s['values'][$i]) . ' ' . Str::plural('order', $s['values'][$i]) }}</title>
                            </rect>
                        @endif
                    @endforeach
                @endfor
            </svg>

            <div class="bl-readout" data-bars-readout aria-hidden="true">
                <span class="bl-readout__d" data-bars-date></span>
                <span class="bl-readout__r">
                    <i class="bl-readout__k bl-readout__k--money"></i>
                    <span data-bars-money></span>
                </span>
                <span class="bl-readout__r">
                    <i class="bl-readout__k bl-readout__k--count"></i>
                    <span data-bars-count></span>
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
