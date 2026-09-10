@extends('layouts.blotter')
@section('title', ($mode === 'create' ? 'New' : 'Edit') . ' watermark template')

{{-- The page shows its own summary and marks the offending fields inline. --}}
@section('own-errors', true)

@section('content')
@php
    $positions = \App\Services\Media\Watermarker::POSITIONS;
    $action = $mode === 'create' ? route('watermarks.store') : route('watermarks.update', $template->id);
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
            <a class="fm-back" href="{{ route('watermarks.index') }}" data-guard-leave>
                <x-ui.icon name="chevron-left" size="14" /> Watermarks
            </a>
            <h1 class="fm-title">{{ $mode === 'create' ? 'New watermark template' : $template->name }}</h1>
        </div>
    </div>

    <form id="wm-form" method="POST" action="{{ $action }}" data-guard-unsaved
          data-wm-preview-url="{{ route('watermarks.preview') }}">
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

                        {{-- Picked from the library, never uploaded here (the owner,
                             2026-09-10): one logo, uploaded once, pointed at by
                             every template that wants it. --}}
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

                        <x-ui.field label="Size" for="wm-size" name="size_percent" :required="true"
                                    hint="Percent of the picture's shorter edge, so it looks the same on a square photo and a tall one. 100 covers the whole frame, which is how a border or a full-frame template is made.">
                            <x-ui.input id="wm-size" name="size_percent" type="number" step="0.5" min="1" max="100" required
                                        :value="old('size_percent', $template->size_percent)" />
                        </x-ui.field>

                        <x-ui.field label="Margin" for="wm-margin" name="margin_percent" :required="true"
                                    hint="How far off the edge, as a percent of the shorter edge.">
                            <x-ui.input id="wm-margin" name="margin_percent" type="number" step="0.5" min="0" max="40" required
                                        :value="old('margin_percent', $template->margin_percent)" />
                        </x-ui.field>

                        <x-ui.field label="Strength" for="wm-opacity" name="opacity" :required="true"
                                    hint="1 is the mark as you drew it. Lower softens it without editing the file.">
                            <x-ui.input id="wm-opacity" name="opacity" type="number" step="0.05" min="0" max="1" required
                                        :value="old('opacity', $template->opacity)" />
                        </x-ui.field>
                    </div>
                </section>

                <section class="fm-section">
                    <div class="fm-section__head"><h2 class="fm-section__title">The main image</h2></div>
                    {{-- The one place a marketplace will actually penalise a
                         seller, so the reason is on the page rather than in a
                         tooltip: this is a rule, not a preference. --}}
                    <label class="gf-check">
                        <input type="checkbox" class="cc-check" name="stamp_main" value="1"
                               @checked(old('stamp_main', $template->stamp_main))>
                        <span class="gf-check__name">Also mark the main image</span>
                    </label>
                    <p class="fm-section__note">
                        Marketplaces police the main image and leave the rest alone. Shopee allows a corner logo of
                        10% or less and refuses a busy watermark over the product; the gallery carries text and
                        graphics freely on Shopee and Lazada alike. Left off, only the gallery and variation pictures
                        are marked.
                    </p>
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

        <div class="fm-save">
            <a class="fm-cancel" href="{{ route('watermarks.index') }}" data-guard-leave>Cancel</a>
            <x-ui.button type="submit" variant="primary">Save template</x-ui.button>
        </div>
    </form>
</div>

@include('partials.image-library-pick', ['browseUrl' => $browseUrl])
@endsection
