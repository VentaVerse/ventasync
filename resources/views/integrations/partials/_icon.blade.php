{{-- Resolves a small set of icon string keys to inline SVG. Unknown keys
     fall through to the first letter of the integration name. --}}
@php($iconKey = $icon ?? '')
@switch($iconKey)
    @case('video')
        <svg viewBox="0 0 24 24"><polygon points="23 7 16 12 23 17 23 7"/><rect x="1" y="5" width="15" height="14" rx="2" ry="2"/></svg>
        @break
    @case('truck')
        <svg viewBox="0 0 24 24"><rect x="1" y="3" width="15" height="13"/><polygon points="16 8 20 8 23 11 23 16 16 16 16 8"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/></svg>
        @break
    @case('cart')
        <svg viewBox="0 0 24 24"><circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/></svg>
        @break
    @case('shop')
        <svg viewBox="0 0 24 24"><path d="M3 9l1-5h16l1 5"/><path d="M5 9v11h14V9"/><path d="M9 22V12h6v10"/></svg>
        @break
    @case('shopee')
        <svg viewBox="0 0 24 24"><path d="M3 7h18l-1.5 12a2 2 0 0 1-2 1.8H6.5a2 2 0 0 1-2-1.8L3 7z"/><path d="M8 7a4 4 0 0 1 8 0"/></svg>
        @break
    @case('lazada')
        <svg viewBox="0 0 24 24"><path d="M12 2 4 6v6c0 5 3.5 9 8 10 4.5-1 8-5 8-10V6l-8-4z"/></svg>
        @break
    @default
        <span>{{ strtoupper(substr($name ?? $iconKey, 0, 1)) }}</span>
@endswitch
