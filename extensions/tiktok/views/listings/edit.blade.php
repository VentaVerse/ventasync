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
    $pct = $listing?->markup_percent;
    $fixed = $listing?->markup_fixed;
    $hasRule = $pct !== null || $fixed !== null;
    $nextPrice = round($corePrice + ($corePrice * (float) ($pct ?? 0) / 100) + (float) ($fixed ?? 0), 2);
    $missing = $readinessMissing;
    $coreDescription = trim(strip_tags(html_entity_decode((string) ($product->description ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
@endphp

<div class="fm-page">

    <div class="fm-head">
        <div class="fm-head__main">
            <a class="fm-back" href="{{ route('ext.tiktok.products.index') }}">
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

    <x-channel.listing-strip channel="TikTok Shop" :state="$stripState" :figures="$stripFigures"
                             :lastPush="$stripLastPush" :linked="(bool) $listed">
        @if($canManage)
            <x-slot:verbs>
                @if($listed)
                    @if(!$liveError && empty($missing))
                        <form method="POST" action="{{ route('ext.tiktok.listings.push_update', $product->product_id) }}"
                              data-guard-stale data-confirm="Push this listing's content and settings onto the live TikTok Shop product? Title, description, package, category, brand and attributes are updated. Price and stock are not touched." data-confirm-tone="primary" data-confirm-verb="Push update">
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

        <x-slot:notes>
            @if($liveError)
                <div class="fm-note fm-note--fail">
                    <div class="fm-note__body">@include('partials.channel-answer', ['channel' => 'TikTok Shop', 'raw' => $liveError, 'settingsRoute' => 'ext.tiktok.index']) Nothing below is stale data standing in for it: the live state is simply unknown until TikTok Shop answers.</div>
                </div>
            @endif
            @if(!empty($missing))
                <div class="fm-note">
                    <div class="fm-note__body">{{ $listed ? 'Push update needs' : 'Before it can go to TikTok Shop, this listing still needs' }} {{ implode(', ', $missing) }} before it can run. Set what is missing and save.</div>
                </div>
            @endif
            @if($listed && !empty($coverage['missing']))
                <div class="fm-note">
                    <div class="fm-note__body">In the catalogue but not on TikTok Shop: {{ implode(', ', $coverage['missing']) }}. Stock and price pushes skip these and say so. Push update carries every catalogue variation, missing ones included.</div>
                </div>
            @endif
        </x-slot:notes>
    </x-channel.listing-strip>

    {{-- The one template (the owner, 2026-09-08, draft C): main is what the
         listing SAYS, rail is what it COSTS. Kept from draft B:
         Content, Category and brand, Category attributes,
         Discount, Images; the rail only reports, Product group and Last
         push. TikTok Shop has nothing only it has. --}}
    <form id="listing-form" method="POST" action="{{ route('ext.tiktok.listings.update', $product->product_id) }}">
        <input type="hidden" name="back" value="{{ \App\Support\BackTo::safe(request('back'), '') }}">
        @csrf
        @method('PUT')
        <x-channel.listing-body>
            <x-slot:main>

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
                        <x-ui.field label="Description" for="tl-description" name="description" wide>
                            <x-ui.textarea id="tl-description" name="description" rows="6" :disabled="!$canManage">{{ old('description', \App\Integrations\Listings\ListingContent::editable($listing->description ?? null, (string) $coreDescription)) }}</x-ui.textarea>
                        </x-ui.field>
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
                            <div class="gf-combo" data-category-combo data-combo-mode="remote"
                                 data-combo-search-url="{{ route('ext.tiktok.products.searchCategories') }}"
                                 data-combo-lookup-url="{{ route('ext.tiktok.products.categoryLookup') }}">
                                <div class="gf-combo__field">
                                    <input type="hidden" id="tl-category-value" name="tiktok_category_id" data-combo-value value="{{ old('tiktok_category_id', $listing->tiktok_category_id ?? '') }}">
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

                @php
                    $discount = [
                        'channel' => 'TikTok Shop', 'prefix' => 'tt',
                        'canManage' => $canManage, 'onMarketplace' => $listed,
                        'step' => '1', 'note' => 'Whole percents only; TikTok cuts the live price by this percent while the window is open.',
                        'savedPct' => $listing?->sale_percent,
                        'savedFrom' => $listing?->sale_starts_at, 'savedTo' => $listing?->sale_ends_at,
                        'salePreview' => null,
                        'remoteId' => $listing?->tiktok_activity_id ?: null,
                        'tracked' => true, 'endClearsRule' => false,
                        'liveError' => $listed ? $liveError : null,
                        'action' => route('ext.tiktok.listings.push_discount', $product->product_id),
                    ];
                @endphp

                @if($listed && $live && count($live['skus']) > 0)
                    {{-- What only TikTok has: the variations it holds right
                         now. A channel supplies its own sections; the strip
                         is the shape they share. --}}
                    <section class="fm-section">
                        <div class="fm-section__head">
                            <h2 class="fm-section__title">Variations on TikTok Shop</h2>
                        </div>
                <x-ui.table caption="This product's variations as TikTok Shop holds them right now">
                    <thead>
                        <tr>
                            <th>Variation</th>
                            <th>SKU</th>
                            <th class="x-num">Price</th>
                            <th class="x-num">Stock</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($live['skus'] as $sku)
                            <tr>
                                <td>{{ $sku['name'] !== '' ? $sku['name'] : 'SKU ' . $sku['id'] }}</td>
                                <td>{{ $sku['seller_sku'] !== '' ? $sku['seller_sku'] : 'none' }}</td>
                                <td class="x-num"><x-money :php="$sku['price']" /></td>
                                <td class="x-num">{{ $sku['stock'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </x-ui.table>
                    </section>
                @endif

                @include('partials.channel-images-card', [
                    'storeName' => 'TikTok Shop', 'images' => $images,
                    'limit' => \Extensions\tiktok\Services\TikTok\TikTokProductPush::MAX_IMAGES, 'fetchesOverWeb' => false,
                ])
            </x-slot:main>

            <x-slot:rail>
                <section class="fm-section">
                    <div class="fm-section__head">
                        <h2 class="fm-section__title">Price rule</h2>
                        <x-ui.hint label="About the price rule">The TikTok Shop price is never stored: it is the catalog price run through this rule at every push and price sync. Without a rule of its own the listing uses its group's.</x-ui.hint>
                    </div>
                        <div class="fm-fields fm-fields--2">
                            <x-ui.field label="Percent added" for="tl-markup-pct" name="markup_percent">
                                <x-ui.input id="tl-markup-pct" name="markup_percent" type="number" step="0.01" :value="old('markup_percent', $listing->markup_percent ?? '')" placeholder="0" :disabled="!$canManage" />
                            </x-ui.field>
                            <x-ui.field label="Fixed amount added" for="tl-markup-fixed" name="markup_fixed">
                                <x-ui.input id="tl-markup-fixed" name="markup_fixed" type="number" step="0.01" :value="old('markup_fixed', $listing->markup_fixed ?? '')" placeholder="0" :disabled="!$canManage" />
                            </x-ui.field>
                        </div>
                        <div class="fm-stats">
                            <div class="fm-stat"><span class="fm-stat__k">Catalog price</span><span class="fm-stat__v"><x-money :php="$corePrice" /></span></div>
                            <div class="fm-stat"><span class="fm-stat__k">Price on TikTok Shop after push</span><span class="fm-stat__v"><x-money :php="$hasRule ? $nextPrice : $corePrice" /></span></div>
                        </div>
                </section>

                @include('partials.channel-discount-card', $discount)

                @include('partials.channel-group-card', [
                    'storeName' => 'TikTok Shop', 'group' => $groupCard,
                    'groupsUrl' => route('ext.tiktok.product-groups.index'),
                ])

                {{-- When and where from are figures on the strip now
                     (the owner, 2026-09-08); what the push SENT would live here,
                     and TikTok does not record it yet. --}}
            </x-slot:rail>
        </x-channel.listing-body>
    </form>

    @include('partials.channel-discount-forms', $discount)

    <div class="fm-bar">
        <span class="fm-bar__meta">{{ !$canManage
            ? 'You can read this. Editing needs the TikTok manage permission.'
            : ($listed
                ? 'Saving changes this record only. Push update to TikTok Shop sends it onto the live product.'
                : 'Saving changes this record only. TikTok Shop sees it when the product is pushed.') }}</span>
        <div class="fm-bar__actions">
            <a class="fm-cancel" href="{{ \App\Support\BackTo::safe(request('back'), route('ext.tiktok.products.index')) }}">{{ $canManage ? 'Cancel' : 'Back' }}</a>
            @if($canManage)
                @if(!$listed)
                    {{-- One trip for the common case (parity, 2026-09-06): save
                         what was just typed, then push. --}}
                    <x-ui.button type="submit" form="listing-form" name="push_after" value="1">Save and push to TikTok Shop</x-ui.button>
                @endif
                <x-ui.button type="submit" form="listing-form" variant="primary">Save listing</x-ui.button>
            @endif
        </div>
    </div>

</div>
@include('partials.channel-description-pick-modal', ['storeUrl' => route('ext.tiktok.description-templates.store', ['store' => $storeId])])

@endsection
