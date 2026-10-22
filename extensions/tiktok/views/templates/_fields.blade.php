<section class="fm-section">
    <div class="fm-section__head">
        <h2 class="fm-section__title">Category and brand</h2>
    </div>
    <div class="fm-fields">
        @include('partials.category-tree-pick', [
            'refreshUrl' => (! isset($canManage) || $canManage) ? route('ext.tiktok.listing-templates.refresh_list', ['store' => \App\Channels\Listings\ListingStore::id('tiktok'), 'list' => 'categories']) : null,
            'name' => 'tiktok_category_id',
            'id' => 'lt-category',
            'label' => 'TikTok category',
            'value' => (int) old('tiktok_category_id', $template->tiktok_category_id ?? 0),
            'valueLabel' => '',
            'channel' => 'tiktok',
            'storeId' => 0,
            'searchUrl' => route('ext.tiktok.products.searchCategories'),
            'childrenUrl' => route('ext.tiktok.products.category_children'),
            'pathUrl' => route('ext.tiktok.products.category_path'),
            'canManage' => $canManage,
            'required' => false,
        ])
        <x-ui.field label="Brand" for="lt-brand" name="brand_id" wide>
            <div class="gf-combo" data-category-combo data-combo-mode="remote" data-combo-plain-label
                 data-combo-search-url="{{ route('ext.tiktok.products.brandsSearch') }}"
                 data-combo-extra-param="category_id" data-combo-extra-source="#lt-category-value">
                <div class="gf-combo__field">
                    <input type="hidden" name="brand_id" data-combo-value value="{{ old('brand_id', $template->brand_id ?? '') }}">
                    <input type="hidden" name="brand_name" value="{{ old('brand_name', $template->brand_name ?? '') }}" data-combo-label-store>
                    <x-ui.input id="lt-brand" type="search" autocomplete="off" data-combo-input
                                value="{{ old('brand_name', $template->brand_name ?? '') }}"
                                placeholder="Each product's own" :disabled="! $canManage" />
                    <div class="gf-combo__results" data-combo-results role="listbox" aria-label="TikTok brands" hidden></div>
                </div>
            </div>
        </x-ui.field>
    </div>
</section>

<section class="fm-section"
         data-attributes-fetch
         data-attributes-url="{{ route('ext.tiktok.product-groups.fetchAttributes') }}"
         data-attributes-field="tiktok_category_id"
         data-attributes-source="[name=tiktok_category_id]"
         @if(empty($attributeRows) && ! $categoryTemplate) hidden @endif>
    <div class="fm-section__head">
        <h2 class="fm-section__title">Category attributes</h2>
    </div>
    <div data-attributes-target>
        @include('ext-tiktok::product-groups._attributes', [
            'rows' => $attributeRows, 'saved' => $savedAttributes, 'template' => $categoryTemplate, 'canManage' => $canManage,
        ])
    </div>
</section>
