<div class="x-select-wrap x-filters__select">
    <select name="order" class="x-input" data-autosubmit aria-label="Sort">
        @foreach($options as $value => $label)
            <option value="{{ $value }}" @selected($chosen === $value)>{{ $label }}</option>
        @endforeach
    </select>
    <x-ui.icon name="chevron-down" size="14" class="x-select-wrap__chevron" />
</div>
