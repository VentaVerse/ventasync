@props([
    'label' => null,
    'for' => null,
    'name' => null,
    'required' => false,
    'hint' => null,
    'wide' => false,
    // Pass an explicit key when the control's error lives under a different
    // name than the field's own (the manufacturer typeahead shows
    // manufacturer_name's error beside the visible text input, exactly where
    // the pre-redesign view put it).
    'errorKey' => null,
])

@php
    $key = $errorKey ?? $name;
    $message = $key ? $errors->first($key) : null;
    $controlId = $for ?? ($name ? 'f-'.\Illuminate\Support\Str::slug($name, '-') : null);
@endphp

<div {{ $attributes->merge(['class' => 'fm-field'.($wide ? ' fm-field--wide' : '')]) }}>
    @if($label)
        <label class="fm-label" @if($controlId) for="{{ $controlId }}" @endif>
            {{ $label }}
            @if($required)<span class="fm-req">Required</span>@endif
        </label>
    @endif

    {{ $slot }}

    @if($hint)<div class="fm-hint">{{ $hint }}</div>@endif

    {{-- Same position the old views put @error in: directly under the
         control it belongs to, one message, never a summary at the top. --}}
    @if($message)<div class="fm-error">{{ $message }}</div>@endif
</div>
