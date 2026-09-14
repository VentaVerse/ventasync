@extends('layouts.channel')
@section('title', 'TikTok Listing')
@section('breadcrumb', ($product->name ?? '') !== '' ? $product->name : 'Listing')

{{-- The individual TikTok listing page (#156), on the same anatomy as
     Shopee's: the LIVE state at the top, fetched from TikTok Shop on this
     load; the record beneath it (category, brand, attribute answers, price
     rule, content overrides, discount), which the next push uses; and
     provenance. Saving never writes the catalog product. --}}
{{-- This page shows its own "Nothing was saved" note and marks the
     offending fields, so the legacy bullet list stands down (critique
     2026-09-08: one event was drawing two red boxes). --}}
@section('own-errors', true)

@section('content')
@php
    $canManage = auth()->user()?->hasPermission('manage_tiktok/listing') ?? false;
    $canEditCatalog = auth()->user()?->hasPermission('manage_catalog/product') ?? false;
    $listed = (bool) $truth;
    /* The engine's words (#184 D), same as every list this product sits
       in - the page's own STATUS_MAP copy is gone. */
    [$statusLabel, $statusTone] = ($live && ($listingState ?? null))
        ? [$listingState->label(), $listingState->tone()]
        : [null, null];

    $corePrice = (float) $product->price;
    $missing = $readinessMissing;
    $coreDescription = (string) ($product->description ?? '');
@endphp

<div class="fm-page">

    {{-- The one thing a refused save must not do is refuse in silence. The
         page marks the offending fields inline (x-ui.field draws each
         message under its own control); this is the summary that says a
         save was refused at all, and it is the surface the owner asked to keep
         when the rest of the notes came off (2026-09-09). --}}
    <div class="fm-flash" role="status" aria-live="polite">
        @if($errors->any())
            <div class="fm-note fm-note--fail">
                <div class="fm-note__body">Nothing was saved. Check the fields marked below.</div>
            </div>
        @endif
    </div>

    <div class="fm-head">
        <div class="fm-head__main">
            <a class="fm-back" href="{{ route('ext.tiktok.products.index') }}" data-guard-leave>
                <x-ui.icon name="chevron-left" size="14" /> Listings
            </a>
            <h1 class="fm-title">{{ ($product->name ?? '') !== '' ? $product->name : 'Product ' . $product->product_id }}</h1>
            <div class="fm-stamps">
                <span class="fm-stamp"><span class="fm-stamp__k">Channel</span><span class="fm-stamp__v">TikTok Shop</span></span>
                <span class="fm-stamp"><span class="fm-stamp__k">SKU</span><span class="fm-stamp__v">{{ ($product->sku ?? '') !== '' ? $product->sku : 'none' }}</span></span>
                @if($listed)
                    <span class="fm-stamp"><span class="fm-stamp__k">TikTok product ID</span><span class="fm-stamp__v">{{ $truth->tiktok_product_id }}</span></span>
                @endif
            </div>
        </div>
    </div>

    {{-- ── The strip: what TikTok Shop says right now ─────────────
         The one template (the owner, 2026-09-08, draft C). What only TikTok has
         - the variations it holds - stays in its own section below. --}}
    @php
        $stripFigures = [];
        if ($listed && $live) {
            $stripFigures[] = ['k' => 'Stock on TikTok Shop', 'v' => (string) $live['stock']];
            if (($coverage['total'] ?? 0) > 0) {
                $stripFigures[] = ['k' => 'On TikTok Shop', 'v' => $coverage['linked'] . ' of ' . $coverage['total'] . ' variations'];
            }
            /* Only when it DIFFERS: a title that matches what you have is
               not news, and the strip is where the news goes. Clipped, so
               one long title cannot stretch the bar. */
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

    {{-- HEALTH OFF (the owner, 2026-09-09): "What I want to see right
         now are just fields to edit. Nothing about health." The state
         word, the live figures, Last push and the accent are gone, and the
         bar keeps only the verbs - what you can DO here, which is not a
         report about the item. The notes went with them.

         What survives is the required-field handling: the validation
         summary above, the browser's own bubble on the required fields,
         and the inline field errors.

         Nothing was deleted from the system: the engine still computes
         every one of these facts and the Listings index still shows them.
         This page takes them back by passing health again. --}}
    <x-channel.listing-strip channel="TikTok Shop" :health="false" :linked="(bool) $listed">
        @if($canManage)
            <x-slot:verbs>
                @if($listed)
                    @if(!$liveError && empty($missing))
                        <form method="POST" action="{{ route('ext.tiktok.listings.push_update', $product->product_id) }}"
                              data-guard-stale data-confirm="Push this listing's content and settings onto the live TikTok Shop product? Title, description, package, category, brand, attributes, price and stock are updated." data-confirm-tone="primary" data-confirm-verb="Push update">
                            @csrf
                            <x-ui.button type="submit" variant="oncolor" size="sm">Push update to TikTok Shop</x-ui.button>
                        </form>
                    @endif
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
                @elseif(empty($missing))
                    <form method="POST" action="{{ route('ext.tiktok.products.push_direct', $product->product_id) }}"
                          data-guard-stale data-confirm="Push this product to TikTok Shop now? It goes up with the settings and content saved on this page." data-confirm-tone="primary" data-confirm-verb="Push to TikTok Shop">
                        @csrf
                        <input type="hidden" name="back" value="{{ \App\Support\BackTo::safe(request('back'), '') }}">
                        <x-ui.button type="submit" variant="oncolor" size="sm">Push to TikTok Shop</x-ui.button>
                    </form>
                @endif
            </x-slot:verbs>
        @endif

    </x-channel.listing-strip>

    {{-- Main is what the listing says, led by its product group, the
         listing's template; the rail is what it costs. --}}
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
                    <div class="fm-section__head"><h2 class="fm-section__title">Content on this channel <x-ui.hint label="About the content">A filled field is this listing's own wording and follows nobody. An empty field follows the catalog product, shown greyed as the placeholder. Clearing a field returns it to the catalog. Nothing here ever edits the catalog product itself.</x-ui.hint></h2></div>
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
                        <x-ui.field label="TikTok category" for="tl-category" name="tiktok_category_id" :required="true" wide
                                    hint="Only leaf categories are listed. TikTok Shop accepts nothing else.">
                            <div class="gf-combo" data-category-combo data-combo-required="Pick a TikTok category from the list." data-combo-mode="remote"
                                 data-combo-search-url="{{ route('ext.tiktok.products.searchCategories') }}"
                                 data-combo-lookup-url="{{ route('ext.tiktok.products.categoryLookup') }}">
                                <div class="gf-combo__field">
                                    <input type="hidden" id="tl-category-value" name="tiktok_category_id" data-combo-value value="{{ old('tiktok_category_id', $shown->tiktok_category_id ?? '') }}">
                                    <x-ui.input id="tl-category" type="search" autocomplete="off" data-combo-input
                                                value="{{ $category ? $category->name . ' (' . $category->id . ')' : '' }}"
                                                placeholder="Type to search TikTok categories" :disabled="!$canManage" />
                                    <div class="gf-combo__results" data-combo-results role="listbox" aria-label="TikTok leaf categories" hidden></div>
                                </div>
                            </div>
                        </x-ui.field>
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

                {{-- The category's attribute sheet (#156): what TikTok Shop asks
                     for this category, answered here for this listing. Reloaded
                     when the category changes, same wire as the group form. --}}
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
                    'limit' => \Extensions\tiktok\Services\TikTok\TikTokProductPush::MAX_IMAGES, 'fetchesOverWeb' => false,
                    'editable' => true, 'canManage' => $canManage, 'browseUrl' => $browseUrl,
                ])

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

                {{-- When and where from are figures on the strip now
                     (the owner, 2026-09-08); what the push SENT would live here,
                     and TikTok does not record it yet. --}}
            </x-slot:rail>
        </x-channel.listing-body>
    </form>

    <div class="fm-bar">
        <span class="fm-bar__meta">{{ !$canManage
            ? 'You can read this. Editing needs the TikTok manage permission.'
            : ($listed
                ? 'Saving changes this record only. Push update to TikTok Shop sends it onto the live product.'
                : 'Saving changes this record only. TikTok Shop sees it when the product is pushed.') }}</span>
        <div class="fm-bar__actions">
            <a class="fm-cancel" href="{{ \App\Support\BackTo::safe(request('back'), route('ext.tiktok.products.index')) }}" data-guard-leave>{{ $canManage ? 'Cancel' : 'Back' }}</a>
            @if($canManage)
                @if(!$listed)
                    {{-- One trip for the common case (parity, 2026-09-06): save
                         what was just typed, then push. --}}
                    <x-ui.button type="submit" form="listing-form" name="push_after" value="1">Save and push to TikTok Shop</x-ui.button>
                @endif
                {{-- Required means required on BOTH presses (the owner,
                     2026-09-09: "Anything that is required = null should not
                     save as well"). Save carried formnovalidate for half a
                     day; a field the page marks with an asterisk and then
                     saves without teaches that the asterisk means nothing. --}}
                <x-ui.button type="submit" form="listing-form" variant="primary">Save listing</x-ui.button>
            @endif
        </div>
    </div>

</div>
@include('partials.channel-description-pick-modal', ['storeUrl' => route('ext.tiktok.description-templates.store', ['store' => $storeId])])

@if($canManage)
    @include('partials.image-library-pick', ['browseUrl' => $browseUrl])
@endif

@endsection
