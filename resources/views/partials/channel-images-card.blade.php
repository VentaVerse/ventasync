{{--
    "Images that go up": the pictures the next push sends, in the order it
    sends them, on every channel's listing page (the owner, 2026-09-07: one
    card order on all five).

    Two cards in one, told apart by $editable:

      read-only  the catalog product's images, main image first. What four
                 of the five channels still show.
      editable   the listing arranges its own order, drops what it does not
                 want and adds from the image library (the owner, 2026-09-08).
                 An untouched listing follows the catalog, exactly as an
                 empty Content field does; Follow the catalog again returns
                 it. Shopee first; the rest follow, see
                 extensions/CHANNEL-CONTRACT.md.

    Expected keys:
      storeName      the channel or store's name, for the words
      tiles          list<array{path: string, url: string}>  what goes up, in order.
                     A channel that has not taken the arrangeable card yet may
                     pass `images`, a plain list of URLs, instead; the read-only
                     card needs nothing more.
      limit          ?int  how many the channel takes; null means all of them
      fetchesOverWeb bool  the store fetches them from this ERP's address (Venta, WooCommerce)
      editable       bool  (optional) the card arranges the order
      canManage      bool  (optional) required with $editable
      catalogTiles   list<array{path,url}>  (optional) the catalog's own list, for the reset
      browseUrl      string (optional) the image library's browse endpoint
      anchor         string (optional) an id, for a readiness gap to link at
--}}
@php
    $anchor = $anchor ?? null;
    $tiles = $tiles ?? array_map(fn ($url) => ['path' => '', 'url' => $url], $images ?? []);
    $editable = ($editable ?? false) && ($canManage ?? false);
    $imageCount = count($tiles);
    $sent = $limit !== null ? min($imageCount, (int) $limit) : $imageCount;
    // Built here rather than inline: a Blade directive glued to the word
    // before it is not a directive at all, it is printed.
    $imageWords = $imageCount === 0
        ? ($editable
            ? 'No pictures yet.'
            : 'The catalog product has no images, so none go up.')
        /* The channel's allowance is always in the sentence (the owner,
           2026-09-09): "2 images go up" told an operator nothing about
           whether there was room for more, and the room is different on
           every channel - Shopee and TikTok take 9, Lazada 8, WooCommerce
           20, a storefront all of them. */
        : ($limit !== null
            ? $sent . ' of ' . (int) $limit . ' ' . ($limit === 1 ? 'image goes' : 'images go') . ' up'
                . ($sent < $imageCount ? ', ' . ($imageCount - $sent) . ' held back' : '')
            : $imageCount . ' ' . ($imageCount === 1 ? 'image goes' : 'images go') . ' up') . '.'
            /* The one fact the tiles cannot show for themselves: a store
               that fetches the pictures from this ERP's address needs that
               address to be reachable from it. True on Venta and
               WooCommerce, said nowhere else, so it rides the sentence
               rather than a tooltip. */
            . ($fetchesOverWeb ? ' ' . $storeName . ' fetches them from this ERP\'s address.' : '');

    /* The input is filled by the server, not only by the script: a page
       whose JavaScript never arrived would otherwise submit an empty
       arrangement and quietly hand the listing back to the catalog. Empty
       here means the same thing it means everywhere else - this listing
       follows the catalog - so it is only filled when the arrangement
       differs from the catalog's own. */
    $ownPaths = array_column($tiles, 'path');
    $catalogPaths = array_column($catalogTiles ?? [], 'path');
    $arrangement = $editable && $ownPaths !== $catalogPaths ? json_encode($ownPaths) : '';

@endphp
<section class="fm-section"
         @if(!empty($anchor)) id="{{ $anchor }}" @endif
         @if($editable)
             data-listing-images
             data-limit="{{ $limit !== null ? (int) $limit : '' }}"
             data-browse-url="{{ $browseUrl }}"
             data-catalog="{{ json_encode(array_values($catalogTiles ?? [])) }}"
         @endif>
    <div class="fm-section__head">
        <h2 class="fm-section__title">Images that go up</h2>
        @if($editable)
            <div class="fm-section__aside">
                <x-ui.button type="button" size="sm" data-images-add>
                    <x-ui.icon name="image" size="14" /> Add from library
                </x-ui.button>
                <x-ui.button type="button" size="sm" data-images-reset hidden>Follow the catalog</x-ui.button>
            </div>
        @endif
    </div>

    <p class="fm-section__note" data-images-words>{{ $imageWords }}</p>

    @if($editable)
        <input type="hidden" name="image_order" value="{{ $arrangement }}" data-images-input>
        <ul class="lc-images lc-images--arrange" data-images-list
            aria-label="The pictures this listing sends, in order"
            aria-describedby="lc-images-help">
            @foreach($tiles as $i => $tile)
                {{-- One tab stop for the whole list, not two per picture:
                     tabbing past this card used to cost two dozen presses.
                     Focus moves between tiles with the arrow keys, and the
                     tile's own verbs answer to keys as well as to a press,
                     so nothing here needs a pointer. --}}
                <li class="lc-images__item{{ $limit !== null && $i >= $sent ? ' lc-images__item--held' : '' }}"
                    data-path="{{ $tile['path'] }}" tabindex="{{ $i === 0 ? 0 : -1 }}"
                    aria-label="Picture {{ $i + 1 }} of {{ $imageCount }}">
                    <img src="{{ $tile['url'] }}" alt="" loading="lazy" draggable="false">
                    <span class="lc-images__rank">{{ $i + 1 }}</span>
                    <span class="lc-images__held">Held back</span>
                    <span class="lc-images__verbs">
                        <button type="button" class="lc-images__verb lc-images__verb--drop" data-images-drop tabindex="-1"
                                aria-label="Remove picture {{ $i + 1 }} from this listing">
                            <x-ui.icon name="x" size="12" />
                        </button>
                    </span>
                </li>
            @endforeach
        </ul>
        {{-- The keyboard route to the same arrangement, and the running
             commentary a screen reader hears while it is used. Not a
             paragraph on the page: an explanation under a control is a
             tooltip here, and the pointer gesture is told by the cursor. --}}
        <p class="x-sr" id="lc-images-help">Hold a picture and drag it where you want it. From the keyboard: the arrow keys move between pictures, the space bar picks one up and the arrow keys then move it, and Delete removes it.</p>
        <p class="x-sr" role="status" aria-live="polite" data-images-say></p>
    @elseif($imageCount > 0)
        <ul class="lc-images">
            @foreach($tiles as $i => $tile)
                <li class="lc-images__item{{ $limit !== null && $i >= $sent ? ' lc-images__item--held' : '' }}"><img src="{{ $tile['url'] }}" alt="" loading="lazy"></li>
            @endforeach
        </ul>
    @endif
</section>
