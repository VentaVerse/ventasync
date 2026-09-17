@extends('layouts.channel')
@section('title', ($mode === 'create' ? 'Add' : 'Edit') . ' TikTok Shop Product group')
@section('breadcrumb', $mode === 'create' ? 'New Product group' : $group->name)

{{-- This page shows its own validation summary (the fm-note below) and marks
     the offending fields inline, so partials/legacy-page-contract skips its
     bullet list of every message rather than stacking a second red box on
     top of this one. --}}
@section('own-errors', true)

{{-- Round F Task 1. Rebuilt on the shared fm- form anatomy
     (components/form-page.css) and the gf- product picker
     (pages/group-form.css), with the behaviour moved out of an inline
     <script> into resources/js/pages/group-form.js.

     The wire, checked against TikTokProductGroupController::store()/update():

       name, tiktok_category_id, markup_percent, markup_fixed, attributes[<key>]

     No product list since #201 (the owner, 2026-09-05): this form is the
     group's own settings only. Products join and leave the group on its
     products page, through the Add products panel over THIS STORE's list;
     the whole-catalogue dual list went with product_ids[] and search-products.

     TikTok is the simplest of the three: no brand, no delivery options and no
     category attributes. Its category picker filters a list the controller
     already loaded rather than searching the server, which is the
     `data-combo-mode="local"` half of the shared combobox. --}}
@section('content')
@php
    // The CREATE render is manage-tier, the EDIT render is in the read product group
    // (extensions/tiktok/routes/web.php), so a view_tiktok operator can open
    // this page on an existing product group. The previous version hardcoded the
    // manage tier on and offered a Save button that would 403.
    $canManageTiktok = auth()->user()?->hasPermission('manage_tiktok/product_group') ?? false;

    $selectedCategoryId = (string) old('tiktok_category_id', $group->tiktok_category_id ?? '');
    $selectedCategory = $selectedCategoryId !== ''
        ? $tiktokCategories->firstWhere('id', $selectedCategoryId)
        : null;

    // The combobox filters this locally. Ids are strings on TikTok's side
    // (tiktok_category_id is `required|string|exists:...`), so they are kept
    // as strings all the way through rather than cast to int anywhere.
    $categoryOptions = $tiktokCategories->map(fn ($c) => [
        'id'   => (string) $c->id,
        'name' => (string) $c->name,
    ])->values();

    // JSON_HEX_TAG encodes < and >, so a category literally named
    // "</script>" cannot close its data island.
    $jsonFlags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
@endphp

