@props(['label' => 'Actions'])

<div class="x-menu" x-data="{ open: false }" @keydown.escape.window="open = false">
    <button type="button"
            class="x-menu__trigger"
            :aria-expanded="open ? 'true' : 'false'"
            aria-haspopup="menu"
            aria-label="{{ $label }}"
            @click.stop="open = !open">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">
            <circle cx="12" cy="5" r="1"/><circle cx="12" cy="12" r="1"/><circle cx="12" cy="19" r="1"/>
        </svg>
    </button>
    <div class="x-menu__panel" role="menu" x-show="open" x-cloak x-transition.duration.120ms @click.outside="open = false">
        {{ $slot }}
    </div>
</div>
