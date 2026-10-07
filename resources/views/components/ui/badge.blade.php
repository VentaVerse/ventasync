@props(['tone' => 'neutral', 'dot' => true])

<span {{ $attributes->merge(['class' => 'x-badge x-badge--' . $tone]) }}>
    @if($dot)<span class="x-badge__dot" aria-hidden="true"></span>@endif
    <span class="x-badge__label">{{ $slot }}</span>
</span>
