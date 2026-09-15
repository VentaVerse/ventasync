{{-- The Catalog change button. With href it is a link: the Listings table
     opens the listing with its comparison already open. Without, it opens
     the comparison panel on this page (partials/catalog-change-panel). --}}
@if(!empty($href))
    <a class="lcx-btn" href="{{ $href }}"><span class="lcx-btn__dot" aria-hidden="true"></span>Catalog change</a>
@else
    <button type="button" class="lcx-btn" data-lcx-open aria-haspopup="dialog" aria-controls="lcx-panel"><span class="lcx-btn__dot" aria-hidden="true"></span>Catalog change</button>
@endif
