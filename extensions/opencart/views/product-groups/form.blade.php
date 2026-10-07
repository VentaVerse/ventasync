@extends('layouts.channel')
@section('title', ($mode === 'create' ? 'Add' : 'Edit') . ' ' . $setting->store_name . ' Product group')
@section('breadcrumb', ($mode === 'create' ? 'New' : 'Edit') . ' Product group')

@section('own-errors', true)

@section('content')
@php
    $canManageOpencart = auth()->user()?->hasPermission('manage_opencart/product_group') ?? false;

    $storeName = $setting->store_name ?: 'Unnamed store';

    $categoryOptions = $catalogCategories->map(fn ($c) => ['id' => (int) $c->category_id, 'name' => (string) $c->name])->values();
    $manufacturerOptions = ($manufacturers ?? collect())->map(fn ($m) => ['id' => (int) $m->manufacturer_id, 'name' => (string) $m->name])->values();


    $jsonFlags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
@endphp

<form method="POST" class="fm-page"
      action="{{ $mode === 'create'
        ? route('ext.opencart.product-groups.store', $setting->id)
        : route('ext.opencart.product-groups.update', [$setting->id, $group->id]) }}"
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
                    <x-ui.field label="Product group name" for="pg-name" name="name" :required="true" wide
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
                    <p class="gf-count">{{ number_format($productCount) }}</p>
                    <p class="fm-section__note">{{ $productCount === 1 ? 'product' : 'products' }} match the filter as it is saved. Save to see this move.</p>
                    @if($mode === 'edit')
                        <x-ui.button size="sm" :href="route('ext.opencart.product-groups.products', [$setting->id, $group->id])">View products</x-ui.button>
                    @endif
                </div>
            </div>
        </div>
    </div>

    <x-ui.form-bar :cancel="route('ext.opencart.product-groups.index', $setting->id)" :cancel-label="$canManageOpencart ? 'Cancel' : 'Back'">
        <x-slot:note>{{ $canManageOpencart
            ? ($mode === 'create' ? 'Nothing is saved until you create the product group.' : 'Changes are saved to ' . $storeName . '.')
            : 'You can read this product group. Changing it needs the OpenCart manage permission.' }}</x-slot:note>
        <x-slot:primary>
            @if($canManageOpencart)
                <x-ui.button type="submit" variant="primary">{{ $mode === 'create' ? 'Create product group' : 'Save product group' }}</x-ui.button>
            @endif
        </x-slot:primary>
    </x-ui.form-bar>
</form>
@endsection
