@props(['label' => 'Select all on this page', 'step' => null])

{{-- The selection bar. Renders the select-all box and a live count; the
     caller supplies whatever can be done with a selection as the default
     slot, and each action container marks itself [data-selection-actions]
     so the module can reveal it.

     At rest the bar offers the box and nothing else. The actions only exist
     once the operator has said what they would act on, which matters most
     where an action prints or ships - a standing button invites a click
     before the sentence is finished. --}}
{{-- The bar is sticky (#142): select nine orders, scroll to the tenth, and
     the print and pack actions are still on screen. It carries the active
     step's name so the operator scrolled into row forty still knows which
     queue this is; the step strip itself stays where it was. --}}
<div class="x-selectbar">
    @if($step)
        <span class="x-selectbar__step">{{ $step }}</span>
    @endif
    <label class="x-selectbar__pick">
        <input type="checkbox" class="x-pick" data-selection-all
               aria-label="{{ $label }}">
        <span>{{ $label }}</span>
    </label>

    <span class="x-selectbar__count" data-selection-count role="status"></span>

    <div class="x-selectbar__spacer"></div>

    {{ $slot }}
</div>
