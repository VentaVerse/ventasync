@php
    $cvOff = (bool) ($videoOff ?? false);
    $cvVideo = $video ?? null;
@endphp
<section class="fm-section" @isset($anchor) id="{{ $anchor }}" @endisset>
    <div class="fm-section__head">
        <h2 class="fm-section__title">Video on {{ $storeName }}</h2>
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
