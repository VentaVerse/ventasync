@extends('layouts.channel')
@section('title', ($mode === 'create' ? 'Add' : 'Edit') . ' ' . $setting->store_name . ' Product Group')
@section('breadcrumb', ($mode === 'create' ? 'New' : 'Edit') . ' Product Group')

{{-- This page shows its own validation summary (the fm-note below) and marks
     the offending fields inline, so partials/legacy-page-contract skips its
     bullet list of every message rather than stacking a second red box on
     top of this one. --}}
@section('own-errors', true)

{{-- Leaves the .x-legacy content boundary, rebuilt on the fm- form anatomy.

     Venta is multi-store: the form action, the back link, the products link
     and the product-search endpoint all carry the {store} segment this page
     arrived on, and the store is named in the header as well as in the
     breadcrumb. --}}
@section('content')
@php
    // The create GET is `manage_venta`, but the EDIT GET is registered in the
    // read group, so a `view_venta` operator can open this page on an existing
    // group. The store and update writes behind it are manage-only, so for
    // that operator every control is disabled, the two-pane picker is replaced
    // by the plain list it is a builder for, and the Save button is absent: a
    // form you can fill in and never submit is worse than a form that says up
    // front it is a read.
    $canManageVenta = auth()->user()?->hasPermission('manage_venta/product_group') ?? false;

    $storeName = $setting->store_name ?: 'Unnamed store';

    // The right-hand panel's starting contents. VentaProductGroupController
    // has already restored these from old('product_ids') when a validation
    // round trip failed, so this is simply what the server says is in the
    // group right now.
    $rightItems = $groupProducts->map(fn ($p) => [
        'product_id' => (int) $p->product_id,
        'name'       => (string) ($p->name ?? ''),
        'sku'        => (string) ($p->sku ?: ($p->model ?: '')),
        'quantity'   => (int) $p->quantity,
    ])->values();

    // JSON_HEX_TAG is what makes a data island safe: it encodes < and > so a
    // product literally named "</script>" cannot close the element. Data only,
    // no behaviour: the picker lives in resources/js/pages/group-form.js.
    $jsonFlags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
@endphp

