@php
    $storeName = (string) ($storeName ?? '');
    $channelLabel = $channelLabel ?? 'the channel';
    $open = $open ?? $errors->has('confirm_name');
    $holds = $holds ?? ('its listings, links, product groups, couriers, ' . $channelLabel . ' orders and returns, and its API log');
@endphp
<div class="modal-backdrop cs-delete" data-channel-delete data-delete-open-on-load="{{ $open ? '1' : '0' }}">
    <form method="POST" action="{{ $action }}" class="modal co-modal co-modal--sm cs-delete__sheet" role="dialog" aria-modal="true" aria-labelledby="cs-delete-title">
        @csrf
        <h2 class="cs-delete__title" id="cs-delete-title">Delete {{ $storeName !== '' ? $storeName : 'this store' }}</h2>
        <ul class="cs-delete__list">
            <li>{{ ucfirst($holds) }} go with it.</li>
            <li>Sales already imported stay, under the name {{ $storeName !== '' ? $storeName : 'of this store' }}.</li>
            <li>There is no undo.</li>
        </ul>
        <x-ui.field label="Type the store name to confirm" for="cs-delete-name" name="confirm_name" :required="true" wide>
            <x-ui.input id="cs-delete-name" name="confirm_name" type="text" autocomplete="off" data-delete-name data-expect="{{ $storeName }}" />
        </x-ui.field>
        <div class="cs-delete__foot">
            <x-ui.button type="button" size="sm" data-delete-close>Keep the store</x-ui.button>
            <x-ui.button type="submit" size="sm" variant="danger" data-delete-submit disabled>Delete this store</x-ui.button>
        </div>
    </form>
</div>
