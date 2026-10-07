<div class="x-select-wrap {{ $wrapClass ?? '' }}">
    <select {{ $attributes->merge(['class' => 'x-input']) }}>{{ $slot }}</select>
    <x-ui.icon name="chevron-down" size="14" class="x-select-wrap__chevron" />
</div>
