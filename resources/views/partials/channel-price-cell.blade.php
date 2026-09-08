{{--
    The Price cell on a channel's listing table.

    A running discount strikes the price (the owner, 2026-09-09: "if the
    discount is live. Slash the price in the store product listing and in
    edit listing"). What stands beside the struck figure is the PERCENT,
    not a second amount: this column is the catalog price, while the sale
    is a percent off the CHANNEL price - catalog run through the listing's
    own rule, then its group's - so a peso figure here would be a number no
    buyer ever pays. The percent is true at either scale, and the exact cut
    price is on the listing page, where the rule and the markup are both on
    screen.

    Ongoing is decided by App\Support\Channels\RunningSale: the window
    covers now and the channel has actually been told.

      price         float   the catalog price
      salePercent   ?float  percent off, or null when nothing is running
--}}
@php
    $salePercent = $salePercent ?? null;
    $offWords = $salePercent !== null
        ? rtrim(rtrim(number_format((float) $salePercent, 2, '.', ''), '0'), '.')
        : '';
@endphp
@if($salePercent !== null)
    <span class="fm-price"><s class="fm-price__was"><x-money :php="(float) $price" /></s><span class="fm-price__off">-{{ $offWords }}%</span></span>
@else
    <x-money :php="(float) $price" />
@endif
