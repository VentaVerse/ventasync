{{--
    "Images that go up": the catalog product's images as the next push
    sends them, on every channel's listing page (the owner, 2026-09-07: one
    card order on all five). Read-only; the images are the catalog's.

    Expected keys:
      storeName      the channel or store's name, for the words
      images         list<string>  public URLs, main image first
      limit          ?int  how many the channel takes; null means all of them
      fetchesOverWeb bool  the store fetches them from this ERP's address (Venta, WooCommerce)
--}}
@php
    $imageCount = count($images);
    $sent = $limit !== null ? min($imageCount, (int) $limit) : $imageCount;
    // Built here rather than inline: a Blade directive glued to the word
    // before it is not a directive at all, it is printed.
    $imageWords = $sent . ' ' . ($sent === 1 ? 'image goes' : 'images go') . ' up'
        . ($sent < $imageCount ? ', the first ' . $sent . ' of ' . $imageCount : '') . '.';
@endphp
<div class="fm-card">
    <div class="fm-card__head"><h2 class="fm-card__title">Images that go up
        <x-ui.hint label="About the images">The catalog product's images, main image first.@if($limit !== null) {{ $storeName }} takes {{ (int) $limit }} at most; the rest stay behind and the push says so.@endif @if($fetchesOverWeb) {{ $storeName }} fetches them over the web from this ERP's address, so that address must be reachable from the store.@endif Change the pictures on the catalog product.</x-ui.hint></h2></div>
    <div class="fm-card__body">
        @if($imageCount === 0)
            <p class="fm-section__note">The catalog product has no images, so none go up.</p>
        @else
            <p class="fm-section__note">{{ $imageWords }}</p>
            <ul class="lc-images">
                @foreach($images as $i => $url)
                    <li class="lc-images__item{{ $i >= $sent ? ' lc-images__item--held' : '' }}"><img src="{{ $url }}" alt="" loading="lazy"></li>
                @endforeach
            </ul>
        @endif
    </div>
</div>
