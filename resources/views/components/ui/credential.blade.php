@props([
    'name',
    'id' => null,
    'label' => null,
    'hint' => null,
    'channel' => null,
    'store' => null,
    'length' => 0,
    'canReveal' => false,
    'disabled' => false,
    'required' => false,
    'placeholder' => null,
])

@php
    $id = $id ?? 'cred-' . str_replace('_', '-', $name);
    $stored = (int) $length > 0;

    $dots = str_repeat('•', min((int) $length, 48));
@endphp

<x-ui.field :label="$label" :for="$id" :name="$name" :hint="$hint" :required="$required" wide>
    {{-- Keep the x-data payload on one line; newlines break Blade's component tokenizer. --}}
    @php
        $bind = $stored && $canReveal
            ? json_encode(['channel' => $channel, 'store' => $store, 'field' => $name, 'mask' => $dots])
            : null;
    @endphp

    <div @if($bind) x-data="credentialField({{ $bind }})" @endif>
    <div class="x-cred">
        <x-ui.input
            :id="$id"
            :name="$name"
            type="password"
            autocomplete="off"
            spellcheck="false"
            :disabled="$disabled"
            :required="$required && ! $stored"
            :value="$stored ? $dots : ''"
            :placeholder="$stored ? null : $placeholder"
            :readonly="$stored"
            data-credential-mask="{{ $stored ? '1' : '0' }}"
            x-ref="input"
            class="x-cred__input" />

        @if($bind)
            <button type="button" class="x-cred__eye"
                    x-show="! edited"
                    x-ref="toggle"
                    @click="toggle()"
                    :aria-pressed="shown ? 'true' : 'false'"
                    :aria-label="shown ? 'Hide {{ $label ?? $name }}' : 'View and edit {{ $label ?? $name }}'"
                    :disabled="busy">
                <template x-if="! shown"><x-ui.icon name="eye" size="14" /></template>
                <template x-if="shown"><x-ui.icon name="eye-off" size="14" /></template>
                <span class="x-cred__eye-label" x-text="shown ? 'Hide' : 'View and edit'">View and edit</span>
            </button>
        @endif
    </div>

    @if($stored)
        <p class="x-cred__meta">
            <span class="x-num">{{ number_format($length) }}</span> stored characters
            @if($canReveal)
                <span class="x-cred__sep" aria-hidden="true">&middot;</span>
                <span x-show="! edited">read-only until you choose to edit</span>
                <span x-show="edited" x-cloak>edited, not yet saved</span>
            @endif
        </p>
    @endif
    </div>
</x-ui.field>
