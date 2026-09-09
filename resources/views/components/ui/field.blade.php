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
    /* Said BEFORE a push, not only after the marketplace refuses: a
       required field that is still empty says so where it is empty, the
       way Lazada's attribute rows have since they were built. The
       marketplace's own refusal, when there is one, wins the line -
       "Required before a push" under a field that has just been refused
       for a different reason would be the smaller truth. */
    'need' => null,
])

@php
    $key = $errorKey ?? $name;
    $message = $key ? $errors->first($key) : null;
    $controlId = $for ?? ($name ? 'f-'.\Illuminate\Support\Str::slug($name, '-') : null);
@endphp

<div {{ $attributes->merge(['class' => 'fm-field'.($wide ? ' fm-field--wide' : '')]) }}>
    @if($label)
        {{-- A description is a tooltip, not a paragraph (the owner, 2026-09-05:
             "anything that is a description, make a tooltip out of it"). The
             hint rides the label ROW as the ? button - one flex line with the
             label, never a line of its own; its bubble keeps the
             {controlId}-hint id so field-a11y.js still points the control at
             it, and a screen reader still hears it. --}}
        <div class="fm-label-row">
            <label class="fm-label" @if($controlId) for="{{ $controlId }}" @endif>
                {{ $label }}
                @if($required)<span class="fm-req" data-required-marker aria-hidden="true">*</span><span class="x-sr">(required)</span>@endif
            </label>
            @if($hint)<x-ui.hint class="fm-label__hint" :bubble-id="$controlId ? $controlId . '-hint' : null" :label="'About ' . $label">{{ $hint }}</x-ui.hint>@endif
        </div>
    @elseif($hint)
        <x-ui.hint class="fm-label__hint" :bubble-id="$controlId ? $controlId . '-hint' : null">{{ $hint }}</x-ui.hint>
    @endif

    {{ $slot }}

    {{-- Same position the old views put @error in: directly under the
         control it belongs to, one message, never a summary at the top. --}}
    @if($message)<div class="fm-error" @if($controlId) id="{{ $controlId }}-error" @endif>{{ $message }}</div>
    @elseif($need)<div class="fm-need" @if($controlId) id="{{ $controlId }}-error" @endif>{{ $need }}</div>@endif
</div>
