@extends('layouts.channel')
@section('title', ($mode === 'create' ? 'Add' : 'Edit') . ' TikTok Shop Product group')
@section('breadcrumb', $mode === 'create' ? 'New Product group' : $group->name)

@section('own-errors', true)

@section('content')
@php
    $canManageTiktok = auth()->user()?->hasPermission('manage_tiktok/product_group') ?? false;

    $selectedCategoryId = (string) old('tiktok_category_id', $group->tiktok_category_id ?? '');
    $selectedCategory = $selectedCategoryId !== ''
        ? $tiktokCategories->firstWhere('id', $selectedCategoryId)
        : null;

    $categoryOptions = $tiktokCategories->map(fn ($c) => [
        'id'   => (string) $c->id,
        'name' => (string) $c->name,
    ])->values();

    $jsonFlags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
@endphp

<form method="POST" class="fm-page"
      action="{{ $mode === 'create'
        ? route('ext.tiktok.product-groups.store')
        : route('ext.tiktok.product-groups.update', $group->id) }}"
      data-guard-unsaved>
    @csrf
    @if($mode === 'create')
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
                    <x-ui.field label="Product group name" for="pg-name" name="name" :required="true" wide
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
                    @include('partials.category-tree-pick', [
                        'name' => 'tiktok_category_id',
                        'id' => 'pg-category',
                        'label' => 'TikTok category',
                        'value' => (int) $selectedCategoryId,
                        'valueLabel' => '',
                        'channel' => 'tiktok',
                        'storeId' => 0,
                        'searchUrl' => route('ext.tiktok.products.searchCategories'),
                        'childrenUrl' => route('ext.tiktok.products.category_children'),
                        'pathUrl' => route('ext.tiktok.products.category_path'),
                        'refreshUrl' => $canManageTiktok ? route('ext.tiktok.categories.sync') : null,
                        'canManage' => $canManageTiktok,
                        'required' => true,
                        'requiredWord' => 'Pick a TikTok category from the list.',
                    ])
                </div>
            </section>

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
                    @include('partials.watermark-pick', ['selected' => old('watermark_template_id', $group->watermark_template_id ?? null), 'integration' => 'tiktok', 'canManage' => $canManageTiktok])
                </div>
            </div>

            <div class="fm-card">
                <div class="fm-card__head"><h2 class="fm-card__title">Price markup</h2></div>
                <div class="fm-card__body">
                    <p class="fm-section__note">The percentage is added to the catalog price first, then the fixed amount on top. Leave both empty to push the catalog price as it is.</p>

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
