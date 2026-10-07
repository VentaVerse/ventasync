@props([
    'label',
    'count' => null,
    'hint' => null,
])

@php
    $foldId = 'fold-' . \Illuminate\Support\Str::random(10);
@endphp

<div {{ $attributes->merge(['class' => 'x-fold']) }}
     x-data="{ open: false }"
     @keydown.escape.window="if (open) { open = false; $refs.foldTrigger.focus() }">

    <button type="button"
            x-ref="foldTrigger"
            id="{{ $foldId }}-t"
            class="x-fold__trigger"
            aria-controls="{{ $foldId }}-p"
            :aria-expanded="open ? 'true' : 'false'"
            @click.stop="open = !open">
        <span>{{ $label }}</span>
        @if($count)
            <span class="x-fold__count">{{ $count }}</span>
        @endif
        <x-ui.icon name="chevron-down" size="13" class="x-fold__chev" ::class="open && 'is-open'" />
    </button>

    <div id="{{ $foldId }}-p"
         class="x-fold__panel"
         role="group"
         aria-labelledby="{{ $foldId }}-t"
         x-show="open"
         x-cloak
         x-transition.duration.120ms
         @click.outside="open = false">
        @if($hint)
            <p class="x-fold__hint">{{ $hint }}</p>
        @endif
        {{ $slot }}
    </div>
</div>
