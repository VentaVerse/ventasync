@extends('layouts.channel')
@section('title', ($mode === 'create' ? 'Add' : 'Edit') . ' Lazada Listing')

{{-- This page shows its own validation summary (the fm-note below) and marks
     the offending fields inline, so partials/legacy-page-contract skips its
     bullet list of every message rather than stacking a second red box on
     top of this one. --}}
@section('own-errors', true)
@section('breadcrumb', $mode === 'create'
    ? 'New Listing'
    : (($productRow->name ?? null) ? html_entity_decode((string) $productRow->name, ENT_QUOTES | ENT_HTML5, 'UTF-8') : 'Listing ' . $listing->id))

{{-- Round F Task 1. The last and largest of the channel deep pages, rebuilt on
     the shared fm- form anatomy (components/form-page.css) plus the gf-
     attribute rows (pages/group-form.css), the fm-vars variant table and the
     cl- pieces in pages/channel-listing.css. All three of its inline <script>
     blocks are gone; the behaviour is in
     resources/js/pages/channel-listing-form.js and
     resources/js/pages/group-form.js.

     A real defect fixed on the way past. The catalog search on the create
     step built its results with `resultsDiv.innerHTML = items.map(...)` over a
     product name straight off the endpoint, and the chosen-product card did
     the same. A catalog product named `<img src=x onerror=...>` executed
     here. Both are the shared picker now, createElement and textContent
     throughout.

     Two forms live on this page since 2026-09-06 (the owner: "Everything should
     be in Save and Push"), each a separate operation with its own route. The
     wire of each is checked against LazadaProductController:

       store/update   product_id (create only), primary_category_id, brand_id,
                      no_brand, markup_fixed, markup_percent, the discount
                      rule, attributes[<key>] with the __map:<field> prefix
                      intact, and push=1 when the press is Save and push
       variants       variants[<id>][seller_sku|price|quantity]

     Picking a category asks products.attributes_fetch for that category's
     sheet and injects it into the listing form, so the answers submit with
     Save listing and Save and push alike. The two buttons sit in the page's
     one sticky bar and reach the form through the HTML5 `form` attribute. --}}
@section('content')
@php
    // Every route on this page, including both GET renders, is registered
    // inside perm:manage_lazada (extensions/lazada/routes/web.php), unlike the
    // product-group pages where the edit GET is read-tier. There is no read
    // branch here because there is no read tier to reach it.
    $groupMarkup = $listing->groups->first();

    $effectiveFixed = old('markup_fixed', $listing->markup_fixed ?? ($groupMarkup->markup_fixed ?? null));
    $effectivePercent = old('markup_percent', $listing->markup_percent ?? ($groupMarkup->markup_percent ?? null));

    $noBrand = old('no_brand');
    if ($noBrand === null) {
        $noBrand = (!empty($listing->brand_name_override)
            && strtolower(trim((string) $listing->brand_name_override)) === 'no brand') ? 1 : 0;
    }
    $noBrand = (int) $noBrand;

    $brandId = old('brand_id', $listing->brand_id);
    $brandName = $noBrand ? 'No Brand' : (old('brand_name') ?? ($selectedBrandName ?? ''));

    $productName = ($productRow->name ?? null)
        ? html_entity_decode((string) $productRow->name, ENT_QUOTES | ENT_HTML5, 'UTF-8')
        : '';
    $basePrice = isset($productRow->price) ? (float) $productRow->price : null;

    // The catalog's own description, as the Content card's placeholder: the
    // greyed value an empty field follows.
    $coreDescription = trim(strip_tags(html_entity_decode((string) ($productRow->description ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8')));

    // The values a mapped attribute can read off the catalog product. Data
    // only, no behaviour: the preview lives in
    // resources/js/pages/group-form.js. JSON_HEX_TAG encodes < and >, so a
    // description containing "</script>" cannot close this element.
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

    $linkedGroups = $listing->groups;
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
            <a class="fm-back" href="{{ route('ext.lazada.products.index') }}" data-guard-leave>
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
                @if($linkedGroups->count() > 0)
                    <span class="fm-stamp">
                        <span class="fm-stamp__k">Product group</span>
                        <span class="fm-stamp__v">{{ $linkedGroups->first()->name }}@if($linkedGroups->count() > 1) and {{ $linkedGroups->count() - 1 }} more @endif</span>
                    </span>
                @endif
            </div>
        </div>
    </div>

    @if($mode === 'edit' && !empty($productRow))
        {{-- Which catalog product this listing is for. Not a field: the
             answer to "what am I editing". --}}
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

    {{-- ── The strip: what Lazada says right now ──────────────────
         The template every channel's listing page uses (the owner, 2026-09-08,
         draft C): the live state, this channel's own figures, the last push
         and the verbs that act on the live item. What only Lazada has - the
         SKU table it holds, the variation overrides - stays in Lazada's own
         sections below. --}}
    @php
        $lzLinked = $mode === 'edit' && !empty($listing->lazada_item_id);
        $lzSkus = $live['skus'] ?? [];

        $stripFigures = [];
        if ($lzLinked && ($live ?? null) !== null) {
            /* One SKU: its price and stock are the item's, so they go on the
               strip the way Shopee's do. Several, and the SKU table below is
               the honest answer; the strip says how many. */
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
            // Only when it differs; clipped, so one long title cannot
            // stretch the bar.
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
        <x-channel.listing-strip channel="Lazada" :state="$stripState" :figures="$stripFigures"
                                 :lastPush="$stripLastPush" :linked="$lzLinked">
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

            <x-slot:notes>
                @if(($liveError ?? null) !== null)
                    <div class="fm-note fm-note--fail">
                        <div class="fm-note__body">@include('partials.channel-answer', ['channel' => 'Lazada', 'raw' => $liveError, 'settingsRoute' => 'ext.lazada.index']) Nothing below is stale data standing in for it: the live state is simply unknown until Lazada answers.</div>
                    </div>
                @endif
                @if($lzLinked && !empty($coverage['missing']))
                    <div class="fm-note">
                        <div class="fm-note__body">In the catalogue but not on Lazada: {{ implode(', ', $coverage['missing']) }}. Stock and price pushes skip these and say so. Save and push the update carries every catalogue variation, missing ones included.</div>
                    </div>
                @endif
                @if($lzPushedAt && $lzCoreMoved && $lzCoreMoved->gt($lzPushedAt))
                    <div class="fm-note fm-note--warn" role="status">
                        <div class="fm-note__body">The catalog product changed {{ $lzCoreMoved->diffInSeconds() < 60 ? 'just now' : $lzCoreMoved->diffForHumans() }} - after the last push {{ $lzPushedAt->diffForHumans() }}. Fields without their own wording follow the catalog automatically; Save and push sends the current state to Lazada.</div>
                    </div>
                @endif
            </x-slot:notes>
        </x-channel.listing-strip>
    @endif

    {{-- ============================================================
         1. The listing itself.
         ============================================================ --}}
    <form id="lazada-listing" method="POST"
          action="{{ route('ext.lazada.products.update', $listing->product_id) }}"
          data-slow-action="push" data-guard-unsaved>
        @csrf
        @method('PUT')
        {{-- Where the operator came from (the Listings page with its search),
             for Save and push and Cancel to return to. --}}
        <input type="hidden" name="back" value="{{ \App\Support\BackTo::safe(request('back'), '') }}">

        {{-- The one template every channel's listing page uses (the owner,
             2026-09-08, draft C): main is what the listing SAYS - Content,
             Category and brand, Category attributes, Images, then what only
             Lazada has - and rail is what it COSTS. The variation overrides
             ride this form too, so one Save keeps the whole page. --}}
        <x-channel.listing-body>
            <x-slot:main>

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
                        <h2 class="fm-section__title">Content on this channel <x-ui.hint label="About the content">A filled field is this listing's own wording and follows nobody. An empty field follows the catalog product, shown greyed as the placeholder. Clearing a field returns it to the catalog. Nothing here ever edits the catalog product itself.</x-ui.hint></h2>
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
                        <x-ui.field label="Description" for="lz-description" name="description" wide>
                            <x-ui.textarea id="lz-description" name="description" rows="6" maxlength="20000">{{ old('description', \App\Integrations\Listings\ListingContent::editable($listing->description ?? null, (string) $coreDescription)) }}</x-ui.textarea>
                        </x-ui.field>
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
                        <x-ui.field label="Lazada category" for="lz-category" name="primary_category_id" :required="true" wide
                                    hint="{{ $mode === 'create' ? 'Pick the category; its attributes are filled in on the edit screen.' : 'Pick the category and its attributes appear below.' }}">
                            {{-- The shared searchable combobox instead of a
                                 5,000-option select (the owner, 2026-08-24).
                                 Local mode: the page already carries the
                                 cached category list. --}}
                            @php
                                $lzSelectedCategoryId = (string) old('primary_category_id', $listing->primary_category_id);
                                $lzSelectedCategory = $lzSelectedCategoryId !== ''
                                    ? ($categories ?? collect())->firstWhere('category_id', (int) $lzSelectedCategoryId)
                                    : null;
                                $lzCategoryOptions = ($categories ?? collect())->map(fn ($c) => [
                                    'id'   => (string) $c->category_id,
                                    'name' => (string) $c->name,
                                ])->values();
                            @endphp
                            <div class="gf-combo" data-category-combo data-combo-mode="local">
                                <div class="gf-combo__field">
                                    <input type="hidden" name="primary_category_id" data-combo-value
                                           value="{{ $lzSelectedCategoryId }}">
                                    <x-ui.input id="lz-category" type="search" autocomplete="off"
                                                data-combo-input
                                                value="{{ $lzSelectedCategory
                                                    ? $lzSelectedCategory->name . ' (' . $lzSelectedCategory->category_id . ')'
                                                    : '' }}"
                                                placeholder="Type to search Lazada categories" />
                                    <div class="gf-combo__results" data-combo-results role="listbox"
                                         aria-label="Lazada categories" hidden></div>
                                </div>
                                <script type="application/json" data-combo-options>{!! json_encode($lzCategoryOptions, $jsonFlags) !!}</script>
                            </div>
                            @if($mode === 'edit')
                                {{-- The template read lives beside the category it
                                     belongs to (the owner, 2026-09-06: it had drifted to
                                     the bottom of the page in the rebuild). Picking a
                                     category reads its template by itself; this button
                                     is for a category Lazada has since changed. --}}
                                <div class="fm-hint">
                                    <span data-template-read-at>@if($template && $template->fetched_at) Attributes read from Lazada {{ $template->fetched_at->format('Y-m-d H:i') }}. @elseif($listing->primary_category_id) Attributes not read from Lazada yet. @else Attributes appear once a category is picked. @endif</span>
                                    <x-ui.button type="button" size="sm" data-attributes-reread :disabled="empty($listing->primary_category_id)">Re-read the template</x-ui.button>
                                </div>
                            @endif
                        </x-ui.field>

                        <x-ui.field label="Brand" for="lz-brand" name="brand_id" :required="true" wide
                                    hint="Start typing and pick a match, or say there is no brand. Lazada rejects a name it does not know.">
                            <div data-brand-picker data-brand-url="{{ route('ext.lazada.brands.autocomplete') }}">
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
                    {{-- The category attributes, INSIDE the listing form since
                         2026-09-06: one Save and push carries the category, the
                         brand, the price rule and every answer below. Picking a
                         category replaces this sheet through
                         LazadaProductController::fetchAttributesAjax(), which
                         answers with products/_attributes, the same partial the
                         page renders here. --}}
                    <section class="fm-section"
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

                    {{-- Data only, no behaviour. Read by the mapped-attribute
                         preview in resources/js/pages/group-form.js. --}}
                    <script type="application/json" data-erp-fields>{!! json_encode($erpFieldValues, $jsonFlags) !!}</script>
                @endif

                @if($mode === 'edit')
                    @include('partials.channel-images-card', [
                        'anchor' => 'lz-images', 'storeName' => 'Lazada', 'tiles' => $tiles, 'catalogTiles' => $catalogTiles,
                        'limit' => \Extensions\lazada\Services\Lazada\LazadaImages::MAX_IMAGES, 'fetchesOverWeb' => false,
                        'editable' => true, 'canManage' => true, 'browseUrl' => $browseUrl,
                    ])

                    {{-- What only Lazada has, in one place at the end of the
                         main column: the per-variation overrides. They ride
                         this form since 2026-09-07, so Save keeps them with
                         everything else rather than needing a second press. --}}
                    <section class="fm-section" id="lz-only">
                        <div class="fm-section__head">
                            <h2 class="fm-section__title">Only on Lazada</h2>
                        </div>
                        <div class="fm-sub">
                            <h3 class="fm-sub__title">Variation overrides <x-ui.hint label="About the overrides">Lazada gets the catalog's own SKU, price and stock for each variation unless one is overridden here. Anything left empty stays as the catalog has it.</x-ui.hint></h3>
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
                                                <th scope="col" class="fm-vcol-sku">Lazada SKU</th>
                                                <th scope="col" class="fm-vcol-price fm-vcol-num">Lazada price</th>
                                                <th scope="col" class="fm-vcol-qty fm-vcol-num">Lazada stock</th>
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
                                                    <td class="fm-vcol-sku" data-label="Lazada SKU">
                                                        <input class="x-input" name="variants[{{ $variantId }}][seller_sku]"
                                                               value="{{ old('variants.' . $variantId . '.seller_sku', $mapped?->seller_sku) }}"
                                                               placeholder="Same as catalog"
                                                               aria-label="Lazada SKU for {{ $variantLabel }}">
                                                    </td>
                                                    <td class="fm-vcol-price fm-vcol-num" data-label="Lazada price">
                                                        <input class="x-input fm-num" name="variants[{{ $variantId }}][price]"
                                                               value="{{ old('variants.' . $variantId . '.price', $mapped?->price) }}"
                                                               placeholder="Same"
                                                               aria-label="Lazada price for {{ $variantLabel }}">
                                                    </td>
                                                    <td class="fm-vcol-qty fm-vcol-num" data-label="Lazada stock">
                                                        <input class="x-input fm-num" name="variants[{{ $variantId }}][quantity]"
                                                               value="{{ old('variants.' . $variantId . '.quantity', $mapped?->quantity) }}"
                                                               placeholder="Same"
                                                               aria-label="Lazada stock for {{ $variantLabel }}">
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
                <section class="fm-section">
                    <div class="fm-section__head">
                        <h2 class="fm-section__title">Price rule</h2>
                        <x-ui.hint label="About the price rule">The percentage is added to the catalog price first, then the fixed amount on top. The Lazada price is never stored: it is the catalog price run through this rule at every push and price sync. Without a rule of its own the listing uses its group's.</x-ui.hint>
                    </div>
                        <div class="fm-fields fm-fields--2">
                            <x-ui.field label="Percent added" for="lz-markup-percent" name="markup_percent">
                                <div class="cs-inline">
                                    <x-ui.input id="lz-markup-percent" name="markup_percent" type="number" step="0.01" min="0"
                                                class="fm-input--num cs-days" data-markup-percent
                                                value="{{ $effectivePercent }}" placeholder="0" />
                                    <span class="cs-inline__unit">%</span>
                                </div>
                            </x-ui.field>

                            <x-ui.field label="Fixed amount added" for="lz-markup-fixed" name="markup_fixed">
                                <x-ui.input id="lz-markup-fixed" name="markup_fixed" type="number" step="0.01" min="0"
                                            class="fm-input--num" data-markup-fixed
                                            value="{{ $effectiveFixed }}" placeholder="0.00" />
                            </x-ui.field>
                        </div>

                        @if($basePrice !== null)
                            {{-- The one figure the two fields above raise a
                                 question about. The currency is resolved by
                                 App\Support\Money here in Blade; the script
                                 only replaces the number, because nothing in
                                 JavaScript can read the default currency off
                                 the currencies table. --}}
                            <div class="cl-total" data-running-total data-base-price="{{ $basePrice }}">
                                <span class="cl-total__k">Price on Lazada after push</span>
                                <span class="cl-total__v" data-total-value><x-money :php="(float) $basePrice" /></span>
                                <span class="cl-total__note">Catalog price <x-money :php="(float) $basePrice" /></span>
                            </div>
                        @endif

                        @if($groupMarkup)
                            @php
                                $inherited = \App\Support\MarkupWords::of(
                                    $groupMarkup->markup_percent !== null ? (float) $groupMarkup->markup_percent : null,
                                    $groupMarkup->markup_fixed !== null ? (float) $groupMarkup->markup_fixed : null
                                );
                            @endphp
                            @if($inherited !== 'none')
                                <p class="fm-section__note">These started from the {{ $groupMarkup->name }} group ({{ $inherited }}). Changing them here overrides the group for this listing only.</p>
                            @endif
                        @endif
                </section>

                @if($mode === 'edit')
                    @include('partials.channel-group-card', [
                        'storeName' => 'Lazada', 'group' => $groupCard,
                        'groupsUrl' => route('ext.lazada.product-groups.index'),
                    ])

                @endif
            </x-slot:rail>
        </x-channel.listing-body>
    </form>


    @if($mode === 'edit')
        {{-- A push is a multi-second round trip, so the page says so rather
             than letting the operator click again. --}}
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

    {{-- The page's actions, both on the listing form through the HTML5
         `form` attribute. Save listing keeps everything on the page. Save and
         push keeps it and sends the listing to Lazada in the same press, as a
         create or, once the listing is linked, as an update that carries the
         content, the price rule, the attribute answers and every variation
         (the owner, 2026-09-06: "Everything should be in Save and Push"). --}}
    <div class="fm-bar">
        <span class="fm-bar__meta">{{ $mode === 'create'
            ? 'Nothing reaches Lazada until you push from the edit screen.'
            : 'Save listing keeps everything on this page. Save and push keeps it and sends the listing to Lazada.' }}</span>
        <div class="fm-bar__actions">
            <a class="fm-cancel" href="{{ \App\Support\BackTo::safe(request('back'), route('ext.lazada.products.index')) }}" data-guard-leave>Cancel</a>
            <x-ui.button type="submit" form="lazada-listing" :variant="$mode === 'edit' ? 'secondary' : 'primary'">{{ $mode === 'create' ? 'Create listing' : 'Save listing' }}</x-ui.button>
            @if($mode === 'edit')
                <x-ui.button type="submit" form="lazada-listing" name="push" value="1" variant="primary">{{ empty($listing->lazada_item_id) ? 'Save and push to Lazada' : 'Save and push the update' }}</x-ui.button>
            @endif
        </div>
    </div>
</div>
@include('partials.channel-description-pick-modal', ['storeUrl' => route('ext.lazada.description-templates.store', ['store' => $storeId])])

@include('partials.image-library-pick', ['browseUrl' => $browseUrl])

@endsection
