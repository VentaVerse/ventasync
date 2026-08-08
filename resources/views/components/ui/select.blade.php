{{-- A native <select> in the design system's clothes. The element itself is
     real, so it keeps the OS popup, keyboard behaviour and form semantics;
     components/ui-controls.css strips the system chrome and this wrapper
     positions the chevron over where the native arrow used to be. Same
     construction the products index already uses inline for its status
     filter, lifted into a component now that form pages need it repeatedly. --}}
<div class="x-select-wrap {{ $wrapClass ?? '' }}">
    <select {{ $attributes->merge(['class' => 'x-input']) }}>{{ $slot }}</select>
    <x-ui.icon name="chevron-down" size="14" class="x-select-wrap__chevron" />
</div>
