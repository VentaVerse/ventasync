@props([
    'tally',
    'selfUrl',
])
@php
    $items = $tally['items'] ?? [];
    $active = (string) ($tally['active'] ?? '');
    $total = (int) ($tally['total'] ?? 0);
@endphp
@if($items !== [])
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
