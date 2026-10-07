@extends('layouts.channel')
@section('title', 'TikTok Listing')
@section('breadcrumb', ($product->name ?? '') !== '' ? $product->name : 'Listing')

@section('own-errors', true)

@section('content')
@php
    $canManage = auth()->user()?->hasPermission('manage_tiktok/listing') ?? false;
    $canEditCatalog = auth()->user()?->hasPermission('manage_catalog/product') ?? false;
    $listed = (bool) $truth;
    [$statusLabel, $statusTone] = ($live && ($listingState ?? null))
        ? [$listingState->label(), $listingState->tone()]
        : [null, null];

    $corePrice = (float) $product->price;
    $missing = $readinessMissing;
    $coreDescription = (string) ($product->description ?? '');
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
            <a class="fm-back" href="{{ \App\Support\BackTo::safe(request('back'), route('ext.tiktok.products.index')) }}" data-guard-leave>
                <x-ui.icon name="chevron-left" size="14" /> Listings
            </a>
            <h1 class="fm-title">{{ ($product->name ?? '') !== '' ? $product->name : 'Product ' . $product->product_id }}</h1>
            <div class="fm-stamps">
                <span class="fm-stamp"><span class="fm-stamp__k">Channel</span><span class="fm-stamp__v">TikTok Shop</span></span>
                <span class="fm-stamp"><span class="fm-stamp__k">SKU</span><span class="fm-stamp__v">{{ ($product->sku ?? '') !== '' ? $product->sku : 'none' }}</span></span>
                @if($listed)
                    <span class="fm-stamp"><span class="fm-stamp__k">TikTok product ID</span><span class="fm-stamp__v">{{ $truth->tiktok_product_id }}</span></span>
                @endif
                @php $catalogChange = ($listing?->exists) ? $listing->catalogChange() : []; @endphp
                @if($catalogChange)
                    @include('partials.catalog-change-button')
                @endif
            </div>
        </div>
    </div>

    @php
        $stripFigures = [];
        if ($listed && $live) {
            $stripFigures[] = ['k' => 'Stock on TikTok Shop', 'v' => (string) $live['stock']];
            if (($coverage['total'] ?? 0) > 0) {
                $stripFigures[] = ['k' => 'On TikTok Shop', 'v' => $coverage['linked'] . ' of ' . $coverage['total'] . ' variations'];
            }
            if (($live['title'] ?? '') !== '' && $live['title'] !== $product->name) {
                $stripFigures[] = ['k' => 'Title on TikTok Shop', 'v' => \Illuminate\Support\Str::limit($live['title'], 40)];
            }
        }

        $stripState = $listed
            ? ['label' => $statusLabel ?? ($liveError ? 'TikTok Shop did not answer' : 'Unknown'), 'tone' => $statusTone ?? 'neutral']
            : ['label' => 'Not on TikTok Shop', 'tone' => 'neutral'];

        $ttPushedAt = ($truth->last_pushed_at ?? null) ? \Illuminate\Support\Carbon::parse($truth->last_pushed_at) : null;
        $stripLastPush = $ttPushedAt
            ? ['when' => $ttPushedAt->diffForHumans(),
               'from' => ($truth->group_name ?? null) ? 'the ' . $truth->group_name . ' group' : 'this listing']
            : null;
    @endphp

    <x-channel.listing-strip channel="TikTok Shop" :health="false" :linked="(bool) $listed">
        @if($canManage)
            <x-slot:verbs>
                @if($listed)
                    @if($live && $live['status'] === 'ACTIVATE')
                        <form method="POST" action="{{ route('ext.tiktok.listings.toggle', $product->product_id) }}"
                              data-guard-stale data-confirm="Deactivate this product on TikTok Shop? Buyers stop seeing it. It can be activated again at any time." data-confirm-verb="Deactivate">
                            @csrf
                            <input type="hidden" name="action" value="deactivate">
                            <x-ui.button type="submit" size="sm">Deactivate on TikTok Shop</x-ui.button>
                        </form>
                    @elseif($live && $live['status'] === 'SELLER_DEACTIVATED')
                        <form method="POST" action="{{ route('ext.tiktok.listings.toggle', $product->product_id) }}"
                              data-guard-stale data-confirm="Activate this product on TikTok Shop? Buyers see it again once TikTok applies it." data-confirm-tone="primary" data-confirm-verb="Activate">
                            @csrf
                            <input type="hidden" name="action" value="activate">
                            <x-ui.button type="submit" variant="oncolor" size="sm">Activate on TikTok Shop</x-ui.button>
                        </form>
                    @endif
                @endif
            </x-slot:verbs>
        @endif

    </x-channel.listing-strip>

    <form id="listing-form" method="POST" action="{{ route('ext.tiktok.listings.update', $product->product_id) }}" data-guard-unsaved>
        <input type="hidden" name="back" value="{{ \App\Support\BackTo::safe(request('back'), '') }}">
        @csrf
        @method('PUT')
        <x-channel.listing-body>
            <x-slot:main>
                @include('partials.listing-group-band', [
                    'fields' => [
                        'category' => ['label' => 'TikTok category', 'kind' => 'combo', 'value' => '#tl-category-value', 'text' => '#tl-category', 'reloads' => true],
                        'markup_percent' => ['label' => 'Percent added', 'kind' => 'input', 'el' => '#tl-markup-pct'],
                        'markup_fixed' => ['label' => 'Fixed amount added', 'kind' => 'input', 'el' => '#tl-markup-fixed'],
                        'watermark' => ['label' => 'Watermark', 'kind' => 'follow', 'el' => 'select[name="watermark_template_id"]'],
                        'attributes' => ['label' => 'Attribute answers', 'kind' => 'attributes'],
                    ],
                    'canManage' => $canManage,
                ])

                <section class="fm-section">
                    <div class="fm-section__head"><h2 class="fm-section__title">Content on this channel</h2></div>
                    <div class="fm-fields">
                        <x-ui.field label="Title" for="tl-title" name="title" wide>
                            <x-ui.input id="tl-title" name="title" :value="old('title', \App\Integrations\Listings\ListingContent::editable($listing->title ?? null, (string) $product->name))" maxlength="255" :disabled="!$canManage" />
                        </x-ui.field>
                        @include('partials.channel-description-pick', [
                            'id' => 'tl-prefix', 'label' => 'Prefix description', 'name' => 'description_prefix_id',
                            'selected' => (int) old('description_prefix_id', $listing->description_prefix_id ?? 0),
                            'options' => $descriptionTemplates, 'canManage' => $canManage,
                        ])
                        @include('partials.channel-description-compose', [
                            'id' => 'tl-description', 'label' => 'Description', 'name' => 'description',
                            'value' => old('description', \App\Integrations\Listings\ListingContent::editable($listing->description ?? null, (string) $coreDescription)),
                            'rich' => true, 'disabled' => !$canManage, 'rows' => 6, 'maxlength' => null,
                            'prefixFor' => 'tl-prefix', 'suffixFor' => 'tl-suffix',
                            'prefix' => $listing->description_prefix_id ?? null, 'suffix' => $listing->description_suffix_id ?? null,
                            'options' => $descriptionTemplates,
                        ])
                        @include('partials.channel-description-pick', [
                            'id' => 'tl-suffix', 'label' => 'Suffix description', 'name' => 'description_suffix_id',
                            'selected' => (int) old('description_suffix_id', $listing->description_suffix_id ?? 0),
                            'options' => $descriptionTemplates, 'canManage' => $canManage,
                        ])
                    </div>
                </section>

                <section class="fm-section" id="tl-category-section">
                    <div class="fm-section__head"><h2 class="fm-section__title">Category and brand</h2></div>
                    <div class="fm-fields">
                        @include('partials.category-tree-pick', [
                            'name' => 'tiktok_category_id',
                            'id' => 'tl-category',
                            'label' => 'TikTok category',
                            'value' => (int) old('tiktok_category_id', $shown->tiktok_category_id ?? 0),
                            'valueLabel' => $category ? $category->name : '',
                            'channel' => 'tiktok',
                            'storeId' => 0,
                            'searchUrl' => route('ext.tiktok.products.searchCategories'),
                            'childrenUrl' => route('ext.tiktok.products.category_children'),
                            'pathUrl' => route('ext.tiktok.products.category_path'),
                            'canManage' => $canManage,
                            'required' => true,
                            'requiredWord' => 'Pick a TikTok category from the list.',
                        ])
                        <x-ui.field label="Brand" for="tl-brand" name="brand_id" wide hint="TikTok Shop's own brand list, searched by name. Leave it empty for no brand.">
                            <div class="gf-combo" data-category-combo data-combo-mode="remote" data-combo-plain-label
                                 data-combo-search-url="{{ route('ext.tiktok.products.brandsSearch') }}"
                                 data-combo-extra-param="category_id" data-combo-extra-source="#tl-category-value">
                                <div class="gf-combo__field">
                                    <input type="hidden" name="brand_id" data-combo-value value="{{ old('brand_id', $listing->brand_id ?? '') }}">
                                    <input type="hidden" name="brand_name" value="{{ old('brand_name', $listing->brand_name ?? '') }}" data-combo-label-store>
                                    <x-ui.input id="tl-brand" type="search" autocomplete="off" data-combo-input
                                                value="{{ old('brand_name', $listing->brand_name ?? '') }}"
                                                placeholder="Type to search TikTok brands" :disabled="!$canManage" />
                                    <div class="gf-combo__results" data-combo-results role="listbox" aria-label="TikTok brands" hidden></div>
                                </div>
                            </div>
                        </x-ui.field>
                    </div>
                </section>

                <section class="fm-section"
                         id="tl-attributes"
                         data-attributes-fetch
                         data-attributes-url="{{ route('ext.tiktok.products.fetchAttributes') }}"
                         data-attributes-field="tiktok_category_id"
                         data-attributes-source="#tl-category-value"
                         @if(empty($attrRows) && !$attrTemplate) hidden @endif>
                    <div class="fm-section__head"><h2 class="fm-section__title">Category attributes <x-ui.hint label="About the attributes">What TikTok Shop asks for this category. Answers are saved with the listing and go with every push; TikTok refuses a push without the required ones.</x-ui.hint></h2></div>
                    <div data-attributes-target>
                        @include('ext-tiktok::product-groups._attributes', [
                            'rows' => $attrRows, 'saved' => $savedAttributes, 'template' => $attrTemplate, 'canManage' => $canManage,
                        ])
                    </div>
                </section>

                @include('partials.channel-images-card', [
                    'anchor' => 'tl-images', 'storeName' => 'TikTok Shop', 'tiles' => $tiles, 'catalogTiles' => $catalogTiles,
                    'fetchesOverWeb' => false,
                    'editable' => true, 'canManage' => $canManage, 'browseUrl' => $browseUrl,
                ])


                @include('partials.channel-video-card', [
                    'anchor' => 'tl-video', 'storeName' => 'TikTok Shop',
                    'productId' => (int) $product->product_id,
                    'uploadUrl' => route('products.video.upload'),
                    'canManage' => $canManage,
                ] + \App\Integrations\Listings\ListingVideo::cardData((int) $product->product_id, $listing ?? null))

                @include('partials.listing-variations-card', [
                    'title' => 'Variations on TikTok Shop', 'storeName' => 'TikTok Shop', 'canManage' => $canManage,
                ])

                @include('partials.listing-parcel-card', [
                    'id' => 'tl', 'canManage' => $canManage, 'listing' => $listing, 'catalog' => $product,
                ])
            </x-slot:main>

            <x-slot:rail>
                @include('partials.listing-price-card', [
                    'id' => 'tl', 'storeName' => 'TikTok Shop', 'canManage' => $canManage,
                    'markupPercent' => $shown->markup_percent, 'markupFixed' => $shown->markup_fixed,
                    'price' => $listing->price ?? null, 'corePrice' => $corePrice,
                    'pushPrice' => $pushPrice, 'hasVariations' => $hasVariations,
                ])

            </x-slot:rail>
        </x-channel.listing-body>
    </form>

    <x-ui.form-bar :cancel="\App\Support\BackTo::safe(request('back'), route('ext.tiktok.products.index'))" :cancel-label="$canManage ? 'Cancel' : 'Back'">
        <x-slot:note>{{ !$canManage
            ? 'You can read this. Editing needs the TikTok manage permission.'
            : 'Save listing keeps everything on this page. Save and push keeps it and sends the listing to TikTok Shop.' }}</x-slot:note>
        @if($canManage)
            <x-ui.button type="submit" form="listing-form" name="push_after" value="1">{{ $listed ? 'Save and push the update' : 'Save and push to TikTok Shop' }}</x-ui.button>
        @endif
        <x-slot:primary>
            @if($canManage)
                <x-ui.button type="submit" form="listing-form" variant="primary">Save listing</x-ui.button>
            @endif
        </x-slot:primary>
    </x-ui.form-bar>

</div>
@include('partials.channel-description-pick-modal', ['storeUrl' => route('ext.tiktok.description-templates.store', ['store' => $storeId])])

@if($canManage)
    @include('partials.image-library-pick', ['browseUrl' => $browseUrl])
@endif
@if(!empty($catalogChange))
    @include('partials.catalog-change-panel', [
        'listing' => $listing,
        'storeLabel' => \App\Support\StoreLabel::of('TikTok', \Extensions\tiktok\Models\TikTokSetting::query()->find($listing->tiktok_setting_id)?->store_name),
        'saveUrl' => route('ext.tiktok.listings.catalog_change_save', $listing->product_id),
        'ignoreUrl' => route('ext.tiktok.listings.catalog_change_ignore', $listing->product_id),
        'back' => request()->boolean('compare') ? \App\Support\BackTo::safe(request('back'), request()->getRequestUri()) : request()->getRequestUri(),
        'canManage' => $canManage,
    ])
@endif

@endsection
