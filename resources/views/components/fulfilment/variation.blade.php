{{-- The variation on an order line, axis and value both: "Color/Finish: Red".
     The axis comes from the catalog by SKU; a SKU the catalog does not know
     falls back to whatever the marketplace sent. Prints nothing when there is
     no variation, so the caller needs no guard. --}}
@props(['sku' => null, 'fallback' => null])
@php $pairs = \App\Support\CatalogVariations::pairsFor($sku, $fallback); @endphp
@foreach($pairs as $pair)
    <span {{ $attributes->merge(['class' => 'co-var']) }}>
        @if($pair['name'] !== '')<span class="co-var__name">{{ $pair['name'] }}</span>@endif<span class="co-var__value">{{ $pair['value'] }}</span>
    </span>
@endforeach
