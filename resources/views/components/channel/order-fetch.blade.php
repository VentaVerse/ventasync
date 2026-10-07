@props(['integration', 'storeId' => null, 'formId', 'channelLabel', 'beginUrl', 'stepUrl', 'stopUrl', 'stateUrl'])
@php
    $resumable = \App\Models\OrderFetchRun::resumable($integration, $storeId !== null ? (int) $storeId : null);
    $resumableState = $resumable ? $resumable->toState() : null;
    $resumableWhen = $resumable?->updated_at?->diffForHumans();
@endphp
<div class="of" data-order-fetch data-form="{{ $formId }}" data-channel="{{ $channelLabel }}"
     data-begin-url="{{ $beginUrl }}" data-step-url="{{ $stepUrl }}" data-stop-url="{{ $stopUrl }}" data-state-url="{{ $stateUrl }}"
     @if($resumableState) data-resumable='@json($resumableState)' data-resumable-when="{{ $resumableWhen }}" @endif hidden>

    <div class="of-strip" data-of-strip role="status" aria-live="polite" hidden>
        <span class="of-strip__title" data-of-title>Fetching orders from {{ $channelLabel }}</span>
        <div class="of-bar" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0" data-of-bar><div class="of-bar__fill" data-of-fill></div></div>
        <span class="of-strip__count" data-of-count></span>
        <span class="of-strip__verdict" data-of-verdict hidden></span>
        <button type="button" class="x-btn x-btn--secondary x-btn--sm" data-of-open>Open</button>
        <button type="button" class="x-btn x-btn--secondary x-btn--sm" data-of-retry hidden>Try again</button>
        <button type="button" class="x-btn x-btn--secondary x-btn--sm" data-of-stop>Stop</button>
        <button type="button" class="x-btn x-btn--primary x-btn--sm" data-of-close hidden>Close</button>
    </div>

    <div class="modal-backdrop of-backdrop" data-of-sheet hidden>
        <div class="modal co-modal co-modal--sm of-run" role="dialog" aria-modal="true" aria-labelledby="of-sheet-title-{{ $integration }}">
            <h2 class="of-run__title" id="of-sheet-title-{{ $integration }}" data-of-sheet-title>Fetching orders from {{ $channelLabel }}</h2>
            <p class="of-run__sub" data-of-sheet-sub></p>
            <div class="of-bar" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0" data-of-bar><div class="of-bar__fill" data-of-fill></div></div>
            <div class="of-run__count"><span data-of-count></span><span class="of-run__page" data-of-page></span></div>
            <p class="of-run__verdict" data-of-verdict hidden></p>
            <ul class="of-ledger" data-of-ledger></ul>
            <div class="of-run__foot">
                <button type="button" class="x-btn x-btn--secondary x-btn--sm" data-of-startover hidden>Start over</button>
                <button type="button" class="x-btn x-btn--secondary x-btn--sm" data-of-retry hidden>Try again</button>
                <button type="button" class="x-btn x-btn--secondary x-btn--sm" data-of-stop>Stop after this page</button>
                <button type="button" class="x-btn x-btn--secondary x-btn--sm" data-of-minimise>Keep working</button>
                <button type="button" class="x-btn x-btn--primary x-btn--sm" data-of-continue hidden>Continue</button>
                <button type="button" class="x-btn x-btn--primary x-btn--sm" data-of-close hidden>Close</button>
            </div>
        </div>
    </div>
</div>
