<section class="fm-section">
    <div class="fm-section__head">
        <h2 class="fm-section__title">Category</h2>
    </div>
    <div class="fm-fields">
        @include('partials.category-tree-pick', [
            'refreshUrl' => (! isset($canManage) || $canManage) ? route('ext.ventacart.listing-templates.refresh_list', ['store' => \App\Channels\Listings\ListingStore::id('ventacart'), 'list' => 'categories']) : null,
            'name' => 'ventacart_category_id',
            'id' => 'lt-category',
            'label' => 'Category',
            'value' => (int) old('ventacart_category_id', $template->ventacart_category_id ?? 0),
            'valueLabel' => '',
            'channel' => 'ventacart',
            'storeId' => $storeId,
            'searchUrl' => route('ext.ventacart.listings.category_search', ['store' => $storeId]),
            'childrenUrl' => route('ext.ventacart.listings.category_children', ['store' => $storeId]),
            'pathUrl' => route('ext.ventacart.listings.category_path', ['store' => $storeId]),
            'canManage' => $canManage,
            'required' => false,
        ])
    </div>
</section>
