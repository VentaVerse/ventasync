@extends('layouts.channel')
@section('title', ($mode === 'create' ? 'Add' : 'Edit') . ' Shopee Product Group')
@section('breadcrumb', $mode === 'create' ? 'New Product Group' : $group->name)

{{-- This page shows its own validation summary (the fm-note below) and marks
     the offending fields inline, so partials/legacy-page-contract skips its
     bullet list of every message rather than stacking a second red box on
     top of this one. --}}
@section('own-errors', true)

{{-- Round F Task 1. The last of the channel deep pages: rebuilt on the shared
     fm- form anatomy (components/form-page.css) and the gf- product picker
     (pages/group-form.css) that Venta's identical form already used, with the
     behaviour moved out of an inline <script> into resources/js/pages/
     group-form.js.

     The wire is unchanged in every particular, checked field by field against
     ShopeeProductGroupController::store()/update():

       name, shopee_category_id, shopee_brand_id, logistic_ids[],
       markup_percent, markup_fixed, attributes[<key>], product_ids[]

     and the five endpoints behind the page (search-categories, category
     lookup, refresh-categories, brands-for-category, fetch-attributes,
     search-products) are called with the same verbs, the same parameters and
     the same headers. --}}
@section('content')
@php
    // The CREATE render is manage-tier, but the EDIT render is registered in
    // the read group (extensions/shopee/routes/web.php), so a view_shopee
    // operator can open this page on an existing group. Every write behind it
    // is manage-only. The route middleware is the real gate; this flag stops
    // the page offering controls that would 403, which the previous version
    // did not do at all: it hardcoded the manage tier on.
    $canManageShopee = auth()->user()?->hasPermission('manage_shopee/product_group') ?? false;

    $selectedCategoryId = (int) old('shopee_category_id', $group->shopee_category_id ?? 0);
    $selectedBrandId = (int) old('shopee_brand_id', $group->shopee_brand_id ?? 0);

    $selectedLogisticIds = old('logistic_ids', $group->logistic_ids ?? []) ?? [];
    $selectedLogisticIds = array_map('intval', (array) $selectedLogisticIds);

    // The right-hand panel's starting contents. The controller has already
    // restored these from old('product_ids') when a validation round trip
    // failed, so this is simply what the server says is in the group now.
    $rightItems = $groupProducts->map(fn ($p) => [
        'product_id' => (int) $p->product_id,
        'name'       => (string) ($p->name ?? ''),
        'sku'        => (string) ($p->sku ?: ($p->model ?: '')),
        'quantity'   => (int) $p->quantity,
    ])->values();

    // JSON_HEX_TAG is what makes a data island safe: it encodes < and > so a
    // product literally named "</script>" cannot close the element. Data
    // only, no behaviour.
    $jsonFlags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

    $hasAttributes = $template && !empty($attributes);
@endphp

