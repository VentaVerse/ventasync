@extends('layouts.channel')
@section('title', ($mode === 'create' ? 'Add' : 'Edit') . ' Shopee Product group')
@section('breadcrumb', $mode === 'create' ? 'New Product group' : $group->name)

@section('own-errors', true)

@section('content')
@php
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
                    <x-ui.field label="Product group name" for="pg-name" name="name" :required="true" wide
                                hint="What this batch of products is, in the words your team uses. It is never sent to Shopee.">
                        <x-ui.input id="pg-name" name="name" value="{{ old('name', $group->name) }}"
                                    placeholder="Guitar pedals" :disabled="!$canManageShopee" />
                    </x-ui.field>
                </div>
            </section>

            <section class="fm-section">
                <div class="fm-section__head">
                    <h2 class="fm-section__title">Category and brand</h2>
                </div>

                <div class="fm-fields">
                    @include('partials.category-tree-pick', [
                        'name' => 'shopee_category_id',
                        'id' => 'pg-category',
                        'label' => 'Shopee category',
                        'value' => $selectedCategoryId,
                        'valueLabel' => '',
                        'channel' => 'shopee',
                        'storeId' => 0,
                        'searchUrl' => route('ext.shopee.product-groups.searchCategories'),
                        'childrenUrl' => route('ext.shopee.products.category_children'),
                        'pathUrl' => route('ext.shopee.products.category_path'),
                        'refreshUrl' => $canManageShopee ? route('ext.shopee.product-groups.refreshCategories') : null,
                        'canManage' => $canManageShopee,
                        'required' => true,
                        'requiredWord' => 'Pick a Shopee category from the list.',
                    ])

                    <x-ui.field label="Shopee brand" for="pg-brand" name="shopee_brand_id" wide>
                        @php
                            $pgBrandName = $selectedBrandId > 0
                                ? (string) (optional($initialBrands->firstWhere('brand_id', $selectedBrandId))->name ?? '')
                                : '';
                        @endphp
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
                    @include('partials.watermark-pick', ['selected' => old('watermark_template_id', $group->watermark_template_id ?? null), 'integration' => 'shopee', 'canManage' => $canManageShopee])
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
