@php
    $pvSaved = $productVideo ?? null;
    $pvPath = (string) old('video_path', $pvSaved['path'] ?? '');
    $pvName = (string) old('video_name', $pvSaved['name'] ?? '');
    $pvAi = (bool) old('video_ai', $pvSaved['aiGenerated'] ?? false);
    $pvHas = $pvPath !== '';
@endphp

<section class="fm-section" aria-labelledby="sec-video">
    <div class="fm-section__head">
        <h2 class="fm-section__title" id="sec-video">Video</h2>
    </div>

    <div class="pv" data-product-video
         data-upload="{{ route('products.video.upload') }}"
         data-remove="{{ route('products.video.delete') }}"
         data-product="{{ (int) ($pimProductId ?? 0) }}"
         data-token="{{ (string) ($pimToken ?? '') }}">
        @csrf

        <input type="hidden" name="video_path" value="{{ $pvPath }}" data-pv-path>
        <input type="hidden" name="video_name" value="{{ $pvName }}" data-pv-name>

        <div class="st-file">
            <label class="st-file__btn" for="pv-file">Choose file</label>
            <span class="st-file__name" data-pv-readout>{{ $pvHas ? $pvName : 'No file chosen' }}</span>
            <button type="button" class="st-file__reset" data-pv-remove @unless($pvHas) hidden @endunless>Remove</button>
            <input type="file" id="pv-file" class="st-file__input" accept="video/mp4,video/quicktime" data-pv-input>
        </div>
        <div class="fm-hint">MP4 or MOV.</div>
        <p class="st-file__error" id="pv-error" role="status" hidden></p>

        <div class="pv__now" data-pv-now @unless($pvHas) hidden @endunless>
            <video class="pv__player" data-pv-player controls preload="metadata"
                   src="{{ $pvSaved['url'] ?? '' }}"></video>
            <div class="pv__facts">
                <span class="pv__fact" data-pv-length>{{ $pvSaved['length'] ?? '' }}</span>
                <span class="pv__fact" data-pv-size>{{ $pvSaved['size'] ?? '' }}</span>
            </div>
        </div>

        <label class="pv__ai">
            <input type="checkbox" name="video_ai" value="1" @checked($pvAi)>
            <span>This video was made or helped by AI</span>
        </label>
    </div>
</section>
