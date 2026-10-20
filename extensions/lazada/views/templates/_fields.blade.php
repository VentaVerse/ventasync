@php
    $selectedCategoryId = (int) old('primary_category_id', $template->primary_category_id ?? 0);
    $noBrand = old('no_brand');
    if ($noBrand === null) {
        $noBrand = strtolower(trim((string) ($template->brand_name_override ?? ''))) === 'no brand' ? 1 : 0;
    }
    $noBrand = (int) $noBrand;
    $brandId = old('brand_id', $template->brand_id);
    $brandName = $noBrand ? 'No Brand' : (old('brand_name') ?? $selectedBrandName);
    $hasAttributes = $categoryTemplate && ! empty($attributes);
@endphp

<section class="fm-section">
    <div class="fm-section__head">
        <h2 class="fm-section__title">Category and brand</h2>
    </div>
    <div class="fm-fields">
        @include('partials.category-tree-pick', [
            'name' => 'primary_category_id',
            'id' => 'lt-category-input',
            'label' => 'Lazada category',
            'value' => $selectedCategoryId,
            'valueLabel' => '',
            'channel' => 'lazada',
            'storeId' => 0,
            'searchUrl' => route('ext.lazada.products.category_search'),
            'childrenUrl' => route('ext.lazada.products.category_children'),
            'pathUrl' => route('ext.lazada.products.category_path'),
            'canManage' => $canManage,
            'required' => false,
        ])

        <x-ui.field label="Brand" for="lt-brand" name="brand_id" wide>
            <div data-brand-picker data-brand-url="{{ route('ext.lazada.brands.autocomplete') }}">
                <input type="hidden" name="brand_id" data-brand-id value="{{ $brandId }}">
                <input type="hidden" name="no_brand" data-brand-none-flag value="{{ $noBrand ? 1 : 0 }}">
                <input id="lt-brand" class="x-input" list="lt-brand-list" autocomplete="off"
                       data-brand-input value="{{ $brandName }}"
                       placeholder="Each product's own" @disabled(! $canManage)>
                <datalist id="lt-brand-list" data-brand-list></datalist>
                <div class="fm-hint">
                    Matched brand ID <span class="x-num" data-brand-picked>{{ $brandId ?: 'None' }}</span>
                    @if($canManage)
                        <x-ui.button type="button" size="sm" data-brand-none>No brand</x-ui.button>
                    @endif
                </div>
            </div>
        </x-ui.field>
    </div>
</section>

<section class="fm-section"
         data-attributes-fetch
         data-attributes-url="{{ route('ext.lazada.product-groups.fetchAttributes') }}"
         data-attributes-field="lazada_category_id"
         data-attributes-source="#lt-category-input-value"
         @unless($hasAttributes) hidden @endunless>
    <div class="fm-section__head">
        <h2 class="fm-section__title">Category attributes</h2>
    </div>
    <div data-attributes-target>
        @if($hasAttributes)
            @include('ext-lazada::product-groups._attributes', [
                'attributes' => $attributes,
                'saved' => $saved,
                'catalogSourceFields' => $catalogSourceFields,
                'template' => $categoryTemplate,
                'canManage' => $canManage,
            ])
        @endif
    </div>
</section>
