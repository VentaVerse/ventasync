@extends('layouts.channel')
@section('title', $setting->store_name . ' Listing')
@section('breadcrumb', ($product->name ?? '') !== '' ? $product->name : 'Listing')

@section('own-errors', true)

@section('content')
@php
    $canManage = auth()->user()?->hasPermission('manage_ventacart/listing') ?? false;
    $storeName = $setting->store_name;
    $onStore = $link && $link->ventacart_product_id;

    $statusWords = [
        \Extensions\ventacart\Models\VentaCartListing::STATUS_ACTIVE => ['Active', 'success'],
        \Extensions\ventacart\Models\VentaCartListing::STATUS_INACTIVE => ['Unlisted', 'warning'],
        \Extensions\ventacart\Models\VentaCartListing::STATUS_MISSING => ['Not on the store', 'neutral'],
    ];
    [$statusLabel, $statusTone] = $live && $live['status'] ? ($statusWords[$live['status']] ?? [ucfirst($live['status']), 'neutral']) : ['Unknown', 'neutral'];

    $corePrice = (float) $product->price;
    $coreDescription = (string) ($product->description ?? '');
    $noSku = $sku === '';
@endphp

<div class="fm-page">

    <div class="fm-flash" role="status" aria-live="polite">
        @if($errors->any())
            <div class="fm-note fm-note--fail">
                <div class="fm-note__body">Nothing was saved. Check the fields marked below.</div>
            </div>
        @endif
    </div>

    <div class="fm-head">
        <div class="fm-head__main">
            <a class="fm-back" href="{{ \App\Support\BackTo::safe(request('back'), route('ext.ventacart.listings.index', $setting->id)) }}" data-guard-leave>
                <x-ui.icon name="chevron-left" size="14" /> Listings
            </a>
            <h1 class="fm-title">{{ ($product->name ?? '') !== '' ? $product->name : 'Product ' . $product->product_id }}</h1>
            <div class="fm-stamps">
                <span class="fm-stamp"><span class="fm-stamp__k">Store</span><span class="fm-stamp__v">{{ $storeName }}</span></span>
                <span class="fm-stamp"><span class="fm-stamp__k">SKU</span><span class="fm-stamp__v">{{ $noSku ? 'none' : $sku }}</span></span>
                @if($onStore)
                    <span class="fm-stamp"><span class="fm-stamp__k">Store product ID</span><span class="fm-stamp__v">{{ $link->ventacart_product_id }}</span></span>
                @endif
                @php $catalogChange = ($listing && $listing->exists) ? $listing->catalogChange() : []; @endphp
                @if($catalogChange)
                    @include('partials.catalog-change-button')
                @endif
            </div>
        </div>
    </div>

    @php
        $found = $onStore && $live && $live['state'] === 'found';

        $stripFigures = [];
        if ($found) {
            $stripFigures[] = ['k' => 'Price on ' . $storeName, 'v' => $live['price'] !== null ? \App\Support\Money::base((float) $live['price']) : 'none'];
            $stripFigures[] = ['k' => 'Stock on ' . $storeName, 'v' => (string) ($live['quantity'] ?? 'none')];
            $stripFigures[] = ['k' => 'Images on ' . $storeName, 'v' => (string) $live['images']];
            if (($live['name'] ?? '') !== '' && $live['name'] !== $product->name) {
                $stripFigures[] = ['k' => 'Name on ' . $storeName, 'v' => \Illuminate\Support\Str::limit($live['name'], 40)];
            }
        }

        $stripState = $onStore
            ? ['label' => $found ? ($statusLabel ?? 'Unknown') : (!$setting->enabled ? 'Store turned off' : ($liveError ? $storeName . ' did not answer' : 'Not found on ' . $storeName)),
               'tone' => $found ? ($statusTone ?? 'neutral') : ($liveError ? 'danger' : 'warning')]
            : ['label' => 'Not on ' . $storeName, 'tone' => 'neutral'];

        $vtPushedAt = $listing?->last_pushed_at;
        $vtSource = (string) ($listing->last_push_source ?? '');
        $stripLastPush = $vtPushedAt
            ? ['when' => $vtPushedAt->diffForHumans(), 'from' => match (true) {
                $vtSource === 'listing' => 'this listing',
                str_starts_with($vtSource, 'group:') => 'the ' . substr($vtSource, 6) . ' group',
                default => null,
            }]
            : null;
    @endphp

    <x-channel.listing-strip :channel="$storeName" :health="false" :linked="(bool) $onStore">
        @if($canManage && $setting->enabled && ($onStore || !$noSku))
            <x-slot:verbs>
                @if($found && $live['status'] === \Extensions\ventacart\Models\VentaCartListing::STATUS_ACTIVE)
                    <form method="POST" action="{{ route('ext.ventacart.listings.toggle', [$setting->id, $product->product_id]) }}"
                          data-guard-stale data-confirm="Unlist this product on {{ $storeName }}? Buyers stop seeing it. It can be relisted at any time."
                          data-confirm-verb="Unlist" data-busy="Unlisting on {{ $storeName }}">
                        @csrf
                        <input type="hidden" name="action" value="unlist">
                        <x-ui.button type="submit" size="sm">Unlist on {{ $storeName }}</x-ui.button>
                    </form>
                @elseif($found && $live['status'] === \Extensions\ventacart\Models\VentaCartListing::STATUS_INACTIVE)
                    <form method="POST" action="{{ route('ext.ventacart.listings.toggle', [$setting->id, $product->product_id]) }}"
                          data-guard-stale data-confirm="Relist this product on {{ $storeName }}? Buyers see it again at once."
                          data-confirm-tone="primary" data-confirm-verb="Relist" data-busy="Relisting on {{ $storeName }}">
                        @csrf
                        <input type="hidden" name="action" value="relist">
                        <x-ui.button type="submit" variant="oncolor" size="sm">Relist on {{ $storeName }}</x-ui.button>
                    </form>
                @endif
            </x-slot:verbs>
        @endif

    </x-channel.listing-strip>

    <form id="listing-form" method="POST" action="{{ route('ext.ventacart.listings.update', [$setting->id, $product->product_id]) }}" data-guard-unsaved>
        <input type="hidden" name="back" value="{{ \App\Support\BackTo::safe(request('back'), '') }}">
        @csrf
        @method('PUT')
        <x-channel.listing-body>
            <x-slot:main>

                @include('partials.listing-group-band', [
                    'fields' => [
                        'category' => ['label' => 'Category', 'kind' => 'input', 'el' => '#vl-category'],
                        'markup_percent' => ['label' => 'Percent added', 'kind' => 'input', 'el' => '#vl-markup-pct'],
                        'markup_fixed' => ['label' => 'Fixed amount added', 'kind' => 'input', 'el' => '#vl-markup-fixed'],
                        'watermark' => ['label' => 'Watermark', 'kind' => 'follow', 'el' => 'select[name="watermark_template_id"]'],
                    ],
                    'canManage' => $canManage,
                ])

                <section class="fm-section">
                    <div class="fm-section__head"><h2 class="fm-section__title">Content on this store</h2></div>
                    <div class="fm-fields">
                        <x-ui.field label="Name" for="vl-name" name="name" wide>
                            <x-ui.input id="vl-name" name="name" :value="old('name', \App\Integrations\Listings\ListingContent::editable($listing->name ?? null, (string) $product->name))" maxlength="255" :disabled="!$canManage" />
                        </x-ui.field>
                        @include('partials.channel-description-pick', [
                            'id' => 'vl-prefix', 'label' => 'Prefix description', 'name' => 'description_prefix_id',
                            'selected' => (int) old('description_prefix_id', $listing->description_prefix_id ?? 0),
                            'options' => $descriptionTemplates, 'canManage' => $canManage,
                        ])
                        @include('partials.channel-description-compose', [
                            'id' => 'vl-description', 'label' => 'Description', 'name' => 'description',
                            'value' => old('description', \App\Integrations\Listings\ListingContent::editable($listing->description ?? null, (string) $coreDescription)),
                            'rich' => true, 'disabled' => !$canManage, 'rows' => 6, 'maxlength' => null,
                            'prefixFor' => 'vl-prefix', 'suffixFor' => 'vl-suffix',
                            'prefix' => $listing->description_prefix_id ?? null, 'suffix' => $listing->description_suffix_id ?? null,
                            'options' => $descriptionTemplates,
                        ])
                        @include('partials.channel-description-pick', [
                            'id' => 'vl-suffix', 'label' => 'Suffix description', 'name' => 'description_suffix_id',
                            'selected' => (int) old('description_suffix_id', $listing->description_suffix_id ?? 0),
                            'options' => $descriptionTemplates, 'canManage' => $canManage,
                        ])
                    </div>
                </section>

                <section class="fm-section">
                    <div class="fm-section__head"><h2 class="fm-section__title">Category on {{ $storeName }}</h2></div>
                    <div class="fm-fields">
                        @include('partials.category-tree-pick', [
                            'name' => 'ventacart_category_id',
                            'id' => 'vl-category',
                            'label' => 'Category',
                            'value' => (int) old('ventacart_category_id', $shownCategoryId),
                            'valueLabel' => '',
                            'channel' => 'ventacart',
                            'storeId' => $setting->id,
                            'searchUrl' => route('ext.ventacart.listings.category_search', ['store' => $setting->id]),
                            'childrenUrl' => route('ext.ventacart.listings.category_children', ['store' => $setting->id]),
                            'pathUrl' => route('ext.ventacart.listings.category_path', ['store' => $setting->id]),
                            'canManage' => $canManage,
                            'required' => false,
                        ])
                    </div>
                </section>

                @include('partials.channel-images-card', [
                    'anchor' => 'vl-images', 'storeName' => $storeName, 'tiles' => $tiles, 'catalogTiles' => $catalogTiles,
                    'fetchesOverWeb' => true,
                    'editable' => true, 'canManage' => $canManage, 'browseUrl' => $browseUrl,
                ])


                @include('partials.channel-video-card', [
                    'anchor' => 'vl-video', 'storeName' => $storeName,
                    'productId' => (int) $product->product_id,
                    'uploadUrl' => route('products.video.upload'),
                    'canManage' => $canManage,
                ] + \App\Integrations\Listings\ListingVideo::cardData((int) $product->product_id, $listing ?? null))

                @include('partials.listing-variations-card', [
                    'title' => 'Variations on ' . $storeName, 'storeName' => $storeName, 'canManage' => $canManage,
                ])

                @include('partials.listing-parcel-card', [
                    'id' => 'vl', 'canManage' => $canManage, 'listing' => $listing, 'catalog' => $product,
                ])

                <section class="fm-section" id="vl-only">
                    <div class="fm-section__head"><h2 class="fm-section__title">Only on {{ $storeName }}</h2></div>
                    <div class="fm-sub">
                        <h3 class="fm-sub__title">Search engines <x-ui.hint label="About the meta fields">What search engines show for the product's page on the store. Empty fields follow the catalogue's.</x-ui.hint></h3>
                        <div class="fm-fields">
                            <x-ui.field label="Meta title" for="vl-meta-title" name="meta_title" wide>
                                <x-ui.input id="vl-meta-title" name="meta_title" :value="old('meta_title', $listing->meta_title ?? '')" :placeholder="$product->meta_title ?? $product->name" maxlength="255" :disabled="!$canManage" />
                            </x-ui.field>
                            <x-ui.field label="Meta description" for="vl-meta-description" name="meta_description" wide>
                                <x-ui.input id="vl-meta-description" name="meta_description" :value="old('meta_description', $listing->meta_description ?? '')" :placeholder="\Illuminate\Support\Str::limit((string) ($product->meta_description ?? ''), 120)" maxlength="500" :disabled="!$canManage" />
                            </x-ui.field>
                        </div>
                    </div>
                </section>
            </x-slot:main>

            <x-slot:rail>
                @include('partials.listing-price-card', [
                    'id' => 'vl', 'storeName' => $storeName, 'canManage' => $canManage,
                    'markupPercent' => $markupPercent, 'markupFixed' => $markupFixed,
                    'price' => $listing?->price, 'corePrice' => $corePrice,
                    'pushPrice' => $pushPrice, 'hasVariations' => $hasVariations,
                ])

                @if($listing?->live_checked_at)
                    <p class="fm-section__note">Store status last read {{ $listing->live_checked_at->diffForHumans() }}.</p>
                @endif
            </x-slot:rail>
        </x-channel.listing-body>
    </form>

    <x-ui.form-bar :cancel="\App\Support\BackTo::safe(request('back'), route('ext.ventacart.listings.index', $setting->id))" :cancel-label="$canManage ? 'Cancel' : 'Back'">
        <x-slot:note>{{ !$canManage
            ? 'You can read this. Editing needs the VentaCart listing manage permission.'
            : 'Save listing keeps everything on this page. Save and push keeps it and sends the listing to ' . $storeName . '.' }}</x-slot:note>
        @if($canManage && $setting->enabled)
            <x-ui.button type="submit" form="listing-form" name="push_after" value="1">{{ $onStore ? 'Save and push the update' : 'Save and push to ' . $storeName }}</x-ui.button>
        @endif
        <x-slot:primary>
            @if($canManage)
                <x-ui.button type="submit" form="listing-form" variant="primary">Save listing</x-ui.button>
            @endif
        </x-slot:primary>
    </x-ui.form-bar>

