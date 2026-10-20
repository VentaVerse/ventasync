<section class="fm-section">
    <div class="fm-section__head">
        <h2 class="fm-section__title">Category</h2>
    </div>
    <div class="fm-fields">
        @include('partials.category-tree-pick', [
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
