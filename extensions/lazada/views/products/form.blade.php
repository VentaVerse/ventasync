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

     A real defect fixed on the way past. The catalogue search on the create
     step built its results with `resultsDiv.innerHTML = items.map(...)` over a
     product name straight off the endpoint, and the chosen-product card did
     the same. A catalogue product named `<img src=x onerror=...>` executed
     here. Both are the shared picker now, createElement and textContent
     throughout.

     Six forms live on this page and each is a separate operation with its own
     route. The wire of every one is unchanged, checked against
     LazadaProductController:

       store/update   product_id (create only), primary_category_id, brand_id,
                      no_brand, markup_fixed, markup_percent
       attributes     attributes[<key>], with the __map:<field> prefix intact
       template sync  primary_category_id
       variants       variants[<id>][seller_sku|price|quantity]
       build payload  nothing but the token
       push sample    nothing but the token, behind the same confirmation

     The primary Save sits in the page's one sticky bar and reaches its form
     through the HTML5 `form` attribute, so a page carrying six forms still has
     exactly one place the main action lives. Every other form's action sits
     beside its own section heading, which is where its scope is obvious. --}}
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

    // The values a mapped attribute can read off the catalogue product. Data
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
            <a class="fm-back" href="{{ route('ext.lazada.products.index') }}">
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
                        <span class="fm-stamp__k">Catalogue product</span>
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
        {{-- Which catalogue product this listing is for. Not a field: the
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

    {{-- ============================================================
         1. The listing itself.
         ============================================================ --}}
    <form id="lazada-listing" method="POST"
          action="{{ $mode === 'create'
            ? route('ext.lazada.products.store')
            : route('ext.lazada.products.update', $listing->id) }}">
        @csrf
        @if($mode === 'edit')
            @method('PUT')
        @endif

        <div class="fm-body">
            <div class="fm-col fm-col--main">

                @if($mode === 'create')
                <section class="fm-section">
                    <div class="fm-section__head">
                        <h2 class="fm-section__title">Which product</h2>
                    </div>

                    <div class="fm-fields" data-catalogue-picker
                         data-search-url="{{ route('ext.lazada.products.search_catalog') }}">
                        <x-ui.field label="Catalogue product" for="lz-search" name="product_id" :required="true" wide
                                    hint="Type at least two characters, then pick one from the list. Every Lazada payload is built from this product.">
                            <div class="fm-ta">
                                <x-ui.input id="lz-search" type="search" autocomplete="off"
                                            data-unmatched-search
                                            placeholder="Search by name, model or SKU" />
                                <div class="fm-ta__list" data-unmatched-results role="listbox" aria-label="Catalogue matches"></div>
                            </div>
                            <input type="hidden" name="product_id" data-unmatched-id value="{{ old('product_id', $listing->product_id) }}">
                        </x-ui.field>
                    </div>
                </section>
                @endif

                <section class="fm-section">
                    <div class="fm-section__head">
                        <h2 class="fm-section__title">Lazada mapping</h2>
                    </div>

                    <div class="fm-fields">
                        <x-ui.field label="Lazada category" for="lz-category" name="primary_category_id" :required="true" wide
                                    hint="Set this and save, then read the category's attribute template below.">
                            <div class="x-select-wrap">
                                <select id="lz-category" name="primary_category_id" class="x-input">
                                    <option value="">Not set</option>
                                    @foreach(($categories ?? collect()) as $category)
                                        <option value="{{ (int) $category->category_id }}"
                                                @selected((string) old('primary_category_id', $listing->primary_category_id) === (string) $category->category_id)>{{ $category->name }} ({{ $category->category_id }})</option>
                                    @endforeach
                                </select>
                                <x-ui.icon name="chevron-down" size="14" class="x-select-wrap__chevron" />
                            </div>
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
            </div>

            <div class="fm-col fm-col--side">
                <div class="fm-card">
                    <div class="fm-card__head"><h2 class="fm-card__title">Price markup</h2></div>
                    <div class="fm-card__body">
                        <p class="fm-section__note">The percentage is taken off the catalogue price first, then the fixed amount is added on top.</p>

                        <x-ui.field label="Percentage of price" for="lz-markup-percent" name="markup_percent"
                                    hint="5 means the listed price is 5% above the catalogue price.">
                            <div class="cs-inline">
                                <x-ui.input id="lz-markup-percent" name="markup_percent" type="number" step="0.01" min="0"
                                            class="fm-input--num cs-days" data-markup-percent
                                            value="{{ $effectivePercent }}" placeholder="0" />
                                <span class="cs-inline__unit">%</span>
                            </div>
                        </x-ui.field>

                        <x-ui.field label="Fixed amount" for="lz-markup-fixed" name="markup_fixed"
                                    hint="Added after the percentage.">
                            <x-ui.input id="lz-markup-fixed" name="markup_fixed" type="number" step="0.01" min="0"
                                        class="fm-input--num" data-markup-fixed
                                        value="{{ $effectiveFixed }}" placeholder="0.00" />
                        </x-ui.field>

                        @if($basePrice !== null)
                            {{-- The one figure the two fields above raise a
                                 question about. The currency is resolved by
                                 App\Support\Money here in Blade; the script
                                 only replaces the number, because nothing in
                                 JavaScript can read the default currency off
                                 the currencies table. --}}
                            <div class="cl-total" data-running-total data-base-price="{{ $basePrice }}">
                                <span class="cl-total__k">Listed at</span>
                                <span class="cl-total__v"><span data-total-value>{{ number_format($basePrice, 2) }}</span></span>
                                <span class="cl-total__note">Catalogue price {{ number_format($basePrice, 2) }} in {{ \App\Support\Money::defaultCode() }}</span>
                            </div>
                        @endif

                        @if($groupMarkup)
                            @php
                                $inherited = [];
                                if (!empty($groupMarkup->markup_percent)) {
                                    $inherited[] = rtrim(rtrim(number_format((float) $groupMarkup->markup_percent, 2), '0'), '.') . '%';
                                }
                                if (!empty($groupMarkup->markup_fixed)) {
                                    $inherited[] = number_format((float) $groupMarkup->markup_fixed, 2);
                                }
                            @endphp
                            @if(!empty($inherited))
                                <p class="fm-section__note">These started from the {{ $groupMarkup->name }} group ({{ implode(' then ', $inherited) }}). Changing them here overrides the group for this listing only.</p>
                            @endif
                        @endif
                    </div>
                </div>

                @if($mode === 'create')
                <div class="fm-card">
                    <div class="fm-card__head"><h2 class="fm-card__title">What happens next</h2></div>
                    <div class="fm-card__body">
                        <p class="fm-section__note">Creating the listing saves it here only. Nothing reaches Lazada until you read the category template, fill its attributes and push a sample from the edit screen.</p>
                    </div>
                </div>
                @endif
            </div>
        </div>
    </form>

    @if($mode === 'edit')
        {{-- ============================================================
             2. Category attributes.
             ============================================================ --}}
        <section class="fm-section">
            <div class="fm-section__head">
                <h2 class="fm-section__title">Category attributes</h2>
                @if($template)
                    <div class="fm-section__aside">
                        <x-ui.button type="submit" form="lazada-attributes" size="sm">Save attributes</x-ui.button>
                    </div>
                @endif
            </div>

            @if(empty($listing->primary_category_id))
                <p class="fm-section__note">Set a Lazada category above and save the listing, then read the template below.</p>
            @elseif(!$template)
                <p class="fm-section__note">The category template has not been read from Lazada yet. Read it below and the attributes will appear here.</p>
            @else
                <p class="fm-section__note">Type a value, or point an attribute at a field on the catalogue product so every push reads the current one. Read from Lazada {{ $template->fetched_at ? $template->fetched_at->format('Y-m-d H:i') : 'at an unrecorded time' }}.</p>

                <form id="lazada-attributes" method="POST" action="{{ route('ext.lazada.products.attributes_save', $listing->id) }}">
                    @csrf

                    <div class="gf-attrs">
                        @foreach($attributes as $attribute)
                            @php
                                $key = (string) ($attribute['key'] ?? '');
                            @endphp
                            @continue(strtolower(trim($key)) === 'brand')
                            @php
                                $suggestion = $suggested[$key] ?? null;
                                $rawSaved = $saved[$key] ?? '';
                                $fromGroup = !array_key_exists($key, ($productOwnAttrs ?? []))
                                    && array_key_exists($key, ($groupAttrs ?? []));

                                $value = old('attributes.' . $key, $saved[$key] ?? ($suggestion ?? ''));
                                $options = $attribute['options'] ?? [];
                                $isSelect = is_array($options) && count($options) > 0;
                                $controlId = 'lz-attr-' . \Illuminate\Support\Str::slug($key, '-');

                                // A suggestion that is not one of the choices
                                // would be silently rejected by Lazada, so it
                                // is not pre-selected.
                                if ($isSelect && $suggestion !== null) {
                                    $suggestionIsAChoice = false;
                                    foreach ($options as $option) {
                                        $candidate = is_array($option)
                                            ? (string) ($option['name'] ?? $option['label'] ?? $option['value'] ?? '')
                                            : (string) $option;
                                        if ((string) $suggestion === $candidate) { $suggestionIsAChoice = true; break; }
                                    }
                                    if (!$suggestionIsAChoice && !isset($saved[$key]) && !old('attributes.' . $key)) {
                                        $value = '';
                                    }
                                }

                                $isMapped = str_starts_with((string) $rawSaved, '__map:');
                                $mappedField = $isMapped ? substr((string) $rawSaved, 6) : '';
                                $typedValue = $isMapped ? '' : $value;
                                $resolvedRaw = ($isMapped && isset($suggested[$key])) ? $suggested[$key] : null;
                                $resolvedValue = $resolvedRaw !== null
                                    ? mb_substr(strip_tags(html_entity_decode((string) $resolvedRaw, ENT_QUOTES | ENT_HTML5, 'UTF-8')), 0, 200)
                                    : '';
                            @endphp
                            <div class="gf-attr">
                                <div class="gf-attr__label">
                                    <label class="gf-attr__name" for="{{ $controlId }}">
                                        {{ $attribute['name'] }}
                                        @if(!empty($attribute['required']))<span class="gf-attr__req">Required</span>@endif
                                    </label>
                                    <span class="gf-attr__key">{{ $key }}</span>
                                </div>

                                <div class="gf-attr__control">
                                    @if($isSelect)
                                        <div class="x-select-wrap">
                                            <select id="{{ $controlId }}" name="attributes[{{ $key }}]" class="x-input">
                                                <option value="">Not set</option>
                                                @foreach($options as $option)
                                                    @php
                                                        $optionValue = is_array($option)
                                                            ? (string) ($option['name'] ?? $option['label'] ?? $option['value'] ?? '')
                                                            : (string) $option;
                                                    @endphp
                                                    <option value="{{ $optionValue }}" @selected((string) $value === $optionValue)>{{ $optionValue }}</option>
                                                @endforeach
                                            </select>
                                            <x-ui.icon name="chevron-down" size="14" class="x-select-wrap__chevron" />
                                        </div>
                                    @else
                                        <div class="gf-attr__mode" data-attr-row>
                                            <div class="x-select-wrap">
                                                <select class="x-input" data-attr-mode aria-label="How {{ $attribute['name'] }} is filled in">
                                                    <option value="manual" @selected(!$isMapped)>Type a value</option>
                                                    <option value="product_field" @selected($isMapped)>Read it off the product</option>
                                                </select>
                                                <x-ui.icon name="chevron-down" size="14" class="x-select-wrap__chevron" />
                                            </div>

                                            <div class="gf-attr__value">
                                                <input id="{{ $controlId }}" class="x-input" data-attr-manual data-attr-manual-box
                                                       value="{{ $typedValue }}" placeholder="Not set"
                                                       aria-label="{{ $attribute['name'] }}"
                                                       @if($isMapped) hidden @endif>

                                                <div class="x-select-wrap" data-attr-field-box @unless($isMapped) hidden @endunless>
                                                    <select class="x-input" data-attr-field aria-label="Which product field {{ $attribute['name'] }} is read from">
                                                        <option value="">Pick a product field</option>
                                                        @foreach($erpSourceFields as $fieldKey => $fieldLabel)
                                                            <option value="{{ $fieldKey }}" @selected($isMapped && $mappedField === $fieldKey)>{{ $fieldLabel }}</option>
                                                        @endforeach
                                                    </select>
                                                    <x-ui.icon name="chevron-down" size="14" class="x-select-wrap__chevron" />
                                                </div>
                                            </div>

                                            {{-- The only control in this row that submits. --}}
                                            <input type="hidden" name="attributes[{{ $key }}]" data-attr-value value="{{ $isMapped ? $rawSaved : $value }}">

                                            <div class="gf-attr__resolved" data-attr-resolved @unless($isMapped) hidden @endunless>
                                                Currently reads <strong data-attr-resolved-text>{{ $resolvedValue }}</strong>
                                            </div>
                                        </div>
                                    @endif

                                    @if($fromGroup)
                                        <p class="gf-attr__note">Inherited from the product group. Saving here overrides it for this listing.</p>
                                    @endif

                                    @if(!$isSelect && !$isMapped && empty($saved[$key] ?? null) && empty(old('attributes.' . $key)) && !empty($suggestion))
                                        <p class="gf-attr__note">The catalogue suggests {{ $suggestion }}.</p>
                                    @endif

                                    @if($errors->has('attributes.' . $key))
                                        <div class="gf-attr__error">{{ $errors->first('attributes.' . $key) }}</div>
                                    @endif
                                </div>
                            </div>
                        @endforeach
                    </div>
                </form>

                {{-- Data only, no behaviour. Read by the mapped-attribute
                     preview in resources/js/pages/group-form.js. --}}
                <script type="application/json" data-erp-fields>{!! json_encode($erpFieldValues, $jsonFlags) !!}</script>
            @endif
        </section>

        {{-- ============================================================
             3. The category template.
             ============================================================ --}}
        <section class="fm-section">
            <div class="fm-section__head">
                <h2 class="fm-section__title">{{ $template ? 'Re-read the category template' : 'Read the category template' }}</h2>
                <div class="fm-section__aside">
                    <form method="POST" action="{{ route('ext.lazada.products.template_sync', $listing->id) }}" data-slow-action>
                        @csrf
                        <input type="hidden" name="primary_category_id" value="{{ (int) ($listing->primary_category_id ?? 0) }}">
                        <x-ui.button type="submit" size="sm" :disabled="empty($listing->primary_category_id)">{{ $template ? 'Re-read it' : 'Read it' }}</x-ui.button>
                    </form>
                </div>
            </div>
            <p class="fm-section__note">
                @if($template)
                    Only worth doing when Lazada has changed what this category asks for. If the attributes above are filling in correctly, leave it alone. Last read {{ $template->fetched_at ? $template->fetched_at->format('Y-m-d H:i') : 'at an unrecorded time' }}.
                @else
                    This asks Lazada which attributes this category needs, so they can be filled in above.
                @endif
            </p>
        </section>

        {{-- ============================================================
             4. Variations.
             ============================================================ --}}
        <section class="fm-section">
            <div class="fm-section__head">
                <h2 class="fm-section__title">Variations</h2>
                @if(($variants ?? collect())->count() > 0)
                    <div class="fm-section__aside">
                        <x-ui.button type="submit" form="lazada-variants" size="sm">Save variations</x-ui.button>
                    </div>
                @endif
            </div>

            @if(($variants ?? collect())->count() === 0)
                <p class="fm-section__note">This product has no variations, so Lazada gets it as a single SKU. Nothing to map.</p>
            @else
                <p class="fm-section__note">Lazada gets the catalogue's own values unless you override one here. Anything left empty stays as the catalogue has it.</p>

                <form id="lazada-variants" method="POST" action="{{ route('ext.lazada.products.variants_save', $listing->id) }}">
                    @csrf
                    <div class="fm-vars__scroll">
                        <table class="fm-vars__table">
                            <thead>
                                <tr>
                                    <th scope="col" class="fm-vcol-name">Variation</th>
                                    <th scope="col" class="fm-vcol-sku">Catalogue SKU</th>
                                    <th scope="col" class="fm-vcol-price fm-vcol-num">Catalogue price</th>
                                    <th scope="col" class="fm-vcol-qty fm-vcol-num">Catalogue stock</th>
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
                                        <td class="fm-vcol-sku" data-label="Catalogue SKU"><span class="x-num">{{ $variant->sku ?: 'None' }}</span></td>
                                        <td class="fm-vcol-price fm-num" data-label="Catalogue price">{{ number_format((float) ($variant->absolute_price ?? 0), 2) }}</td>
                                        <td class="fm-vcol-qty fm-num" data-label="Catalogue stock">{{ $variant->quantity }}</td>
                                        <td class="fm-vcol-sku" data-label="Lazada SKU">
                                            <input class="x-input" name="variants[{{ $variantId }}][seller_sku]"
                                                   value="{{ old('variants.' . $variantId . '.seller_sku', $mapped?->seller_sku) }}"
                                                   placeholder="Same as catalogue"
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
                </form>
            @endif
        </section>

        {{-- ============================================================
             5. The payload.
             ============================================================ --}}
        <section class="fm-section">
            <div class="fm-section__head">
                <h2 class="fm-section__title">The payload</h2>
                <div class="fm-section__aside">
                    <form method="POST" action="{{ route('ext.lazada.products.payload_build', $listing->id) }}">
                        @csrf
                        <x-ui.button type="submit" size="sm">Build it</x-ui.button>
                    </form>
                    <form method="POST" action="{{ route('ext.lazada.products.push_sample', $listing->id) }}" data-slow-action
                          data-confirm="Send this to Lazada now as a sample push? It reaches the marketplace and may come back with quality-check errors.">
                        @csrf
                        <x-ui.button type="submit" size="sm">Send a sample push</x-ui.button>
                    </form>
                </div>
            </div>
            <p class="fm-section__note">Building it changes nothing at Lazada. It assembles exactly what would be sent, from this listing's attributes, the variation mapping and the catalogue's weight and dimensions, so you can read it before anything leaves.</p>

            @if(!empty($payloadPreview))
                <details class="od-disclose" open>
                    <summary>What would be sent</summary>
                    <pre class="cl-json">{{ $payloadPreview }}</pre>
                </details>
            @endif

            @if(!empty($pushRequest))
                <details class="od-disclose">
                    <summary>What was sent last time</summary>
                    <pre class="cl-json">{{ $pushRequest }}</pre>
                </details>
            @endif

            @if(!empty($pushResponse))
                <details class="od-disclose" open>
                    <summary>What Lazada said last time</summary>
                    <pre class="cl-json">{{ $pushResponse }}</pre>
                </details>
            @endif

            @if(empty($payloadPreview) && empty($pushRequest) && empty($pushResponse))
                <p class="fm-section__note">Nothing built yet.</p>
            @endif
        </section>

        {{-- Sending a sample to Lazada is a multi-second round trip, so the
             page says so rather than letting the operator click again. --}}
        <div id="lazada-group-progress" class="modal-backdrop">
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

    {{-- The page's one primary action. It reaches the listing form through the
         HTML5 `form` attribute, which is the same mechanism the group pages'
         add-a-product checkboxes use, so a page carrying six forms still has
         exactly one sticky bar and one obvious Save. --}}
    <div class="fm-bar">
        <span class="fm-bar__meta">{{ $mode === 'create'
            ? 'Nothing reaches Lazada until you push from the edit screen.'
            : 'Saves the category, brand and markup. The sections above each save on their own.' }}</span>
        <div class="fm-bar__actions">
            <a class="fm-cancel" href="{{ route('ext.lazada.products.index') }}">Cancel</a>
            <x-ui.button type="submit" form="lazada-listing" variant="primary">{{ $mode === 'create' ? 'Create listing' : 'Save listing' }}</x-ui.button>
        </div>
    </div>
</div>
@endsection
