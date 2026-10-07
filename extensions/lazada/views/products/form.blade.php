@extends('layouts.channel')
@section('title', ($mode === 'create' ? 'Add' : 'Edit') . ' Lazada Listing')

@section('own-errors', true)
@section('breadcrumb', $mode === 'create'
    ? 'New Listing'
    : (($productRow->name ?? null) ? html_entity_decode((string) $productRow->name, ENT_QUOTES | ENT_HTML5, 'UTF-8') : 'Listing ' . $listing->id))

@section('content')
@php
    $groupMarkup = $listing->groups->first();

    $effectiveFixed = old('markup_fixed', $listing->markup_fixed ?? ($groupMarkup->markup_fixed ?? null));
    $effectivePercent = old('markup_percent', $listing->markup_percent ?? ($groupMarkup->markup_percent ?? null));

    $noBrand = old('no_brand');
    if ($noBrand === null) {
        $brandOverrideShown = $effectiveBrandOverride ?? $listing->brand_name_override;
        $noBrand = (!empty($brandOverrideShown)
            && strtolower(trim((string) $brandOverrideShown)) === 'no brand') ? 1 : 0;
    }
    $noBrand = (int) $noBrand;

    $brandId = old('brand_id', $effectiveBrandId ?? $listing->brand_id);
    $brandName = $noBrand ? 'No Brand' : (old('brand_name') ?? ($selectedBrandName ?? ''));

    $productName = ($productRow->name ?? null)
        ? html_entity_decode((string) $productRow->name, ENT_QUOTES | ENT_HTML5, 'UTF-8')
        : '';
    $basePrice = isset($productRow->price) ? (float) $productRow->price : null;

    $hasVariations = ($variants ?? collect())->count() > 0;
    $pushPrice = \Extensions\lazada\Services\Lazada\LazadaPushPayload::computeFinalPrice(
        $hasVariations
            ? (float) $basePrice
            : \Extensions\lazada\Services\Lazada\LazadaPushPayload::startingPrice($listing, (float) $basePrice),
        is_numeric($effectiveFixed) ? (float) $effectiveFixed : null,
        is_numeric($effectivePercent) ? (float) $effectivePercent : null
    );

    $coreDescription = (string) ($productRow->description ?? '');

    $jsonFlags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
    $erpFieldValues = $productRow ? [
        'name'             => (string) ($productRow->name ?? ''),
        'description'      => $productRow->description
            ? strip_tags(html_entity_decode((string) $productRow->description, ENT_QUOTES | ENT_HTML5, 'UTF-8'))
            : '',
        'meta_title'       => (string) ($productRow->meta_title ?? ''),
        'meta_description' => (string) ($productRow->meta_description ?? ''),
        'model'            => (string) ($productRow->model ?? ''),
        'sku'              => (string) ($productRow->sku ?? ''),
        'upc'              => (string) ($productRow->upc ?? ''),
        'ean'              => (string) ($productRow->ean ?? ''),
        'jan'              => (string) ($productRow->jan ?? ''),
        'isbn'             => (string) ($productRow->isbn ?? ''),
        'mpn'              => (string) ($productRow->mpn ?? ''),
        'weight'           => (string) ($productRow->weight ?? ''),
        'length'           => (string) ($productRow->length ?? ''),
        'width'            => (string) ($productRow->width ?? ''),
        'height'           => (string) ($productRow->height ?? ''),
        'price'            => (string) ($productRow->price ?? ''),
        'quantity'         => (string) ($productRow->quantity ?? ''),
    ] : [];
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
            <a class="fm-back" href="{{ \App\Support\BackTo::safe(request('back'), route('ext.lazada.products.index')) }}" data-guard-leave>
                <x-ui.icon name="chevron-left" size="14" /> Listings
            </a>
            <h1 class="fm-title">{{ $mode === 'create' ? 'New Lazada listing' : ($productName !== '' ? $productName : 'Listing ' . $listing->id) }}</h1>
            <div class="fm-stamps">
                <span class="fm-stamp">
                    <span class="fm-stamp__k">Channel</span>
                    <span class="fm-stamp__v">Lazada</span>
                </span>
                @if($mode === 'edit' && $listing->product_id)
                    <span class="fm-stamp">
                        <span class="fm-stamp__k">Catalog product</span>
                        <a class="fm-stamp__v" href="{{ route('products.edit', $listing->product_id) }}">{{ $listing->product_id }}</a>
                    </span>
                @endif
                @php $catalogChange = ($mode === 'edit' && $listing->exists) ? $listing->catalogChange() : []; @endphp
                @if($catalogChange)
                    @include('partials.catalog-change-button')
                @endif
            </div>
        </div>
    </div>

    @if($mode === 'edit' && !empty($productRow))
        <div class="cl-summary">
            @if(!empty($productImageUrl))
                <img class="cl-summary__thumb" src="{{ $productImageUrl }}" alt="" loading="lazy" decoding="async">
            @else
                <span class="cl-summary__none">No image</span>
            @endif
            <span class="cl-summary__body">
                <span class="cl-summary__name">{{ $productName !== '' ? $productName : 'Unnamed product' }}</span>
                @php
                    $summaryParts = [];
                    if (!empty($productRow->sku)) $summaryParts[] = 'SKU ' . $productRow->sku;
                    if (!empty($productRow->model)) $summaryParts[] = 'Model ' . $productRow->model;
                    if ($basePrice !== null) $summaryParts[] = \App\Support\Money::base($basePrice);
                @endphp
                <span class="cl-summary__meta">{{ $summaryParts ? implode(' / ', $summaryParts) : 'No identifiers set' }}</span>
            </span>
        </div>
    @endif

    @php
        $lzLinked = $mode === 'edit' && !empty($listing->lazada_item_id);
        $lzSkus = $live['skus'] ?? [];

        $stripFigures = [];
        if ($lzLinked && ($live ?? null) !== null) {
            if (count($lzSkus) === 1) {
                $one = $lzSkus[0];
                $stripFigures[] = ['k' => 'Price on Lazada', 'v' => ($one['price'] ?? null) !== null
                    ? \App\Support\Money::base((float) ($one['special_price'] ?: $one['price']))
                    : 'unknown'];
                $stripFigures[] = ['k' => 'Stock on Lazada', 'v' => ($one['quantity'] ?? null) !== null ? (string) $one['quantity'] : 'unknown'];
            } elseif (count($lzSkus) > 1) {
                $stripFigures[] = ['k' => 'SKUs on Lazada', 'v' => (string) count($lzSkus)];
            }
            if (($live['sub_status'] ?? '') !== '') {
                $stripFigures[] = ['k' => 'Detail', 'v' => $live['sub_status']];
            }
            if (($coverage['total'] ?? 0) > 0) {
                $stripFigures[] = ['k' => 'On Lazada', 'v' => $coverage['linked'] . ' of ' . $coverage['total'] . ' variations'];
            }
            if (($live['item_name'] ?? '') !== '' && $live['item_name'] !== $productName) {
                $stripFigures[] = ['k' => 'Title on Lazada', 'v' => \Illuminate\Support\Str::limit($live['item_name'], 40)];
            }
        }

        $stripState = $lzLinked
            ? ['label' => ($listingState ?? null) ? $listingState->label() : (($liveError ?? null) !== null ? 'Lazada did not answer' : 'Unknown'),
               'tone' => ($listingState ?? null) ? $listingState->tone() : 'neutral']
            : ['label' => 'Not on Lazada', 'tone' => 'neutral'];

        $lzPushedAt = $listing->last_pushed_at ?? null;
        $lzSource = (string) ($listing->last_push_source ?? '');
        $stripLastPush = $lzPushedAt
            ? ['when' => $lzPushedAt->diffForHumans(), 'from' => match (true) {
                $lzSource === 'listing' => 'this listing',
                str_starts_with($lzSource, 'group:') => 'the ' . substr($lzSource, 6) . ' group',
                default => null,
            }]
            : null;

        $lzCoreMoved = ($productRow->date_modified ?? null)
            ? \Illuminate\Support\Carbon::parse($productRow->date_modified) : null;
    @endphp

    @if($mode === 'edit')
    <x-channel.listing-strip channel="Lazada" :health="false" :linked="$lzLinked">
            @if($lzLinked && ($live ?? null) !== null)
                <x-slot:verbs>
                    @if(($live['status'] ?? '') === 'active')
                        <form method="POST" action="{{ route('ext.lazada.listings.toggle', $listing->product_id) }}"
                              data-guard-stale data-confirm="Deactivate this item on Lazada? Buyers stop seeing it immediately. It stays here and can be activated again." data-confirm-verb="Deactivate">
                            @csrf
                            <input type="hidden" name="action" value="deactivate">
                            <x-ui.button type="submit" size="sm">Deactivate on Lazada</x-ui.button>
                        </form>
                    @elseif(($live['status'] ?? '') === 'inactive')
                        <form method="POST" action="{{ route('ext.lazada.listings.toggle', $listing->product_id) }}"
                              data-guard-stale data-confirm="Activate this item on Lazada? Buyers see it again once Lazada applies it." data-confirm-tone="primary" data-confirm-verb="Activate">
                            @csrf
                            <input type="hidden" name="action" value="activate">
                            <x-ui.button type="submit" variant="oncolor" size="sm">Activate on Lazada</x-ui.button>
                        </form>
                    @endif
                </x-slot:verbs>
            @endif

        </x-channel.listing-strip>
    @endif

    <form id="lazada-listing" method="POST"
          action="{{ route('ext.lazada.products.update', $listing->product_id) }}"
          data-slow-action="push" data-guard-unsaved>
        @csrf
        @method('PUT')
        <input type="hidden" name="back" value="{{ \App\Support\BackTo::safe(request('back'), '') }}">

        <x-channel.listing-body>
            <x-slot:main>
                @if($mode === 'edit')
                    @include('partials.listing-group-band', [
                        'fields' => [
                            'category' => ['label' => 'Lazada category', 'kind' => 'combo', 'value' => 'input[name="primary_category_id"]', 'text' => '#lz-category', 'reloads' => true],
                            'brand' => ['label' => 'Brand', 'kind' => 'lzbrand', 'id' => '[data-brand-id]', 'none' => '[data-brand-none-flag]', 'text' => '#lz-brand', 'readout' => '[data-brand-picked]'],
                            'markup_percent' => ['label' => 'Percent added', 'kind' => 'input', 'el' => '#lz-markup-pct'],
                            'markup_fixed' => ['label' => 'Fixed amount added', 'kind' => 'input', 'el' => '#lz-markup-fixed'],
                            'watermark' => ['label' => 'Watermark', 'kind' => 'follow', 'el' => 'select[name="watermark_template_id"]'],
                            'attributes' => ['label' => 'Attribute answers', 'kind' => 'attributes'],
                        ],
                        'canManage' => true,
                    ])
                @endif

                @if($mode === 'create')
                <section class="fm-section">
                    <div class="fm-section__head">
                        <h2 class="fm-section__title">Which product</h2>
                    </div>

                    <div class="fm-fields" data-catalog-picker
                         data-search-url="{{ route('ext.lazada.products.search_catalog') }}">
                        <x-ui.field label="Catalog product" for="lz-search" name="product_id" :required="true" wide
                                    hint="Type at least two characters, then pick one from the list. Every Lazada payload is built from this product.">
                            <div class="fm-ta">
                                <x-ui.input id="lz-search" type="search" autocomplete="off"
                                            data-unmatched-search
                                            placeholder="Search by name, model or SKU" />
                                <div class="fm-ta__list" data-unmatched-results role="listbox" aria-label="Catalog matches"></div>
                            </div>
                            <input type="hidden" name="product_id" data-unmatched-id value="{{ old('product_id', $listing->product_id) }}">
                        </x-ui.field>
                    </div>
                </section>
                @endif

                @if($mode === 'edit')
                <section class="fm-section">
                    <div class="fm-section__head">
                        <h2 class="fm-section__title">Content on this channel</h2>
                    </div>
                    <div class="fm-fields">
                        <x-ui.field label="Title" for="lz-item-name" name="item_name" wide>
                            <x-ui.input id="lz-item-name" name="item_name" maxlength="255"
                                        :value="old('item_name', \App\Integrations\Listings\ListingContent::editable($listing->item_name ?? null, (string) $productName))" />
                        </x-ui.field>
                        @include('partials.channel-description-pick', [
                            'id' => 'lz-prefix', 'label' => 'Prefix description', 'name' => 'description_prefix_id',
                            'selected' => (int) old('description_prefix_id', $listing->description_prefix_id ?? 0),
                            'options' => $descriptionTemplates, 'canManage' => true,
                        ])
                        @include('partials.channel-description-compose', [
                            'id' => 'lz-description', 'label' => 'Description', 'name' => 'description',
                            'value' => old('description', \App\Integrations\Listings\ListingContent::editable($listing->description ?? null, (string) $coreDescription)),
                            'rich' => true, 'disabled' => false, 'rows' => 6, 'maxlength' => 20000,
                            'prefixFor' => 'lz-prefix', 'suffixFor' => 'lz-suffix',
                            'prefix' => $listing->description_prefix_id ?? null, 'suffix' => $listing->description_suffix_id ?? null,
                            'options' => $descriptionTemplates,
                        ])
                        @include('partials.channel-description-pick', [
                            'id' => 'lz-suffix', 'label' => 'Suffix description', 'name' => 'description_suffix_id',
                            'selected' => (int) old('description_suffix_id', $listing->description_suffix_id ?? 0),
                            'options' => $descriptionTemplates, 'canManage' => true,
                        ])
                    </div>
                </section>
                @endif

                <section class="fm-section">
                    <div class="fm-section__head">
                        <h2 class="fm-section__title">Category and brand</h2>
                    </div>

                    <div class="fm-fields">
                        @include('partials.category-tree-pick', [
                            'name' => 'primary_category_id',
                            'id' => 'lz-category',
                            'label' => 'Lazada category',
                            'value' => (int) old('primary_category_id', $effectiveCategoryId ?? $listing->primary_category_id),
                            'valueLabel' => '',
                            'channel' => 'lazada',
                            'storeId' => 0,
                            'searchUrl' => route('ext.lazada.products.category_search'),
                            'childrenUrl' => route('ext.lazada.products.category_children'),
                            'pathUrl' => route('ext.lazada.products.category_path'),
                            'canManage' => true,
                            'required' => true,
                            'requiredWord' => 'Pick a Lazada category from the list.',
                        ])
                            @if($mode === 'edit')
                                <div class="fm-hint">
                                    <span data-template-read-at>@if($template && $template->fetched_at) Attributes read from Lazada {{ $template->fetched_at->format('Y-m-d H:i') }}. @elseif($listing->primary_category_id) Attributes not read from Lazada yet. @else Attributes appear once a category is picked. @endif</span>
                                    <x-ui.button type="button" size="sm" data-attributes-reread :disabled="empty($listing->primary_category_id)">Re-read the template</x-ui.button>
                                </div>
                            @endif

                        <x-ui.field label="Brand" for="lz-brand" name="brand_id" :required="true" wide
                                    hint="Start typing and pick a match, or say there is no brand. Lazada rejects a name it does not know.">
                            <div data-brand-picker data-brand-url="{{ route('ext.lazada.brands.autocomplete') }}"
                                 data-must-pick="Pick a brand from the list, or press No brand."
                                 data-must-pick-value="[data-brand-id]"
                                 data-must-pick-unless="[data-brand-none-flag]">
                                <input type="hidden" name="brand_id" data-brand-id value="{{ $brandId }}">
                                <input type="hidden" name="no_brand" data-brand-none-flag value="{{ $noBrand ? 1 : 0 }}">

                                <input id="lz-brand" class="x-input" list="lz-brand-list" autocomplete="off"
                                       data-brand-input value="{{ $brandName }}"
                                       placeholder="Type to search Lazada brands">
                                <datalist id="lz-brand-list" data-brand-list></datalist>

                                <div class="fm-hint">
                                    Matched brand ID <span class="x-num" data-brand-picked>{{ $brandId ?: 'None' }}</span>
                                    <x-ui.button type="button" size="sm" data-brand-none>No brand</x-ui.button>
                                    <a class="fm-cancel" href="{{ route('ext.lazada.brands.index') }}" target="_blank" rel="noopener">Browse the brand list</a>
                                </div>
                            </div>
                        </x-ui.field>
                    </div>

                    @if(!empty($productRow?->manufacturer_name))
                        <p class="fm-section__note">
                            This product's manufacturer is <strong>{{ $productRow->manufacturer_name }}</strong>.
                            @if(!empty($brandSuggestion['brand_id']))
                                The closest Lazada brand is <strong>{{ $brandSuggestion['brand_name'] }}</strong> ({{ $brandSuggestion['brand_id'] }}).
                            @else
                                No Lazada brand matches that name. Fetch the brand list and try again, or use No brand.
                            @endif
                        </p>
                    @endif
                </section>

                @if($mode === 'edit')
                    <section class="fm-section"
                             id="lz-attributes"
                             data-attributes-fetch
                             data-attributes-url="{{ route('ext.lazada.products.attributes_fetch', $listing->product_id) }}"
                             data-attributes-field="primary_category_id"
                             data-attributes-source="#lazada-listing [data-combo-value]"
                             data-attributes-reread-button="[data-attributes-reread]"
                             data-attributes-read-at="[data-template-read-at]"
                             data-attributes-empty="Pick a Lazada category above and its attributes appear here."
                             @if(!$template && $listing->primary_category_id) data-attributes-autoload @endif>
                        <div class="fm-section__head">
                            <h2 class="fm-section__title">Category attributes</h2>
                        </div>
                        <div data-attributes-target>
                            @if(empty($listing->primary_category_id))
                                <p class="fm-section__note">Pick a Lazada category above and its attributes appear here.</p>
                            @elseif(!$template)
                                <p class="fm-section__note">Reading this category's attributes from Lazada.</p>
                            @else
                                @include('ext-lazada::products._attributes', [
                                    'template' => $template,
                                    'attributes' => $attributes,
                                    'saved' => $saved,
                                    'suggested' => $suggested,
                                    'groupAttrs' => $groupAttrs,
                                    'productOwnAttrs' => $productOwnAttrs,
                                    'erpSourceFields' => $erpSourceFields,
                                ])
                            @endif
                        </div>
                    </section>

                    <script type="application/json" data-erp-fields>{!! json_encode($erpFieldValues, $jsonFlags) !!}</script>
                @endif

                @if($mode === 'edit')
                    @include('partials.channel-images-card', [
                        'anchor' => 'lz-images', 'storeName' => 'Lazada', 'tiles' => $tiles, 'catalogTiles' => $catalogTiles,
                        'fetchesOverWeb' => false,
                        'editable' => true, 'canManage' => true, 'browseUrl' => $browseUrl,
                    ])

                    @include('partials.channel-video-card', [
                        'anchor' => 'lz-video', 'storeName' => 'Lazada',
                        'productId' => (int) $listing->product_id,
                        'uploadUrl' => route('products.video.upload'),
                        'canManage' => true,
                    ] + \App\Integrations\Listings\ListingVideo::cardData((int) $listing->product_id, $listing ?? null))

                    @include('partials.listing-variations-card', [
                        'title' => 'Variations on Lazada', 'storeName' => 'Lazada', 'canManage' => true,
                    ])

                    @include('partials.listing-parcel-card', [
                        'id' => 'lz', 'canManage' => true, 'listing' => $listing, 'catalog' => $productRow,
                    ])

                    <section class="fm-section" id="lz-only">
                        <div class="fm-section__head">
                            <h2 class="fm-section__title">Only on Lazada</h2>
                        </div>
                        <div class="fm-sub">
                            <h3 class="fm-sub__title">Variation prices</h3>
                            @if(($variants ?? collect())->count() === 0)
                                <p class="fm-section__note">This product has no variations, so Lazada gets it as a single SKU. Nothing to map.</p>
                            @else
                                <div class="fm-vars__scroll">
                                    <table class="fm-vars__table">
                                        <thead>
                                            <tr>
                                                <th scope="col" class="fm-vcol-name">Variation</th>
                                                <th scope="col" class="fm-vcol-sku">Catalog SKU</th>
                                                <th scope="col" class="fm-vcol-price fm-vcol-num">Catalog price</th>
                                                <th scope="col" class="fm-vcol-qty fm-vcol-num">Catalog stock</th>
                                                <th scope="col" class="fm-vcol-price fm-vcol-num">Lazada price</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            @foreach($variants as $variant)
                                                @php
                                                    $variantId = (string) $variant->product_option_value_id;
                                                    $mapped = $variantMap[$variantId] ?? null;
                                                    $variantLabel = trim((string) ($variant->option_name ?? '')) !== ''
                                                        ? ((string) $variant->option_name) . ': ' . ((string) ($variant->option_value_name ?? ''))
                                                        : 'Variation ' . $variantId;
                                                @endphp
                                                <tr>
                                                    <td class="fm-vcol-name" data-label="Variation">{{ $variantLabel }}</td>
                                                    <td class="fm-vcol-sku" data-label="Catalog SKU"><span class="x-num">{{ $variant->sku ?: 'None' }}</span></td>
                                                    <td class="fm-vcol-price fm-num" data-label="Catalog price">{{ number_format((float) ($variant->absolute_price ?? 0), 2) }}</td>
                                                    <td class="fm-vcol-qty fm-num" data-label="Catalog stock">{{ $variant->quantity }}</td>
                                                    <td class="fm-vcol-price fm-vcol-num" data-label="Lazada price">
                                                        <input class="x-input fm-num" name="variants[{{ $variantId }}][price]"
                                                               type="number" step="0.01" min="0"
                                                               value="{{ old('variants.' . $variantId . '.price', $mapped?->price) }}"
                                                               placeholder="Same"
                                                               aria-label="Lazada price for {{ $variantLabel }}">
                                                    </td>
                                                </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                </div>
                            @endif
                        </div>
                    </section>
                @endif
            </x-slot:main>

            <x-slot:rail>
                @include('partials.listing-price-card', [
                    'id' => 'lz', 'storeName' => 'Lazada', 'canManage' => true,
                    'markupPercent' => $effectivePercent, 'markupFixed' => $effectiveFixed,
                    'price' => $listing->price, 'corePrice' => (float) $basePrice,
                    'pushPrice' => $pushPrice, 'hasVariations' => $hasVariations,
                ])
            </x-slot:rail>
        </x-channel.listing-body>
    </form>


    @if($mode === 'edit')
        <div id="lazada-group-progress" class="modal-backdrop" role="dialog" aria-modal="true" aria-live="polite">
            <div class="modal co-modal co-modal--sm">
                <div class="cc-progress">
                    <span class="co-spinner co-spinner--lg" aria-hidden="true"></span>
                    <div>
                        <div class="cc-progress__title" data-progress-title>Talking to Lazada</div>
                        <div class="cc-progress__note">Please keep this tab open.</div>
                    </div>
                </div>
            </div>
        </div>
    @endif

    <x-ui.form-bar :cancel="\App\Support\BackTo::safe(request('back'), route('ext.lazada.products.index'))">
        <x-slot:note>{{ $mode === 'create'
            ? 'Nothing reaches Lazada until you push from the edit screen.'
            : 'Save listing keeps everything on this page. Save and push keeps it and sends the listing to Lazada.' }}</x-slot:note>
        @if($mode === 'edit')
            <x-ui.button type="submit" form="lazada-listing" name="push" value="1">{{ empty($listing->lazada_item_id) ? 'Save and push to Lazada' : 'Save and push the update' }}</x-ui.button>
        @endif
        <x-slot:primary>
            <x-ui.button type="submit" form="lazada-listing" variant="primary">{{ $mode === 'create' ? 'Create listing' : 'Save listing' }}</x-ui.button>
        </x-slot:primary>
    </x-ui.form-bar>
</div>
@include('partials.channel-description-pick-modal', ['storeUrl' => route('ext.lazada.description-templates.store', ['store' => $storeId])])

@include('partials.image-library-pick', ['browseUrl' => $browseUrl])
@if(!empty($catalogChange))
    @include('partials.catalog-change-panel', [
        'listing' => $listing,
        'storeLabel' => \App\Support\StoreLabel::of('Lazada', \Extensions\lazada\Models\LazadaSetting::query()->find($listing->lazada_setting_id)?->store_name),
        'saveUrl' => route('ext.lazada.products.catalog_change_save', $listing->product_id),
        'ignoreUrl' => route('ext.lazada.products.catalog_change_ignore', $listing->product_id),
        'back' => request()->boolean('compare') ? \App\Support\BackTo::safe(request('back'), request()->getRequestUri()) : request()->getRequestUri(),
        'canManage' => (bool) (auth()->user()?->hasPermission('manage_lazada/product') ?? false),
    ])
@endif

@endsection
