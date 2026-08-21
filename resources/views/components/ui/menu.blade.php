@props(['label' => 'Actions'])

@php
    // A stable handle for this instance, so the panel can point at its own
    // trigger rather than repeating the trigger's name.
    $menuId = 'menu-' . \Illuminate\Support\Str::random(10);
@endphp

{{-- The popup below is a plain group of native <a>/<button> items, not a
     full ARIA menu widget. It has no arrow-key, Home/End or typeahead
     navigation, only the browser's ordinary Tab order between items. Using
     role="menu" with role="menuitem" would announce "menu, N items" and set
     an expectation of arrow-key navigation this component does not provide,
     so the popup carries role="group" instead and each item keeps its
     native link/button semantics, which matches what actually happens when
     a keyboard user reaches it. If true APG menu behaviour is needed later,
     switch both roles back and add the arrow-key handling in the same
     change. --}}
{{-- $trigger is an optional named slot for callers that need a custom
     trigger face (e.g. an avatar and name instead of the default kebab
     icon) - the account menu in layouts/shell.blade.php is the first
     consumer. Omitting it keeps every existing caller (order row actions,
     the ui-kit-v2 reference page) pixel-identical to before. $attributes
     lets a caller add its own modifier class to the root element without
     this component needing to know what that class does. --}}
{{-- THE PANEL IS FIXED AND PLACED BY SCRIPT, not absolute inside the row.

     Every list in this product renders through <x-ui.table>, which wraps its
     table in .x-table-wrap { overflow-x: auto } so a wide table scrolls
     inside its own box rather than dragging the page sideways. But
     overflow-x: auto forces the OTHER axis from visible to auto - that is the
     spec, not a bug - so the wrapper clipped vertically too, and a row menu
     on the last rows of any table was cut off mid-item. Screenshotted on the
     Lazada group listing: "Push stock" sheared in half at the wrapper's edge.

     Taking the panel out of flow is what fixes it, and it buys the second
     half of the same problem for free: the last row of a long table has no
     room BELOW it either, so the panel now flips above its trigger when that
     is where the space is. An absolutely positioned panel could do neither.

     The cost is that a fixed element does not travel with a scrolling
     ancestor, so place() re-runs on scroll (captured at the window, which is
     the only way to hear the table's own horizontal scroll - scroll events do
     not bubble) and on resize. Both bail immediately while closed. --}}
<div {{ $attributes->merge(['class' => 'x-menu']) }}
     x-data="{
        open: false,
        place() {
            const t = $refs.trigger, p = $refs.panel;
            if (!t || !p) return;

            const r = t.getBoundingClientRect();
            const gap = 4, edge = 8;
            const h = p.offsetHeight, w = p.offsetWidth;

            /* Below by default, above when below does not fit and above
               fits better. Not simply 'above when near the bottom': on a
               short viewport neither side fits and the clamp below is what
               keeps the panel on screen. */
            const room = window.innerHeight - r.bottom - gap;
            const up = room < h && (r.top - gap) > room;

            let top = up ? r.top - gap - h : r.bottom + gap;
            top = Math.max(edge, Math.min(top, window.innerHeight - h - edge));

            /* Right edge to right edge, as it read before. Clamped so a
               trigger near the left edge cannot push the panel off it. */
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
            {{-- Placed after it is shown, because a display:none panel
                 measures 0 and there would be nothing to place. Alpine's
                 enter transition starts at opacity 0, so the frame it
                 spends unplaced is a frame nobody sees. --}}
            @click.stop="open = !open; if (open) $nextTick(() => place())">
        @isset($trigger)
            {{ $trigger }}
        @else
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">
                <circle cx="12" cy="5" r="1"/><circle cx="12" cy="12" r="1"/><circle cx="12" cy="19" r="1"/>
            </svg>
        @endisset
    </button>
    {{-- The panel is named BY its trigger, not again. Labelling both put
         the same string on the button and on the group it opens, so ten
         row menus announced twenty identical names and read as twenty
         menus to anyone counting. --}}
    <div class="x-menu__panel" x-ref="panel" role="group" aria-labelledby="{{ $menuId }}-t" x-show="open" x-cloak x-transition.duration.120ms @click.outside="open = false">
        {{ $slot }}
    </div>
</div>
