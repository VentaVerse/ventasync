@extends('layouts.channel')
@section('title', ($mode === 'create' ? 'Add' : 'Edit') . ' Lazada Product group')
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

     The wire is unchanged, checked field by field against
     LazadaProductGroupController::store()/update():

       name, lazada_category_id, brand_id, no_brand, markup_percent,
       markup_fixed, attributes[<key>], product_ids[]

     and the four endpoints behind the page (brands autocomplete,
     refresh-categories, fetch-attributes, search-products) are called with the
     same verbs, parameters and headers.

     Lazada differs from Shopee in exactly two ways, and both are here rather
     than in the shared module: its category list is small enough for a plain
     select, and its brand is a free-text name resolved to an id, with an
     explicit "No brand" flag posted alongside. --}}
@section('content')
@php
    // The CREATE render is manage-tier, the EDIT render is in the read product group
    // (extensions/lazada/routes/web.php), so a view_lazada operator can open
    // this page on an existing product group and every write behind it would 403.
    // The previous version hardcoded the manage tier on.
    $canManageLazada = auth()->user()?->hasPermission('manage_lazada/product_group') ?? false;

    $selectedCategoryId = (string) old('lazada_category_id', $group->lazada_category_id ?? '');

    // The No-brand flag survives a validation round trip through old(), and
    // otherwise reads whatever the product group was last saved with.
    $noBrand = old('no_brand');
    if ($noBrand === null) {
        $noBrand = (!empty($group->brand_name_override)
            && strtolower(trim((string) $group->brand_name_override)) === 'no brand') ? 1 : 0;
    }
    $noBrand = (int) $noBrand;

    $brandId = old('brand_id', $group->brand_id);
    $brandName = $noBrand ? 'No Brand' : (old('brand_name') ?? ($selectedBrandName ?? ''));

    $rightItems = $groupProducts->map(fn ($p) => [
        'product_id' => (int) $p->product_id,
        'name'       => (string) ($p->name ?? ''),
        'sku'        => (string) ($p->sku ?: ($p->model ?: '')),
        'quantity'   => (int) $p->quantity,
    ])->values();

    // JSON_HEX_TAG encodes < and >, so a product literally named
    // "</script>" cannot close the data island.
    $jsonFlags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

    $hasAttributes = $template && !empty($attributes);
@endphp

