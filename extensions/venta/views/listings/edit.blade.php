@extends('layouts.channel')
@section('title', $setting->store_name . ' Listing')
@section('breadcrumb', ($product->name ?? '') !== '' ? $product->name : 'Listing')

{{-- The individual Venta listing page (#171), on the same anatomy as
     Shopee's and TikTok's: the LIVE state at the top, read from the store
     on this load; the record beneath it (price rule, content on this store),
     which the next push from here uses; and provenance. Saving never writes
     the catalogue product. Minus what the storefront has no notion of: no
     category attribute tree, no courier list, no promotion object. --}}
{{-- This page shows its own "Nothing was saved" note and marks the
     offending fields, so the legacy bullet list stands down (critique
     2026-09-08: one event was drawing two red boxes). --}}
@section('own-errors', true)

@section('content')
@php
    $canManage = auth()->user()?->hasPermission('manage_venta/listing') ?? false;
    $storeName = $setting->store_name;
    $onStore = $link && $link->venta_product_id;

    $statusWords = [
        \Extensions\venta\Models\VentaListing::STATUS_ACTIVE => ['Active', 'success'],
        \Extensions\venta\Models\VentaListing::STATUS_INACTIVE => ['Unlisted', 'warning'],
        \Extensions\venta\Models\VentaListing::STATUS_MISSING => ['Not on the store', 'neutral'],
    ];
    [$statusLabel, $statusTone] = $live && $live['status'] ? ($statusWords[$live['status']] ?? [ucfirst($live['status']), 'neutral']) : ['Unknown', 'neutral'];

    $corePrice = (float) $product->price;
    $coreDescription = (string) ($product->description ?? '');
    $noSku = $sku === '';
@endphp

<div class="fm-page">

    {{-- The one thing a refused save must not do is refuse in silence. The
         page marks the offending fields inline (x-ui.field draws each
         message under its own control); this is the summary that says a
         save was refused at all, and it is the surface the owner asked to keep
         when the rest of the notes came off (2026-09-09). --}}
    <div class="fm-flash" role="status" aria-live="polite">
        @if($errors->any())
            <div class="fm-note fm-note--fail">
                <div class="fm-note__body">Nothing was saved. Check the fields marked below.</div>
            </div>
        @endif
    </div>

    <div class="fm-head">
        <div class="fm-head__main">
            <a class="fm-back" href="{{ route('ext.venta.product-groups.index', $setting->id) }}" data-guard-leave>
                <x-ui.icon name="chevron-left" size="14" /> Product groups
            </a>
            <h1 class="fm-title">{{ ($product->name ?? '') !== '' ? $product->name : 'Product ' . $product->product_id }}</h1>
            <div class="fm-stamps">
                <span class="fm-stamp"><span class="fm-stamp__k">Store</span><span class="fm-stamp__v">{{ $storeName }}</span></span>
                <span class="fm-stamp"><span class="fm-stamp__k">SKU</span><span class="fm-stamp__v">{{ $noSku ? 'none' : $sku }}</span></span>
                @if($onStore)
                    <span class="fm-stamp"><span class="fm-stamp__k">Store product ID</span><span class="fm-stamp__v">{{ $link->venta_product_id }}</span></span>
                @endif
                @php $catalogChange = ($listing && $listing->exists) ? $listing->catalogChange() : []; @endphp
                @if($catalogChange)
                    @include('partials.catalog-change-button')
                @endif
            </div>
        </div>
    </div>

    {{-- ── The strip: what the store says right now ───────────────
         The one template (the owner, 2026-09-08, draft C). What only this store
         has - the variations it holds, its meta fields - stays in its own
         sections below. --}}
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

    {{-- HEALTH OFF (the owner, 2026-09-09): "What I want to see right
         now are just fields to edit. Nothing about health." The state
         word, the live figures, Last push and the accent are gone, and the
         bar keeps only the verbs - what you can DO here, which is not a
         report about the item. The notes went with them.

         What survives is the required-field handling: the validation
         summary above, the browser's own bubble on the required fields,
         and the inline field errors.

         Nothing was deleted from the system: the engine still computes
         every one of these facts and the Listings index still shows them.
         This page takes them back by passing health again. --}}
    <x-channel.listing-strip :channel="$storeName" :health="false" :linked="(bool) $onStore">
        @if($canManage && $setting->enabled && ($onStore || !$noSku))
            <x-slot:verbs>
                <form method="POST" action="{{ route('ext.venta.listings.push', [$setting->id, $product->product_id]) }}"
                      data-guard-stale
                      data-confirm="{{ $onStore
                          ? 'Push this listing\'s content and price rule onto the live ' . $storeName . ' product? Name, description, price, stock, images and variations are updated in place.'
                          : 'Push this product to ' . $storeName . ' now? It goes up with the price rule and content saved on this page.' }}"
                      data-confirm-tone="primary" data-confirm-verb="{{ $onStore ? 'Push update' : 'Push to ' . $storeName }}"
                      data-busy="Pushing to {{ $storeName }}">
                    @csrf
                    <input type="hidden" name="back" value="{{ \App\Support\BackTo::safe(request('back'), '') }}">
                    <x-ui.button type="submit" variant="oncolor" size="sm">{{ $onStore ? 'Push update to ' . $storeName : 'Push to ' . $storeName }}</x-ui.button>
                </form>
                @if($found && $live['status'] === \Extensions\venta\Models\VentaListing::STATUS_ACTIVE)
                    <form method="POST" action="{{ route('ext.venta.listings.toggle', [$setting->id, $product->product_id]) }}"
                          data-guard-stale data-confirm="Unlist this product on {{ $storeName }}? Buyers stop seeing it. It can be relisted at any time."
                          data-confirm-verb="Unlist" data-busy="Unlisting on {{ $storeName }}">
                        @csrf
                        <input type="hidden" name="action" value="unlist">
                        <x-ui.button type="submit" size="sm">Unlist on {{ $storeName }}</x-ui.button>
                    </form>
                @elseif($found && $live['status'] === \Extensions\venta\Models\VentaListing::STATUS_INACTIVE)
                    <form method="POST" action="{{ route('ext.venta.listings.toggle', [$setting->id, $product->product_id]) }}"
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

    {{-- The one template (the owner, 2026-09-08, draft C): main is what the
         listing SAYS - Content, Category, Images, then what only this store
         has - and rail is what it COSTS. No Discount: the storefront has no
         sale price (card on the board). --}}
    <form id="listing-form" method="POST" action="{{ route('ext.venta.listings.update', [$setting->id, $product->product_id]) }}" data-guard-unsaved>
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
                        <x-ui.field label="Category" for="vl-category" name="venta_category_id">
                            <select id="vl-category" name="venta_category_id" class="x-input" @disabled(!$canManage)>
                                <option value="0" @selected((int) old('venta_category_id', $shownCategoryId) === 0)>None</option>
                                @foreach($storeCategories as $category)
                                    <option value="{{ $category->venta_category_id }}" @selected((int) old('venta_category_id', $shownCategoryId) === (int) $category->venta_category_id)>{{ $category->name }}</option>
                                @endforeach
                            </select>
                        </x-ui.field>
                    </div>
                </section>

                @include('partials.channel-images-card', [
                    'anchor' => 'vl-images', 'storeName' => $storeName, 'tiles' => $tiles, 'catalogTiles' => $catalogTiles,
                    'limit' => null, 'fetchesOverWeb' => true,
                    'editable' => true, 'canManage' => $canManage, 'browseUrl' => $browseUrl,
                ])

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
                    {{-- Last push sent went with the owner's read on 2026-09-09;
                         when and where from are figures on the strip, and
                         what it sent is detail nobody was asking for here.
                         When the store was last READ is not on the strip,
                         so it stays. --}}
                    <p class="fm-section__note">Store status last read {{ $listing->live_checked_at->diffForHumans() }}.</p>
                @endif
            </x-slot:rail>
        </x-channel.listing-body>
    </form>

    <div class="fm-bar">
        <span class="fm-bar__meta">{{ !$canManage
            ? 'You can read this. Editing needs the Venta listing manage permission.'
            : ($onStore
                ? 'Saving changes this record only. Push update sends it onto the live product.'
                : 'Saving changes this record only. ' . $storeName . ' sees it when the product is pushed.') }}</span>
        <div class="fm-bar__actions">
            <a class="fm-cancel" href="{{ \App\Support\BackTo::safe(request('back'), route('ext.venta.listings.index', $setting->id)) }}" data-guard-leave>{{ $canManage ? 'Cancel' : 'Back' }}</a>
            @if($canManage)
                @if(!$onStore && $setting->enabled)
                    <x-ui.button type="submit" form="listing-form" name="push_after" value="1">Save and push to {{ $storeName }}</x-ui.button>
                @endif
                <x-ui.button type="submit" form="listing-form" variant="primary">Save listing</x-ui.button>
            @endif
        </div>
    </div>

</div>

{{-- The wait after a confirmed push, unlist or relist, through
     resources/js/busy-overlay.js and the data-busy hooks above. --}}
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
@include('partials.channel-description-pick-modal', ['storeUrl' => route('ext.venta.description-templates.store', ['store' => $setting->id])])

@if($canManage)
    @include('partials.image-library-pick', ['browseUrl' => $browseUrl])
@endif
@if(!empty($catalogChange))
    @include('partials.catalog-change-panel', [
        'listing' => $listing,
        'storeLabel' => \App\Support\StoreLabel::of('Venta', $storeName),
        'saveUrl' => route('ext.venta.listings.catalog_change_save', [$setting->id, $listing->product_id]),
        'ignoreUrl' => route('ext.venta.listings.catalog_change_ignore', [$setting->id, $listing->product_id]),
        'back' => request()->boolean('compare') ? \App\Support\BackTo::safe(request('back'), request()->getRequestUri()) : request()->getRequestUri(),
        'canManage' => $canManage,
    ])
@endif

@endsection
