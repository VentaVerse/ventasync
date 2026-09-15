@extends('layouts.channel')
@section('title', ($mode === 'create' ? 'Add' : 'Edit') . ' Shopee Product group')
@section('breadcrumb', $mode === 'create' ? 'New Product group' : $group->name)

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

     The wire, checked field by field against
     ShopeeProductGroupController::store()/update():

       name, shopee_category_id, shopee_brand_id, logistic_ids[],
       markup_percent, markup_fixed, attributes[<key>]

     and the endpoints behind the page (search-categories, category lookup,
     refresh-categories, brands-for-category, fetch-attributes) are called
     with the same verbs, the same parameters and the same headers.

     No product list since #201 (the owner, 2026-09-05): this form is the
     group's own settings only. Products join and leave the group on its
     products page, through the Add products panel over THIS STORE's list;
     the whole-catalogue dual list that used to sit below the attributes
     went with product_ids[] and search-products. --}}
@section('content')
@php
    // The CREATE render is manage-tier, but the EDIT render is registered in
    // the read product group (extensions/shopee/routes/web.php), so a view_shopee
    // operator can open this page on an existing product group. Every write behind it
    // is manage-only. The route middleware is the real gate; this flag stops
    // the page offering controls that would 403, which the previous version
    // did not do at all: it hardcoded the manage tier on.
    $canManageShopee = auth()->user()?->hasPermission('manage_shopee/product_group') ?? false;

    $selectedCategoryId = (int) old('shopee_category_id', $group->shopee_category_id ?? 0);
    $selectedBrandId = (int) old('shopee_brand_id', $group->shopee_brand_id ?? 0);

    $selectedLogisticIds = old('logistic_ids', $group->logistic_ids ?? []) ?? [];
    $selectedLogisticIds = array_map('intval', (array) $selectedLogisticIds);

    $hasAttributes = $template && !empty($attributes);
@endphp

<form method="POST" class="fm-page"
      action="{{ $mode === 'create'
        ? route('ext.shopee.product-groups.store')
        : route('ext.shopee.product-groups.update', $group->id) }}"
      data-guard-unsaved>
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
                        <span class="fm-stamp__k">Product group</span>
                        <span class="fm-stamp__v">{{ $group->id }}</span>
                    </span>
                @endif
            </div>
        </div>
    </div>

    <div class="fm-body">
        <div class="fm-col fm-col--main">

            <section class="fm-section">
                <div class="fm-section__head">
                    <h2 class="fm-section__title">Name</h2>
                </div>
                <div class="fm-fields">
                    <x-ui.field label="Product product group name" for="pg-name" name="name" :required="true" wide
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
                    <h2 class="fm-section__title">Category and brand</h2>
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
                                <input type="hidden" id="pg-category-value" name="shopee_category_id" data-combo-value
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
                        @php
                            $pgBrandName = $selectedBrandId > 0
                                ? (string) (optional($initialBrands->firstWhere('brand_id', $selectedBrandId))->name ?? '')
                                : '';
                        @endphp
                        {{-- The same searchable combobox the category uses,
                             dependent on it (2026-08-24; the plain select
                             swallowed whole brand lists). --}}
                        <div class="gf-combo"
                             data-category-combo
                             data-combo-mode="remote"
                             data-combo-plain-label
                             data-combo-search-url="{{ route('ext.shopee.product-groups.brandsForCategory') }}"
                             data-combo-extra-param="category_id"
                             data-combo-extra-source="#pg-category-value"
                             data-combo-extra-empty="Pick a category first - every brand list belongs to one."
                             data-combo-warm>
                            <div class="gf-combo__field">
                                <input type="hidden" name="shopee_brand_id" data-combo-value
                                       value="{{ $selectedBrandId > 0 ? $selectedBrandId : '' }}">
                                <x-ui.input id="pg-brand" type="search" autocomplete="off"
                                            data-combo-input
                                            value="{{ $pgBrandName }}"
                                            placeholder="Type to search this category's brands"
                                            :disabled="!$canManageShopee" />
                                <div class="gf-combo__results" data-combo-results role="listbox"
                                     aria-label="Shopee brands" hidden></div>
                                {{-- A first-time brand list is read from Shopee a
                                     page at a time; this says so while it comes in. --}}
                                <div class="gf-combo__progress" data-combo-progress role="status" aria-live="polite" hidden>
                                    <span class="gf-combo__bar" aria-hidden="true"></span>
                                    <span data-combo-progress-text></span>
                                </div>
                            </div>
                        </div>
                        <div class="fm-hint">Leave it empty for No brand.</div>
                    </x-ui.field>
                </div>
            </section>

            {{-- ------------------------------------------------------------
                 Category attributes.

                 Rendered inline when the product group already has a category, and
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
                <p class="fm-section__note">Values Shopee asks for on every product in this category. They are saved with the product group and used on every push.</p>
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

            {{-- What only Shopee has, in one place at the end of the main
                 column (the owner, 2026-09-07, the one group shape). --}}
            <section class="fm-section">
                <div class="fm-section__head">
                    <h2 class="fm-section__title">Only on Shopee</h2>
                </div>
                <div class="fm-sub">
                    <h3 class="fm-sub__title">Delivery options <span class="fm-req" aria-hidden="true">*</span><span class="x-sr">(required)</span> <x-ui.hint label="About the delivery options">Which of Shopee's couriers a product from this product group is offered on. Pick at least one.</x-ui.hint></h3>

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
                </div>
            </section>

        </div>

        <div class="fm-col fm-col--side">
            @include('partials.channel-group-products-card', [
                'mode' => $mode,
                'productCount' => $productCount ?? 0,
                'productsUrl' => $mode === 'edit' ? route('ext.shopee.product-groups.products', $group->id) : null,
            ])

            <div class="fm-card">
                <div class="fm-card__head"><h2 class="fm-card__title">Watermark</h2></div>
                <div class="fm-card__body">
                    @include('partials.watermark-pick', ['selected' => old('watermark_template_id', $group->watermark_template_id ?? null), 'canManage' => $canManageShopee])
                </div>
            </div>

            <div class="fm-card">
                <div class="fm-card__head"><h2 class="fm-card__title">Price markup</h2></div>
                <div class="fm-card__body">
                    <p class="fm-section__note">The percentage is taken off the catalog price first, then the fixed amount is added on top. Leave both empty to push the catalog price as it is.</p>

                    <x-ui.field label="Percentage of price" for="pg-markup-percent" name="markup_percent"
                                hint="5 means the pushed price is 5% above the catalog price.">
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

    <x-ui.form-bar :cancel="route('ext.shopee.product-groups.index')" :cancel-label="$canManageShopee ? 'Cancel' : 'Back'">
        <x-slot:note>{{ $canManageShopee
            ? ($mode === 'create' ? 'Nothing is saved until you create the product group.' : 'Changes are saved to this product group, not pushed to Shopee.')
            : 'You can read this product group. Changing it needs the Shopee manage permission.' }}</x-slot:note>
        <x-slot:primary>
            @if($canManageShopee)
                <x-ui.button type="submit" variant="primary">{{ $mode === 'create' ? 'Create product group' : 'Save product group' }}</x-ui.button>
            @endif
        </x-slot:primary>
    </x-ui.form-bar>
</form>
@endsection
