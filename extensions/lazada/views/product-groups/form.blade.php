@extends('layouts.channel')
@section('title', ($mode === 'create' ? 'Add' : 'Edit') . ' Lazada Product group')
@section('breadcrumb', $mode === 'create' ? 'New Product group' : $group->name)

@section('own-errors', true)

@section('content')
@php
    $canManageLazada = auth()->user()?->hasPermission('manage_lazada/product_group') ?? false;

    $selectedCategoryId = (string) old('lazada_category_id', $group->lazada_category_id ?? '');

    $noBrand = old('no_brand');
    if ($noBrand === null) {
        $noBrand = (!empty($group->brand_name_override)
            && strtolower(trim((string) $group->brand_name_override)) === 'no brand') ? 1 : 0;
    }
    $noBrand = (int) $noBrand;

    $brandId = old('brand_id', $group->brand_id);
    $brandName = $noBrand ? 'No Brand' : (old('brand_name') ?? ($selectedBrandName ?? ''));

    $hasAttributes = $template && !empty($attributes);
@endphp

<form method="POST" class="fm-page"
      action="{{ $mode === 'create'
        ? route('ext.lazada.product-groups.store')
        : route('ext.lazada.product-groups.update', $group->id) }}"
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
            <a class="fm-back" href="{{ route('ext.lazada.product-groups.index') }}">
                <x-ui.icon name="chevron-left" size="14" /> Product Groups
            </a>
            <h1 class="fm-title">{{ $mode === 'create' ? 'New product group' : $group->name }}</h1>
            <div class="fm-stamps">
                <span class="fm-stamp">
                    <span class="fm-stamp__k">Channel</span>
                    <span class="fm-stamp__v">Lazada</span>
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
                                hint="What this batch of products is, in the words your team uses. It is never sent to Lazada.">
                        <x-ui.input id="pg-name" name="name" value="{{ old('name', $group->name) }}"
                                    placeholder="Guitar pedals" :disabled="!$canManageLazada" />
                    </x-ui.field>
                </div>
            </section>

            <section class="fm-section">
                <div class="fm-section__head">
                    <h2 class="fm-section__title">Category and brand</h2>
                </div>

                <div class="fm-fields">
                    @include('partials.category-tree-pick', [
                        'name' => 'lazada_category_id',
                        'id' => 'pg-category-input',
                        'label' => 'Lazada category',
                        'value' => (int) ($selectedCategoryId ?? 0),
                        'valueLabel' => '',
                        'channel' => 'lazada',
                        'storeId' => 0,
                        'searchUrl' => route('ext.lazada.products.category_search'),
                        'childrenUrl' => route('ext.lazada.products.category_children'),
                        'pathUrl' => route('ext.lazada.products.category_path'),
                        'canManage' => $canManageLazada ?? true,
                        'required' => false,
                    ])

                    <x-ui.field label="Brand" for="pg-brand" name="brand_id" wide
                                hint="Start typing and pick a match, or say there is no brand. Lazada rejects a name it does not know.">
                        <div data-brand-picker data-brand-url="{{ route('ext.lazada.brands.autocomplete') }}">
                            <input type="hidden" name="brand_id" data-brand-id value="{{ $brandId }}">
                            <input type="hidden" name="no_brand" data-brand-none-flag value="{{ $noBrand ? 1 : 0 }}">

                            <input id="pg-brand" class="x-input" list="pg-brand-list" autocomplete="off"
                                   data-brand-input value="{{ $brandName }}"
                                   placeholder="Type to search Lazada brands" @disabled(!$canManageLazada)>
                            <datalist id="pg-brand-list" data-brand-list></datalist>

                            <div class="fm-hint">
                                Matched brand ID <span class="x-num" data-brand-picked>{{ $brandId ?: 'None' }}</span>
                                @if($canManageLazada)
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
                     data-attributes-source="#pg-category"
                     @unless($hasAttributes) hidden @endunless>
                <div class="fm-section__head">
                    <h2 class="fm-section__title">Category attributes</h2>
                </div>
                <p class="fm-section__note">Values Lazada asks for on every product in this category. Type one, or point it at a field on the catalog product so every push reads the current value.</p>
                <div data-attributes-target>
                    @if($hasAttributes)
                        @include('ext-lazada::product-groups._attributes', [
                            'attributes' => $attributes,
                            'saved' => $saved,
                            'erpSourceFields' => $erpSourceFields,
                            'template' => $template,
                            'canManage' => $canManageLazada,
                        ])
                    @endif
                </div>
            </section>

        </div>

        <div class="fm-col fm-col--side">
            @include('partials.channel-group-products-card', [
                'mode' => $mode,
                'productCount' => $productCount ?? 0,
                'productsUrl' => $mode === 'edit' ? route('ext.lazada.product-groups.products', $group->id) : null,
            ])

            <div class="fm-card">
                <div class="fm-card__head"><h2 class="fm-card__title">Watermark</h2></div>
                <div class="fm-card__body">
                    @include('partials.watermark-pick', ['selected' => old('watermark_template_id', $group->watermark_template_id ?? null), 'integration' => 'lazada', 'canManage' => $canManageLazada])
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
                                        :disabled="!$canManageLazada" />
                            <span class="cs-inline__unit">%</span>
                        </div>
                    </x-ui.field>

                    <x-ui.field label="Fixed amount" for="pg-markup-fixed" name="markup_fixed"
                                hint="Added after the percentage.">
                        <x-ui.input id="pg-markup-fixed" name="markup_fixed" type="number" step="0.01" min="0"
                                    class="fm-input--num"
                                    value="{{ old('markup_fixed', $group->markup_fixed) }}" placeholder="0.00"
                                    :disabled="!$canManageLazada" />
                    </x-ui.field>
                </div>
            </div>
        </div>
    </div>

    <x-ui.form-bar :cancel="route('ext.lazada.product-groups.index')" :cancel-label="$canManageLazada ? 'Cancel' : 'Back'">
        <x-slot:note>{{ $canManageLazada
            ? ($mode === 'create' ? 'Nothing is saved until you create the product group.' : 'Changes are saved to this product group, not pushed to Lazada.')
            : 'You can read this product group. Changing it needs the Lazada manage permission.' }}</x-slot:note>
        <x-slot:primary>
            @if($canManageLazada)
                <x-ui.button type="submit" variant="primary">{{ $mode === 'create' ? 'Create product group' : 'Save product group' }}</x-ui.button>
            @endif
        </x-slot:primary>
    </x-ui.form-bar>
</form>
@endsection