<form method="POST" class="fm-page"
      action="{{ $mode === 'create'
        ? route('ext.shopee.product-groups.store')
        : route('ext.shopee.product-groups.update', $group->id) }}">
    @csrf
    @if($mode === 'edit')
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
            <a class="fm-back" href="{{ route('ext.shopee.product-groups.index') }}">
                <x-ui.icon name="chevron-left" size="14" /> Product Groups
            </a>
            <h1 class="fm-title">{{ $mode === 'create' ? 'New product group' : $group->name }}</h1>
            <div class="fm-stamps">
                <span class="fm-stamp">
                    <span class="fm-stamp__k">Channel</span>
                    <span class="fm-stamp__v">Shopee</span>
                </span>
                @if($mode === 'edit')
                    <span class="fm-stamp">
                        <span class="fm-stamp__k">Group</span>
                        <span class="fm-stamp__v">{{ $group->id }}</span>
                    </span>
                @endif
            </div>
        </div>
        @if($mode === 'edit')
            <div class="fm-head__actions">
                <x-ui.button size="sm" :href="route('ext.shopee.product-groups.products', $group->id)">View products</x-ui.button>
            </div>
        @endif
    </div>

    <div class="fm-body">
        <div class="fm-col fm-col--main">

            <section class="fm-section">
                <div class="fm-section__head">
                    <h2 class="fm-section__title">Name</h2>
                </div>
                <div class="fm-fields">
                    <x-ui.field label="Product group name" for="pg-name" name="name" :required="true" wide
                                hint="What this batch of products is, in the words your team uses. It is never sent to Shopee.">
                        <x-ui.input id="pg-name" name="name" value="{{ old('name', $group->name) }}"
                                    placeholder="Guitar pedals" :disabled="!$canManageShopee" />
                    </x-ui.field>
                </div>
            </section>

            {{-- ------------------------------------------------------------
                 Shopee mapping.

                 The category picker is a combobox over Shopee's own leaf
                 categories, searched on the server because the cache holds
                 tens of thousands of them. The hidden input beside it is what
                 submits; the visible box is the display. Picking one fires a
                 change on the hidden input, which is what the brand loader
                 and the attribute fetch below listen for. That coupling is
                 the pre-redesign behaviour, unchanged.
                 ------------------------------------------------------------ --}}
            <section class="fm-section">
                <div class="fm-section__head">
                    <h2 class="fm-section__title">Shopee mapping</h2>
                </div>

                <div class="fm-fields">
                    <x-ui.field label="Shopee category" for="pg-category" name="shopee_category_id" :required="true" wide
                                hint="Only leaf categories are listed. Shopee accepts nothing else when it creates a product.">
                        <div class="gf-combo"
                             data-category-combo
                             data-combo-mode="remote"
                             data-combo-search-url="{{ route('ext.shopee.product-groups.searchCategories') }}"
                             data-combo-lookup-url="{{ route('ext.shopee.product-groups.categoryLookup') }}"
                             @if($canManageShopee) data-combo-refresh-url="{{ route('ext.shopee.product-groups.refreshCategories') }}" @endif>
                            <div class="gf-combo__field">
                                <input type="hidden" name="shopee_category_id" data-combo-value
                                       value="{{ $selectedCategoryId > 0 ? $selectedCategoryId : '' }}">
                                <x-ui.input id="pg-category" type="search" autocomplete="off"
                                            data-combo-input
                                            placeholder="Type to search Shopee categories"
                                            :disabled="!$canManageShopee" />
                                <div class="gf-combo__results" data-combo-results role="listbox"
                                     aria-label="Shopee leaf categories" hidden></div>
                            </div>
                            @if($canManageShopee)
                                <x-ui.button type="button" size="sm" data-combo-refresh>Refresh</x-ui.button>
                            @endif
                        </div>
                    </x-ui.field>

                    <x-ui.field label="Shopee brand" for="pg-brand" name="shopee_brand_id" wide>
                        <div class="x-select-wrap">
                            <select id="pg-brand" name="shopee_brand_id" class="x-input"
                                    data-brand-select
                                    data-brand-url="{{ route('ext.shopee.product-groups.brandsForCategory') }}"
                                    data-brand-selected="{{ $selectedBrandId }}"
                                    @disabled(!$canManageShopee)>
                                <option value="0" @selected($selectedBrandId === 0)>No brand</option>
                                @foreach($initialBrands as $brand)
                                    <option value="{{ (int) $brand->brand_id }}" @selected($selectedBrandId === (int) $brand->brand_id)>{{ $brand->name }}</option>
                                @endforeach
                            </select>
                            <x-ui.icon name="chevron-down" size="14" class="x-select-wrap__chevron" />
                        </div>
                        <div class="fm-hint" data-brand-hint>Brands load when you pick a category. It stays on No brand until you choose one.</div>
                    </x-ui.field>
                </div>
            </section>

            {{-- ------------------------------------------------------------
                 Logistics channels. Required by the controller
                 (logistic_ids is `required|array`), so the label says so.
                 ------------------------------------------------------------ --}}
            <section class="fm-section">
                <div class="fm-section__head">
                    <h2 class="fm-section__title">Delivery options</h2>
                </div>
                <p class="fm-section__note">Which of Shopee's couriers a product from this group is offered on. Pick at least one.</p>

                @if($errors->has('logistic_ids'))
                    <div class="fm-error">{{ $errors->first('logistic_ids') }}</div>
                @endif

                @if(!empty($logistics))
                    <div class="gf-checks">
                        @foreach($logistics as $channel)
                            @php
                                $logisticId = (int) ($channel['logistic_id'] ?? 0);
                                $logisticName = (string) ($channel['logistic_name'] ?? 'Channel ' . $logisticId);
                            @endphp
                            <label class="gf-check">
                                <input type="checkbox" class="cc-check" name="logistic_ids[]" value="{{ $logisticId }}"
                                       @checked(in_array($logisticId, $selectedLogisticIds, true))
                                       @disabled(!$canManageShopee)>
                                <span class="gf-check__name">{{ $logisticName }}</span>
                                <span class="gf-check__id">{{ $logisticId }}</span>
                            </label>
                        @endforeach
                    </div>
                @else
                    <x-ui.empty title="No delivery options cached"
                                description="Fetch them from the Logistics page first, then come back.">
                        <x-slot:action>
                            <x-ui.button :href="route('ext.shopee.logistics.index')">Go to Logistics</x-ui.button>
                        </x-slot:action>
                    </x-ui.empty>
                @endif
            </section>

            {{-- ------------------------------------------------------------
                 Category attributes.

                 Rendered inline when the group already has a category, and
                 replaced wholesale when the category changes: the endpoint
                 answers with the same Blade partial this @include renders, so
                 both paths land looking identical.

                 The section carries `hidden` rather than an inline
                 display:none. resources/css/pages/group-form.css does not set
                 a display on .fm-section, so the user agent's [hidden] rule
                 wins here; the JS toggles the same attribute.
                 ------------------------------------------------------------ --}}
            <section class="fm-section"
                     data-attributes-fetch
                     data-attributes-url="{{ route('ext.shopee.product-groups.fetchAttributes') }}"
                     data-attributes-field="shopee_category_id"
                     @unless($hasAttributes) hidden @endunless>
                <div class="fm-section__head">
                    <h2 class="fm-section__title">Category attributes</h2>
                </div>
                <p class="fm-section__note">Values Shopee asks for on every product in this category. They are saved with the group and used on every push.</p>
                <div data-attributes-target>
                    @if($hasAttributes)
                        @include('ext-shopee::product-groups._attributes', [
                            'attributes' => $attributes,
                            'saved' => $saved,
                            'template' => $template,
                            'canManage' => $canManageShopee,
                        ])
                    @endif
                </div>
            </section>

            {{-- ------------------------------------------------------------
                 Products.

                 The same two-panel set builder Venta's group form uses, and
                 the same module drives it. The only thing that submits is the
                 right-hand side, as `product_ids[]`, which is the wire this
                 page always had.
                 ------------------------------------------------------------ --}}
            <section class="fm-section">
                <div class="fm-section__head">
                    <h2 class="fm-section__title">Products in this group</h2>
                </div>

                @if($canManageShopee)
                <p class="fm-section__note">Search the catalogue on the left, move what belongs into the group on the right. Only what is on the right is saved.</p>

                <div class="gf-dual"
                     data-group-product-picker
                     data-search-url="{{ route('ext.shopee.product-groups.searchProducts') }}">

                    <div class="gf-filters">
                        <x-ui.input type="search" class="gf-filters__search" data-gf-q
                                    placeholder="Name, SKU or model" aria-label="Search the catalogue by name, SKU or model" />

                        <div class="x-select-wrap gf-filters__select">
                            <select class="x-input" data-gf-category aria-label="Filter by catalogue category">
                                <option value="">All categories</option>
                                @foreach($catalogCategories as $cat)
                                    <option value="{{ $cat->category_id }}">{{ $cat->name }}</option>
                                @endforeach
                            </select>
                            <x-ui.icon name="chevron-down" size="14" class="x-select-wrap__chevron" />
                        </div>

                        <div class="x-select-wrap gf-filters__select">
                            <select class="x-input" data-gf-manufacturer aria-label="Filter by manufacturer">
                                <option value="">All manufacturers</option>
                                @foreach($manufacturers as $mfg)
                                    <option value="{{ $mfg->manufacturer_id }}">{{ $mfg->name }}</option>
                                @endforeach
                            </select>
                            <x-ui.icon name="chevron-down" size="14" class="x-select-wrap__chevron" />
                        </div>

                        <x-ui.button type="button" variant="secondary" data-gf-search>Search</x-ui.button>
                    </div>

                    <div class="gf-panes">
                        <div class="gf-pane">
                            <div class="gf-pane__head">
                                <span class="gf-pane__title">Catalogue</span>
                                <span class="gf-pane__count x-num" data-gf-left-count>0</span>
                            </div>
                            <div class="gf-pane__body">
                                <table class="gf-list">
                                    <thead>
                                        <tr>
                                            <th scope="col" class="gf-col-check"><input type="checkbox" class="cc-check" data-gf-left-all aria-label="Select every catalogue result"></th>
                                            <th scope="col" class="gf-col-id">ID</th>
                                            <th scope="col">Product</th>
                                            <th scope="col" class="gf-col-qty">Stock</th>
                                        </tr>
                                    </thead>
                                    <tbody data-gf-left></tbody>
                                </table>
                            </div>
                        </div>

                        <div class="gf-move">
                            <x-ui.button type="button" size="sm" data-gf-add aria-label="Add the selected catalogue products to the group">
                                Add <x-ui.icon name="chevron-right" size="14" />
                            </x-ui.button>
                            <x-ui.button type="button" size="sm" data-gf-remove aria-label="Take the selected products out of the group">
                                <x-ui.icon name="chevron-left" size="14" /> Remove
                            </x-ui.button>
                        </div>

                        <div class="gf-pane">
                            <div class="gf-pane__head">
                                <span class="gf-pane__title">In this group</span>
                                <span class="gf-pane__count x-num" data-gf-right-count>{{ $rightItems->count() }}</span>
                            </div>
                            <div class="gf-pane__body">
                                <table class="gf-list">
                                    <thead>
                                        <tr>
                                            <th scope="col" class="gf-col-check"><input type="checkbox" class="cc-check" data-gf-right-all aria-label="Select every product in the group"></th>
                                            <th scope="col" class="gf-col-id">ID</th>
                                            <th scope="col">Product</th>
                                            <th scope="col" class="gf-col-qty">Stock</th>
                                        </tr>
                                    </thead>
                                    <tbody data-gf-right></tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                    {{-- The only part of this control that submits. Rewritten
                         by the picker on every change; one hidden
                         `product_ids[]` per row on the right. --}}
                    <div data-gf-inputs></div>

                    <script type="application/json" data-gf-initial>{!! json_encode($rightItems, $jsonFlags) !!}</script>
                </div>
                @else
                {{-- Read tier. The two-pane control is a builder for this list
                     and nothing else, so the list itself is what a reader is
                     shown. Nothing here submits. --}}
                <p class="fm-section__note">{{ $rightItems->count() }} {{ $rightItems->count() === 1 ? 'product is' : 'products are' }} in this group.</p>

                @if($rightItems->isEmpty())
                    <x-ui.empty title="No products in this group"
                                description="Someone with the manage tier needs to add them." />
                @else
                    <x-ui.table>
                        <x-slot:head>
                            <tr>
                                <th scope="col" class="cc-col-id">ID</th>
                                <th scope="col">Product</th>
                                <th scope="col" class="cc-col-qty x-td-num">Stock</th>
                            </tr>
                        </x-slot:head>
                        @foreach($rightItems as $item)
                            <tr>
                                <td class="cc-col-id" data-label="ID"><span class="x-num">{{ $item['product_id'] }}</span></td>
                                <td data-label="Product">
                                    <span class="co-item__name">{{ $item['name'] !== '' ? $item['name'] : 'Unnamed product' }}</span>
                                    <span class="co-item__meta">{{ $item['sku'] !== '' ? $item['sku'] : 'No SKU' }}</span>
                                </td>
                                <td class="cc-col-qty x-td-num" data-label="Stock"><span class="x-num">{{ number_format($item['quantity']) }}</span></td>
                            </tr>
                        @endforeach
                    </x-ui.table>
                @endif
                @endif
            </section>
        </div>

        <div class="fm-col fm-col--side">
            <div class="fm-card">
                <div class="fm-card__head"><h2 class="fm-card__title">Price markup</h2></div>
                <div class="fm-card__body">
                    <p class="fm-section__note">The percentage is taken off the catalogue price first, then the fixed amount is added on top. Leave both empty to push the catalogue price as it is.</p>

                    <x-ui.field label="Percentage of price" for="pg-markup-percent" name="markup_percent"
                                hint="5 means the pushed price is 5% above the catalogue price.">
                        <div class="cs-inline">
                            <x-ui.input id="pg-markup-percent" name="markup_percent" type="number" step="0.01" min="0"
                                        class="fm-input--num cs-days"
                                        value="{{ old('markup_percent', $group->markup_percent) }}" placeholder="0"
                                        :disabled="!$canManageShopee" />
                            <span class="cs-inline__unit">%</span>
                        </div>
                    </x-ui.field>

                    <x-ui.field label="Fixed amount" for="pg-markup-fixed" name="markup_fixed"
                                hint="Added after the percentage.">
                        <x-ui.input id="pg-markup-fixed" name="markup_fixed" type="number" step="0.01" min="0"
                                    class="fm-input--num"
                                    value="{{ old('markup_fixed', $group->markup_fixed) }}" placeholder="0.00"
                                    :disabled="!$canManageShopee" />
                    </x-ui.field>
                </div>
            </div>
        </div>
    </div>

    <div class="fm-bar">
        <span class="fm-bar__meta">{{ $canManageShopee
            ? ($mode === 'create' ? 'Nothing is saved until you create the group.' : 'Changes are saved to this group, not pushed to Shopee.')
            : 'You can read this group. Changing it needs the Shopee manage permission.' }}</span>
        <div class="fm-bar__actions">
            <a class="fm-cancel" href="{{ route('ext.shopee.product-groups.index') }}">{{ $canManageShopee ? 'Cancel' : 'Back' }}</a>
            @if($canManageShopee)
                <x-ui.button type="submit" variant="primary">{{ $mode === 'create' ? 'Create product group' : 'Save product group' }}</x-ui.button>
            @endif
        </div>
    </div>
</form>
@endsection
