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

        /* HOW overdue, not just THAT it is overdue.
         *
         * This printed the literal string OVERDUE, so an order one hour late
         * and an order three weeks late rendered the same chip. The whole
         * point of a countdown is triage, and it stopped working at exactly
         * the moment triage starts mattering - on a To Ship queue where four
         * rows all read OVERDUE there is nothing to sort by.
         *
         * Same units as the countdown above it, so the two read as one scale
         * running past zero rather than as two different displays. */
        if ($diff < 0) {
            $late = -$diff;
            $ld = intdiv($late, 86400);
            $lh = intdiv($late % 86400, 3600);
            $lm = intdiv($late % 3600, 60);
            if ($ld > 0)      $initial = "{$ld}d {$lh}h";
            elseif ($lh > 0)  $initial = "{$lh}h {$lm}m";
            else              $initial = "{$lm}m";
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

        /* Past the deadline the label is the sentence, not the prefix.
         *
         * The label was a fixed prop and the time carried the word "late", so
         * an overdue chip printed "Ship by 33d 2h late" - nine of ten rows on
         * a To Pack queue reading as a broken sentence. The one element on
         * the page built to drive triage under time pressure was the one that
         * did not parse, and staff learn to read the colour and skip the
         * words. Now it reads "Overdue 33d 2h", same units, same scale
         * running past zero.
         *
         * The original label rides along in data-sla-label because the 30s
         * ticker has to put it back when a chip is refreshed but not overdue,
         * and it cannot know what the caller passed. */
        $shownLabel = $diff < 0 ? 'Overdue' : $label;

        $deadlineLabel = date('Y-m-d H:i', $ts);
    @endphp
    <span class="sla-chip-wrap">
        <span class="sla-chip {{ $tier }}"
              data-sla-deadline="{{ $ts }}"
              data-sla-label="{{ $label }}"
              title="{{ $label }} {{ $deadlineLabel }}">
            <svg class="sla-chip__icon" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <circle cx="8" cy="8.5" r="5.5"/>
                <path d="M8 5.5v3l2 1.5"/>
                <path d="M6 1.8h4"/>
            </svg>
            <span class="sla-chip__label">{{ $shownLabel }}</span>
            <span class="sla-chip__time">{{ $initial }}</span>
            {{-- The date is VISIBLE now, not hidden behind a hover.

                 It lived in title= and a screen-reader span, so a mouse user
                 got it after about a second of hovering and a phone user
                 never got it at all. DESIGN.md already rejects title as a
                 sole reading path for exactly this reason, in the charts
                 section, and the chip predated that lesson.

                 The countdown answers "how long"; the date answers "by when",
                 and a packer deciding what to touch next needs both. title=
                 stays for the mouse; the screen-reader span goes, because a
                 visible date makes it a duplicate announcement. --}}
        </span>
        <span class="sla-chip__when">{{ $deadlineLabel }}</span>
    </span>
@endif
