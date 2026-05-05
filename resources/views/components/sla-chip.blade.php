@props([
    'deadline' => null,   // Unix timestamp (seconds) — required to render
    'label'    => 'Ship by',
])

@php
    $ts = is_numeric($deadline) ? (int) $deadline : null;
@endphp

@if($ts && $ts > 0)
    @php
        $diff = $ts - time();
        if ($diff < 0)              $tier = 'sla-chip--overdue';
        elseif ($diff < 4 * 3600)   $tier = 'sla-chip--crit';
        elseif ($diff < 24 * 3600)  $tier = 'sla-chip--warn';
        else                        $tier = 'sla-chip--ok';

        if ($diff < 0) {
            $initial = 'OVERDUE';
        } else {
            $d = intdiv($diff, 86400);
            $h = intdiv($diff % 86400, 3600);
            $m = intdiv($diff % 3600, 60);
            $s = $diff % 60;
            if ($d > 0)      $initial = "{$d}d {$h}h";
            elseif ($h > 0)  $initial = "{$h}h {$m}m";
            elseif ($m > 0)  $initial = "{$m}m " . str_pad($s, 2, '0', STR_PAD_LEFT) . 's';
            else             $initial = "{$s}s";
        }

        $deadlineLabel = date('Y-m-d H:i', $ts);
    @endphp
    <span class="sla-chip-wrap">
        <span class="sla-chip {{ $tier }}"
              data-sla-deadline="{{ $ts }}"
              title="Ship by {{ $deadlineLabel }}">
            <svg class="sla-chip__icon" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <circle cx="8" cy="8.5" r="5.5"/>
                <path d="M8 5.5v3l2 1.5"/>
                <path d="M6 1.8h4"/>
            </svg>
            <span class="sla-chip__label">{{ $label }}</span>
            <span class="sla-chip__time">{{ $initial }}</span>
        </span>
    </span>
@endif
