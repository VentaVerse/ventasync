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
{{-- $trigger is an optional named slot for callers that need a custom
     trigger face (e.g. an avatar and name instead of the default kebab
     icon) - the account menu in layouts/shell.blade.php is the first
     consumer. Omitting it keeps every existing caller (order row actions,
     the ui-kit-v2 reference page) pixel-identical to before. $attributes
     lets a caller add its own modifier class to the root element without
     this component needing to know what that class does. --}}
<div {{ $attributes->merge(['class' => 'x-menu']) }} x-data="{ open: false }" @keydown.escape.window="if (open) { open = false; $refs.trigger.focus() }">
    <button type="button"
            x-ref="trigger"
            class="x-menu__trigger{{ isset($trigger) ? ' x-menu__trigger--custom' : '' }}"
            :aria-expanded="open ? 'true' : 'false'"
            aria-haspopup="true"
            aria-label="{{ $label }}"
            @click.stop="open = !open">
        @isset($trigger)
            {{ $trigger }}
        @else
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">
                <circle cx="12" cy="5" r="1"/><circle cx="12" cy="12" r="1"/><circle cx="12" cy="19" r="1"/>
            </svg>
        @endisset
    </button>
    <div class="x-menu__panel" role="group" aria-label="{{ $label }}" x-show="open" x-cloak x-transition.duration.120ms @click.outside="open = false">
        {{ $slot }}
    </div>
</div>
