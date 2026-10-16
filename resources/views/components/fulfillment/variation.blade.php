@props(['sku' => null, 'fallback' => null, 'order' => null, 'name' => null])
@php $pairs = \App\Support\OrderSnapshot::savedPairs($order ? (int) $order : null, $sku, $name) ?? \App\Support\CatalogVariations::pairsFor($sku, $fallback); @endphp
@foreach($pairs as $pair)
    <span {{ $attributes->merge(['class' => 'co-var']) }}>
        @if($pair['name'] !== '')<span class="co-var__name">{{ $pair['name'] }}</span>@endif<span class="co-var__value">{{ $pair['value'] }}</span>
    </span>
@endforeach
