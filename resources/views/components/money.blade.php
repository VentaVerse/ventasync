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
