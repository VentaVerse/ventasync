@extends('layouts.channel')
@section('title', ($mode === 'create' ? 'New' : 'Edit') . ' watermark template')
@section('breadcrumb', $mode === 'create' ? 'New template' : $template->name)

@section('own-errors', true)

@section('content')
@php
    $positions = \App\Services\Media\Watermarker::POSITIONS;
@endphp

<div class="fm-page">

    <div class="fm-flash" role="status" aria-live="polite">
        @if($errors->any())
            <div class="fm-note fm-note--fail">
                <div class="fm-note__body">Nothing was saved. Check the fields marked below.</div>
            </div>
        @endif
    </div>

    <div class="fm-head">
        <div class="fm-head__main">
            <a class="fm-back" href="{{ $indexUrl }}" data-guard-leave>
                <x-ui.icon name="chevron-left" size="14" /> Watermarks
            </a>
            <h1 class="fm-title">{{ $mode === 'create' ? 'New watermark template' : $template->name }}</h1>
        </div>
    </div>

    <form id="wm-form" method="POST" action="{{ $action }}" data-guard-unsaved
          data-wm-preview-url="{{ $previewUrl }}">
        @csrf
        @if($mode !== 'create') @method('PUT') @endif

        <div class="cl-body">
            <div class="cl-main">

                <section class="fm-section">
                    <div class="fm-section__head"><h2 class="fm-section__title">The mark</h2></div>
                    <div class="fm-fields">
                        <x-ui.field label="Name" for="wm-name" name="name" :required="true" wide
                                    hint="What you will call it when choosing it on a listing or a product group.">
                            <x-ui.input id="wm-name" name="name" maxlength="120" required
                                        :value="old('name', $template->name)" placeholder="Shop logo, bottom right" />
                        </x-ui.field>

                        <x-ui.field label="Picture" name="image_path" :required="true" wide
                                    hint="A PNG with a transparent background. Where it is clear, the photograph shows through.">
                            <div class="wm-pick" data-wm-pick data-browse-url="{{ $browseUrl }}">
                                <input type="hidden" name="image_path" data-wm-path
                                       value="{{ old('image_path', $template->image_path) }}">
                                <span class="wm-chip wm-chip--lg" data-wm-mark>
                                    @if(old('image_path', $template->image_path))
                                        <img src="{{ \App\Support\Catalog\ProductImages::url(old('image_path', $template->image_path)) }}" alt="">
                                    @endif
                                </span>
                                <x-ui.button type="button" size="sm" data-wm-browse>
                                    <x-ui.icon name="image" size="14" /> Choose from the library
                                </x-ui.button>
                            </div>
                        </x-ui.field>
                    </div>
                </section>

                <section class="fm-section">
                    <div class="fm-section__head"><h2 class="fm-section__title">Where it sits</h2></div>
                    <div class="fm-fields">
                        <x-ui.field label="Position" name="position" :required="true" wide>
                            <div class="wm-grid" role="radiogroup" aria-label="Position">
                                @foreach($positions as $p)
                                    <label class="wm-grid__cell">
                                        <input type="radio" name="position" value="{{ $p }}"
                                               @checked(old('position', $template->position ?: 'bottom-right') === $p)>
                                        <span class="x-sr">{{ ucfirst(str_replace('-', ' ', $p)) }}</span>
                                    </label>
                                @endforeach
                            </div>
                        </x-ui.field>

                        <x-ui.field label="Size" for="wm-size" name="size_percent" :required="true" wide
                                    hint="How wide the mark is, as a share of the picture's shorter edge, so it looks the same on a square photo and a tall one. 100 fills the frame, which is how a border is made.">
                            <div class="wm-slider">
                                <input id="wm-size" class="wm-slider__range" type="range" name="size_percent"
                                       min="1" max="100" step="1" required data-wm-unit="%"
                                       value="{{ old('size_percent', (int) round((float) $template->size_percent)) }}">
                                <output class="wm-slider__value" for="wm-size" data-wm-readout></output>
                            </div>
                        </x-ui.field>

                        <x-ui.field label="Offset across" for="wm-offset-x" name="offset_x_percent" :required="true" wide
                                    hint="Moves the mark left or right from its position, as a share of the shorter edge. Zero sits it flush against the position you picked.">
                            <div class="wm-slider wm-slider--signed">
                                <input id="wm-offset-x" class="wm-slider__range" type="range" name="offset_x_percent"
                                       min="-50" max="50" step="0.5" required data-wm-unit="%" data-wm-signed
                                       value="{{ old('offset_x_percent', (float) $template->offset_x_percent) }}">
                                <output class="wm-slider__value" for="wm-offset-x" data-wm-readout></output>
                                <span class="wm-slider__ends" aria-hidden="true"><span>Left</span><span>Right</span></span>
                            </div>
                        </x-ui.field>

                        <x-ui.field label="Offset down" for="wm-offset-y" name="offset_y_percent" :required="true" wide
                                    hint="Moves the mark up or down from its position, as a share of the shorter edge. Zero sits it flush against the position you picked.">
                            <div class="wm-slider wm-slider--signed">
                                <input id="wm-offset-y" class="wm-slider__range" type="range" name="offset_y_percent"
                                       min="-50" max="50" step="0.5" required data-wm-unit="%" data-wm-signed
                                       value="{{ old('offset_y_percent', (float) $template->offset_y_percent) }}">
                                <output class="wm-slider__value" for="wm-offset-y" data-wm-readout></output>
                                <span class="wm-slider__ends" aria-hidden="true"><span>Up</span><span>Down</span></span>
                            </div>
                        </x-ui.field>

                        <x-ui.field label="Transparency" for="wm-transparency" name="transparency" :required="true" wide
                                    hint="0 is the mark exactly as drawn. Higher lets more of the photograph show through it, without editing the file.">
                            <div class="wm-slider">
                                <input id="wm-transparency" class="wm-slider__range" type="range" name="transparency"
                                       min="0" max="{{ \App\Models\WatermarkTemplate::MAX_TRANSPARENCY }}" step="5" required data-wm-unit="%"
                                       value="{{ old('transparency', $template->transparencyPercent()) }}">
                                <output class="wm-slider__value" for="wm-transparency" data-wm-readout></output>
                            </div>
                        </x-ui.field>
                    </div>
                </section>
            </div>

            <aside class="cl-rail">
                <section class="fm-section">
                    <div class="fm-section__head"><h2 class="fm-section__title">What the buyer sees</h2></div>
                    <p class="fm-section__note" data-wm-preview-note>A real photograph from your catalog, marked exactly as a push would mark it.</p>
                    <div class="wm-preview"><img data-wm-preview alt="" hidden></div>
                </section>
            </aside>
        </div>

        <x-ui.form-bar :cancel="$indexUrl">
            <x-slot:note>{{ $mode === 'edit' ? 'Template ' . $template->id : '' }}</x-slot:note>
            <x-slot:primary><x-ui.button type="submit" variant="primary">Save template</x-ui.button></x-slot:primary>
        </x-ui.form-bar>
    </form>
</div>

@include('partials.image-library-pick', ['browseUrl' => $browseUrl])
@endsection
