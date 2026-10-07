@props(['variant' => 'secondary', 'size' => 'md', 'href' => null, 'type' => 'button'])

@php
    $classes = 'x-btn x-btn--' . $variant . ' x-btn--' . $size;
@endphp

@if($href)
    <a href="{{ $href }}" {{ $attributes->merge(['class' => $classes]) }}>{{ $slot }}</a>
@else
    <button type="{{ $type }}" {{ $attributes->merge(['class' => $classes]) }}>{{ $slot }}</button>
@endif
