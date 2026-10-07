@props([
    'label' => 'More information',
    'bubbleId' => null,
])

@php
    $hintId = $bubbleId ?: 'hint-' . \Illuminate\Support\Str::random(10);
@endphp

<span {{ $attributes->merge(['class' => 'bl-hint']) }}>
    <button type="button" class="bl-hint__btn"
            aria-label="{{ $label }}" aria-describedby="{{ $hintId }}">
        <span aria-hidden="true">?</span>
    </button>
    <span class="bl-hint__bubble" role="tooltip" id="{{ $hintId }}">{{ $slot }}</span>
</span>
