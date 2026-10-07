@props([
    'label' => null,
    'for' => null,
    'name' => null,
    'required' => false,
    'hint' => null,
    'wide' => false,
    'errorKey' => null,
])

@php
    $key = $errorKey ?? $name;
    $message = $key ? $errors->first($key) : null;
    $controlId = $for ?? ($name ? 'f-'.\Illuminate\Support\Str::slug($name, '-') : null);
@endphp

<div {{ $attributes->merge(['class' => 'fm-field'.($wide ? ' fm-field--wide' : '')]) }}>
    @if($label)
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

    @if($message)<div class="fm-error" @if($controlId) id="{{ $controlId }}-error" @endif>{{ $message }}</div>@endif
</div>
