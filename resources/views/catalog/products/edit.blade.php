@extends('layouts.blotter')
@section('title', 'Edit ' . ($product->name ?: 'product'))
@section('breadcrumb', $product->name ?: ('Product ' . $product->product_id))

@section('content')
@php
    $backUrl = \App\Support\BackTo::safe(old('_return', $returnUrl ?? null), route('products.index'));

    $trim = function ($v) {
        $s = (string) ($v ?? '0');
        if (!str_contains($s, '.')) return $s === '' ? '0' : $s;
        $s = rtrim(rtrim($s, '0'), '.');
        return ($s === '' || $s === '-') ? '0' : $s;
    };


    $__productActions = app(\App\Integrations\IntegrationRegistry::class)
        ->productActions((int) $product->product_id);
    $__user = auth()->user();

    $__inventoryBreakdown = null;
    foreach (app(\App\Integrations\IntegrationRegistry::class)->productInventoryDetailContributors() as $__c) {
        $__line = $__c->inventoryBreakdownFor((int) $product->product_id);
        if ($__line !== null) { $__inventoryBreakdown = $__line; break; }
    }
@endphp

<form method="POST" action="{{ route('products.update', $product->product_id) }}" class="fm-page"
      data-product-form
      data-manufacturer-store-url="{{ route('api.catalog.manufacturers.store') }}">
    @csrf
    @method('PUT')
    <input type="hidden" name="_return" value="{{ $backUrl }}">

    <div class="fm-flash" role="status" aria-live="polite">
        @if(session('status'))
            <div class="fm-note fm-note--ok"><div class="fm-note__body">{{ session('status') }}</div></div>
        @endif
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
            <a class="fm-back" href="{{ $backUrl }}">
                <x-ui.icon name="chevron-left" size="14" /> Products
            </a>
            <h1 class="fm-title">{{ $product->name ?: 'Untitled product' }}</h1>
            <div class="fm-stamps">
                <span class="fm-stamp">
                    <span class="fm-stamp__k">ID</span>
                    <span class="fm-stamp__v">{{ $product->product_id }}</span>
                </span>
                @if($product->sku)
                    <span class="fm-stamp">
                        <span class="fm-stamp__k">SKU</span>
                        <span class="fm-stamp__v">{{ $product->sku }}</span>
                    </span>
                @endif
                @if($product->date_added)
                    <span class="fm-stamp">
                        <span class="fm-stamp__k">Added</span>
                        <span class="fm-stamp__v">{{ \Carbon\Carbon::parse($product->date_added)->format('Y-m-d') }}</span>
                    </span>
                @endif
            </div>
        </div>

        <div class="fm-head__actions">
            <x-ui.menu label="Product {{ $product->product_id }} actions">
                <a class="x-menu__item" href="{{ route('products.sales', $product->product_id) }}">Sales history</a>
                <a class="x-menu__item" href="{{ route('products.stock_history', $product->product_id) }}">Stock history</a>
                @foreach($__productActions as $__action)
                    @php
                        $__perm = $__action['permission'] ?? null;
                        $__visible = !$__perm || ($__user && $__user->hasPermission($__perm));
                    @endphp
                    @if($__visible)
                        <a class="x-menu__item" href="{{ $__action['url'] }}">{{ $__action['label'] }}</a>
                    @endif
                @endforeach
                <div class="x-menu__sep"></div>
                <button type="button" class="x-menu__item x-menu__item--danger"
                        data-confirm="Delete {{ $product->name }}? This cannot be undone.{{ ($channelNote ?? '') !== '' ? ' ' . $channelNote : '' }}"
                        data-confirm-submit="product-delete-form">Delete product</button>
            </x-ui.menu>
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
                                    value="{{ old('manufacturer_name', $manufacturerName ?? '') }}" />
                        <input type="hidden" name="manufacturer_id" id="manufacturer_id" value="{{ old('manufacturer_id', $product->manufacturer_id) }}">
                        <div class="fm-ta__list" id="manufacturer_list"></div>
                        </div>
                    </x-ui.field>

                    <x-ui.field label="Name" for="product_name" name="name" :required="true" wide>
                        <div class="fm-group">
                            <x-ui.input id="product_name" name="name"
                                        value="{{ old('name', html_entity_decode($product->name ?? '')) }}" />
                        </div>
                    </x-ui.field>

                    <x-ui.field label="SKU" name="sku" :required="true"
                                hint="How imports, stock pushes and link repair recognise this product.">
                        <x-ui.input id="f-sku" name="sku" class="fm-input--num" value="{{ old('sku', $product->sku) }}" required />
                    </x-ui.field>

                    <x-ui.field label="Categories" for="category_search" name="category_ids"
                                hint="Pick as many as apply."
                                wide>
                        <div id="category_tags" class="fm-tags">
                            @foreach(old('category_ids', collect($currentCategories ?? [])->pluck('id')->all()) as $catId)
                                @php
                                    $catName = collect($currentCategories ?? [])->firstWhere('id', $catId)['name'] ?? 'Category #'.$catId;
                                @endphp
                                <span class="fm-tag" data-id="{{ $catId }}">
                                    <span>{{ $catName }}</span>
                                    <input type="hidden" name="category_ids[]" value="{{ $catId }}">
                                    <button type="button" class="fm-tag__x" aria-label="Remove {{ $catName }}"
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
                        <x-ui.textarea id="f-description" name="description" class="wysiwyg">{{ old('description', $product->description) }}</x-ui.textarea>
                    </x-ui.field>
                </div>
            </section>

            <section class="fm-section" aria-labelledby="sec-pricing">
                <div class="fm-section__head">
                    <h2 class="fm-section__title" id="sec-pricing">Pricing</h2>
                    <div class="fm-section__aside">
                        <x-ui.button type="button" size="sm" id="cost-override-btn">Edit by hand</x-ui.button>
                    </div>
                </div>
                <p class="fm-section__note" id="cost-lock-note">Set when a purchase order is received.</p>

                <div class="fm-fields">
                    <x-ui.field label="Selling price" for="product_price" name="price" :required="true">
                        <x-ui.input id="product_price" name="price" class="fm-input--num" value="{{ old('price', $trim($product->price)) }}" />
                    </x-ui.field>
                </div>

                <div class="fm-cost">
                    <div class="fm-cost__cell">
                        <x-ui.field label="Cost" for="cost_amount" name="cost_amount" hint="A fixed amount per unit.">
                            <x-ui.input id="cost_amount" name="cost_amount" class="fm-input--num"
                                        value="{{ old('cost_amount', $trim($product->cost_amount ?? 0)) }}" readonly tabindex="-1" />
                        </x-ui.field>
                    </div>
                    <div class="fm-cost__op">plus</div>
                    <div class="fm-cost__cell">
                        <x-ui.field label="Cost %" for="cost_percentage" name="cost_percentage" hint="Share of the selling price.">
                            <x-ui.input id="cost_percentage" name="cost_percentage" class="fm-input--num"
                                        value="{{ old('cost_percentage', $trim($product->cost_percentage ?? 0)) }}" readonly tabindex="-1" />
                        </x-ui.field>
                    </div>
                    <div class="fm-cost__op">plus</div>
                    <div class="fm-cost__cell">
                        <x-ui.field label="Additional cost" for="cost_additional" name="cost_additional" hint="Supplier shipping and the like.">
                            <x-ui.input id="cost_additional" name="cost_additional" class="fm-input--num"
                                        value="{{ old('cost_additional', $trim($product->cost_additional ?? 0)) }}" readonly tabindex="-1" />
                        </x-ui.field>
                    </div>
                    <div class="fm-cost__op">equals</div>
                    <div class="fm-cost__cell">
                        <x-ui.field label="Unit cost" for="product_cost" name="cost" hint="Worked out for you when you save.">
                            <x-ui.input id="product_cost" name="cost" class="fm-input--num"
                                        value="{{ old('cost', $trim($product->cost ?? 0)) }}" readonly tabindex="-1" />
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

            @include('catalog.products.partials.image_manager', ['pimToken' => '', 'pimProductId' => (int) $product->product_id])

            @include('catalog.products.partials.video_manager', ['pimToken' => '', 'pimProductId' => (int) $product->product_id])

            <section class="fm-section" aria-labelledby="sec-variations">
                <div class="fm-section__head">
                    <h2 class="fm-section__title" id="sec-variations">Variations</h2>
                </div>
                <p class="fm-section__note">With variations, this product's quantity is the total of its variation quantities. A variation can go negative if it is oversold. Every variation needs its own SKU.</p>

                @include('catalog.products.partials.options_fields')
            </section>
        </div>

        <div class="fm-col fm-col--side">

            <div class="fm-card">
                <div class="fm-card__head"><h2 class="fm-card__title">Status</h2></div>
                <div class="fm-card__body">
                    @php $statusOn = old('status', (string) $product->status) === '1'; @endphp
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
                <div class="fm-card__head">
                    <h2 class="fm-card__title">Inventory</h2>
                    <a class="fm-cancel" href="{{ route('products.stock_history', $product->product_id) }}">History</a>
                </div>
                <div class="fm-card__body">
                    <div class="fm-fields fm-fields--2">
                        <x-ui.field label="Quantity" for="product_quantity" name="quantity">
                            <x-ui.input id="product_quantity" name="quantity" class="fm-input--num" value="{{ old('quantity', $product->quantity) }}" />
                        </x-ui.field>

                        <x-ui.field label="Reorder level" name="reorder_level">
                            <x-ui.input id="f-reorder-level" type="number" name="reorder_level" class="fm-input--num"
                                        value="{{ old('reorder_level', $product->reorder_level ?? 0) }}" min="0" />
                        </x-ui.field>
                    </div>
                    <div class="fm-hint d-none" id="qty_hint">Worked out from the variation quantities below.</div>
                    @if($__inventoryBreakdown !== null)
                        <div class="fm-hint">{{ $__inventoryBreakdown }}</div>
                    @endif
                    <div class="fm-hint">Reorder level 0 turns the low-stock alert off.</div>
                </div>
            </div>

            @php
                $parcelIncomplete = (float) ($product->weight ?? 0) <= 0
                    || (float) ($product->length ?? 0) <= 0
                    || (float) ($product->width ?? 0) <= 0
                    || (float) ($product->height ?? 0) <= 0;
            @endphp
            <div class="fm-card">
                <div class="fm-card__head"><h2 class="fm-card__title">Shipping</h2></div>
                <div class="fm-card__body">
                    @if($parcelIncomplete)
                        <div class="fm-note fm-note--warn" role="status">
                            <div class="fm-note__body">This product is missing its weight or size. Fill these four fields and the channel pages stop flagging it.</div>
                        </div>
                    @endif
                    <div class="fm-fields fm-fields--2">
                        <x-ui.field label="Weight" name="weight" :required="true">
                            <x-ui.input id="f-weight" name="weight" class="fm-input--num" value="{{ old('weight', $trim($product->weight ?? '0')) }}" placeholder="0.5" />
                        </x-ui.field>
                        <x-ui.field label="Length" name="length" :required="true">
                            <x-ui.input id="f-length" name="length" class="fm-input--num" value="{{ old('length', $trim($product->length ?? '0')) }}" placeholder="10" />
                        </x-ui.field>
                        <x-ui.field label="Width" name="width" :required="true">
                            <x-ui.input id="f-width" name="width" class="fm-input--num" value="{{ old('width', $trim($product->width ?? '0')) }}" placeholder="5" />
                        </x-ui.field>
                        <x-ui.field label="Height" name="height" :required="true">
                            <x-ui.input id="f-height" name="height" class="fm-input--num" value="{{ old('height', $trim($product->height ?? '0')) }}" placeholder="3" />
                        </x-ui.field>
                    </div>
                    <div class="fm-hint">Weight and size are set once, here, and ride every listing.</div>
                </div>
            </div>
        </div>
    </div>

    <x-ui.form-bar :cancel="$backUrl">
        <x-slot:note>Product {{ $product->product_id }}</x-slot:note>
        <x-slot:primary><x-ui.button type="submit" variant="primary">Save changes</x-ui.button></x-slot:primary>
    </x-ui.form-bar>
</form>

<form id="product-delete-form" method="POST" action="{{ route('products.destroy', $product->product_id) }}" class="x-sr">
    @csrf
    @method('DELETE')
</form>

@include('catalog.products.partials.wysiwyg')
@endsection
