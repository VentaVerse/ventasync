@props(['label' => 'Actions'])

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
<div class="x-menu" x-data="{ open: false }" @keydown.escape.window="if (open) { open = false; $refs.trigger.focus() }">
    <button type="button"
            x-ref="trigger"
            class="x-menu__trigger"
            :aria-expanded="open ? 'true' : 'false'"
            aria-haspopup="true"
            aria-label="{{ $label }}"
            @click.stop="open = !open">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">
            <circle cx="12" cy="5" r="1"/><circle cx="12" cy="12" r="1"/><circle cx="12" cy="19" r="1"/>
        </svg>
    </button>
    <div class="x-menu__panel" role="group" aria-label="{{ $label }}" x-show="open" x-cloak x-transition.duration.120ms @click.outside="open = false">
        {{ $slot }}
    </div>
</div>