<form method="POST" class="fm-page"
      action="{{ $mode === 'create'
        ? route('ext.tiktok.product-groups.store')
        : route('ext.tiktok.product-groups.update', $group->id) }}"
      data-guard-unsaved>
    @csrf
    @if($mode === 'create')
        {{-- Back to the page that sent you here: the store's list, or the
             groups list. Only a local path is ever followed. --}}
        <input type="hidden" name="_return" value="{{ \App\Support\BackTo::capture() }}">
    @endif
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
            <a class="fm-back" href="{{ route('ext.tiktok.product-groups.index') }}">
                <x-ui.icon name="chevron-left" size="14" /> Product Groups
            </a>
            <h1 class="fm-title">{{ $mode === 'create' ? 'New product group' : $group->name }}</h1>
            <div class="fm-stamps">
                <span class="fm-stamp">
                    <span class="fm-stamp__k">Channel</span>
                    <span class="fm-stamp__v">TikTok Shop</span>
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
                                hint="What this batch of products is, in the words your team uses. It is never sent to TikTok Shop.">
                        <x-ui.input id="pg-name" name="name" value="{{ old('name', $group->name) }}"
                                    placeholder="Guitar pedals" :disabled="!$canManageTiktok" />
                    </x-ui.field>
                </div>
            </section>

            <section class="fm-section">
                <div class="fm-section__head">
                    <h2 class="fm-section__title">Category</h2>
                </div>

                <div class="fm-fields">
                    <x-ui.field label="TikTok category" for="pg-category" name="tiktok_category_id" :required="true" wide
                                hint="Only leaf categories are listed. TikTok Shop accepts nothing else when it creates a product.">
                        <div class="gf-combo"
                             data-category-combo
                             data-combo-mode="local"
                             @if($canManageTiktok) data-combo-refresh-url="{{ route('ext.tiktok.categories.sync') }}" @endif>
                            <div class="gf-combo__field">
                                <input type="hidden" name="tiktok_category_id" data-combo-value value="{{ $selectedCategoryId }}">
                                <x-ui.input id="pg-category" type="search" autocomplete="off"
                                            data-combo-input
                                            value="{{ $selectedCategory
                                                ? $selectedCategory->name . ' (' . $selectedCategory->id . ')'
                                                : '' }}"
                                            placeholder="Type to search TikTok categories"
                                            :disabled="!$canManageTiktok" />
                                <div class="gf-combo__results" data-combo-results role="listbox"
                                     aria-label="TikTok leaf categories" hidden></div>
                            </div>
                            @if($canManageTiktok)
                                <x-ui.button type="button" size="sm" data-combo-refresh>Refresh</x-ui.button>
                            @endif

                            {{-- Data only, no behaviour. JSON_HEX_TAG means a
                                 category named "</script>" cannot close this
                                 element, and the picker reads every name into
                                 textContent. --}}
                            <script type="application/json" data-combo-options>{!! json_encode($categoryOptions, $jsonFlags) !!}</script>
                        </div>
                    </x-ui.field>
                </div>
            </section>

            {{-- The category's attribute sheet (#156): what TikTok Shop asks
                 for this category, answered once for the whole product group. Reloaded
                 when the category changes (data-attributes-fetch, same wire
                 as Shopee's). --}}
            <section class="fm-section"
                     data-attributes-fetch
                     data-attributes-url="{{ route('ext.tiktok.product-groups.fetchAttributes') }}"
                     data-attributes-field="tiktok_category_id"
                     data-attributes-source="[name=tiktok_category_id]"
                     @if(empty($attributeRows) && !$template) hidden @endif>
                <div class="fm-section__head">
                    <h2 class="fm-section__title">Category attributes</h2>
                </div>
                <p class="fm-section__note">What TikTok Shop asks for this category. Answers go with every push from this product group; a product's own listing page can override them.</p>
                <div data-attributes-target>
                    @include('ext-tiktok::product-groups._attributes', [
                        'rows' => $attributeRows, 'saved' => $savedAttributes, 'template' => $template, 'canManage' => $canManageTiktok,
                    ])
                </div>
            </section>

        </div>

        <div class="fm-col fm-col--side">
            @include('partials.channel-group-products-card', [
                'mode' => $mode,
                'productCount' => $productCount ?? 0,
                'productsUrl' => $mode === 'edit' ? route('ext.tiktok.product-groups.products', $group->id) : null,
            ])

            <div class="fm-card">
                <div class="fm-card__head"><h2 class="fm-card__title">Watermark</h2></div>
                <div class="fm-card__body">
                    @include('partials.watermark-pick', ['selected' => old('watermark_template_id', $group->watermark_template_id ?? null), 'canManage' => $canManageTiktok])
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
                                        :disabled="!$canManageTiktok" />
                            <span class="cs-inline__unit">%</span>
                        </div>
                    </x-ui.field>

                    <x-ui.field label="Fixed amount" for="pg-markup-fixed" name="markup_fixed"
                                hint="Added after the percentage.">
                        <x-ui.input id="pg-markup-fixed" name="markup_fixed" type="number" step="0.01" min="0"
                                    class="fm-input--num"
                                    value="{{ old('markup_fixed', $group->markup_fixed) }}" placeholder="0.00"
                                    :disabled="!$canManageTiktok" />
                    </x-ui.field>
                </div>
            </div>
        </div>
    </div>

    <x-ui.form-bar :cancel="route('ext.tiktok.product-groups.index')" :cancel-label="$canManageTiktok ? 'Cancel' : 'Back'">
        <x-slot:note>{{ $canManageTiktok
            ? ($mode === 'create' ? 'Nothing is saved until you create the product group.' : 'Changes are saved to this product group, not pushed to TikTok Shop.')
            : 'You can read this product group. Changing it needs the TikTok manage permission.' }}</x-slot:note>
        <x-slot:primary>
            @if($canManageTiktok)
                <x-ui.button type="submit" variant="primary">{{ $mode === 'create' ? 'Create product group' : 'Save product group' }}</x-ui.button>
            @endif
        </x-slot:primary>
    </x-ui.form-bar>
</form>
@endsection
