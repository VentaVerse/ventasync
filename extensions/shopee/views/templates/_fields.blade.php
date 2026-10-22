@php
    $selectedCategoryId = (int) old('shopee_category_id', $template->shopee_category_id ?? 0);
    $selectedBrandId = (int) old('shopee_brand_id', $template->shopee_brand_id ?? 0);
    $selectedLogisticIds = array_map('intval', (array) (old('logistic_ids', $template->logistic_ids ?? []) ?? []));
    $hasAttributes = $categoryTemplate && ! empty($attributes);
@endphp

<section class="fm-section">
    <div class="fm-section__head">
        <h2 class="fm-section__title">Category and brand</h2>
    </div>
    <div class="fm-fields">
        @include('partials.category-tree-pick', [
            'refreshUrl' => (! isset($canManage) || $canManage) ? route('ext.shopee.listing-templates.refresh_list', ['store' => \App\Channels\Listings\ListingStore::id('shopee'), 'list' => 'categories']) : null,
            'name' => 'shopee_category_id',
            'id' => 'lt-category',
            'label' => 'Shopee category',
            'value' => $selectedCategoryId,
            'valueLabel' => '',
            'channel' => 'shopee',
            'storeId' => 0,
            'searchUrl' => route('ext.shopee.product-groups.searchCategories'),
            'childrenUrl' => route('ext.shopee.products.category_children'),
            'pathUrl' => route('ext.shopee.products.category_path'),
            'canManage' => $canManage,
            'required' => false,
        ])

        <x-ui.field label="Shopee brand" for="lt-brand" name="shopee_brand_id" wide>
            <div class="gf-combo"
                 data-category-combo
                 data-combo-mode="remote"
                 data-combo-plain-label
                 data-combo-search-url="{{ route('ext.shopee.product-groups.brandsForCategory') }}"
                 data-combo-extra-param="category_id"
                 data-combo-extra-source="#lt-category-value"
                 data-combo-extra-empty="Pick a category first - every brand list belongs to one."
                 data-combo-warm>
                <div class="gf-combo__field">
                    <input type="hidden" name="shopee_brand_id" data-combo-value value="{{ $selectedBrandId > 0 ? $selectedBrandId : '' }}">
                    <x-ui.input id="lt-brand" type="search" autocomplete="off" data-combo-input
                                value="{{ $brandName }}" placeholder="Each product's own" :disabled="! $canManage" />
                    <div class="gf-combo__results" data-combo-results role="listbox" aria-label="Shopee brands" hidden></div>
                    <div class="gf-combo__progress" data-combo-progress role="status" aria-live="polite" hidden>
                        <span class="gf-combo__bar" aria-hidden="true"></span>
                        <span data-combo-progress-text></span>
                    </div>
                </div>
            </div>
        </x-ui.field>
    </div>
</section>

<section class="fm-section"
         data-attributes-fetch
         data-attributes-url="{{ route('ext.shopee.product-groups.fetchAttributes') }}"
         data-attributes-field="shopee_category_id"
         data-attributes-source="#lt-category-value"
         @unless($hasAttributes) hidden @endunless>
    <div class="fm-section__head">
        <h2 class="fm-section__title">Category attributes</h2>
    </div>
    <div data-attributes-target>
        @if($hasAttributes)
            @include('ext-shopee::product-groups._attributes', [
                'attributes' => $attributes,
                'saved' => $saved,
                'template' => $categoryTemplate,
                'canManage' => $canManage,
            ])
        @endif
    </div>
</section>

<section class="fm-section">
    <div class="fm-section__head">
        <h2 class="fm-section__title">Only on Shopee</h2>
    </div>
    @include('ext-shopee::listings._couriers', [
        'id' => 'lt-couriers',
        'logistics' => $logistics ?? [],
        'selected' => $selectedLogisticIds,
        'canManage' => $canManage,
        'required' => false,
        'refreshUrl' => $canManage ? route('ext.shopee.listing-templates.refresh_list', ['store' => \App\Channels\Listings\ListingStore::id('shopee'), 'list' => 'couriers']) : null,
    ])
</section>
