@extends('layouts.blotter')
@section('title', 'Edit ' . ($brand->name ?: 'brand'))
@section('breadcrumb', $brand->name ?: ('Brand ' . $brand->brand_id))

@section('content')
@php $backUrl = \App\Support\BackTo::safe(old('_return', $returnUrl ?? null), route('brands.index')); @endphp
<form method="POST" action="{{ route('brands.update', $brand->brand_id) }}" class="fm-page" data-guard-unsaved>
    <input type="hidden" name="_return" value="{{ $backUrl }}">
    @csrf
    @method('PUT')

    <div class="fm-flash" role="status" aria-live="polite">
        @if(session('status'))
            <div class="fm-note fm-note--ok"><div class="fm-note__body">{{ session('status') }}</div></div>
        @endif
        @if($errors->any())
            <div class="fm-note fm-note--fail">
                <div class="fm-note__body">Nothing was saved. Check the fields marked below.</div>
            </div>
        @endif
    </div>

    <div class="fm-head">
        <div class="fm-head__main">
            <a class="fm-back" href="{{ $backUrl }}" data-guard-leave>
                <x-ui.icon name="chevron-left" size="14" /> Brands
            </a>
            <h1 class="fm-title">{{ $brand->name ?: 'Untitled brand' }}</h1>
            <div class="fm-stamps">
                <span class="fm-stamp">
                    <span class="fm-stamp__k">ID</span>
                    <span class="fm-stamp__v">{{ $brand->brand_id }}</span>
                </span>
            </div>
        </div>

        <div class="fm-head__actions">
            <x-ui.menu label="Brand {{ $brand->brand_id }} actions">
                <button type="button" class="x-menu__item x-menu__item--danger"
                        data-confirm="Delete {{ $brand->name ?: 'this brand' }}? This cannot be undone."
                        data-confirm-submit="brand-delete-form">Delete brand</button>
            </x-ui.menu>
        </div>
    </div>

    <div class="fm-body">
        <div class="fm-col fm-col--main">

            <section class="fm-section">
                <div class="fm-section__head">
                    <h2 class="fm-section__title">General</h2>
                </div>

                <div class="fm-fields">
                    <x-ui.field label="Name" for="brand_name_field" name="name" :required="true" wide>
                        <x-ui.input id="brand_name_field" name="name" value="{{ old('name', $brand->name) }}" />
                    </x-ui.field>
                </div>
            </section>
        </div>

        <div class="fm-col fm-col--side">

            <div class="fm-card">
                <div class="fm-card__head"><h2 class="fm-card__title">Details</h2></div>
                <div class="fm-card__body">
                    <x-ui.field label="Sort order" for="sort_order" name="sort_order" hint="Lower numbers show first.">
                        <x-ui.input id="sort_order" name="sort_order" class="fm-input--num" value="{{ old('sort_order', $brand->sort_order) }}" />
                    </x-ui.field>
                </div>
            </div>
        </div>
    </div>

    <x-ui.form-bar :cancel="$backUrl">
        <x-slot:note>Brand {{ $brand->brand_id }}</x-slot:note>
        <x-slot:primary><x-ui.button type="submit" variant="primary">Save changes</x-ui.button></x-slot:primary>
    </x-ui.form-bar>
</form>

<form id="brand-delete-form" method="POST" action="{{ route('brands.destroy', $brand->brand_id) }}" class="x-sr">
    @csrf
    @method('DELETE')
</form>
@endsection
