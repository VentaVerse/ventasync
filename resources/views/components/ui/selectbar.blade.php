@props(['label' => 'Select all on this page'])

{{-- The selection bar. Renders the select-all box and a live count; the
     caller supplies whatever can be done with a selection as the default
     slot, and each action container marks itself [data-selection-actions]
     so the module can reveal it.

     At rest the bar offers the box and nothing else. The actions only exist
     once the operator has said what they would act on, which matters most
     where an action prints or ships - a standing button invites a click
     before the sentence is finished. --}}
<div class="x-selectbar">
    <label class="x-selectbar__pick">
        <input type="checkbox" class="x-pick" data-selection-all
               aria-label="{{ $label }}">
        <span>{{ $label }}</span>
    </label>

    <span class="x-selectbar__count" data-selection-count role="status"></span>

    <div class="x-selectbar__spacer"></div>

    {{ $slot }}
</div>
