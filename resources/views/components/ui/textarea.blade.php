{{-- Multi-line control. Shares .x-input so border, focus ring and invalid
     state are the ones every other control on the page uses;
     components/form-page.css gives textarea.x-input its own height and
     padding, since it is the one control that cannot be 32px tall. --}}
<textarea {{ $attributes->merge(['class' => 'x-input']) }}>{{ $slot }}</textarea>
