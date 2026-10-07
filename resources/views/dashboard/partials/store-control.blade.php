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
