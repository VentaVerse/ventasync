{{--
    Renders a money figure through App\Support\Money - never a bare number.

    PHP is always the primary (emphasised) line. When a foreign amount and
    its currency code are both supplied, the as-transacted foreign figure
    renders as a secondary line beneath it. Pass no foreign amount (or no
    code) for a plain single PHP figure - the default-currency case.

    A row whose currency code is genuinely foreign but has no foreign amount
    yet (foreign_total IS NULL - not-yet-normalized, or synced in the gap
    before a rate was recorded) is NOT the same as a plain PHP order: the
    figure shown is unlabelled and could be read as either currency. Rather
    than render it identically to a real PHP order, a small "pending rate"
    marker makes that ambiguity visible - see show.blade.php's currency
    banner for the fuller version of the same distinction on the order
    detail page.

    Props:
      php          float   required - amount in the default (PHP) currency
      foreign      float?  the as-transacted foreign amount, or null
      foreign-code string? the foreign currency's code (e.g. "USD"), or null
--}}
@props([
    'php' => 0,
    'foreign' => null,
    'foreignCode' => null,
])
@php
    $showForeign = $foreign !== null && !empty($foreignCode);
    $isPendingForeign = !$showForeign
        && !empty($foreignCode)
        && strcasecmp((string) $foreignCode, \App\Support\Money::defaultCode()) !== 0;
@endphp
<span class="money-primary">{{ \App\Support\Money::base((float) $php) }}</span>
@if($showForeign)
<span class="money-secondary">{{ \App\Support\Money::foreign((float) $foreign, (string) $foreignCode) }}</span>
@elseif($isPendingForeign)
<span class="money-pending" title="{{ $foreignCode }} order - exchange rate not yet recorded">pending rate</span>
@endif
