@props([
    'label',
    'count' => null,
    'hint' => null,
])

@php
    $foldId = 'fold-' . \Illuminate\Support\Str::random(10);
@endphp

{{-- A toolbar section that folds behind its own button.

     Twenty six controls stood between the heading of an orders page and the
     first order a packer could work, in four stacked bands, putting the work
     itself 517px down a 900px screen. Grouping by PURPOSE is what fixes that:
     the marketplace pull is periodic maintenance, the date and sort controls
     are per-session, and neither is touched per order.

     x-show, not a template: the panel keeps its fields in the DOM while
     closed, so a folded date still submits with its form. Removing them would
     silently drop a filter the moment someone collapsed the panel.

     The trigger carries the state a closed panel would otherwise hide. A fold
     that conceals an ACTIVE filter is worse than no fold at all, because the
     list is then narrowed by something invisible. --}}
<div {{ $attributes->merge(['class' => 'x-fold']) }}
     x-data="{ open: false }"
     @keydown.escape.window="if (open) { open = false; $refs.foldTrigger.focus() }">

    <button type="button"
            x-ref="foldTrigger"
            id="{{ $foldId }}-t"
            class="x-fold__trigger"
            {{-- No aria-haspopup. It defaults to "menu", and this panel is a
                 role="group" of form fields, so a screen reader was promised
                 a menu and handed a group. aria-expanded plus aria-controls
                 is the whole contract a disclosure needs. --}}
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
