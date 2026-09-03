@extends('layouts.channel')
@section('title', ($mode === 'create' ? 'Add' : 'Edit') . ' ' . $setting->store_name . ' Product group')
@section('breadcrumb', ($mode === 'create' ? 'New' : 'Edit') . ' Product group')

{{-- This page shows its own validation summary (the fm-note below) and marks
     the offending fields inline, so partials/legacy-page-contract skips its
     bullet list of every message rather than stacking a second red box on
     top of this one. --}}
@section('own-errors', true)

{{-- Leaves the .x-legacy content boundary, rebuilt on the fm- form anatomy.

     OpenCart is multi-store: the form action, the back link and the products
     link all carry the {store} segment this page arrived on, and the store is
     named in the sub-line as well as in the breadcrumb. --}}
@section('content')
@php
    // The create GET is `manage_opencart`, but the EDIT GET is registered in
    // the read product group, so a `view_opencart` operator can open this page on an
    // existing product group. The store and update writes behind it are manage-only,
    // so for that operator every control is disabled and the Save button is
    // absent: a form you can fill in and never submit is worse than a form
    // that says up front it is a read.
    $canManageOpencart = auth()->user()?->hasPermission('manage_opencart/product_group') ?? false;

    $storeName = $setting->store_name ?: 'Unnamed store';

    // Same restore-from-old shape the pre-redesign view used, so a failed
    // validation round trip keeps the operator's selections.

    $categoryOptions = $catalogCategories->map(fn ($c) => ['id' => (int) $c->category_id, 'name' => (string) $c->name])->values();
    $manufacturerOptions = ($manufacturers ?? collect())->map(fn ($m) => ['id' => (int) $m->manufacturer_id, 'name' => (string) $m->name])->values();


    // JSON_HEX_TAG is what makes a data island safe: it encodes < and > so a
    // category literally named "</script>" cannot close the element. The rest
    // of the flags keep quotes and ampersands out of the payload for the same
    // reason. Data only, no behaviour: the picker lives in
    // resources/js/pages/group-form.js.
    $jsonFlags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
@endphp

<form method="POST" class="fm-page"
      action="{{ $mode === 'create'
        ? route('ext.opencart.product-groups.store', $setting->id)
        : route('ext.opencart.product-groups.update', [$setting->id, $group->id]) }}">
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
            <a class="fm-back" href="{{ route('ext.opencart.product-groups.index', $setting->id) }}">
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
                                hint="What this batch of products is, in the words your team uses. It is never sent to OpenCart.">
                        <x-ui.input id="pg-name" name="name" value="{{ old('name', $group->name) }}" placeholder="Guitar pedals" :disabled="!$canManageOpencart" />
                    </x-ui.field>
                </div>
            </section>

                    </div>

        <div class="fm-col fm-col--side">
            <div class="fm-card">
                <div class="fm-card__head"><h2 class="fm-card__title">Matching now</h2></div>
                <div class="fm-card__body">
                    {{-- The figure the old view printed at the bottom of the
                         filter block. It is the count as the product group is SAVED,
                         not as the form currently stands, so the sentence
                         underneath says so rather than leaving an operator to
                         wonder why it did not move when they added a
                         category. --}}
                    <p class="gf-count">{{ number_format($productCount) }}</p>
                    <p class="fm-section__note">{{ $productCount === 1 ? 'product' : 'products' }} match the filter as it is saved. Save to see this move.</p>
                    @if($mode === 'edit')
                        <x-ui.button size="sm" :href="route('ext.opencart.product-groups.products', [$setting->id, $group->id])">View products</x-ui.button>
                    @endif
                </div>
            </div>
        </div>
    </div>

    <div class="fm-bar">
        <span class="fm-bar__meta">{{ $canManageOpencart
            ? ($mode === 'create' ? 'Nothing is saved until you create the product group.' : 'Changes are saved to ' . $storeName . '.')
            : 'You can read this product group. Changing it needs the OpenCart manage permission.' }}</span>
        <div class="fm-bar__actions">
            <a class="fm-cancel" href="{{ route('ext.opencart.product-groups.index', $setting->id) }}">{{ $canManageOpencart ? 'Cancel' : 'Back' }}</a>
            @if($canManageOpencart)
                <x-ui.button type="submit" variant="primary">{{ $mode === 'create' ? 'Create product group' : 'Save product group' }}</x-ui.button>
            @endif
        </div>
    </div>
</form>
@endsection
