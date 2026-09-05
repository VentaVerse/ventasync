{{-- A question mark that explains the control beside it.

     For prose that answers "what does this do" rather than prose the operator
     has to read to work. Kept out of the page until asked for, so a screen of
     controls stays a screen of controls.

     Shows on hover, on keyboard focus, and on tap: tapping the button focuses
     it, which is the same state hover produces, so one CSS rule covers all
     three and no JavaScript is involved. Tapping elsewhere blurs it away.

     The bubble stays in the accessibility tree at all times through
     aria-describedby rather than being hidden with `visibility` or `hidden`,
     so a screen reader announces the explanation when it reaches the button
     instead of finding an unlabelled control. It is only visually withheld.

     Above 900px the bubble opens down and to the right of the button. For a
     hint that sits near the right edge of a wide screen, where that would run
     off the page, add class="bl-hint--end" to open it the other way. Below
     900px it is a sheet across the bottom of the screen and the class does
     nothing.

     Usage:
         <x-ui.hint>Both dates are required.</x-ui.hint>
         <x-ui.hint label="About Lazada brands">Reads every brand ...</x-ui.hint>
         <x-ui.hint class="bl-hint--end">Opens leftward.</x-ui.hint>
--}}

@props([
    // The button's accessible name. Override where "More information" would
    // be ambiguous because several hints sit on one screen.
    'label' => 'More information',
    // The bubble's id, when a control points at it through aria-describedby
    // (x-ui.field passes {controlId}-hint). Random otherwise.
    'bubbleId' => null,
])

@php
    // Unique per render: two hints on a page must not share a description id,
    // or aria-describedby resolves both buttons to the first bubble.
    $hintId = $bubbleId ?: 'hint-' . \Illuminate\Support\Str::random(10);
@endphp

<span {{ $attributes->merge(['class' => 'bl-hint']) }}>
    <button type="button" class="bl-hint__btn"
            aria-label="{{ $label }}" aria-describedby="{{ $hintId }}">
        <span aria-hidden="true">?</span>
    </button>
    <span class="bl-hint__bubble" role="tooltip" id="{{ $hintId }}">{{ $slot }}</span>
</span>
