@extends('layouts.channel')
@section('title', ($template->exists ? $template->name : 'New listing template') . ', ' . $channelName)
@section('breadcrumb', $template->exists ? $template->name : 'New listing template')

@section('own-errors', true)

@section('content')
<form method="POST" class="fm-page"
      action="{{ $saveUrl }}"
      data-guard-unsaved>
    @csrf
    @if($template->exists)
        @method('PUT')
    @endif

    <div class="fm-flash" role="status" aria-live="polite">
        @if($errors->any())
            <div class="fm-note fm-note--fail">
                <div class="fm-note__body">Nothing was saved. Check the fields marked below.</div>
            </div>
        @endif
    </div>

    <div class="fm-head">
        <div class="fm-head__main">
            <a class="fm-back" href="{{ $productsUrl }}">
                <x-ui.icon name="chevron-left" size="14" /> Products
            </a>
            <h1 class="fm-title">{{ $template->exists ? $template->name : 'New listing template' }}</h1>
        </div>
    </div>

    <div class="lt-switch">
        @if($templates->isNotEmpty())
            <x-ui.field label="Listing template" for="lt-switch">
                <x-ui.select id="lt-switch" data-nav-select aria-label="Open another listing template">
                    @unless($template->exists)
                        <option value="" selected>New listing template</option>
                    @endunless
                    @foreach($templates as $t)
                        <option value="{{ $t->url }}" @selected($template->exists && (int) $template->id === (int) $t->id)>{{ $t->name }}</option>
                    @endforeach
                </x-ui.select>
            </x-ui.field>
        @endif
        @if($canManage && $template->exists)
            <x-ui.button href="{{ $createUrl }}">New listing template</x-ui.button>
        @endif
    </div>

    <div class="fm-body">
        <div class="fm-col fm-col--main">
            <section class="fm-section">
                <div class="fm-section__head">
                    <h2 class="fm-section__title">Name</h2>
                </div>
                <div class="fm-fields">
                    <x-ui.field label="Listing template name" for="lt-name" name="name" :required="true" wide>
                        <x-ui.input id="lt-name" name="name" value="{{ old('name', $template->name) }}" maxlength="64"
                                    placeholder="Effects pedals" :disabled="!$canManage" />
                    </x-ui.field>
                </div>
                @if($usedBy->isNotEmpty())
                    <p class="fm-section__note">Used by @foreach($usedBy as $g)<a href="{{ $g->url }}">{{ $g->name }}</a>@if(! $loop->last), @endif @endforeach</p>
                @endif
            </section>

            @switch($channel)
                @case('shopee') @include('ext-shopee::templates._fields', $fields + ['template' => $template, 'canManage' => $canManage, 'storeId' => $storeId]) @break
                @case('lazada') @include('ext-lazada::templates._fields', $fields + ['template' => $template, 'canManage' => $canManage, 'storeId' => $storeId]) @break
                @case('tiktok') @include('ext-tiktok::templates._fields', $fields + ['template' => $template, 'canManage' => $canManage, 'storeId' => $storeId]) @break
                @case('woocommerce') @include('ext-woocommerce::templates._fields', $fields + ['template' => $template, 'canManage' => $canManage, 'storeId' => $storeId]) @break
                @case('ventacart') @include('ext-ventacart::templates._fields', $fields + ['template' => $template, 'canManage' => $canManage, 'storeId' => $storeId]) @break
            @endswitch
        </div>

        <div class="fm-col fm-col--side">
            <div class="fm-card">
                <div class="fm-card__head"><h2 class="fm-card__title">Price rule</h2></div>
                <div class="fm-card__body">
                    <x-ui.field label="Percentage of price" for="lt-markup-percent" name="markup_percent">
                        <div class="cs-inline">
                            <x-ui.input id="lt-markup-percent" name="markup_percent" type="number" step="0.01" min="0"
                                        class="fm-input--num cs-days"
                                        value="{{ old('markup_percent', $template->markup_percent) }}" placeholder="0"
                                        :disabled="!$canManage" />
                            <span class="cs-inline__unit">%</span>
                        </div>
                    </x-ui.field>
                    <x-ui.field label="Fixed amount" for="lt-markup-fixed" name="markup_fixed">
                        <x-ui.input id="lt-markup-fixed" name="markup_fixed" type="number" step="0.01" min="0"
                                    class="fm-input--num"
                                    value="{{ old('markup_fixed', $template->markup_fixed) }}" placeholder="0.00"
                                    :disabled="!$canManage" />
                    </x-ui.field>
                </div>
            </div>

            <div class="fm-card">
                <div class="fm-card__head"><h2 class="fm-card__title">Watermark</h2></div>
                <div class="fm-card__body">
                    @include('partials.watermark-pick', ['selected' => old('watermark_template_id', $template->watermark_template_id ?? null), 'channel' => $channel, 'canManage' => $canManage])
                </div>
            </div>
        </div>
    </div>

    <x-ui.form-bar :cancel="$productsUrl" :cancel-label="$canManage ? 'Cancel' : 'Back'">
        <x-slot:primary>
            @if($canManage)
                <x-ui.button type="submit" variant="primary">{{ $template->exists ? 'Save listing template' : 'Create listing template' }}</x-ui.button>
            @endif
        </x-slot:primary>
    </x-ui.form-bar>
</form>

@if($canManage && $template->exists)
    <form method="POST" class="lt-delete" action="{{ $destroyUrl }}"
          data-confirm="Delete the listing template &quot;{{ $template->name }}&quot;? Listings it was applied to keep their values." data-confirm-verb="Delete">
        @csrf
        @method('DELETE')
        <x-ui.button type="submit" variant="danger" size="sm">Delete listing template</x-ui.button>
    </form>
@endif
@endsection
