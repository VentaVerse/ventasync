@props(['label' => 'Select all on this page', 'step' => null])

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