<form method="POST" class="fm-page"
      action="{{ $mode === 'create'
        ? route('ext.venta.product-groups.store', $setting->id)
        : route('ext.venta.product-groups.update', [$setting->id, $group->id]) }}">
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
            <a class="fm-back" href="{{ route('ext.venta.product-groups.index', $setting->id) }}">
                <x-ui.icon name="chevron-left" size="14" /> Product Groups
            </a>
            <h1 class="fm-title">{{ $mode === 'create' ? 'New product group' : $group->name }}</h1>
            <div class="fm-stamps">
                <span class="fm-stamp">
                    <span class="fm-stamp__k">Store</span>
                    <span class="fm-stamp__v">{{ $storeName }}</span>
                </span>
                @if($mode === 'edit')
                    <span class="fm-stamp">
                        <span class="fm-stamp__k">Group</span>
                        <span class="fm-stamp__v">{{ $group->id }}</span>
                    </span>
                @endif
            </div>
        </div>
        @if($mode === 'edit')
            <div class="fm-head__actions">
                <x-ui.button size="sm" :href="route('ext.venta.product-groups.products', [$setting->id, $group->id])">View products</x-ui.button>
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
                    <x-ui.field label="Product group name" for="pg-name" name="name" :required="true" wide
                                hint="What this batch of products is, in the words your team uses. It is never sent to the Venta store.">
                        <x-ui.input id="pg-name" name="name" value="{{ old('name', $group->name) }}" placeholder="Guitar pedals" :disabled="!$canManageVenta" />
                    </x-ui.field>
                </div>
            </section>

            {{-- ------------------------------------------------------------
                 Products.

                 The same two-panel control the old page had, and for the same
                 reason: this is a set you build by moving items into it, and
                 the only thing that submits is the right-hand side, as
                 `product_ids[]`. What changed is that the panels are drawn on
                 the token set instead of on inline styles, the numerals are
                 mono so quantities line up, and the two move buttons say what
                 they do instead of being chevron glyphs.
                 ------------------------------------------------------------ --}}
            <section class="fm-section">
                <div class="fm-section__head">
                    <h2 class="fm-section__title">Products in this group</h2>
                </div>
                @if($canManageVenta)
                <p class="fm-section__note">Search the catalogue on the left, move what belongs into the group on the right. Only what is on the right is saved.</p>

                <div class="gf-dual"
                     data-group-product-picker
                     data-search-url="{{ route('ext.venta.product-groups.searchProducts', $setting->id) }}">

                    <div class="gf-filters">
                        <x-ui.input type="search" class="gf-filters__search" data-gf-q
                                    placeholder="Name, SKU or model" aria-label="Search the catalogue by name, SKU or model" />

                        <div class="x-select-wrap gf-filters__select">
                            <select class="x-input" data-gf-category aria-label="Filter by catalogue category">
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
                                <span class="gf-pane__title">Catalogue</span>
                                <span class="gf-pane__count x-num" data-gf-left-count>0</span>
                            </div>
                            <div class="gf-pane__body">
                                <table class="gf-list">
                                    <thead>
                                        <tr>
                                            <th scope="col" class="gf-col-check"><input type="checkbox" class="cc-check" data-gf-left-all aria-label="Select every catalogue result"></th>
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
                            <x-ui.button type="button" size="sm" data-gf-add aria-label="Add the selected catalogue products to the group">
                                Add <x-ui.icon name="chevron-right" size="14" />
                            </x-ui.button>
                            <x-ui.button type="button" size="sm" data-gf-remove aria-label="Take the selected products out of the group">
                                <x-ui.icon name="chevron-left" size="14" /> Remove
                            </x-ui.button>
                        </div>

                        <div class="gf-pane">
                            <div class="gf-pane__head">
                                <span class="gf-pane__title">In this group</span>
                                <span class="gf-pane__count x-num" data-gf-right-count>{{ $rightItems->count() }}</span>
                            </div>
                            <div class="gf-pane__body">
                                <table class="gf-list">
                                    <thead>
                                        <tr>
                                            <th scope="col" class="gf-col-check"><input type="checkbox" class="cc-check" data-gf-right-all aria-label="Select every product in the group"></th>
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

                    {{-- The only part of this control that submits. Rewritten
                         by the picker on every change; one hidden
                         `product_ids[]` per row on the right. --}}
                    <div data-gf-inputs></div>

                    <script type="application/json" data-gf-initial>{!! json_encode($rightItems, $jsonFlags) !!}</script>
                </div>
                @else
                {{-- Read tier. The two-pane control is a builder for this list
                     and nothing else, so the list itself is what a reader is
                     shown. Nothing here submits. --}}
                <p class="fm-section__note">{{ $rightItems->count() }} {{ $rightItems->count() === 1 ? 'product is' : 'products are' }} in this group.</p>

                @if($rightItems->isEmpty())
                    <x-ui.empty title="No products in this group"
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
                    <p class="fm-section__note">The percentage is taken off the catalogue price first, then the fixed amount is added on top. Leave both empty to push the catalogue price as it is.</p>

                    <x-ui.field label="Percentage of price" for="pg-markup-percent" name="markup_percent"
                                hint="5 means the pushed price is 5% above the catalogue price.">
                        <div class="cs-inline">
                            <x-ui.input id="pg-markup-percent" name="markup_percent" type="number" step="0.01" min="0"
                                        class="fm-input--num cs-days"
                                        value="{{ old('markup_percent', $group->markup_percent ?? '') }}" placeholder="0" :disabled="!$canManageVenta" />
                            <span class="cs-inline__unit">%</span>
                        </div>
                    </x-ui.field>

                    <x-ui.field label="Fixed amount" for="pg-markup-fixed" name="markup_fixed"
                                hint="Added after the percentage.">
                        <x-ui.input id="pg-markup-fixed" name="markup_fixed" type="number" step="0.01" min="0"
                                    class="fm-input--num"
                                    value="{{ old('markup_fixed', $group->markup_fixed ?? '') }}" placeholder="0.00" :disabled="!$canManageVenta" />
                    </x-ui.field>
                </div>
            </div>
        </div>
    </div>

    <div class="fm-bar">
        <span class="fm-bar__meta">{{ $canManageVenta
            ? ($mode === 'create' ? 'Nothing is saved until you create the group.' : 'Changes are saved to ' . $storeName . '.')
            : 'You can read this group. Changing it needs the Venta manage permission.' }}</span>
        <div class="fm-bar__actions">
            <a class="fm-cancel" href="{{ route('ext.venta.product-groups.index', $setting->id) }}">{{ $canManageVenta ? 'Cancel' : 'Back' }}</a>
            @if($canManageVenta)
                <x-ui.button type="submit" variant="primary">{{ $mode === 'create' ? 'Create product group' : 'Save product group' }}</x-ui.button>
            @endif
        </div>
    </div>
</form>
@endsection