<form method="POST" class="fm-page"
      action="{{ $mode === 'create'
        ? route('ext.lazada.product-groups.store')
        : route('ext.lazada.product-groups.update', $group->id) }}">
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
        @if($mode === 'edit')
            <div class="fm-head__actions">
                <x-ui.button size="sm" :href="route('ext.lazada.product-groups.products', $group->id)">View products</x-ui.button>
            </div>
        @endif
    </div>

    <div class="fm-body">
        <div class="fm-col fm-col--main">

            <section class="fm-section">
                <div class="fm-section__head">
                    <h2 class="fm-section__title">Name</h2>
                </div>
                <div class="fm-fields">
                    <x-ui.field label="Product product group name" for="pg-name" name="name" :required="true" wide
                                hint="What this batch of products is, in the words your team uses. It is never sent to Lazada.">
                        <x-ui.input id="pg-name" name="name" value="{{ old('name', $group->name) }}"
                                    placeholder="Guitar pedals" :disabled="!$canManageLazada" />
                    </x-ui.field>
                </div>
            </section>

            {{-- ------------------------------------------------------------
                 Lazada mapping.

                 The category is a plain select: the controller ships at most
                 5000 rows and Lazada has no leaf-search endpoint to put
                 behind a combobox. Changing it fires the attribute fetch
                 below, which is what the original did too.

                 The brand is a name the operator recognises resolved to an
                 id. Two hidden inputs carry the answer: brand_id when one was
                 matched, and no_brand when the operator said there is not
                 one. Both are the pre-redesign wire.
                 ------------------------------------------------------------ --}}
            <section class="fm-section">
                <div class="fm-section__head">
                    <h2 class="fm-section__title">Lazada mapping</h2>
                </div>

                <div class="fm-fields">
                    <x-ui.field label="Lazada category" for="pg-category-input" name="lazada_category_id" wide
                                hint="Pick it before filling the attributes below. Changing it reloads them.">
                        {{-- The shared searchable combobox (local mode), the
                             same control the Shopee and TikTok product group forms
                             carry. #pg-category stays the HIDDEN value
                             input: the attributes panel listens for change
                             on that id and reads its value, exactly as it
                             read the old select. --}}
                        @php
                            $lzSelectedCategory = $selectedCategoryId !== ''
                                ? ($lazadaCategories ?? collect())->firstWhere('category_id', (int) $selectedCategoryId)
                                : null;
                            $lzCategoryOptions = ($lazadaCategories ?? collect())->map(fn ($c) => [
                                'id'   => (string) $c->category_id,
                                'name' => (string) $c->name,
                            ])->values();
                        @endphp
                        <div class="gf-combo" data-category-combo data-combo-mode="local"
                             @if($canManageLazada) data-combo-refresh-url="{{ route('ext.lazada.product-groups.refreshCategories') }}" @endif>
                            <div class="gf-combo__field">
                                <input type="hidden" id="pg-category" name="lazada_category_id" data-combo-value
                                       value="{{ $selectedCategoryId }}">
                                <x-ui.input id="pg-category-input" type="search" autocomplete="off"
                                            data-combo-input
                                            value="{{ $lzSelectedCategory
                                                ? $lzSelectedCategory->name . ' (' . $lzSelectedCategory->category_id . ')'
                                                : '' }}"
                                            placeholder="Type to search Lazada categories"
                                            :disabled="!$canManageLazada" />
                                <div class="gf-combo__results" data-combo-results role="listbox"
                                     aria-label="Lazada categories" hidden></div>
                            </div>
                            @if($canManageLazada)
                                <x-ui.button type="button" size="sm" data-combo-refresh>Refresh</x-ui.button>
                            @endif
                        </div>
                    </x-ui.field>

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

            {{-- ------------------------------------------------------------
                 Category attributes. Rendered inline when the product group already
                 has a category, replaced wholesale when it changes: the
                 endpoint answers with this same partial.
                 ------------------------------------------------------------ --}}
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

            {{-- ------------------------------------------------------------
                 Products. The same two-panel set builder and the same module
                 the other channels' product group forms use. Only the right-hand side
                 submits, as `product_ids[]`.
                 ------------------------------------------------------------ --}}
            <section class="fm-section">
                <div class="fm-section__head">
                    <h2 class="fm-section__title">Products in this product group</h2>
                </div>

                @if($canManageLazada)
                <p class="fm-section__note">Search the catalog on the left, move what belongs into the product group on the right. Only what is on the right is saved.</p>

                <div class="gf-dual"
                     data-group-product-picker
                     data-group-id="{{ $group->id ?? 0 }}"
                     data-search-url="{{ route('ext.lazada.product-groups.searchProducts') }}">

                    <div class="gf-filters">
                        <x-ui.input type="search" class="gf-filters__search" data-gf-q
                                    placeholder="Name, SKU or model" aria-label="Search the catalog by name, SKU or model" />

                        <div class="x-select-wrap gf-filters__select">
                            <select class="x-input" data-gf-category aria-label="Filter by catalog category">
                                <option value="">All categories</option>
                                @foreach($catalogCategories as $cat)
                                    <option value="{{ $cat->category_id }}">{{ $cat->name }}</option>
                                @endforeach
                            </select>
                            <x-ui.icon name="chevron-down" size="14" class="x-select-wrap__chevron" />
                        </div>

                        <div class="x-select-wrap gf-filters__select">
                            <select class="x-input" data-gf-manufacturer aria-label="Filter by manufacturer">
                                <option value="">All manufacturers</option>
                                @foreach($manufacturers as $mfg)
                                    <option value="{{ $mfg->manufacturer_id }}">{{ $mfg->name }}</option>
                                @endforeach
                            </select>
                            <x-ui.icon name="chevron-down" size="14" class="x-select-wrap__chevron" />
                        </div>

                        <x-ui.button type="button" variant="secondary" data-gf-search>Search</x-ui.button>
                    </div>

                    <div class="gf-panes">
                        <div class="gf-pane">
                            <div class="gf-pane__head">
                                <span class="gf-pane__title">Catalog</span>
                                <span class="gf-pane__count x-num" data-gf-left-count>0</span>
                            </div>
                            <div class="gf-pane__body">
                                <table class="gf-list">
                                    <thead>
                                        <tr>
                                            <th scope="col" class="gf-col-check"><input type="checkbox" class="cc-check" data-gf-left-all aria-label="Select every catalog result"></th>
                                            <th scope="col" class="gf-col-id">ID</th>
                                            <th scope="col">Product</th>
                                            <th scope="col" class="gf-col-qty">Stock</th>
                                        </tr>
                                    </thead>
                                    <tbody data-gf-left></tbody>
                                </table>
                            </div>
                        </div>

                        <div class="gf-move">
                            <x-ui.button type="button" size="sm" data-gf-add aria-label="Add the selected catalog products to the product group">
                                Add <x-ui.icon name="chevron-right" size="14" />
                            </x-ui.button>
                            <x-ui.button type="button" size="sm" data-gf-remove aria-label="Take the selected products out of the product group">
                                <x-ui.icon name="chevron-left" size="14" /> Remove
                            </x-ui.button>
                        </div>

                        <div class="gf-pane">
                            <div class="gf-pane__head">
                                <span class="gf-pane__title">In this product group</span>
                                <span class="gf-pane__count x-num" data-gf-right-count>{{ $rightItems->count() }}</span>
                            </div>
                            <div class="gf-pane__body">
                                <table class="gf-list">
                                    <thead>
                                        <tr>
                                            <th scope="col" class="gf-col-check"><input type="checkbox" class="cc-check" data-gf-right-all aria-label="Select every product in the product group"></th>
                                            <th scope="col" class="gf-col-id">ID</th>
                                            <th scope="col">Product</th>
                                            <th scope="col" class="gf-col-qty">Stock</th>
                                        </tr>
                                    </thead>
                                    <tbody data-gf-right></tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                    <div data-gf-inputs></div>

                    <script type="application/json" data-gf-initial>{!! json_encode($rightItems, $jsonFlags) !!}</script>
                </div>
                @else
                <p class="fm-section__note">{{ $rightItems->count() }} {{ $rightItems->count() === 1 ? 'product is' : 'products are' }} in this product group.</p>

                @if($rightItems->isEmpty())
                    <x-ui.empty title="No products in this product group"
                                description="Someone with the manage tier needs to add them." />
                @else
                    <x-ui.table>
                        <x-slot:head>
                            <tr>
                                <th scope="col" class="cc-col-id">ID</th>
                                <th scope="col">Product</th>
                                <th scope="col" class="cc-col-qty x-td-num">Stock</th>
                            </tr>
                        </x-slot:head>
                        @foreach($rightItems as $item)
                            <tr>
                                <td class="cc-col-id" data-label="ID"><span class="x-num">{{ $item['product_id'] }}</span></td>
                                <td data-label="Product">
                                    <span class="co-item__name">{{ $item['name'] !== '' ? $item['name'] : 'Unnamed product' }}</span>
                                    <span class="co-item__meta">{{ $item['sku'] !== '' ? $item['sku'] : 'No SKU' }}</span>
                                </td>
                                <td class="cc-col-qty x-td-num" data-label="Stock"><span class="x-num">{{ number_format($item['quantity']) }}</span></td>
                            </tr>
                        @endforeach
                    </x-ui.table>
                @endif
                @endif
            </section>
        </div>

        <div class="fm-col fm-col--side">
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

    <div class="fm-bar">
        <span class="fm-bar__meta">{{ $canManageLazada
            ? ($mode === 'create' ? 'Nothing is saved until you create the product group.' : 'Changes are saved to this product group, not pushed to Lazada.')
            : 'You can read this product group. Changing it needs the Lazada manage permission.' }}</span>
        <div class="fm-bar__actions">
            <a class="fm-cancel" href="{{ route('ext.lazada.product-groups.index') }}">{{ $canManageLazada ? 'Cancel' : 'Back' }}</a>
            @if($canManageLazada)
                <x-ui.button type="submit" variant="primary">{{ $mode === 'create' ? 'Create product group' : 'Save product group' }}</x-ui.button>
            @endif
        </div>
    </div>
</form>
@endsection
