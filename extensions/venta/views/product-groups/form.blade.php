@extends('layouts.channel')
@section('title', ($mode === 'create' ? 'Add' : 'Edit') . ' ' . $setting->store_name . ' Product group')
@section('breadcrumb', ($mode === 'create' ? 'New' : 'Edit') . ' Product group')

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
    // read product group, so a `view_venta` operator can open this page on an
    // existing product group. The store and update writes behind it are
    // manage-only, so for that operator every control is disabled and the Save
    // button is absent: a form you can fill in and never submit is worse than a
    // form that says up front it is a read.
    $canManageVenta = auth()->user()?->hasPermission('manage_venta/product_group') ?? false;

    $storeName = $setting->store_name ?: 'Unnamed store';

    // The store's own categories, nested for the picker. The store owns
    // them; nothing here is ever written back to it.
    $byParent = $storeCategories->groupBy('parent_id');
    $categoryOptions = [];
    $walk = function (int $parent, int $depth) use (&$walk, &$categoryOptions, $byParent) {
        foreach ($byParent->get($parent, collect()) as $c) {
            $categoryOptions[] = ['id' => (int) $c->venta_category_id, 'label' => str_repeat('  ', $depth) . $c->name];
            $walk((int) $c->venta_category_id, $depth + 1);
        }
    };
    $walk(0, 0);
    $seen = array_column($categoryOptions, 'id');
    foreach ($storeCategories as $c) {
        if (! in_array((int) $c->venta_category_id, $seen, true)) {
            $categoryOptions[] = ['id' => (int) $c->venta_category_id, 'label' => $c->name];
        }
    }
@endphp

<form method="POST" class="fm-page"
      action="{{ $mode === 'create'
        ? route('ext.venta.product-groups.store', $setting->id)
        : route('ext.venta.product-groups.update', [$setting->id, $group->id]) }}"
      data-guard-unsaved>
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
                    <x-ui.field label="Product product group name" for="pg-name" name="name" :required="true" wide
                                hint="What this batch of products is, in the words your team uses. It is never sent to the Venta store.">
                        <x-ui.input id="pg-name" name="name" value="{{ old('name', $group->name) }}" placeholder="Guitar pedals" :disabled="!$canManageVenta" />
                    </x-ui.field>
                </div>
            </section>

            <section class="fm-section">
                <div class="fm-section__head">
                    <h2 class="fm-section__title">Category on {{ $storeName }} <x-ui.hint label="About the category">The store owns its categories; this list is what was fetched from {{ $storeName }} when the store was set up. Every product in this group is pushed into it. Nothing is written to the store's categories.</x-ui.hint></h2>
                </div>
                @if($categoryOptions === [])
                    <p class="fm-section__note">No categories on file for {{ $storeName }} yet. Fetch them on the store's Settings page first; products pushed without one go up under the catalogue's own category names.</p>
                @endif
                <div class="fm-fields">
                    <x-ui.field label="Category" for="pg-category" name="venta_category_id" wide>
                        <div class="x-select-wrap">
                            <select id="pg-category" name="venta_category_id" class="x-input" @disabled(!$canManageVenta)>
                                <option value="">None (the catalogue's own category names)</option>
                                @foreach($categoryOptions as $opt)
                                    <option value="{{ $opt['id'] }}" @selected((int) old('venta_category_id', $group->venta_category_id ?? 0) === $opt['id'])>{{ $opt['label'] }}</option>
                                @endforeach
                            </select>
                            <x-ui.icon name="chevron-down" size="14" class="x-select-wrap__chevron" />
                        </div>
                    </x-ui.field>
                </div>
            </section>
        </div>

        <div class="fm-col fm-col--side">
            @include('partials.channel-group-products-card', [
                'mode' => $mode,
                'productCount' => $productCount ?? 0,
                'productsUrl' => $mode === 'edit' ? route('ext.venta.product-groups.products', [$setting->id, $group->id]) : null,
            ])

            <div class="fm-card">
                <div class="fm-card__head"><h2 class="fm-card__title">Watermark</h2></div>
                <div class="fm-card__body">
                    @include('partials.watermark-pick', ['selected' => old('watermark_template_id', $group->watermark_template_id ?? null), 'canManage' => $canManage])
                </div>
            </div>

            <div class="fm-card">
                <div class="fm-card__head"><h2 class="fm-card__title">Price markup</h2></div>
                <div class="fm-card__body">
                    <p class="fm-section__note">The percentage is taken off the catalog price first, then the fixed amount is added on top. Leave both empty to push the catalog price as it is.</p>

                    <x-ui.field label="Percentage of price" for="pg-markup-percent" name="markup_percent"
                                hint="5 means the pushed price is 5% above the catalog price.">
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

    <x-ui.form-bar :cancel="route('ext.venta.product-groups.index', $setting->id)" :cancel-label="$canManageVenta ? 'Cancel' : 'Back'">
        <x-slot:note>{{ $canManageVenta
            ? ($mode === 'create' ? 'Nothing is saved until you create the product group.' : 'Changes are saved to ' . $storeName . '.')
            : 'You can read this product group. Changing it needs the Venta manage permission.' }}</x-slot:note>
        <x-slot:primary>
            @if($canManageVenta)
                <x-ui.button type="submit" variant="primary">{{ $mode === 'create' ? 'Create product group' : 'Save product group' }}</x-ui.button>
            @endif
        </x-slot:primary>
    </x-ui.form-bar>
</form>
@endsection
