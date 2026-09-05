{{-- Per-store reports (the owner, 2026-09-05): one control scopes the page,
     from the page head, because every figure below answers to it. A
     select, not a segmented control: ranges are seven, stores can be
     dozens. Submits itself through list-filters.js; the range rides
     along as a hidden field so the two controls never lose each other.
     Nothing to choose on a fresh install: the control stays away. --}}
@if(!empty($storeOptions))
<form method="GET" action="{{ route('dashboard') }}" class="db-store">
    <input type="hidden" name="range" value="{{ $range }}">
    <label class="x-sr" for="db-store">Store</label>
    <x-ui.select id="db-store" name="store" class="db-store__select" data-autosubmit>
        <option value="" @selected($storeKey === '')>All stores</option>
        @foreach(collect($storeOptions)->groupBy('channel') as $channel => $stores)
            <optgroup label="{{ $channel }}">
                @foreach($stores as $s)
                    <option value="{{ $s['key'] }}" @selected($storeKey === $s['key'])>{{ $s['label'] }}</option>
                @endforeach
            </optgroup>
        @endforeach
    </x-ui.select>
</form>
@endif
