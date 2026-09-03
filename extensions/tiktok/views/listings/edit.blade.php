@extends('layouts.channel')
@section('title', 'TikTok Listing')
@section('breadcrumb', ($product->name ?? '') !== '' ? $product->name : 'Listing')

{{-- The individual TikTok listing page (#156), on the same anatomy as
     Shopee's: the LIVE state at the top, fetched from TikTok Shop on this
     load; the record beneath it (category, brand, attribute answers, price
     rule, content overrides, discount), which the next push uses; and
     provenance. Saving never writes the catalog product. --}}
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

    {{-- ── The live half ─────────────────────────────────────── --}}
    @if($listed)
        <section class="fm-section">
            <div class="fm-section__head">
                <h2 class="fm-section__title">On TikTok Shop right now</h2>
                @if($canManage)
                    <div class="fm-section__aside">
                        @if(!$liveError && empty($missing))
                        <form method="POST" action="{{ route('ext.tiktok.listings.push_update', $product->product_id) }}"
                              data-confirm="Push this listing's content and settings onto the live TikTok Shop product? Title, description, package, category, brand and attributes are updated. Price and stock are not touched." data-confirm-tone="primary" data-confirm-verb="Push update">
                            @csrf
                            <x-ui.button type="submit">Push update to TikTok Shop</x-ui.button>
                        </form>
                        @endif
                        @if($live && $live['status'] === 'ACTIVATE')
                            <form method="POST" action="{{ route('ext.tiktok.listings.toggle', $product->product_id) }}"
                                  data-confirm="Deactivate this product on TikTok Shop? Buyers stop seeing it. It can be activated again at any time." data-confirm-verb="Deactivate">
                                @csrf
                                <input type="hidden" name="action" value="deactivate">
                                <x-ui.button type="submit">Deactivate on TikTok Shop</x-ui.button>
                            </form>
                        @elseif($live && $live['status'] === 'SELLER_DEACTIVATED')
                            <form method="POST" action="{{ route('ext.tiktok.listings.toggle', $product->product_id) }}"
                                  data-confirm="Activate this product on TikTok Shop? Buyers see it again once TikTok applies it." data-confirm-tone="primary" data-confirm-verb="Activate">
                                @csrf
                                <input type="hidden" name="action" value="activate">
                                <x-ui.button type="submit" variant="primary">Activate on TikTok Shop</x-ui.button>
                            </form>
                        @endif
                    </div>
                @endif
            </div>

            @if($liveError)
                <div class="fm-note fm-note--fail">
                    <div class="fm-note__body">@include('partials.channel-answer', ['channel' => 'TikTok Shop', 'raw' => $liveError, 'settingsRoute' => 'ext.tiktok.index']) Nothing below is stale data standing in for it: the live state is simply unknown until TikTok Shop answers.</div>
                </div>
            @endif
            @if(!empty($missing))
                <div class="fm-note">
                    <div class="fm-note__body">Push update needs {{ implode(', ', $missing) }} before it can run. Set what is missing and save.</div>
                </div>
            @endif
            @if($live)
                <p class="fm-section__note">Fetched from TikTok Shop as this page loaded.</p>
                <div class="fm-stats">
                    <div class="fm-stat">
                        <span class="fm-stat__k">Status</span>
                        <span class="fm-stat__v"><x-ui.badge :tone="$statusTone">{{ $statusLabel }}</x-ui.badge></span>
                    </div>
                    {{-- Variation coverage (Phase F, #184): Push update
                         carries every catalogue variation, so it IS the
                         add-variation verb - said here at last. --}}
                    @if(($coverage['total'] ?? 0) > 0)
                        <div class="fm-stat">
                            <span class="fm-stat__k">On TikTok Shop</span>
                            <span class="fm-stat__v">
                                {{ $coverage['linked'] }} of {{ $coverage['total'] }} variations
                                @if(!empty($coverage['missing']))
                                    <x-ui.hint label="What is not on TikTok Shop">In the catalogue but not on TikTok Shop: {{ implode(', ', $coverage['missing']) }}. Stock and price pushes skip these and say so. Press Push update - it carries every catalogue variation, missing ones included.</x-ui.hint>
                                @endif
                            </span>
                        </div>
                    @endif
                    <div class="fm-stat">
                        <span class="fm-stat__k">Stock on TikTok Shop</span>
                        <span class="fm-stat__v">{{ $live['stock'] }}</span>
                    </div>
                    @if($live['title'] !== '')
                        <div class="fm-stat">
                            <span class="fm-stat__k">Title on TikTok Shop</span>
                            <span class="fm-stat__v">{{ $live['title'] }}</span>
                        </div>
                    @endif
                </div>
                @if(count($live['skus']) > 0)
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
                @endif
            @endif
            @if($truth->last_pushed_at ?? null)
                <p class="fm-section__note">Pushed {{ \Illuminate\Support\Carbon::parse($truth->last_pushed_at)->diffForHumans() }}{{ ($truth->group_name ?? null) ? ' from the ' . $truth->group_name . ' product group' : ' from this listing' }}.</p>
            @endif
        </section>
    @else
        <section class="fm-section">
            <div class="fm-section__head">
                <h2 class="fm-section__title">Not on TikTok Shop yet</h2>
                @if($canManage && empty($missing))
                    <div class="fm-section__aside">
                        <form method="POST" action="{{ route('ext.tiktok.products.push_direct', $product->product_id) }}"
                              data-confirm="Push this product to TikTok Shop now? It goes up with the settings and content saved on this page." data-confirm-tone="primary" data-confirm-verb="Push to TikTok Shop">
                            @csrf
                            <x-ui.button type="submit" variant="primary">Push to TikTok Shop</x-ui.button>
                        </form>
                    </div>
                @endif
            </div>
            @if(empty($missing))
                <p class="fm-section__note">Everything the push needs is set. The one-click push uses the settings and content below.</p>
            @else
                <p class="fm-section__note">Before the one-click push can run, this listing still needs {{ implode(', ', $missing) }}. Set what is missing below and save, or push it from one of its product groups.</p>
            @endif
        </section>
    @endif

    <form id="listing-form" method="POST" action="{{ route('ext.tiktok.listings.update', $product->product_id) }}">
        @csrf
        @method('PUT')
        <div class="fm-body">
            <div class="fm-col">

                <section class="fm-section">
                    <div class="fm-section__head"><h2 class="fm-section__title">Content on this channel</h2></div>
                    <p class="fm-section__note">A filled field is this listing's own wording and follows nobody. An empty field follows the catalog product, shown greyed as the placeholder. Clearing a field returns it to the catalog. Nothing here ever edits the catalog product itself.</p>
                    <div class="fm-fields">
                        <x-ui.field label="Title" for="tl-title" name="title" wide>
                            <x-ui.input id="tl-title" name="title" :value="old('title', $listing->title ?? '')" :placeholder="$product->name" maxlength="255" :disabled="!$canManage" />
                        </x-ui.field>
                        <x-ui.field label="Description" for="tl-description" name="description" wide>
                            <x-ui.textarea id="tl-description" name="description" rows="6" :placeholder="\Illuminate\Support\Str::limit($coreDescription, 300)" :disabled="!$canManage">{{ old('description', $listing->description ?? '') }}</x-ui.textarea>
                        </x-ui.field>
                    </div>
                </section>

                <div class="fm-card">
                    <div class="fm-card__head"><h2 class="fm-card__title">Price rule</h2></div>
                    <div class="fm-card__body">
                        <p class="fm-section__note">The TikTok Shop price is never stored: it is the catalog price run through this rule at every push and price sync.</p>
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
                    </div>
                </div>

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
                @include('partials.channel-discount-card', $discount)

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
                    <div class="fm-section__head"><h2 class="fm-section__title">Category attributes</h2></div>
                    <p class="fm-section__note">What TikTok Shop asks for this category. Answers are saved with the listing and go with every push; TikTok refuses a push without the required ones.</p>
                    <div data-attributes-target>
                        @include('ext-tiktok::product-groups._attributes', [
                            'rows' => $attrRows, 'saved' => $savedAttributes, 'template' => $attrTemplate, 'canManage' => $canManage,
                        ])
                    </div>
                </section>
            </div>

            <div class="fm-col fm-col--side">
                <div class="fm-card">
                    <div class="fm-card__head"><h2 class="fm-card__title">Category and brand</h2></div>
                    <div class="fm-card__body">
                        <x-ui.field label="TikTok category" for="tl-category" name="tiktok_category_id" :required="true"
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
                        <x-ui.field label="Brand" for="tl-brand" name="brand_id" hint="TikTok Shop's own brand list, searched by name. Leave it empty for no brand.">
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
                </div>

                <div class="fm-card">
                    <div class="fm-card__head"><h2 class="fm-card__title">Last push</h2></div>
                    <div class="fm-card__body">
                        @if($listing?->last_pushed_at)
                            <div class="fm-stats">
                                <div class="fm-stat"><span class="fm-stat__k">When</span><span class="fm-stat__v">{{ $listing->last_pushed_at->diffForHumans() }}</span></div>
                                <div class="fm-stat"><span class="fm-stat__k">From</span><span class="fm-stat__v">{{ str_starts_with((string) $listing->last_push_source, 'group:') ? 'the ' . substr($listing->last_push_source, 6) . ' product group' : 'this listing' }}</span></div>
                            </div>
                        @else
                            <p class="fm-section__note">Never pushed from VentaSync.</p>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    </form>

    @include('partials.channel-discount-forms', $discount)

    <div class="fm-bar">
        <span class="fm-bar__meta">{{ !$canManage
            ? 'You can read this. Editing needs the TikTok manage permission.'
            : ($listed
                ? 'Saving changes this record only. Push update to TikTok Shop sends it onto the live product.'
                : 'Saving changes this record only. TikTok Shop sees it when the product is pushed.') }}</span>
        <div class="fm-bar__actions">
            <a class="fm-cancel" href="{{ route('ext.tiktok.products.index') }}">{{ $canManage ? 'Cancel' : 'Back' }}</a>
            @if($canManage)
                <x-ui.button type="submit" form="listing-form" variant="primary">Save listing</x-ui.button>
            @endif
        </div>
    </div>

</div>
@endsection
