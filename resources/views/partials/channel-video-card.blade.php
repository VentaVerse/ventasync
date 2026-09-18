{{-- THE VIDEO THAT GOES UP, on a listing page.

     The catalog's video is the source, as the picture library is. This store
     sends it unless it says otherwise: its own video instead, or none at all.

     It exists because the channels disagree and that is not an error in the
     video. Ninety seconds is fine on TikTok and Lazada and refused by Shopee,
     so the seller keeps their video where it is taken and gives that one store
     its own cut. The catalog and the other channels are untouched either way.

     Expected keys:
       storeName   the store's name, for the words
       productId   the catalog product
       video       ?array from ListingVideo::cardData, already measured
       videoOff    bool  this store sends none
       videoError  ?string (optional) what the store said about this video
       canManage   bool
       uploadUrl   where a replacement for this store is posted
       anchor      string (optional) an id, for a readiness gap to link at --}}
@php
    $cvOff = (bool) ($videoOff ?? false);
    $cvVideo = $video ?? null;
@endphp
<section class="fm-section" @isset($anchor) id="{{ $anchor }}" @endisset>
    <div class="fm-section__head">
        <h2 class="fm-section__title">Video on {{ $storeName }}</h2>
        {{-- What the store said about this video, beside it rather than at the
             top of the page: the store is the only one that knows what it
             takes, and this is where the seller answers it. --}}
        @if(! empty($videoError))
            <p class="fm-section__bad">{{ $videoError }}</p>
        @endif
    </div>

    <div class="cv" data-listing-video
         data-upload="{{ $uploadUrl }}"
         data-product="{{ (int) $productId }}">

        <input type="hidden" name="video_path" value="{{ $cvVideo && $cvVideo['own'] ? $cvVideo['path'] : '' }}" data-cv-path>
        <input type="hidden" name="video_off" value="{{ $cvOff ? '1' : '0' }}" data-cv-off>

        @if($cvOff)
            <p class="cv__none" data-cv-none>This store sends no video.</p>
        @elseif($cvVideo)
            <div class="cv__now" data-cv-now>
                <video class="cv__player" data-cv-player controls preload="metadata" src="{{ $cvVideo['url'] }}"></video>
                <div class="cv__facts">
                    <span class="cv__fact">{{ $cvVideo['length'] ?? 'Length unknown' }}</span>
                    <span class="cv__fact">{{ $cvVideo['size'] }}</span>
                    <span class="cv__fact cv__fact--from">{{ $cvVideo['own'] ? 'This store\'s own' : 'From the catalog' }}</span>
                </div>
            </div>
        @else
            <p class="cv__none" data-cv-none>No video on the catalog product.</p>
        @endif

        @if($canManage)
            <div class="cv__verbs">
                <label class="cv__replace" for="cv-file-{{ (int) $productId }}">Upload</label>
                <input type="file" id="cv-file-{{ (int) $productId }}" class="cv__input"
                       accept="video/mp4,video/quicktime" data-cv-input>

                @if($cvVideo && $cvVideo['own'])
                    <button type="button" class="cv__verb" data-cv-revert>Back to the catalog's</button>
                @endif

                @if($cvOff)
                    <button type="button" class="cv__verb" data-cv-send>Send the catalog's video</button>
                @elseif($cvVideo)
                    <button type="button" class="cv__verb" data-cv-drop>Send no video here</button>
                @endif
            </div>
            <p class="cv__say" role="status" aria-live="polite" data-cv-say hidden></p>
        @endif
    </div>
</section>