</div>

<div class="modal-backdrop" data-busy-overlay>
    <div class="modal co-modal co-modal--sm">
        <div class="co-progress">
            <span class="co-spinner co-spinner--lg" aria-hidden="true"></span>
            <div>
                <div class="co-progress__title" data-busy-title>Working</div>
                <div class="co-progress__note">Please keep this tab open.</div>
            </div>
        </div>
    </div>
</div>
@include('partials.channel-description-pick-modal', ['storeUrl' => route('ext.ventacart.description-templates.store', ['store' => $setting->id])])

@if($canManage)
    @include('partials.image-library-pick', ['browseUrl' => $browseUrl])
@endif
@if(!empty($catalogChange))
    @include('partials.catalog-change-panel', [
        'listing' => $listing,
        'storeLabel' => \App\Support\StoreLabel::of('VentaCart', $storeName),
        'saveUrl' => route('ext.ventacart.listings.catalog_change_save', [$setting->id, $listing->product_id]),
        'ignoreUrl' => route('ext.ventacart.listings.catalog_change_ignore', [$setting->id, $listing->product_id]),
        'back' => request()->boolean('compare') ? \App\Support\BackTo::safe(request('back'), request()->getRequestUri()) : request()->getRequestUri(),
        'canManage' => $canManage,
    ])
@endif

@endsection
