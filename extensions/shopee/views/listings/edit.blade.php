@extends('layouts.channel')
@section('title', 'Shopee Listing')
@section('breadcrumb', $product->name !== '' ? $product->name : 'Listing')
@section('own-errors', true)

@section('content')
@php
    $canManage = auth()->user()?->hasPermission('manage_shopee/product') ?? false;
    $canEditCatalog = auth()->user()?->hasPermission('manage_catalog/product') ?? false;

    [$statusLabel, $statusTone] = ($live && ($listingState ?? null))
        ? [$listingState->label(), $listingState->tone()]
        : [null, null];

    $corePrice = (float) $product->price;
    $pushPrice = $shown->itemPriceFor($corePrice, $hasVariations);

    $selectedCategoryId = (int) old('shopee_category_id', $shown->shopee_category_id ?? 0);
    $oldLogisticIds = old('logistic_ids');
    $savedLogisticIds = array_map('intval', $shown->logistic_ids ?? []);

    $coreDescription = \App\Support\Catalog\DescriptionText::of((string) ($product->description ?? ''));

    $missing = $readinessMissing;
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
            <a class="fm-back" href="{{ \App\Support\BackTo::safe(request('back'), route('ext.shopee.products.index')) }}" data-guard-leave>
                <x-ui.icon name="chevron-left" size="14" /> Listings
            </a>
            <h1 class="fm-title">{{ $product->name !== '' ? $product->name : 'Product ' . $product->product_id }}</h1>
            <div class="fm-stamps">
                <span class="fm-stamp">
                    <span class="fm-stamp__k">Channel</span>
                    <span class="fm-stamp__v">Shopee</span>
                </span>
                <span class="fm-stamp">
                    <span class="fm-stamp__k">SKU</span>
                    <span class="fm-stamp__v">{{ $product->sku !== '' && $product->sku !== null ? $product->sku : 'none' }}</span>
                </span>
                @if($link)
                    <span class="fm-stamp">
                        <span class="fm-stamp__k">Item ID</span>
                        @php $liveUrl = \Extensions\shopee\Models\ShopeeSetting::publicListingUrl($link->shopee_item_id); @endphp
                        @if($liveUrl)
                            <a class="fm-stamp__v" href="{{ $liveUrl }}" target="_blank" rel="noopener">{{ $link->shopee_item_id }}<x-ui.icon name="external-link" size="10" class="x-extlink" /><span class="x-sr"> (opens Shopee in a new tab)</span></a>
                        @else
                            <span class="fm-stamp__v">{{ $link->shopee_item_id }}</span>
                        @endif
                    </span>
                @endif
                @php $catalogChange = ($listing && $listing->exists) ? $listing->catalogChange() : []; @endphp
                @if($catalogChange)
                    @include('partials.catalog-change-button')
                @endif
            </div>
        </div>
    </div>

    @php
        $stripFigures = [];
        if ($link && $live) {
            if (!$live['has_model']) {
                $stripFigures[] = ['k' => 'Price on Shopee', 'v' => $live['price']['original'] !== null
                    ? \App\Support\Money::base((float) ($live['price']['current'] ?? $live['price']['original']))
                    : 'unknown'];
            }
            $stripFigures[] = ['k' => 'Stock on Shopee', 'v' => $live['stock'] !== null ? (string) $live['stock'] : 'unknown'];
            if (($coverage['total'] ?? 0) > 0) {
                $stripFigures[] = ['k' => 'On Shopee', 'v' => $coverage['linked'] . ' of ' . $coverage['total'] . ' variations'];
            }
            if (($live['item_name'] ?? '') !== '' && $live['item_name'] !== $product->name) {
                $stripFigures[] = ['k' => 'Title on Shopee', 'v' => \Illuminate\Support\Str::limit($live['item_name'], 40)];
            }
        }

        $stripState = $link
            ? ['label' => $statusLabel ?? ($liveError ? 'Shopee did not answer' : 'Unknown'), 'tone' => $statusTone ?? 'neutral']
            : ['label' => 'Not on Shopee', 'tone' => 'neutral'];

        $stripLastPush = ($listing && $listing->last_pushed_at)
            ? ['when' => $listing->last_pushed_at->diffForHumans(), 'from' => match (true) {
                (string) $listing->last_push_source === 'form' => 'the push form',
                (string) $listing->last_push_source === 'listing' => 'this listing',
                str_starts_with((string) $listing->last_push_source, 'group:') => 'the ' . substr((string) $listing->last_push_source, 6) . ' group',
                default => null,
            }]
            : null;
    @endphp

    <x-channel.listing-strip channel="Shopee" :health="false" :linked="(bool) $link">
        @if($canManage)
            <x-slot:verbs>
                @if($link)
                    @if($live && $live['item_status'] === 'NORMAL')
                        <form method="POST" action="{{ route('ext.shopee.listings.toggle', $product->product_id) }}"
                              data-confirm="Delist this item from Shopee? Buyers stop seeing it immediately. It stays here and can be published again at any time." data-confirm-verb="Unlist">
                            @csrf
                            <input type="hidden" name="action" value="unlist">
                            <x-ui.button type="submit" size="sm">Delist from Shopee</x-ui.button>
                        </form>
                    @elseif($live && $live['item_status'] === 'UNLIST')
                        <form method="POST" action="{{ route('ext.shopee.listings.toggle', $product->product_id) }}"
                              data-confirm="Publish this item on Shopee? Buyers see it again immediately." data-confirm-tone="primary" data-confirm-verb="Relist">
                            @csrf
                            <input type="hidden" name="action" value="list">
                            <x-ui.button type="submit" variant="oncolor" size="sm">Publish on Shopee</x-ui.button>
                        </form>
                    @endif
                    @if(($coverage['missing'] ?? collect())->isNotEmpty())
                        <form method="POST" action="{{ route('ext.shopee.listings.push_missing_variations', $product->product_id) }}" data-slow-action
                              data-guard-stale data-confirm="Add the missing variations to the live Shopee listing? What is already live is not touched; new tier options are appended and the new variations go up priced by this listing's rule." data-confirm-tone="primary" data-confirm-verb="Add variations">
                            @csrf
                            <x-ui.button type="submit" size="sm">Push missing variations</x-ui.button>
                        </form>
                    @endif
                    <form method="POST" action="{{ route('ext.shopee.listings.reread_variations', $product->product_id) }}" data-slow-action>
                        @csrf
                        <x-ui.button type="submit" size="sm">Re-read variations from Shopee</x-ui.button>
                    </form>
                @endif
            </x-slot:verbs>
        @endif

    </x-channel.listing-strip>

    <form id="listing-form" method="POST" action="{{ route('ext.shopee.listings.update', $product->product_id) }}" data-guard-unsaved>
        <input type="hidden" name="back" value="{{ \App\Support\BackTo::safe(request('back'), '') }}">
        @csrf
        <x-channel.listing-body>
            <x-slot:main>

                @include('partials.listing-group-band', [
                    'fields' => [
                        'category' => ['label' => 'Shopee category', 'kind' => 'combo', 'value' => '#sl-category-value', 'text' => '#sl-category', 'reloads' => true],
                        'brand' => ['label' => 'Brand', 'kind' => 'combo', 'value' => 'input[name="shopee_brand_id"]', 'text' => '#sl-brand'],
                        'couriers' => ['label' => 'Couriers', 'kind' => 'checks', 'name' => 'logistic_ids[]'],
                        'markup_percent' => ['label' => 'Percent added', 'kind' => 'input', 'el' => '#sl-markup-pct'],
                        'markup_fixed' => ['label' => 'Fixed amount added', 'kind' => 'input', 'el' => '#sl-markup-fixed'],
                        'watermark' => ['label' => 'Watermark', 'kind' => 'follow', 'el' => 'select[name="watermark_template_id"]'],
                        'attributes' => ['label' => 'Attribute answers', 'kind' => 'attributes'],
                    ],
                    'canManage' => $canManage,
                ])

                <section class="fm-section">
                    <div class="fm-section__head">
                        <h2 class="fm-section__title">Content on this channel</h2>
                    </div>
                    <div class="fm-fields">
                        <x-ui.field label="Title" for="sl-name" name="item_name" wide>
                            <x-ui.input id="sl-name" name="item_name" maxlength="255"
                                        :value="old('item_name', \App\Integrations\Listings\ListingContent::editable($listing->item_name ?? null, (string) $product->name))"
                                        :disabled="!$canManage" />
                        </x-ui.field>
                        @include('partials.channel-description-pick', [
                            'id' => 'sl-prefix', 'label' => 'Prefix description', 'name' => 'description_prefix_id',
                            'selected' => (int) old('description_prefix_id', $listing->description_prefix_id ?? 0),
                            'options' => $descriptionTemplates, 'canManage' => $canManage,
                        ])
                        @include('partials.channel-description-compose', [
                            'id' => 'sl-description', 'label' => 'Description', 'name' => 'description',
                            'value' => old('description', \App\Integrations\Listings\ListingContent::editable($listing->description ?? null, (string) $coreDescription)),
                            'rich' => true, 'disabled' => !$canManage, 'rows' => 6, 'maxlength' => null,
                            'prefixFor' => 'sl-prefix', 'suffixFor' => 'sl-suffix',
                            'prefix' => $listing->description_prefix_id ?? null, 'suffix' => $listing->description_suffix_id ?? null,
                            'options' => $descriptionTemplates,
                        ])
                        @include('partials.channel-description-pick', [
                            'id' => 'sl-suffix', 'label' => 'Suffix description', 'name' => 'description_suffix_id',
                            'selected' => (int) old('description_suffix_id', $listing->description_suffix_id ?? 0),
                            'options' => $descriptionTemplates, 'canManage' => $canManage,
                        ])
                    </div>
                </section>

                <section class="fm-section" id="sl-category-section">
                    <div class="fm-section__head">
                        <h2 class="fm-section__title">Category and brand</h2>
                    </div>
                    <div class="fm-fields">
                        @include('partials.category-tree-pick', [
                            'name' => 'shopee_category_id',
                            'id' => 'sl-category',
                            'label' => 'Shopee category',
                            'value' => $selectedCategoryId,
                            'valueLabel' => '',
                            'channel' => 'shopee',
                            'storeId' => 0,
                            'searchUrl' => route('ext.shopee.products.searchCategories'),
                            'childrenUrl' => route('ext.shopee.products.category_children'),
                            'pathUrl' => route('ext.shopee.products.category_path'),
                            'canManage' => $canManage,
                            'required' => true,
                            'requiredWord' => 'Pick a Shopee category from the list.',
                        ])
                        @php
                            $slBrandId = (int) old('shopee_brand_id', $shown->shopee_brand_id ?? 0);
                            $slBrandName = $slBrandId > 0
                                ? (string) (optional($initialBrands->firstWhere('brand_id', $slBrandId))->name ?? '')
                                : '';
                        @endphp
                        <x-ui.field label="Brand" for="sl-brand" name="shopee_brand_id" wide
                                    hint="Only brands Shopee registers for the chosen category are offered. Leave it empty for No brand.">
                            <div class="gf-combo"
                                 data-category-combo
                                 data-combo-mode="remote"
                                 data-combo-plain-label
                                 data-combo-search-url="{{ route('ext.shopee.products.brandsForCategory') }}"
                                 data-combo-extra-param="category_id"
                                 data-combo-extra-source="#sl-category-value"
                                 data-combo-extra-empty="Pick a category first - every brand list belongs to one."
                                 data-combo-warm>
                                <div class="gf-combo__field">
                                    <input type="hidden" name="shopee_brand_id" data-combo-value
                                           value="{{ $slBrandId > 0 ? $slBrandId : '' }}">
                                    <x-ui.input id="sl-brand" type="search" autocomplete="off"
                                                data-combo-input
                                                value="{{ $slBrandName }}"
                                                placeholder="Type to search this category's brands"
                                                :disabled="!$canManage" />
                                    <div class="gf-combo__results" data-combo-results role="listbox"
                                         aria-label="Shopee brands" hidden></div>
                                    <div class="gf-combo__progress" data-combo-progress role="status" aria-live="polite" hidden>
                                        <span class="gf-combo__bar" aria-hidden="true"></span>
                                        <span data-combo-progress-text></span>
                                    </div>
                                </div>
                            </div>
                        </x-ui.field>
                    </div>
                </section>

                <section class="fm-section"
                         data-attributes-fetch
                         data-attributes-url="{{ route('ext.shopee.products.fetchAttributes') }}"
                         data-attributes-field="shopee_category_id"
                         id="sl-attributes"
                         data-attributes-source="#sl-category-value"
                         @if(empty($attrRows) && !$attrTemplate) hidden @endif>
                    <div class="fm-section__head">
                        <h2 class="fm-section__title">Category attributes <x-ui.hint label="About the attributes">What Shopee asks for this category. Answers are saved with the listing and go with every push. Required ones block nothing here, but Shopee refuses a push without them.</x-ui.hint></h2>
                    </div>
                    <div data-attributes-target>
                        @include('ext-shopee::product-groups._attributes', [
                            'attributes' => $attrRows,
                            'saved' => $savedAttributes,
                            'template' => $attrTemplate,
                            'canManage' => $canManage,
                        ])
                    </div>
                </section>

                @include('partials.channel-images-card', [
                    'anchor' => 'sl-images', 'storeName' => 'Shopee', 'tiles' => $tiles, 'catalogTiles' => $catalogTiles,
                    'fetchesOverWeb' => false,
                    'editable' => true, 'canManage' => $canManage, 'browseUrl' => $browseUrl,
                ])


                @include('partials.channel-video-card', [
                    'anchor' => 'sl-video', 'storeName' => 'Shopee',
                    'productId' => (int) $product->product_id,
                    'uploadUrl' => route('products.video.upload'),
                    'canManage' => $canManage,
                ] + \App\Integrations\Listings\ListingVideo::cardData((int) $product->product_id, $listing ?? null))

                @include('partials.listing-variations-card', [
                    'title' => 'Variations on Shopee', 'storeName' => 'Shopee', 'canManage' => $canManage,
                ])

                @include('partials.listing-parcel-card', [
                    'id' => 'sl', 'canManage' => $canManage, 'listing' => $listing, 'catalog' => $product,
                    'weightMin' => '0.01',
                ])

                <section class="fm-section" id="sl-only">
                    <div class="fm-section__head">
                        <h2 class="fm-section__title">Only on Shopee</h2>
                    </div>
                    <div class="fm-sub" id="sl-couriers">
                        <h3 class="fm-sub__title">Couriers <span class="fm-req" aria-hidden="true">*</span><span class="x-sr">(required)</span> <x-ui.hint label="About the couriers">Which of Shopee's couriers this listing is offered on. Pick at least one.</x-ui.hint></h3>
                        @if($errors->has('logistic_ids'))
                            <div class="fm-error">{{ $errors->first('logistic_ids') }}</div>
                        @endif
                        @if(!empty($logistics))
                            <div class="gf-checks" data-must-pick="Pick at least one courier.">
                                @foreach($logistics as $channel)
                                    @php
                                        $logisticId = (int) ($channel['logistic_id'] ?? 0);
                                        $isChecked = is_array($oldLogisticIds)
                                            ? in_array($logisticId, array_map('intval', $oldLogisticIds), true)
                                            : in_array($logisticId, $savedLogisticIds, true);
                                    @endphp
                                    <label class="gf-check">
                                        <input type="checkbox" class="cc-check" name="logistic_ids[]" value="{{ $logisticId }}"
                                               @checked($isChecked) @disabled(!$canManage)>
                                        <span class="gf-check__name">{{ (string) ($channel['logistic_name'] ?? 'Channel ' . $logisticId) }}</span>
                                        <span class="gf-check__id">{{ $logisticId }}</span>
                                    </label>
                                @endforeach
                            </div>
                        @else
                            <x-ui.empty title="No couriers synced yet"
                                        description="Sync Shopee's courier channels first; a listing cannot ship without one.">
                                <x-slot:action>
                                    <x-ui.button :href="route('ext.shopee.logistics.index')">Go to Logistics</x-ui.button>
                                </x-slot:action>
                            </x-ui.empty>
                        @endif
                    </div>
                </section>

            </x-slot:main>

            <x-slot:rail>
                @include('partials.listing-price-card', [
                    'id' => 'sl', 'storeName' => 'Shopee', 'canManage' => $canManage,
                    'markupPercent' => $shown->markup_percent, 'markupFixed' => $shown->markup_fixed,
                    'price' => $hasVariations ? null : ($listing->price ?? null),
                    'corePrice' => $corePrice, 'pushPrice' => $pushPrice, 'hasVariations' => $hasVariations,
                ])

            </x-slot:rail>
        </x-channel.listing-body>
    </form>



    <x-ui.form-bar :cancel="\App\Support\BackTo::safe(request('back'), route('ext.shopee.products.index'))" :cancel-label="$canManage ? 'Cancel' : 'Back'">
        <x-slot:note>{{ !$canManage
            ? 'You can read this. Editing needs the Shopee manage permission.'
            : 'Save listing keeps everything on this page. Save and push keeps it and sends the listing to Shopee.' }}</x-slot:note>
        @if($canManage)
            <x-ui.button type="submit" form="listing-form" name="push_after" value="1">{{ $link ? 'Save and push the update' : 'Save and push to Shopee' }}</x-ui.button>
        @endif
        <x-slot:primary>
            @if($canManage)
                <x-ui.button type="submit" form="listing-form" variant="primary">Save listing</x-ui.button>
            @endif
        </x-slot:primary>
    </x-ui.form-bar>

</div>

@if($canManage)
@endif

@include('partials.channel-description-pick-modal', ['storeUrl' => route('ext.shopee.description-templates.store', ['store' => $storeId])])

@if($canManage || !empty($catalogChange))
    @include('partials.image-library-pick', ['browseUrl' => $browseUrl])
@endif
@if(!empty($catalogChange))
    @include('catalog.products.partials.wysiwyg')
    @include('partials.catalog-change-panel', [
        'listing' => $listing,
        'storeLabel' => \App\Support\StoreLabel::of('Shopee', \Extensions\shopee\Models\ShopeeSetting::query()->find($listing->shopee_setting_id)?->store_name),
        'saveUrl' => route('ext.shopee.listings.catalog_change_save', $listing->product_id),
        'ignoreUrl' => route('ext.shopee.listings.catalog_change_ignore', $listing->product_id),
        'back' => request()->boolean('compare') ? \App\Support\BackTo::safe(request('back'), request()->getRequestUri()) : request()->getRequestUri(),
        'canManage' => $canManage,
    ])
@endif

@endsection
