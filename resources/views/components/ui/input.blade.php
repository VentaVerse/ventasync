@props(['type' => 'text'])

<input type="{{ $type }}" {{ $attributes->merge(['class' => 'x-input']) }}>
