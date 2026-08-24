@extends('layouts.channel')
@section('title', 'Shopee Listing')
@section('breadcrumb', $product->name !== '' ? $product->name : 'Listing')

{{-- The individual listing page (quest 1, 2026-08-24). One catalog
     product on Shopee, presented the way the seller center presents it:

       1. the LIVE state at the top, fetched from Shopee on this page load,
          with the same Active control the seller center has;
       2. the listing record beneath it: the settings the next push will
          use and the content overrides, editable;
       3. provenance: what the last push actually sent, and from where.

     Built entirely on the shared fm- anatomy (blotter-components.css) and
     the gf- checkbox rows; no new CSS. Three forms live here - toggle,
     push, save - and each is its own route; the primary Save reaches its
     form through the HTML5 form attribute from the sticky bar. --}}
@section('content')
@php
    $canManage = auth()->user()?->hasPermission('manage_shopee/product') ?? false;

    // Shopee's status vocabulary -> the seller center's words.
    $statusMap = [
        'NORMAL' => ['Live', 'success'],
        'UNLIST' => ['Unlisted', 'neutral'],
        'BANNED' => ['Violation', 'danger'],
        'REVIEWING' => ['Under Shopee review', 'warning'],
        'SELLER_DELETE' => ['Deleted by seller', 'neutral'],
        'SHOPEE_DELETE' => ['Deleted by Shopee', 'danger'],
    ];
    [$statusLabel, $statusTone] = $live
        ? ($statusMap[$live['item_status']] ?? [$live['item_status'], 'neutral'])
        : [null, null];

    $corePrice = (float) $product->price;
    $pct = $listing?->markup_percent;
    $fixed = $listing?->markup_fixed;
    $hasRule = $pct !== null || $fixed !== null;
    $nextPrice = round($corePrice + ($corePrice * (float) ($pct ?? 0) / 100) + (float) ($fixed ?? 0), 2);

    $selectedCategoryId = (int) old('shopee_category_id', $listing->shopee_category_id ?? 0);
    $oldLogisticIds = old('logistic_ids');
    $savedLogisticIds = array_map('intval', $listing->logistic_ids ?? []);

    $coreDescription = trim(strip_tags(html_entity_decode((string) ($product->description ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8')));

    /* "Core has changed" (#100). Two different situations, told apart:
       - fields WITHOUT their own wording follow the catalog automatically,
         so a catalog edit after the last push only means the LIVE listing
         is behind: the staleness note below points at Push update.
       - fields WITH their own wording never move on their own. When the
         catalog now differs under one, the field says so inline; clearing
         it is the accept gesture, keeping it is a decision, and nothing
         ever overwrites the operator's wording unasked. */
    $coreChangedAt = ($product->date_modified ?? null)
        ? \Illuminate\Support\Carbon::parse($product->date_modified)
        : null;
    $staleSincePush = $link && ($listing->last_pushed_at ?? null) && $coreChangedAt
        && $coreChangedAt->gt($listing->last_pushed_at);

    $catalogNow = function ($override, $core) {
        $override = $override !== null ? trim((string) $override) : '';
        $core = trim((string) $core);
        if ($override === '' || $core === '' || $override === $core) {
            return null;
        }
        return \Illuminate\Support\Str::limit($core, 120);
    };
    $nameDiverges = $catalogNow($listing->item_name ?? null, $product->name);
    $descriptionDiverges = $catalogNow($listing->description ?? null, $coreDescription);
    $weightDiverges = ($listing->weight ?? null) !== null && (float) $listing->weight !== (float) $product->weight
        ? (string) (float) $product->weight : null;

    // Readiness: what the one-click push still needs before it can run.
    // Computed by the shared ShopeeListingReadiness, the same answer the
    // listings index and the bulk push read (#101).
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
            <a class="fm-back" href="{{ route('ext.shopee.products.index') }}">
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
                        <span class="fm-stamp__v">{{ $link->shopee_item_id }}</span>
                    </span>
                @endif
            </div>
        </div>
    </div>

    {{-- ── The live half: what Shopee says right now ─────────────── --}}
    @if($link)
        <section class="fm-section">
            <div class="fm-section__head">
                <h2 class="fm-section__title">On Shopee right now</h2>
                @if($canManage)
                    <div class="fm-section__aside">
                        {{-- The other half of Save: the record drives the
                             NEXT push, and this sends it onto the LIVE item
                             now. Offered even when the live fetch failed -
                             the push reports its own outcome. --}}
                        <form method="POST" action="{{ route('ext.shopee.listings.push_update', $product->product_id) }}"
                              data-confirm="Push this listing's content and settings onto the live Shopee item? Title, description, parcel, category and couriers are updated. Price and stock are not touched - they have their own syncs.">
                            @csrf
                            <x-ui.button type="submit">Push update to Shopee</x-ui.button>
                        </form>
                        @if($live && $live['item_status'] === 'NORMAL')
                            <form method="POST" action="{{ route('ext.shopee.listings.toggle', $product->product_id) }}"
                                  data-confirm="Unlist this item from Shopee? Buyers stop seeing it immediately. It stays here and can be relisted at any time.">
                                @csrf
                                <input type="hidden" name="action" value="unlist">
                                <x-ui.button type="submit">Unlist from Shopee</x-ui.button>
                            </form>
                        @elseif($live && $live['item_status'] === 'UNLIST')
                            <form method="POST" action="{{ route('ext.shopee.listings.toggle', $product->product_id) }}"
                                  data-confirm="Relist this item on Shopee? Buyers see it again immediately.">
                                @csrf
                                <input type="hidden" name="action" value="list">
                                <x-ui.button type="submit" variant="primary">Relist on Shopee</x-ui.button>
                            </form>
                        @endif
                    </div>
                @endif
            </div>

            @if($liveError)
                <div class="fm-note fm-note--fail">
                    <div class="fm-note__body">{{ $liveError }} Nothing below is stale data standing in for it: the live state is simply unknown until Shopee answers.</div>
                </div>
            @elseif($live)
                <p class="fm-section__note">Fetched from Shopee as this page loaded.</p>
                @if($staleSincePush)
                    <div class="fm-note fm-note--warn" role="status">
                        <div class="fm-note__body">The catalog product changed {{ $coreChangedAt->diffForHumans() }} - after the last push {{ $listing->last_pushed_at->diffForHumans() }}. Fields without their own wording follow the catalog automatically; Push update to Shopee sends the current state to the live listing.</div>
                    </div>
                @endif
                <div class="fm-stats">
                    <div class="fm-stat">
                        <span class="fm-stat__k">Status</span>
                        <span class="fm-stat__v"><x-ui.badge :tone="$statusTone">{{ $statusLabel }}</x-ui.badge></span>
                    </div>
                    @if(!$live['has_model'])
                        <div class="fm-stat">
                            <span class="fm-stat__k">Price on Shopee</span>
                            <span class="fm-stat__v">
                                @if($live['price']['current'] !== null && $live['price']['original'] !== null && $live['price']['current'] < $live['price']['original'])
                                    <x-money :php="$live['price']['current']" /> <s><x-money :php="$live['price']['original']" /></s>
                                @elseif($live['price']['original'] !== null)
                                    <x-money :php="$live['price']['original']" />
                                @else
                                    unknown
                                @endif
                            </span>
                        </div>
                    @endif
                    <div class="fm-stat">
                        <span class="fm-stat__k">Stock on Shopee</span>
                        <span class="fm-stat__v">{{ $live['stock'] !== null ? $live['stock'] : 'unknown' }}</span>
                    </div>
                    <div class="fm-stat">
                        <span class="fm-stat__k">Promotion</span>
                        <span class="fm-stat__v">{{ $live['has_promotion'] ? 'Running' : 'None' }}</span>
                    </div>
                    @if($live['item_name'] !== '' && $live['item_name'] !== $product->name)
                        <div class="fm-stat">
                            <span class="fm-stat__k">Title on Shopee</span>
                            <span class="fm-stat__v">{{ $live['item_name'] }}</span>
                        </div>
                    @endif
                </div>

                @if(!empty($live['models']))
                    <x-ui.table caption="This item's variations as Shopee holds them right now">
                        <thead>
                            <tr>
                                <th>Variation</th>
                                <th>SKU</th>
                                <th class="x-num">Price</th>
                                <th class="x-num">Stock</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($live['models'] as $m)
                                <tr>
                                    <td>{{ $m['name'] !== '' ? $m['name'] : 'Model ' . $m['model_id'] }}</td>
                                    <td>{{ $m['model_sku'] !== '' ? $m['model_sku'] : 'none' }}</td>
                                    <td class="x-num">
                                        @if($m['price']['original'] !== null)
                                            <x-money :php="$m['price']['current'] ?? $m['price']['original']" />
                                        @else
                                            unknown
                                        @endif
                                    </td>
                                    <td class="x-num">{{ $m['stock'] !== null ? $m['stock'] : 'unknown' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </x-ui.table>
                @endif
            @endif
        </section>
    @else
        {{-- ── Not on Shopee yet: readiness instead of live state ──── --}}
        <section class="fm-section">
            <div class="fm-section__head">
                <h2 class="fm-section__title">Not on Shopee yet</h2>
                @if($canManage && empty($missing))
                    <div class="fm-section__aside">
                        <form method="POST" action="{{ route('ext.shopee.products.push_direct', $product->product_id) }}"
                              data-confirm="Push this product to Shopee now? It goes up with the settings and content saved on this page.">
                            @csrf
                            <x-ui.button type="submit" variant="primary">Push to Shopee</x-ui.button>
                        </form>
                    </div>
                @endif
            </div>
            @if(empty($missing))
                <p class="fm-section__note">Everything the push needs is set. The one-click push uses the settings and content below.</p>
            @else
                <p class="fm-section__note">Before the one-click push can run, this listing still needs {{ implode(', ', $missing) }}. Set what is missing below and save, or use the <a href="{{ route('ext.shopee.products.push', $product->product_id) }}">push form</a>, which walks through all of it including images.</p>
            @endif
        </section>
    @endif

    <form id="listing-form" method="POST" action="{{ route('ext.shopee.listings.update', $product->product_id) }}">
        @csrf
        <div class="fm-body">
            <div class="fm-col">

                <section class="fm-section">
                    <div class="fm-section__head">
                        <h2 class="fm-section__title">Content on this channel</h2>
                    </div>
                    <p class="fm-section__note">A filled field is this listing's own wording and follows nobody. An empty field follows the catalog product, shown greyed as the placeholder. Clearing a field returns it to the catalog. Nothing here ever edits the catalog product itself.</p>
                    <div class="fm-fields">
                        <x-ui.field label="Title" for="sl-name" name="item_name" wide>
                            <x-ui.input id="sl-name" name="item_name" maxlength="255"
                                        :value="old('item_name', $listing->item_name ?? '')"
                                        placeholder="{{ $product->name }}"
                                        :disabled="!$canManage" />
                            @if($nameDiverges)
                                <div class="fm-hint">The catalog now says "{{ $nameDiverges }}". Clearing this field follows it again; keeping your wording is fine too.</div>
                            @endif
                        </x-ui.field>
                        <x-ui.field label="Description" for="sl-description" name="description" wide>
                            <x-ui.textarea id="sl-description" name="description" rows="6" maxlength="5000"
                                           placeholder="{{ \Illuminate\Support\Str::limit($coreDescription, 400) }}"
                                           :disabled="!$canManage">{{ old('description', $listing->description ?? '') }}</x-ui.textarea>
                            @if($descriptionDiverges)
                                <div class="fm-hint">The catalog's description now differs from this listing's own. Clearing this field follows the catalog again; keeping your wording is fine too.</div>
                            @endif
                        </x-ui.field>
                    </div>
                </section>

                <section class="fm-section">
                    <div class="fm-section__head">
                        <h2 class="fm-section__title">Parcel</h2>
                    </div>
                    <p class="fm-section__note">What Shopee quotes shipping from. Empty fields follow the catalog's figures.</p>
                    <div class="fm-fields">
                        <x-ui.field label="Weight in kg" for="sl-weight" name="weight">
                            <x-ui.input id="sl-weight" name="weight" type="number" step="0.01" min="0.01"
                                        :value="old('weight', $listing->weight ?? '')"
                                        placeholder="{{ (float) $product->weight }}"
                                        :disabled="!$canManage" />
                            @if($weightDiverges)
                                <div class="fm-hint">The catalog now says {{ $weightDiverges }} kg. Clearing this field follows it again.</div>
                            @endif
                        </x-ui.field>
                        <x-ui.field label="Length in cm" for="sl-length" name="package_length">
                            <x-ui.input id="sl-length" name="package_length" type="number" min="1"
                                        :value="old('package_length', $listing->package_length ?? '')"
                                        placeholder="{{ (int) $product->length }}"
                                        :disabled="!$canManage" />
                        </x-ui.field>
                        <x-ui.field label="Width in cm" for="sl-width" name="package_width">
                            <x-ui.input id="sl-width" name="package_width" type="number" min="1"
                                        :value="old('package_width', $listing->package_width ?? '')"
                                        placeholder="{{ (int) $product->width }}"
                                        :disabled="!$canManage" />
                        </x-ui.field>
                        <x-ui.field label="Height in cm" for="sl-height" name="package_height">
                            <x-ui.input id="sl-height" name="package_height" type="number" min="1"
                                        :value="old('package_height', $listing->package_height ?? '')"
                                        placeholder="{{ (int) $product->height }}"
                                        :disabled="!$canManage" />
                        </x-ui.field>
                    </div>
                </section>

                {{-- ── The category's own attribute sheet (#98) ────────
                     What Shopee's form would ask for this category:
                     required-ness, option lists and free-text fields read
                     from the marketplace's published template, never
                     hardcoded. Same partial and same wire as the group
                     form; picking a different category above reloads it. --}}
                <section class="fm-section"
                         data-attributes-fetch
                         data-attributes-url="{{ route('ext.shopee.products.fetchAttributes') }}"
                         data-attributes-field="shopee_category_id"
                         data-attributes-source="#sl-category-value"
                         @if(empty($attrRows) && !$attrTemplate) hidden @endif>
                    <div class="fm-section__head">
                        <h2 class="fm-section__title">Category attributes</h2>
                    </div>
                    <p class="fm-section__note">What Shopee asks for this category. Answers are saved with the listing and go with every push. Required ones block nothing here, but Shopee refuses a push without them.</p>
                    <div data-attributes-target>
                        @include('ext-shopee::product-groups._attributes', [
                            'attributes' => $attrRows,
                            'saved' => $savedAttributes,
                            'template' => $attrTemplate,
                            'canManage' => $canManage,
                        ])
                    </div>
                </section>

            </div>

            <div class="fm-col fm-col--side">

                <div class="fm-card">
                    <div class="fm-card__head"><h2 class="fm-card__title">Shopee category</h2></div>
                    <div class="fm-card__body">
                        <x-ui.field label="Category" for="sl-category" name="shopee_category_id"
                                    hint="Only leaf categories are listed. Shopee accepts nothing else.">
                            <div class="gf-combo"
                                 data-category-combo
                                 data-combo-mode="remote"
                                 data-combo-search-url="{{ route('ext.shopee.products.searchCategories') }}"
                                 data-combo-lookup-url="{{ route('ext.shopee.products.categoryLookup') }}">
                                <div class="gf-combo__field">
                                    <input type="hidden" id="sl-category-value" name="shopee_category_id" data-combo-value
                                           value="{{ $selectedCategoryId > 0 ? $selectedCategoryId : '' }}">
                                    <x-ui.input id="sl-category" type="search" autocomplete="off"
                                                data-combo-input
                                                placeholder="Type to search Shopee categories"
                                                :disabled="!$canManage" />
                                    <div class="gf-combo__results" data-combo-results role="listbox"
                                         aria-label="Shopee leaf categories" hidden></div>
                                </div>
                            </div>
                        </x-ui.field>
                    </div>
                </div>

                <div class="fm-card">
                    <div class="fm-card__head"><h2 class="fm-card__title">Brand</h2></div>
                    <div class="fm-card__body">
                        @php
                            $slBrandId = (int) ($listing->shopee_brand_id ?? 0);
                            $slBrandName = $slBrandId > 0
                                ? (string) (optional($initialBrands->firstWhere('brand_id', $slBrandId))->name ?? '')
                                : '';
                        @endphp
                        <x-ui.field label="Brand" for="sl-brand" name="shopee_brand_id"
                                    hint="Only brands Shopee registers for the chosen category are offered. Leave it empty for No brand.">
                            {{-- Same searchable combobox as the category,
                                 dependent on it: the search asks for THIS
                                 category's brands. --}}
                            <div class="gf-combo"
                                 data-category-combo
                                 data-combo-mode="remote"
                                 data-combo-plain-label
                                 data-combo-search-url="{{ route('ext.shopee.products.brandsForCategory') }}"
                                 data-combo-extra-param="category_id"
                                 data-combo-extra-source="#sl-category-value"
                                 data-combo-extra-empty="Pick a category first - every brand list belongs to one.">
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
                                </div>
                            </div>
                        </x-ui.field>
                    </div>
                </div>

                <div class="fm-card">
                    <div class="fm-card__head"><h2 class="fm-card__title">Couriers</h2></div>
                    <div class="fm-card__body">
                        @if($errors->has('logistic_ids'))
                            <div class="fm-error">{{ $errors->first('logistic_ids') }}</div>
                        @endif
                        @if(!empty($logistics))
                            <div class="gf-checks">
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
                </div>

                <div class="fm-card">
                    <div class="fm-card__head"><h2 class="fm-card__title">Price rule</h2></div>
                    <div class="fm-card__body">
                        <p class="fm-section__note">The Shopee price is never stored: it is the catalog price run through this rule at every push and price sync, so catalog price changes carry through on their own.</p>
                        <div class="fm-fields fm-fields--2">
                            <x-ui.field label="Markup percent" for="sl-markup-pct" name="markup_percent">
                                <x-ui.input id="sl-markup-pct" name="markup_percent" type="number" step="0.01"
                                            :value="old('markup_percent', $listing->markup_percent ?? '')"
                                            placeholder="0"
                                            :disabled="!$canManage" />
                            </x-ui.field>
                            <x-ui.field label="Markup fixed" for="sl-markup-fixed" name="markup_fixed">
                                <x-ui.input id="sl-markup-fixed" name="markup_fixed" type="number" step="0.01"
                                            :value="old('markup_fixed', $listing->markup_fixed ?? '')"
                                            placeholder="0"
                                            :disabled="!$canManage" />
                            </x-ui.field>
                        </div>
                        <div class="fm-stats">
                            <div class="fm-stat">
                                <span class="fm-stat__k">Catalog price</span>
                                <span class="fm-stat__v"><x-money :php="$corePrice" /></span>
                            </div>
                            <div class="fm-stat">
                                <span class="fm-stat__k">Next push sends</span>
                                <span class="fm-stat__v"><x-money :php="$hasRule ? $nextPrice : $corePrice" /></span>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="fm-card">
                    <div class="fm-card__head"><h2 class="fm-card__title">Last push</h2></div>
                    <div class="fm-card__body">
                        @if($listing && $listing->last_pushed_at)
                            @php
                                $source = (string) $listing->last_push_source;
                                $sourceLabel = match (true) {
                                    $source === 'form' => 'the push form',
                                    $source === 'listing' => 'this listing',
                                    str_starts_with($source, 'group:') => 'the ' . substr($source, 6) . ' group',
                                    default => $source,
                                };
                                $sent = $listing->last_push_settings ?? [];
                            @endphp
                            <div class="fm-stats">
                                <div class="fm-stat">
                                    <span class="fm-stat__k">When</span>
                                    <span class="fm-stat__v">{{ $listing->last_pushed_at->diffForHumans() }}</span>
                                </div>
                                <div class="fm-stat">
                                    <span class="fm-stat__k">Settings from</span>
                                    <span class="fm-stat__v">{{ $sourceLabel }}</span>
                                </div>
                                @if(!empty($sent['category_id']))
                                    <div class="fm-stat">
                                        <span class="fm-stat__k">Category sent</span>
                                        <span class="fm-stat__v">{{ (int) $sent['category_id'] }}</span>
                                    </div>
                                @endif
                                @if(isset($sent['price']))
                                    <div class="fm-stat">
                                        <span class="fm-stat__k">Price sent</span>
                                        <span class="fm-stat__v"><x-money :php="(float) $sent['price']" /></span>
                                    </div>
                                @elseif(isset($sent['markup_percent']) || isset($sent['markup_fixed']))
                                    <div class="fm-stat">
                                        <span class="fm-stat__k">Rule sent</span>
                                        <span class="fm-stat__v">{{ (float) ($sent['markup_percent'] ?? 0) }}% + {{ (float) ($sent['markup_fixed'] ?? 0) }}</span>
                                    </div>
                                @endif
                            </div>
                        @else
                            <p class="fm-section__note">Never pushed from VentaSync.</p>
                        @endif
                    </div>
                </div>

            </div>
        </div>
    </form>

    <div class="fm-bar">
        <span class="fm-bar__meta">{{ !$canManage
            ? 'You can read this. Editing needs the Shopee manage permission.'
            : ($link
                ? 'Saving changes this record only. Push update to Shopee sends it onto the live listing.'
                : 'Saving changes this record only. Shopee sees it when the product is pushed.') }}</span>
        <div class="fm-bar__actions">
            <a class="fm-cancel" href="{{ route('ext.shopee.products.index') }}">{{ $canManage ? 'Cancel' : 'Back' }}</a>
            @if($canManage)
                <x-ui.button type="submit" form="listing-form" variant="primary">Save listing</x-ui.button>
            @endif
        </div>
    </div>

</div>
@endsection
