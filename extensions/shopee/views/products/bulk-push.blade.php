@extends('layouts.channel')
@section('title', 'Push ' . count($rows) . ' products to Shopee')
@section('breadcrumb', 'Push to Shopee')
@section('own-errors', true)

{{-- The combined review form for a multi-select push (#151). One page for
     the selection, reviewing only what the products can share: couriers,
     category and the price rule. Content stays each listing's own. Blank
     means "each listing's own"; a value given here rides the whole batch
     the way a group's settings ride a group push, and is recorded as what
     was sent, never written onto the listings. --}}
@section('content')
@php
    $canManageShopee = auth()->user()?->hasPermission('manage_shopee/product') ?? false;
    $unready = array_values(array_filter($rows, fn ($r) => !$r['ready']));
    $toCreate = count(array_filter($rows, fn ($r) => !$r['listed']));
    $toUpdate = count($rows) - $toCreate;
@endphp

<form method="POST" class="fm-page" action="{{ route('ext.shopee.products.bulk_push') }}" data-slow-action>
    @csrf
    @foreach($rows as $row)
        <input type="hidden" name="product_ids[]" value="{{ $row['product_id'] }}">
    @endforeach

    <div class="fm-flash" role="status" aria-live="polite">
        @if($errors->any())
            <div class="fm-note fm-note--fail"><div class="fm-note__body">Nothing was sent to Shopee. Check the fields marked below.</div></div>
        @endif
        @if(!empty($unready))
            <div class="fm-note fm-note--fail">
                <div class="fm-note__body">
                    {{ count($unready) }} of {{ count($rows) }} {{ count($unready) === 1 ? 'is' : 'are' }} not ready, so nothing will be pushed until {{ count($unready) === 1 ? 'it is' : 'they are' }}. A category or couriers given below count for every product here; the rest is on each product's listing page.
                </div>
            </div>
        @endif
    </div>

    <div class="fm-head">
        <h1 class="x-page-title">Push {{ count($rows) }} {{ count($rows) === 1 ? 'product' : 'products' }} to Shopee</h1>
        <p class="x-page-sub">{{ $toCreate }} to create{{ $toUpdate ? ', ' . $toUpdate . ' already listed and updated in place' : '' }}. What is shared is reviewed once here; title, description, images and attributes stay each listing's own.</p>
    </div>

    <div class="fm-cols">
        <div class="fm-col fm-col--main">
            <section class="fm-section">
                <div class="fm-section__head"><h2 class="fm-section__title">The selection</h2></div>
                <x-ui.table>
                    <x-slot:head>
                        <tr>
                            <th scope="col">Product</th>
                            <th scope="col" class="x-td-num">Catalog price</th>
                            <th scope="col">Its own settings</th>
                            <th scope="col">Goes up as</th>
                        </tr>
                    </x-slot:head>
                    @foreach($rows as $row)
                        <tr>
                            <td data-label="Product">
                                <div class="co-item">
                                    <div class="order-img-wrap">
                                        @if($row['image'])<img class="order-img" src="{{ $row['image'] }}" alt="">@else<span class="co-item__none">No image</span>@endif
                                    </div>
                                    <div class="co-item__body">
                                        <span class="co-item__name">{{ $row['name'] !== '' ? $row['name'] : 'Unnamed product' }}</span>
                                        <div class="co-item__meta"><span class="co-item__sku">{{ $row['sku'] }}</span></div>
                                    </div>
                                </div>
                            </td>
                            <td class="x-td-num" data-label="Catalog price"><x-money :php="$row['price']" /></td>
                            <td data-label="Its own settings">
                                <span class="x-cell-muted">
                                    {{ $row['category_id'] ? 'Category ' . $row['category_id'] : 'No category' }}
                                    &middot; {{ count($row['logistic_ids']) }} {{ count($row['logistic_ids']) === 1 ? 'courier' : 'couriers' }}
                                    &middot; {{ ($row['markup_percent'] || $row['markup_fixed']) ? ((float) $row['markup_percent'] . '% + ' . (float) $row['markup_fixed']) : 'catalog price' }}
                                </span>
                            </td>
                            <td data-label="Goes up as">
                                @if($row['listed'])
                                    <x-ui.badge tone="info">Content update</x-ui.badge>
                                @elseif($row['ready'])
                                    <x-ui.badge tone="success">New listing</x-ui.badge>
                                @else
                                    <x-ui.badge tone="danger">Not ready</x-ui.badge>
                                    <div class="x-cell-muted">needs {{ implode(', ', $row['missing']) }}</div>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </x-ui.table>
            </section>

            <section class="fm-section">
                <div class="fm-section__head"><h2 class="fm-section__title">Delivery options for the batch</h2></div>
                <p class="fm-section__note">Tick couriers to offer every product here on these instead of its own. Leave all unticked to keep each listing's own couriers.</p>
                @if($errors->has('shared_logistic_ids'))<div class="fm-error">{{ $errors->first('shared_logistic_ids') }}</div>@endif
                @if(!empty($logistics))
                    <div class="gf-checks">
                        @foreach($logistics as $channel)
                            @php
                                $logisticId = (int) ($channel['logistic_id'] ?? 0);
                                $logisticName = (string) ($channel['logistic_name'] ?? 'Channel ' . $logisticId);
                                $enabledAtShopee = (bool) ($channel['enabled'] ?? false);
                            @endphp
                            <label class="gf-check {{ $enabledAtShopee ? '' : 'gf-check--off' }}">
                                <input type="checkbox" class="cc-check" name="shared_logistic_ids[]" value="{{ $logisticId }}"
                                       @checked(in_array((string) $logisticId, (array) old('shared_logistic_ids', []), true))
                                       @disabled(!$canManageShopee)>
                                <span class="gf-check__label">{{ $logisticName }}</span>
                                @unless($enabledAtShopee)<span class="gf-check__note">off at Shopee</span>@endunless
                            </label>
                        @endforeach
                    </div>
                @else
                    <p class="fm-section__note">No couriers on file yet. Each listing's own couriers are used.</p>
                @endif
            </section>
        </div>

        <div class="fm-col fm-col--side">
            <div class="fm-card">
                <div class="fm-card__head"><h2 class="fm-card__title">Category for the batch</h2></div>
                <div class="fm-card__body">
                    <x-ui.field label="Shopee category" for="bp-category" name="shared_category_id"
                                hint="Leave blank to keep each listing's own. Only leaf categories are listed.">
                        <div class="gf-combo" data-category-combo data-combo-mode="remote"
                             data-combo-search-url="{{ route('ext.shopee.products.searchCategories') }}"
                             data-combo-lookup-url="{{ route('ext.shopee.products.categoryLookup') }}">
                            <div class="gf-combo__field">
                                <input type="hidden" id="bp-category-value" name="shared_category_id" data-combo-value value="{{ old('shared_category_id') }}">
                                <x-ui.input id="bp-category" type="search" autocomplete="off" data-combo-input
                                            placeholder="Type to search Shopee categories" :disabled="!$canManageShopee" />
                                <div class="gf-combo__results" data-combo-results role="listbox" aria-label="Shopee leaf categories" hidden></div>
                            </div>
                        </div>
                    </x-ui.field>
                </div>
            </div>

            <div class="fm-card">
                <div class="fm-card__head"><h2 class="fm-card__title">Price rule for the batch</h2></div>
                <div class="fm-card__body">
                    <p class="fm-section__note">Over each product's catalog price. Leave both blank to keep each listing's own rule.</p>
                    <div class="fm-fields fm-fields--2">
                        <x-ui.field label="Percent added" for="bp-markup-pct" name="shared_markup_percent">
                            <x-ui.input id="bp-markup-pct" name="shared_markup_percent" type="number" step="0.01" min="-100" max="1000"
                                        class="fm-input--num" placeholder="own" :value="old('shared_markup_percent')" :disabled="!$canManageShopee" />
                        </x-ui.field>
                        <x-ui.field label="Fixed amount added" for="bp-markup-fixed" name="shared_markup_fixed">
                            <x-ui.input id="bp-markup-fixed" name="shared_markup_fixed" type="number" step="0.01"
                                        class="fm-input--num" placeholder="own" :value="old('shared_markup_fixed')" :disabled="!$canManageShopee" />
                        </x-ui.field>
                    </div>
                </div>
            </div>

            <div class="fm-card">
                <div class="fm-card__head"><h2 class="fm-card__title">Brand</h2></div>
                <div class="fm-card__body">
                    <p class="fm-section__note">Each listing's own brand, as saved on its listing page. A brand is not set for a batch.</p>
                </div>
            </div>
        </div>
    </div>

    <div class="fm-bar">
        <span class="fm-bar__meta">Nothing is written onto the listings: what you set here is sent with this push and recorded as sent.</span>
        <div class="fm-bar__actions">
            <a class="fm-cancel" href="{{ route('ext.shopee.products.index') }}">Cancel</a>
            @if($canManageShopee)
                {{-- Never disabled: a category or couriers stated above can
                     make an unready product ready, and the push itself
                     refuses, naming what is still missing, if not. --}}
                <x-ui.button type="submit" variant="primary">Push {{ count($rows) }} to Shopee</x-ui.button>
            @endif
        </div>
    </div>
</form>
@endsection
