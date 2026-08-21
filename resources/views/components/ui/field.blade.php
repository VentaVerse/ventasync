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
            @if($required)<span class="fm-req" data-required-marker>Required</span>@endif
        </label>
    @endif

    {{ $slot }}

    {{-- The hint and the error carry ids so the control can point at them.
         The wiring itself is done once at runtime by resources/js/field-a11y.js
         rather than here, because this component receives its control as an
         opaque slot and cannot reach into it - and the 49 callers pass
         everything from x-ui.input to a select to hand-rolled markup, so
         there is no single child component to push the attribute through.
         The ids are stable and derived from the control id, so the wiring has
         something deterministic to find. --}}
    @if($hint)<div class="fm-hint" @if($controlId) id="{{ $controlId }}-hint" @endif>{{ $hint }}</div>@endif

    {{-- Same position the old views put @error in: directly under the
         control it belongs to, one message, never a summary at the top. --}}
    @if($message)<div class="fm-error" @if($controlId) id="{{ $controlId }}-error" @endif>{{ $message }}</div>@endif
</div>
