@extends('layouts.channel')
@section('title', 'Push ' . $product->name . ' to Shopee')
@section('breadcrumb', 'Push to Shopee')

{{-- This page shows its own validation summary (the fm-note below) and marks
     the offending fields inline, so partials/legacy-page-contract skips its
     bullet list of every message rather than stacking a second red box on
     top of this one. --}}
@section('own-errors', true)

{{-- Round F Task 1. Rebuilt on the shared fm- form anatomy
     (components/form-page.css), with the image chooser and the delivery-option
     list in pages/channel-listing.css and pages/group-form.css.

     This page is one form. Its only script is the running total under the
     price rule (pages/channel-listing-form.js, the same one the Lazada
     form runs), which replaces the digits of a figure the server rendered.

     The wire is unchanged, checked field by field against
     ShopeeProductController::push():

       item_name, description, original_price, normal_stock, item_sku,
       weight, package_length, package_width, package_height, category_id,
       logistic_ids[], selected_images[]

     including the checked-by-default behaviour of every image and the
     three-step precedence on the delivery options (old input, then the product
     group's channels, then whatever Shopee has enabled).

     Changed beyond the drawing: the required marker was a red asterisk on nine
     labels, which is nine points of red on a page whose only real accent is
     the Push button. The fm- anatomy says "Required" in words at label weight,
     once per field, and colour goes back to meaning one thing. --}}
@section('content')
@php
    // The GET render is `view_shopee|manage_shopee` but the POST is
    // `manage_shopee` (extensions/shopee/routes/web.php), so a read-tier
    // operator can reach this page. The previous version offered them the
    // whole form and a Push button that would 403.
    $canManageShopee = auth()->user()?->hasPermission('manage_shopee/product') ?? false;

    $selectedCategoryId = (int) old('category_id', $groupCategoryId ?? 0);

    $oldLogisticIds = old('logistic_ids');
    $groupLogisticIds = array_map('intval', $groupLogisticIds ?? []);

    // Every image is ticked unless a failed submit says otherwise. That is the
    // pre-redesign default and the controller requires at least one.
    $selectedImages = old('selected_images', $images);

    $productName = (string) ($product->name ?? '');
    $suggested = $suggestedPrice ?? (float) $product->price;
    $markupPercent = old('markup_percent', $markupPercent ?? null);
    $markupFixed = old('markup_fixed', $markupFixed ?? null);
@endphp

<form method="POST" class="fm-page" action="{{ route('ext.shopee.products.push.post', $product->product_id) }}">
    @csrf

    <div class="fm-flash" role="status" aria-live="polite">
        @if($errors->any())
            <div class="fm-note fm-note--fail">
                <div class="fm-note__body">Nothing was sent to Shopee. Check the fields marked below.</div>
            </div>
        @endif
    </div>

    <div class="fm-head">
        <div class="fm-head__main">
            <a class="fm-back" href="{{ route('ext.shopee.products.index') }}">
                <x-ui.icon name="chevron-left" size="14" /> Products
            </a>
            <h1 class="fm-title">{{ $productName !== '' ? $productName : 'Unnamed product' }}</h1>
            <div class="fm-stamps">
                <span class="fm-stamp">
                    <span class="fm-stamp__k">Catalog product</span>
                    <a class="fm-stamp__v" href="{{ route('products.edit', $product->product_id) }}">{{ $product->product_id }}</a>
                </span>
                @if(!empty($groupName))
                    <span class="fm-stamp">
                        <span class="fm-stamp__k">Preset</span>
                        <span class="fm-stamp__v">{{ $groupName }}</span>
                    </span>
                @endif
            </div>
        </div>
    </div>

    <div class="fm-body">
        <div class="fm-col fm-col--main">

            <section class="fm-section">
                <div class="fm-section__head">
                    <h2 class="fm-section__title">The listing</h2>
                </div>

                <div class="fm-fields">
                    <x-ui.field label="Title" for="ps-name" name="item_name" :required="true" wide
                                hint="What a buyer sees in Shopee search. Up to 255 characters.">
                        <x-ui.input id="ps-name" name="item_name" maxlength="255"
                                    value="{{ old('item_name', $product->name) }}" :disabled="!$canManageShopee" />
                    </x-ui.field>

                    <x-ui.field label="Description" for="ps-description" name="description" :required="true" wide
                                hint="Plain text. Shopee strips formatting, so the catalog description arrives here with its markup already removed.">
                        <x-ui.textarea id="ps-description" name="description" rows="6" maxlength="5000"
                                       :disabled="!$canManageShopee">{{ old('description', strip_tags($product->description ?? '')) }}</x-ui.textarea>
                    </x-ui.field>
                </div>

                {{-- The price as a RULE over the catalog price, the same
                     grammar the listing page and the group form speak
                     (#150). The form used to take a final price and derive
                     a fixed markup from it; now the operator states the
                     rule, the figure below previews what Shopee will list
                     at, and the rule is what the listing stores, so a
                     catalog price change carries through on its own. --}}
                <div class="fm-fields fm-fields--2">
                    <x-ui.field label="Percent added" for="ps-markup-pct" name="markup_percent"
                                hint="On top of the catalog price. Leave both blank to list at the catalog price.">
                        <x-ui.input id="ps-markup-pct" name="markup_percent" type="number" step="0.01" min="-100" max="1000"
                                    class="fm-input--num" placeholder="0" data-markup-percent
                                    :value="$markupPercent !== null && $markupPercent !== '' ? $markupPercent : ''"
                                    :disabled="!$canManageShopee" />
                    </x-ui.field>
                    <x-ui.field label="Fixed amount added" for="ps-markup-fixed" name="markup_fixed">
                        <x-ui.input id="ps-markup-fixed" name="markup_fixed" type="number" step="0.01"
                                    class="fm-input--num" placeholder="0" data-markup-fixed
                                    :value="$markupFixed !== null && $markupFixed !== '' ? $markupFixed : ''"
                                    :disabled="!$canManageShopee" />
                    </x-ui.field>
                </div>
                <div class="fm-stats" data-running-total data-base-price="{{ number_format((float) $product->price, 2, '.', '') }}">
                    <div class="fm-stat">
                        <span class="fm-stat__k">Catalog price</span>
                        <span class="fm-stat__v"><x-money :php="(float) $product->price" /></span>
                    </div>
                    <div class="fm-stat">
                        <span class="fm-stat__k">Price on Shopee</span>
                        <span class="fm-stat__v" data-total-value><x-money :php="(float) $suggested" /></span>
                    </div>
                </div>

                <div class="fm-fields fm-fields--2">
                    <x-ui.field label="Stock" for="ps-stock" name="normal_stock" :required="true"
                                hint="What Shopee shows as available. It is the catalog quantity unless you change it.">
                        <x-ui.input id="ps-stock" name="normal_stock" type="number" min="0"
                                    class="fm-input--num"
                                    value="{{ old('normal_stock', (int) $product->quantity) }}"
                                    :disabled="!$canManageShopee" />
                    </x-ui.field>

                    <x-ui.field label="Seller SKU" for="ps-sku" name="item_sku"
                                hint="Your own code for it, shown only to you in Shopee's back office.">
                        <x-ui.input id="ps-sku" name="item_sku" maxlength="100"
                                    value="{{ old('item_sku', $product->sku) }}" :disabled="!$canManageShopee" />
                    </x-ui.field>
                </div>
            </section>

            <section class="fm-section">
                <div class="fm-section__head">
                    <h2 class="fm-section__title">Parcel</h2>
                </div>
                <p class="fm-section__note">What Shopee quotes shipping from. Every field is required and every one starts from the catalog's own figures.</p>

                <div class="fm-fields fm-fields--2">
                    <x-ui.field label="Weight in kg" for="ps-weight" name="weight" :required="true">
                        <x-ui.input id="ps-weight" name="weight" type="number" step="0.01" min="0.01"
                                    class="fm-input--num"
                                    value="{{ old('weight', number_format((float) $product->weight, 2, '.', '')) }}"
                                    :disabled="!$canManageShopee" />
                    </x-ui.field>

                    <x-ui.field label="Length in cm" for="ps-length" name="package_length" :required="true">
                        <x-ui.input id="ps-length" name="package_length" type="number" min="1"
                                    class="fm-input--num"
                                    value="{{ old('package_length', max(1, (int) $product->length)) }}"
                                    :disabled="!$canManageShopee" />
                    </x-ui.field>

                    <x-ui.field label="Width in cm" for="ps-width" name="package_width" :required="true">
                        <x-ui.input id="ps-width" name="package_width" type="number" min="1"
                                    class="fm-input--num"
                                    value="{{ old('package_width', max(1, (int) $product->width)) }}"
                                    :disabled="!$canManageShopee" />
                    </x-ui.field>

                    <x-ui.field label="Height in cm" for="ps-height" name="package_height" :required="true">
                        <x-ui.input id="ps-height" name="package_height" type="number" min="1"
                                    class="fm-input--num"
                                    value="{{ old('package_height', max(1, (int) $product->height)) }}"
                                    :disabled="!$canManageShopee" />
                    </x-ui.field>
                </div>
            </section>

            {{-- ── The category's own attribute sheet (#98, form half) ──
                 What Shopee's form would ask for the chosen category, so a
                 FIRST push can answer it here instead of going up bare.
                 Same partial and wire as the listing page and group form;
                 picking a different category reloads it in place. --}}
            <section class="fm-section"
                     data-attributes-fetch
                     data-attributes-url="{{ route('ext.shopee.products.fetchAttributes') }}"
                     data-attributes-field="shopee_category_id"
                     data-attributes-source="#ps-category-value"
                     @if(empty($attrRows) && !$attrTemplate) hidden @endif>
                <div class="fm-section__head">
                    <h2 class="fm-section__title">Category attributes</h2>
                </div>
                <p class="fm-section__note">What Shopee asks for this category. Answers go with the push and are saved on the listing. Required ones block nothing here, but Shopee refuses a push without them.</p>
                <div data-attributes-target>
                    @include('ext-shopee::product-groups._attributes', [
                        'attributes' => $attrRows,
                        'saved' => $savedAttributes,
                        'template' => $attrTemplate,
                        'canManage' => $canManageShopee,
                    ])
                </div>
            </section>

            {{-- ------------------------------------------------------------
                 Images. At least one is required, and every one starts ticked,
                 which is what the operator almost always wants. The tile is
                 the control: a 16px checkbox is not a fair target for a
                 decision about a 120px picture.
                 ------------------------------------------------------------ --}}
            <section class="fm-section">
                <div class="fm-section__head">
                    <h2 class="fm-section__title">Images</h2>
                </div>
                <p class="fm-section__note">Everything ticked is uploaded to Shopee. At least one is required.</p>

                @if($errors->has('selected_images'))
                    <div class="fm-error">{{ $errors->first('selected_images') }}</div>
                @endif

                @if(!empty($images))
                    <div class="cl-images">
                        @foreach($images as $image)
                            <label class="cl-image">
                                <img class="cl-image__thumb" src="{{ asset('storage/' . ltrim($image, '/')) }}"
                                     alt="" loading="lazy" decoding="async">
                                <span class="cl-image__foot">
                                    <input type="checkbox" class="cc-check" name="selected_images[]" value="{{ $image }}"
                                           @checked(is_array($selectedImages) && in_array($image, $selectedImages, true))
                                           @disabled(!$canManageShopee)>
                                    <span class="cl-image__name">{{ basename($image) }}</span>
                                </span>
                            </label>
                        @endforeach
                    </div>
                @else
                    <x-ui.empty title="This product has no images"
                                description="Shopee will not accept a listing without one. Add an image to the catalog product first.">
                        <x-slot:action>
                            <x-ui.button :href="route('products.edit', $product->product_id)">Edit in catalog</x-ui.button>
                        </x-slot:action>
                    </x-ui.empty>
                @endif
            </section>

            {{-- ------------------------------------------------------------
                 Delivery options. Required, and the checked set follows the
                 same three-step precedence it always has.
                 ------------------------------------------------------------ --}}
            <section class="fm-section">
                <div class="fm-section__head">
                    <h2 class="fm-section__title">Delivery options</h2>
                </div>
                <p class="fm-section__note">Which of Shopee's couriers this product is offered on. Pick at least one.@if(!empty($groupName) && !empty($groupLogisticIds)) The ticked ones come from the {{ $groupName }} preset.@endif</p>

                @if($errors->has('logistic_ids'))
                    <div class="fm-error">{{ $errors->first('logistic_ids') }}</div>
                @endif

                @if(!empty($logistics))
                    <div class="gf-checks">
                        @foreach($logistics as $channel)
                            @php
                                $logisticId = (int) ($channel['logistic_id'] ?? 0);
                                $logisticName = (string) ($channel['logistic_name'] ?? 'Channel ' . $logisticId);
                                $enabledAtShopee = (bool) ($channel['enabled'] ?? false);

                                // Old input first (a failed submit), then the
                                // product group's own channels, then whatever
                                // Shopee already has switched on.
                                if (is_array($oldLogisticIds)) {
                                    $isChecked = in_array($logisticId, array_map('intval', $oldLogisticIds), true);
                                } elseif (!empty($groupLogisticIds)) {
                                    $isChecked = in_array($logisticId, $groupLogisticIds, true);
                                } else {
                                    $isChecked = $enabledAtShopee;
                                }
                            @endphp
                            <label class="gf-check">
                                <input type="checkbox" class="cc-check" name="logistic_ids[]" value="{{ $logisticId }}"
                                       @checked($isChecked) @disabled(!$canManageShopee)>
                                <span class="gf-check__name">{{ $logisticName }}</span>
                                <span class="gf-check__id">{{ $logisticId }}</span>
                            </label>
                        @endforeach
                    </div>
                @else
                    <x-ui.empty title="No delivery options cached"
                                description="Fetch them from the Logistics page first, then come back.">
                        <x-slot:action>
                            <x-ui.button :href="route('ext.shopee.logistics.index')">Go to Logistics</x-ui.button>
                        </x-slot:action>
                    </x-ui.empty>
                @endif
            </section>
        </div>

        <div class="fm-col fm-col--side">
            <div class="fm-card">
                <div class="fm-card__head"><h2 class="fm-card__title">Shopee category</h2></div>
                <div class="fm-card__body">
                    <x-ui.field label="Category" for="ps-category" name="category_id" :required="true"
                                :hint="!empty($groupName) && !empty($groupCategoryId)
                                    ? 'Pre-selected from the ' . $groupName . ' group.'
                                    : 'Only leaf categories are listed. Shopee accepts nothing else.'">
                        {{-- The group form's searchable combobox instead of
                             a 5,000-option select (the owner, 2026-08-24). The
                             endpoints live on the products controller so
                             they answer to this page's own permission key. --}}
                        <div class="gf-combo"
                             data-category-combo
                             data-combo-mode="remote"
                             data-combo-search-url="{{ route('ext.shopee.products.searchCategories') }}"
                             data-combo-lookup-url="{{ route('ext.shopee.products.categoryLookup') }}">
                            <div class="gf-combo__field">
                                <input type="hidden" id="ps-category-value" name="category_id" data-combo-value
                                       value="{{ $selectedCategoryId > 0 ? $selectedCategoryId : '' }}">
                                <x-ui.input id="ps-category" type="search" autocomplete="off"
                                            data-combo-input
                                            placeholder="Type to search Shopee categories"
                                            :disabled="!$canManageShopee" />
                                <div class="gf-combo__results" data-combo-results role="listbox"
                                     aria-label="Shopee leaf categories" hidden></div>
                            </div>
                        </div>
                    </x-ui.field>
                </div>
            </div>

            <div class="fm-card">
                <div class="fm-card__head"><h2 class="fm-card__title">Brand</h2></div>
                <div class="fm-card__body">
                    {{-- Not a control. The brand is fixed at No brand server
                         side on this route, so it is stated rather than drawn
                         as a disabled field pretending to be one. --}}
                    <p class="fm-section__note">Shopee lists this as <strong>No brand</strong>. Change it in Shopee's own back office after the push, or set a brand on the preset and push from there instead.</p>
                </div>
            </div>

        </div>
    </div>

    <div class="fm-bar">
        <span class="fm-bar__meta">{{ $canManageShopee
            ? 'This creates the listing on Shopee. It can take a moment.'
            : 'You can read this. Pushing to Shopee needs the Shopee manage permission.' }}</span>
        <div class="fm-bar__actions">
            <a class="fm-cancel" href="{{ route('ext.shopee.products.index') }}">{{ $canManageShopee ? 'Cancel' : 'Back' }}</a>
            @if($canManageShopee)
                <x-ui.button type="submit" variant="primary">Push to Shopee</x-ui.button>
            @endif
        </div>
    </div>
</form>
@endsection
