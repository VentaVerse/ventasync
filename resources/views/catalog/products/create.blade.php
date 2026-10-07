@extends('layouts.blotter')
@section('title', 'New product')

@section('content')
@php
    $pimToken = (string) \Illuminate\Support\Str::uuid();
@endphp

<form method="POST" action="{{ route('products.store') }}" class="fm-page"
      data-product-form
      data-manufacturer-store-url="{{ route('api.catalog.manufacturers.store') }}">
    @csrf

    <div class="fm-flash" role="status" aria-live="polite">
        @if(session('error'))
            <div class="fm-note fm-note--fail"><div class="fm-note__body">{{ session('error') }}</div></div>
        @endif
        @if($errors->any())
            <div class="fm-note fm-note--fail">
                <div class="fm-note__body">Nothing was saved. Check the fields marked below.</div>
            </div>
        @endif
    </div>

    <div class="fm-head">
        <div class="fm-head__main">
            <a class="fm-back" href="{{ route('products.index') }}">
                <x-ui.icon name="chevron-left" size="14" /> Products
            </a>
            <h1 class="fm-title">New product</h1>
        </div>
    </div>

    <div class="fm-body">
        <div class="fm-col fm-col--main">

            <section class="fm-section" aria-labelledby="sec-general">
                <div class="fm-section__head">
                    <h2 class="fm-section__title" id="sec-general">General</h2>
                </div>

                <div class="fm-fields">
                    <x-ui.field label="Manufacturer" for="manufacturer_search" name="manufacturer_name"
                                hint="Type to search, then pick from the list. Used on marketplace listings."
                                wide>
                        <div class="fm-ta">
                        <x-ui.input id="manufacturer_search" name="manufacturer_name" autocomplete="off"
                                    placeholder="Search manufacturers"
                                    value="{{ old('manufacturer_name', '') }}" />
                        <input type="hidden" name="manufacturer_id" id="manufacturer_id" value="{{ old('manufacturer_id', 0) }}">
                        <div class="fm-ta__list" id="manufacturer_list"></div>
                        </div>
                    </x-ui.field>

                    <x-ui.field label="Name" for="product_name" name="name" :required="true" wide>
                        <div class="fm-group">
                            <x-ui.input id="product_name" name="name" value="{{ old('name') }}" />
                        </div>
                    </x-ui.field>

                    <x-ui.field label="SKU" name="sku" :required="true"
                                hint="How imports, stock pushes and link repair recognise this product.">
                        <x-ui.input id="f-sku" name="sku" class="fm-input--num" value="{{ old('sku') }}" required />
                    </x-ui.field>

                    <x-ui.field label="Categories" for="category_search" name="category_ids"
                                hint="Pick as many as apply."
                                wide>
                        <div id="category_tags" class="fm-tags">
                            @foreach(old('category_ids', []) as $catId)
                                <span class="fm-tag" data-id="{{ $catId }}">
                                    <span>Category #{{ $catId }}</span>
                                    <input type="hidden" name="category_ids[]" value="{{ $catId }}">
                                    <button type="button" class="fm-tag__x" aria-label="Remove category {{ $catId }}"
                                            onclick="this.parentElement.remove();">&times;</button>
                                </span>
                            @endforeach
                        </div>
                        <div class="fm-ta">
                        <x-ui.input id="category_search" autocomplete="off" placeholder="Search categories" />
                        <div class="fm-ta__list" id="category_list"></div>
                        </div>
                    </x-ui.field>

                    <x-ui.field label="Description" name="description" wide
                                hint="Optional here. A marketplace with a minimum of its own says so when the listing is pushed.">
                        {{-- Escaped, not raw: a description containing </textarea> would break out of the control. --}}
                        <x-ui.textarea id="f-description" name="description" class="wysiwyg">{{ old('description', '') }}</x-ui.textarea>
                    </x-ui.field>
                </div>
            </section>

            <section class="fm-section" aria-labelledby="sec-pricing">
                <div class="fm-section__head">
                    <h2 class="fm-section__title" id="sec-pricing">Pricing</h2>
                </div>

                <div class="fm-fields">
                    <x-ui.field label="Selling price" for="product_price" name="price" :required="true">
                        <x-ui.input id="product_price" name="price" class="fm-input--num" value="{{ old('price', '0') }}" />
                    </x-ui.field>
                </div>

                <div class="fm-cost">
                    <div class="fm-cost__cell">
                        <x-ui.field label="Cost" for="cost_amount" name="cost_amount" hint="A fixed amount per unit.">
                            <x-ui.input id="cost_amount" name="cost_amount" class="fm-input--num" value="{{ old('cost_amount', '0') }}" />
                        </x-ui.field>
                    </div>
                    <div class="fm-cost__op">plus</div>
                    <div class="fm-cost__cell">
                        <x-ui.field label="Cost %" for="cost_percentage" name="cost_percentage" hint="Share of the selling price.">
                            <x-ui.input id="cost_percentage" name="cost_percentage" class="fm-input--num" value="{{ old('cost_percentage', '0') }}" />
                        </x-ui.field>
                    </div>
                    <div class="fm-cost__op">plus</div>
                    <div class="fm-cost__cell">
                        <x-ui.field label="Additional cost" for="cost_additional" name="cost_additional" hint="Supplier shipping and the like.">
                            <x-ui.input id="cost_additional" name="cost_additional" class="fm-input--num" value="{{ old('cost_additional', '0') }}" />
                        </x-ui.field>
                    </div>
                    <div class="fm-cost__op">equals</div>
                    <div class="fm-cost__cell">
                        <x-ui.field label="Unit cost" for="product_cost" name="cost" hint="Worked out for you when you save.">
                            <x-ui.input id="product_cost" name="cost" class="fm-input--num"
                                        value="{{ old('cost', '0') }}" readonly tabindex="-1" />
                        </x-ui.field>
                    </div>
                </div>

                <div class="fm-metrics">
                    <div class="fm-metric">
                        <span class="fm-metric__k">Profit</span>
                        <span class="fm-metric__v" id="calc_profit">0.00</span>
                    </div>
                    <div class="fm-metric">
                        <span class="fm-metric__k">Margin</span>
                        <span class="fm-metric__v" id="calc_margin">0.00%</span>
                    </div>
                    <div class="fm-metric">
                        <span class="fm-metric__k">Markup</span>
                        <span class="fm-metric__v" id="calc_markup">0.00%</span>
                    </div>
                </div>
            </section>

            @include('catalog.products.partials.image_manager', ['pimToken' => $pimToken, 'pimProductId' => 0])

            @include('catalog.products.partials.video_manager', ['pimToken' => $pimToken, 'pimProductId' => 0, 'productVideo' => null])

            <section class="fm-section" aria-labelledby="sec-variations">
                <div class="fm-section__head">
                    <h2 class="fm-section__title" id="sec-variations">Variations</h2>
                </div>
                <p class="fm-section__note">Add a variation and this product's quantity becomes the total of its variation quantities. Every variation needs its own SKU.</p>

                @include('catalog.products.partials.options_fields')
            </section>
        </div>

        <div class="fm-col fm-col--side">

            <div class="fm-card">
                <div class="fm-card__head"><h2 class="fm-card__title">Status</h2></div>
                <div class="fm-card__body">
                    @php $statusOn = old('status', '1') === '1'; @endphp
                    <input type="hidden" name="status" value="0">
                    <label class="fm-switch">
                        <input type="checkbox" class="fm-switch__input" name="status" value="1" @checked($statusOn)>
                        <span class="fm-switch__track"></span>
                        <span class="fm-switch__text">
                            <span class="fm-switch__label">Enabled</span>
                            <span class="fm-switch__note">Off keeps it out of the storefront and marketplace listings.</span>
                        </span>
                    </label>
                    @error('status')<div class="fm-error">{{ $message }}</div>@enderror
                </div>
            </div>

            <div class="fm-card">
                <div class="fm-card__head"><h2 class="fm-card__title">Inventory</h2></div>
                <div class="fm-card__body">
                    <div class="fm-fields fm-fields--2">
                        <x-ui.field label="Quantity" for="product_quantity" name="quantity">
                            <x-ui.input id="product_quantity" name="quantity" class="fm-input--num" value="{{ old('quantity', '0') }}" />
                        </x-ui.field>

                        <x-ui.field label="Reorder level" name="reorder_level">
                            <x-ui.input id="f-reorder-level" type="number" name="reorder_level" class="fm-input--num"
                                        value="{{ old('reorder_level', 0) }}" min="0" />
                        </x-ui.field>
                    </div>
                    <div class="fm-hint d-none" id="qty_hint">Worked out from the variation quantities below.</div>
                    <div class="fm-hint">Reorder level 0 turns the low-stock alert off.</div>
                </div>
            </div>

            <div class="fm-card">
                <div class="fm-card__head"><h2 class="fm-card__title">Shipping</h2></div>
                <div class="fm-card__body">
                    <div class="fm-fields fm-fields--2">
                        <x-ui.field label="Weight" name="weight" :required="true">
                            <x-ui.input id="f-weight" name="weight" class="fm-input--num" value="{{ old('weight') }}" placeholder="0.5" required />
                        </x-ui.field>
                        <x-ui.field label="Length" name="length" :required="true">
                            <x-ui.input id="f-length" name="length" class="fm-input--num" value="{{ old('length') }}" placeholder="10" required />
                        </x-ui.field>
                        <x-ui.field label="Width" name="width" :required="true">
                            <x-ui.input id="f-width" name="width" class="fm-input--num" value="{{ old('width') }}" placeholder="5" required />
                        </x-ui.field>
                        <x-ui.field label="Height" name="height" :required="true">
                            <x-ui.input id="f-height" name="height" class="fm-input--num" value="{{ old('height') }}" placeholder="3" required />
                        </x-ui.field>
                    </div>
                    <div class="fm-hint">Weight and size are set once, here, and ride every listing.</div>
                </div>
            </div>
        </div>
    </div>

    <x-ui.form-bar :cancel="route('products.index')">
        <x-slot:note>Nothing is saved until you create the product.</x-slot:note>
        <x-slot:primary><x-ui.button type="submit" variant="primary">Create product</x-ui.button></x-slot:primary>
    </x-ui.form-bar>
</form>

@include('catalog.products.partials.wysiwyg')
@endsection
