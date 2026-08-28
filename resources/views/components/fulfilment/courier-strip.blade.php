@props([
    // What App\Support\Fulfilment\CourierTally::forStrip() returns.
    'tally',
    // The panel's own URL builder: fn (array $params, array $except) => string.
    'selfUrl',
])
@php
    $items = $tally['items'] ?? [];
    $active = (string) ($tally['active'] ?? '');
    $total = (int) ($tally['total'] ?? 0);
@endphp
@if($items !== [])
{{-- THE COURIER IS THE THIRD LEVEL OF THE SAME CONTROL.

     This was a row of 12px chips under a caption, a footnote to the step
     above it, and the packing bench could not use it for anything but
     reading. Riders arrive one courier at a time and in no fixed order, so
     the bench packs J&T's orders first when J&T's rider comes first, and
     hands J&T's parcels over when that rider is at the door, oldest first
     within each. That is a filter, so it is drawn as one on every To Ship
     step: the same strip the steps use, one pill per courier, the count in
     the same place, and the list under it narrows to what was picked.

     "All couriers" is a pill and not just the absence of one, because the
     way back has to be as visible as the way in. Zero is impossible here (a
     courier with no parcels is not in the tally), so nothing goes inert. --}}
<nav class="x-segment x-segment--sub x-segment--courier" aria-label="Courier">
    <span class="x-segment__lead">Courier</span>
    <a href="{{ $selfUrl([], ['page', 'courier']) }}"
       class="x-segment__item {{ $active === '' ? 'is-active' : '' }}"
       @if($active === '') aria-current="true" @endif>
        <span>All couriers</span>
        <span class="x-segment__count">{{ number_format($total) }}</span>
    </a>
    @foreach($items as $key => $item)
        <a href="{{ $selfUrl(['courier' => $key], ['page']) }}"
           class="x-segment__item {{ $active === (string) $key ? 'is-active' : '' }}"
           @if($active === (string) $key) aria-current="true" @endif>
            <span>{{ $item['label'] }}</span>
            <span class="x-segment__count">{{ number_format($item['count']) }}</span>
        </a>
    @endforeach
</nav>
@endif
