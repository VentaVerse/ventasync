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

    $slot = 10; $bar = 7; $ph = 100;
    $height = fn (float $v, float $peak) => $peak <= 0 ? 0.0 : round(max(0.0, $v) / $peak * $ph, 2);

    $tickStep = max(1, (int) ceil(($n - 1) / 7));
    $ticks = [];
    for ($t = 0; $t < $n; $t += $tickStep) { $ticks[] = $t; }
    if ($ticks !== [] && end($ticks) !== $n - 1) { $ticks[] = $n - 1; }

    $rows = [
        ['key' => 'money', 'label' => 'Revenue', 'values' => $rev, 'peak' => $revPeak, 'peakText' => Money::base($revPeak)],
        ['key' => 'count', 'label' => 'Orders', 'values' => $ord, 'peak' => $ordPeak, 'peakText' => number_format($ordPeak)],
    ];
@endphp

@if($hasPlot)
    <div class="bl-plotcard cw-bars"
         data-bars="{{ json_encode([
             'labels' => array_map(fn ($i) => $labels[$i] ?? '', range(0, $n - 1)),
             'money'  => array_map(fn ($v) => Money::base((float) $v), $rev),
             'counts' => array_map(fn ($v) => number_format($v) . ' ' . Str::plural('order', $v), $ord),
         ]) }}">
        <div class="cw-bars__wrap" data-bars-wrap>
            @foreach($rows as $row)
                <div class="cw-bars__row">
                    <div class="cw-bars__head">
                        <span class="cw-bars__k">{{ $row['label'] }}</span>
                        <span class="cw-bars__peak">peak {{ $row['peakText'] }}</span>
                    </div>
                    <svg class="cw-bars__svg cw-bars__svg--{{ $row['key'] }}" viewBox="0 0 {{ $n * $slot }} {{ $ph }}"
                         preserveAspectRatio="none" role="img" aria-label="{{ $row['label'] }} per day">
                        <line class="cw-bars__base" x1="0" x2="{{ $n * $slot }}" y1="{{ $ph }}" y2="{{ $ph }}"></line>
                        @foreach($row['values'] as $i => $v)
                            @php $h = $height($v, $row['peak']); @endphp
                            @if($h > 0)
                                <rect class="cw-bars__bar" data-i="{{ $i }}" x="{{ $i * $slot + ($slot - $bar) / 2 }}" y="{{ $ph - $h }}"
                                      width="{{ $bar }}" height="{{ $h }}">
                                    <title>{{ $labels[$i] ?? '' }} &middot; {{ $row['key'] === 'money' ? Money::base($v) : number_format($v) . ' ' . Str::plural('order', $v) }}</title>
                                </rect>
                            @endif
                        @endforeach
                    </svg>
                </div>
            @endforeach

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
