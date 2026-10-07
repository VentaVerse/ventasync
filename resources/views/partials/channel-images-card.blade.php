@php
    $anchor = $anchor ?? null;
    $tiles = $tiles ?? array_map(fn ($url) => ['path' => '', 'url' => $url], $images ?? []);
    $editable = ($editable ?? false) && ($canManage ?? false);
    $imageCount = count($tiles);
    $imageOff = array_values(array_filter((array) ($imagesOff ?? [])));
    $imageWords = $imageCount === 0
        ? ($editable
            ? 'No pictures yet.'
            : 'The catalog product has no images, so none go up.')
        : ''
            . ($fetchesOverWeb ? ' ' . $storeName . ' fetches them from this ERP\'s address.' : '');

    $ownPaths = array_column($tiles, 'path');
    $catalogPaths = array_column($catalogTiles ?? [], 'path');
    $arrangement = $editable && $ownPaths !== $catalogPaths ? json_encode($ownPaths) : '';

@endphp
<section class="fm-section"
         @if(!empty($anchor)) id="{{ $anchor }}" @endif
         @if($editable)
             data-listing-images
             data-browse-url="{{ $browseUrl }}"
             data-catalog="{{ json_encode(array_values($catalogTiles ?? [])) }}"
         @endif>
    <div class="fm-section__head">
        <h2 class="fm-section__title">Images that go up</h2>
        @if(! empty($imagesError))
            <p class="fm-section__bad">{{ $imagesError }}</p>
        @endif
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

    @isset($watermarkOptions)
        <div class="fm-fields lc-mark">
            <x-ui.field label="Watermark" for="{{ ($anchor ?? 'lc-images') }}-watermark" name="watermark_template_id">
                <div class="x-select-wrap">
                    <select id="{{ ($anchor ?? 'lc-images') }}-watermark" name="watermark_template_id" class="x-input" @disabled(!($canManage ?? false))>
                        <option value="">{{ !empty($watermarkLent) ? 'From the product group: ' . $watermarkLent : 'None' }}</option>
                        @foreach($watermarkOptions as $option)
                            <option value="{{ $option->id }}" @selected((int) ($watermarkTemplateId ?? 0) === (int) $option->id)>{{ $option->name }}</option>
                        @endforeach
                    </select>
                    <x-ui.icon name="chevron-down" size="14" class="x-select-wrap__chevron" />
                </div>
            </x-ui.field>
            <label class="gf-check">
                <input type="checkbox" class="cc-check" name="watermark_all_images" value="1"
                       @checked($watermarkAllImages ?? false) @disabled(!($canManage ?? false))>
                <span class="gf-check__name">Apply to all images in a product</span>
            </label>
        </div>
    @endisset

    @if($editable)
        <input type="hidden" name="image_order" value="{{ $arrangement }}" data-images-input>
        <input type="hidden" name="image_off" value="{{ $imageOff === [] ? '' : json_encode($imageOff) }}" data-images-off-input>
        <ul class="lc-images lc-images--arrange" data-images-list
            aria-label="The pictures this listing sends, in order"
            aria-describedby="lc-images-help">
            @foreach($tiles as $i => $tile)
                @php $tileOff = in_array($tile['path'], $imageOff, true); @endphp
                <li class="lc-images__item{{ $tileOff ? ' lc-images__item--held' : '' }}"
                    data-path="{{ $tile['path'] }}" tabindex="{{ $i === 0 ? 0 : -1 }}"
                    @if($tileOff) data-off @endif
                    aria-label="Picture {{ $i + 1 }} of {{ $imageCount }}">
                    <img src="{{ $tile['url'] }}" alt="" loading="lazy" draggable="false">
                    <span class="lc-images__rank">{{ $i + 1 }}</span>
                    <span class="lc-images__held">Off</span>
                    <span class="lc-images__verbs">
                        <button type="button" class="lc-images__verb lc-images__verb--drop" data-images-drop tabindex="-1"
                                aria-label="Remove picture {{ $i + 1 }} from this listing">
                            <x-ui.icon name="x" size="12" />
                        </button>
                        <button type="button" class="lc-images__verb lc-images__verb--off" data-images-off tabindex="-1"
                                aria-label="{{ $tileOff ? 'Send picture ' . ($i + 1) . ' again' : 'Hold picture ' . ($i + 1) . ' back' }}">
                            <x-ui.icon name="{{ $tileOff ? 'eye' : 'eye-off' }}" size="12" />
                        </button>
                    </span>
                </li>
            @endforeach
        </ul>
        <p class="x-sr" id="lc-images-help">Hold a picture and drag it where you want it. From the keyboard: the arrow keys move between pictures, the space bar picks one up and the arrow keys then move it, and Delete removes it.</p>
        <p class="x-sr" role="status" aria-live="polite" data-images-say></p>
    @elseif($imageCount > 0)
        <ul class="lc-images">
            @foreach($tiles as $i => $tile)
                <li class="lc-images__item{{ in_array($tile['path'], $imageOff, true) ? ' lc-images__item--held' : '' }}"><img src="{{ $tile['url'] }}" alt="" loading="lazy"></li>
            @endforeach
        </ul>
    @endif
</section>
