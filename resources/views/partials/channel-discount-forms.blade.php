{{--
    The Discount card's push and end forms, declared OUTSIDE the page's
    main form (nested forms are illegal HTML). The card's buttons reach
    them by the HTML5 form attribute, and data-confirm intercepts the
    submit as everywhere. The confirm copy prints the actual figures:
    the seller is about to change a live price and should read exactly
    what changes. Takes the same $discount array as the card, plus
      action   the push/end route URL
--}}
@php
    $hasRule = $savedPct !== null && $savedFrom && $savedTo;
    $pctWords = $step === '1' ? (string) (int) $savedPct : rtrim(rtrim(number_format((float) $savedPct, 2, '.', ''), '0'), '.');
    $pushWords = $hasRule
        ? 'Push this discount to ' . $channel . '? ' . $pctWords . '% off'
            . ($salePreview !== null ? ', sale price ' . \App\Support\Money::defaultSymbol() . number_format($salePreview, 2) : '')
            . ', from ' . $savedFrom->format('M j, Y H:i') . ' to ' . $savedTo->format('M j, Y H:i')
            . '. Buyers see the cut price once the window opens.'
        : 'Set and save the discount first.';
    /* The promotion id lives here now, not in a status line on the card:
       the one moment it matters is the moment you end that promotion. */
    $endWords = 'End this discount on ' . $channel . ' now?'
        . ($remoteId ? ' Promotion ' . $remoteId . '.' : '')
        . ' Buyers pay the full price again'
        . ($endClearsRule ? ', and the saved rule here is cleared.' : '.');
@endphp
@if($canManage && $onMarketplace && ($liveError ?? null) === null)
    <form id="{{ $prefix }}-discount-push" method="POST" action="{{ $action }}" data-confirm="{{ $pushWords }}">
        @csrf
        <input type="hidden" name="action" value="push">
    </form>
    <form id="{{ $prefix }}-discount-end" method="POST" action="{{ $action }}" data-confirm="{{ $endWords }}">
        @csrf
        <input type="hidden" name="action" value="end">
    </form>
@endif
