@extends('layouts.channel')
@section('title', 'Add a Product to Shopee')
@section('breadcrumb', 'Add a Product')

{{-- Round F Task 1. Rebuilt on the shared fm- form anatomy
     (components/form-page.css), with the behaviour moved out of an inline
     <script> into resources/js/pages/channel-listing-form.js.

     This page submits nothing. It is the step before the push form: pick a
     catalogue product, optionally pre-fill a category and a markup, and go.
     The Continue button is a navigation, and that is why it says so rather
     than sitting where a Save would.

     One real defect fixed on the way past. The catalogue search built its
     result list with `resultsDiv.innerHTML = items.map(...).join('')` over a
     product name taken straight off the endpoint's JSON. A catalogue product
     named `<img src=x onerror=...>` executed here. The list is now the shared
     picker in resources/js/pages/channel-listings.js, which is createElement
     and textContent throughout, and pages/product-result.css (which existed
     only to style that dropdown) is deleted.

     The wire is unchanged: the same search endpoint with the same
     two-character minimum, and the same push URL with the same three optional
     query parameters. The URL is now built from route() through a template
     rather than from a hardcoded '/integrations/shopee/products' string, so it
     follows the address plan instead of silently breaking with it. --}}
@section('content')
@php
    // This whole page is manage-tier: the GET is registered inside
    // perm:manage_shopee (extensions/shopee/routes/web.php). No read-tier
    // branch is needed, and offering one would be a lie.
    //
    // The placeholder is replaced by the picker's chosen id in JavaScript.
    // route() will not build a URL with an empty parameter, so a sentinel is
    // what keeps the address in the routing table rather than in a string.
    $pushUrlTemplate = route('ext.shopee.products.push', ['productId' => '__PRODUCT_ID__']);
@endphp

<div class="fm-page"
     data-continue-to-push
     data-push-url="{{ $pushUrlTemplate }}">

    <div class="fm-head">
        <div class="fm-head__main">
            <a class="fm-back" href="{{ route('ext.shopee.products.index') }}">
                <x-ui.icon name="chevron-left" size="14" /> Products
            </a>
            <h1 class="fm-title">Add a product to Shopee</h1>
        </div>
    </div>

    <div class="fm-body fm-body--single">
        <div class="fm-col fm-col--main">

            <section class="fm-section">
                <div class="fm-section__head">
                    <h2 class="fm-section__title">Which product</h2>
                </div>

                <div class="fm-fields" data-catalogue-picker
                     data-search-url="{{ route('ext.shopee.products.search_catalog') }}">
                    <x-ui.field label="Catalogue product" for="add-search" :required="true" wide
                                hint="Type at least two characters, then pick one from the list.">
                        <div class="fm-ta">
                            <x-ui.input id="add-search" type="search" autocomplete="off"
                                        data-unmatched-search
                                        placeholder="Search by name, model or SKU" />
                            <div class="fm-ta__list" data-unmatched-results role="listbox" aria-label="Catalogue matches"></div>
                        </div>
                        <input type="hidden" value="" data-unmatched-id>
                    </x-ui.field>
                </div>
            </section>

            {{-- ------------------------------------------------------------
                 Everything below is optional. It only pre-fills the push form
                 on the next screen, so it is said plainly rather than dressed
                 as a second set of required fields.
                 ------------------------------------------------------------ --}}
            <section class="fm-section">
                <div class="fm-section__head">
                    <h2 class="fm-section__title">Start the push form with</h2>
                </div>
                <p class="fm-section__note">All optional. Anything left empty is filled from the catalogue product on the next screen, and everything is still editable there.</p>

                <div class="fm-fields fm-fields--2">
                    <x-ui.field label="Shopee category" for="add-category"
                                hint="Only leaf categories are listed.">
                        <div class="x-select-wrap">
                            <select id="add-category" class="x-input" data-carry="category_id">
                                <option value="">Choose it on the next screen</option>
                                @foreach($shopeeCategories as $category)
                                    <option value="{{ (int) $category->category_id }}">{{ $category->name }} ({{ $category->category_id }})</option>
                                @endforeach
                            </select>
                            <x-ui.icon name="chevron-down" size="14" class="x-select-wrap__chevron" />
                        </div>
                    </x-ui.field>

                    <x-ui.field label="Brand" for="add-brand"
                                hint="Shopee lists it as No brand unless you change it after the push.">
                        <x-ui.input id="add-brand" value="No brand" :disabled="true" />
                    </x-ui.field>

                    <x-ui.field label="Percentage of price" for="add-markup-percent"
                                hint="5 means the pushed price is 5% above the catalogue price.">
                        <div class="cs-inline">
                            <x-ui.input id="add-markup-percent" type="number" step="0.01" min="0"
                                        class="fm-input--num cs-days" data-carry="markup_percent" placeholder="0" />
                            <span class="cs-inline__unit">%</span>
                        </div>
                    </x-ui.field>

                    <x-ui.field label="Fixed amount" for="add-markup-fixed"
                                hint="Added after the percentage.">
                        <x-ui.input id="add-markup-fixed" type="number" step="0.01" min="0"
                                    class="fm-input--num" data-carry="markup_fixed" placeholder="0.00" />
                    </x-ui.field>
                </div>
            </section>
        </div>
    </div>

    <div class="fm-bar">
        <span class="fm-bar__meta">Nothing is sent to Shopee yet. The next screen is where you check the listing and push it.</span>
        <div class="fm-bar__actions">
            <a class="fm-cancel" href="{{ route('ext.shopee.products.index') }}">Cancel</a>
            <x-ui.button type="button" variant="primary" data-continue disabled>Continue</x-ui.button>
        </div>
    </div>
</div>
@endsection
