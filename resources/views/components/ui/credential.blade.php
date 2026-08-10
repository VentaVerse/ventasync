@props([
    // The form field name, which is also the name the reveal endpoint checks
    // against the channel's allowlist.
    'name',
    'id' => null,
    'label' => null,
    'hint' => null,
    // The channel this credential belongs to, and the store on a multi-store
    // channel. Both travel to the reveal endpoint.
    'channel' => null,
    'store' => null,
    // How many characters are stored. 0 means nothing is stored yet, which is
    // the one state that renders a genuinely empty, immediately typeable field.
    'length' => 0,
    // A read-tier operator sees the mask and no button at all.
    'canReveal' => false,
    'disabled' => false,
    'required' => false,
    'placeholder' => null,
])

@php
    $id = $id ?? 'cred-' . str_replace('_', '-', $name);
    $stored = (int) $length > 0;

    /*
     * The mask is drawn to the STORED LENGTH, not to a fixed eight dots.
     *
     * Eight dots on a 64-character token and eight on a 32-character secret
     * look identical, so the field said "something is here" and nothing else.
     * Sized, it also answers "is the right KIND of thing here" - a token
     * pasted into the secret field is obvious before it is ever submitted.
     *
     * Capped at 48 so a long value does not overflow the input, with the true
     * count printed underneath either way. The cap is display only; the count
     * is the fact.
     */
    $dots = str_repeat('•', min((int) $length, 48));
@endphp

{{--
    HOW THIS FIELD BEHAVES, AND WHY

    A stored credential renders masked AND READ-ONLY. There is no way to type
    into it by accident, and nothing about touching it destroys what is there.

    "View and edit" fetches the real value, drops it into the field, and makes
    the field editable in place. The operator can then correct one character of
    a token rather than being made to re-paste the whole thing.

    That last part is the point. The previous design cleared the field the
    moment editing began, on the reasoning that a half-edited secret is a
    corrupt secret. In practice nobody types a token by hand: they paste, and a
    field that empties itself when touched reads as "I have just destroyed the
    saved value" every single time. Keeping the value and letting it be edited
    is both the calmer interaction and the honest one, because the value really
    is still there.

    Submitting without editing posts BLANK, not the revealed value. The
    controllers treat blank as "keep what is stored", so looking at a secret
    never rewrites it, and a revealed secret does not travel back over the wire
    for no reason.
--}}

<x-ui.field :label="$label" :for="$id" :name="$name" :hint="$hint" :required="$required" wide>
    {{-- The x-data payload is built in PHP and emitted on ONE line.

         Written across several lines it broke Blade's component tokenizer:
         an attribute value containing newlines and braces stopped the parser
         recognising the <x-ui.input> tag that followed, which then reached
         the browser as literal text and had Alpine trying to evaluate $stored
         and $name as JavaScript. --}}
    @php
        $bind = $stored && $canReveal
            ? json_encode(['channel' => $channel, 'store' => $store, 'field' => $name, 'mask' => $dots])
            : null;
    @endphp

    <div class="x-cred" @if($bind) x-data="credentialField({{ $bind }})" @endif>
        {{-- type=password while masked: a browser that offers to save or
             autofill a text field would happily do it to a field full of
             dots, and the dots would be what it saved. --}}
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
            {{-- x-ref unconditionally, never behind an @if. A Blade directive
                 inside a component tag's attribute list stops the tag being
                 recognised at all: it reached the browser as literal
                 "<x-ui.input :name=\"$name\"" text and Alpine tried to run
                 the Blade expressions as JavaScript. Outside an x-data scope
                 x-ref simply does nothing, so the condition bought nothing
                 and cost the whole component. --}}
            x-ref="input"
            class="x-cred__input" />

        @if($bind)
            <button type="button" class="x-cred__eye"
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
        <p class="x-cred__meta" @if($bind) x-data @endif>
            <span class="x-num">{{ number_format($length) }}</span> stored characters
            @if($canReveal)
                <span class="x-cred__sep" aria-hidden="true">&middot;</span>
                {{-- Says what the button does BEFORE it is pressed, and says
                     what happens if it is not: the old hint, "type to
                     replace", described a behaviour this field no longer has
                     and never explained that leaving it alone was safe. --}}
                <span>read-only until you choose to edit</span>
            @endif
        </p>
    @endif
</x-ui.field>
