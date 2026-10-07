@props(['label' => 'Actions'])

@php
    $menuId = 'menu-' . \Illuminate\Support\Str::random(10);
@endphp

<div {{ $attributes->merge(['class' => 'x-menu']) }}
     x-data="{
        open: false,
        place() {
            const t = $refs.trigger, p = $refs.panel;
            if (!t || !p) return;

            const r = t.getBoundingClientRect();
            const gap = 4, edge = 8;
            const h = p.offsetHeight, w = p.offsetWidth;

            const room = window.innerHeight - r.bottom - gap;
            const up = room < h && (r.top - gap) > room;

            let top = up ? r.top - gap - h : r.bottom + gap;
            top = Math.max(edge, Math.min(top, window.innerHeight - h - edge));

            let left = r.right - w;
            left = Math.max(edge, Math.min(left, window.innerWidth - w - edge));

            p.style.top = top + 'px';
            p.style.left = left + 'px';
        },
     }"
     @keydown.escape.window="if (open) { open = false; $refs.trigger.focus() }"
     @scroll.window.capture="if (open) place()"
     @resize.window="if (open) place()">
    <button type="button"
            id="{{ $menuId }}-t"
            x-ref="trigger"
            class="x-menu__trigger{{ isset($trigger) ? ' x-menu__trigger--custom' : '' }}"
            :aria-expanded="open ? 'true' : 'false'"
            aria-haspopup="true"
            aria-label="{{ $label }}"
            @click.stop="open = !open; if (open) $nextTick(() => place())">
        @isset($trigger)
            {{ $trigger }}
        @else
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">
                <circle cx="12" cy="5" r="1"/><circle cx="12" cy="12" r="1"/><circle cx="12" cy="19" r="1"/>
            </svg>
        @endisset
    </button>
    <div class="x-menu__panel" x-ref="panel" role="group" aria-labelledby="{{ $menuId }}-t" x-show="open" x-cloak x-transition.duration.120ms @click.outside="open = false">
        {{ $slot }}
    </div>
</div>
