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
    $pct = $listing?->markup_percent;
    $fixed = $listing?->markup_fixed;
    $hasRule = $pct !== null || $fixed !== null;
    $nextPrice = round($corePrice + ($corePrice * (float) ($pct ?? 0) / 100) + (float) ($fixed ?? 0), 2);
    $coreDescription = trim(strip_tags(html_entity_decode((string) ($product->description ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    $noSku = $sku === '';
@endphp

<div class="fm-page">

    <div class="fm-head">
        <div class="fm-head__main">
            <a class="fm-back" href="{{ route('ext.venta.product-groups.index', $setting->id) }}">
                <x-ui.icon name="chevron-left" size="14" /> Product groups
            </a>
            <h1 class="fm-title">{{ ($product->name ?? '') !== '' ? $product->name : 'Product ' . $product->product_id }}</h1>
            <div class="fm-stamps">
                <span class="fm-stamp"><span class="fm-stamp__k">Store</span><span class="fm-stamp__v">{{ $storeName }}</span></span>
                <span class="fm-stamp"><span class="fm-stamp__k">SKU</span><span class="fm-stamp__v">{{ $noSku ? 'none' : $sku }}</span></span>
                @if($onStore)
                    <span class="fm-stamp"><span class="fm-stamp__k">Store product ID</span><span class="fm-stamp__v">{{ $link->venta_product_id }}</span></span>
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

    <x-channel.listing-strip :channel="$storeName" :state="$stripState" :figures="$stripFigures"
                             :lastPush="$stripLastPush" :linked="(bool) $onStore">
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

        <x-slot:notes>
            @if(!$setting->enabled)
                <div class="fm-note">
                    <div class="fm-note__body">This store is turned off, so it was not asked. The record below can still be edited; enable the store in its settings to push or to read what it holds.</div>
                </div>
            @elseif($liveError)
                <div class="fm-note fm-note--fail">
                    <div class="fm-note__body">@include('partials.channel-answer', ['channel' => $storeName, 'raw' => $liveError, 'settingsRoute' => null]) Nothing below is stale data standing in for it: the live state is simply unknown until the store answers.</div>
                </div>
            @endif
            @if($noSku)
                <div class="fm-note">
                    <div class="fm-note__body">This product has no SKU, and {{ $storeName }} files products by SKU. Give it one in the catalogue before pushing.</div>
                </div>
            @endif
            @if($onStore && $live && $live['state'] === 'missing')
                <div class="fm-note">
                    <div class="fm-note__body">{{ $storeName }} no longer has a product with this SKU. The link the ERP held has been dropped; Push creates it again.</div>
                </div>
            @endif
        </x-slot:notes>
    </x-channel.listing-strip>

    {{-- The one template (the owner, 2026-09-08, draft C): main is what the
         listing SAYS - Content, Category, Images, then what only this store
         has - and rail is what it COSTS. No Discount: the storefront has no
         sale price (card on the board). --}}
    <form id="listing-form" method="POST" action="{{ route('ext.venta.listings.update', [$setting->id, $product->product_id]) }}">
        <input type="hidden" name="back" value="{{ \App\Support\BackTo::safe(request('back'), '') }}">
        @csrf
        @method('PUT')
        <x-channel.listing-body>
            <x-slot:main>

                <section class="fm-section">
                    <div class="fm-section__head"><h2 class="fm-section__title">Content on this store <x-ui.hint label="About the content">A filled field is this listing's own wording and follows nobody. An empty field follows the catalogue product, shown greyed as the placeholder. Clearing a field returns it to the catalogue. Nothing here ever edits the catalogue product itself.</x-ui.hint></h2></div>
                    <div class="fm-fields">
                        <x-ui.field label="Name" for="vl-name" name="name" wide>
                            <x-ui.input id="vl-name" name="name" :value="old('name', \App\Integrations\Listings\ListingContent::editable($listing->name ?? null, (string) $product->name))" maxlength="255" :disabled="!$canManage" />
                        </x-ui.field>
                        @include('partials.channel-description-pick', [
                            'id' => 'vl-prefix', 'label' => 'Prefix description', 'name' => 'description_prefix_id',
                            'selected' => (int) old('description_prefix_id', $listing->description_prefix_id ?? 0),
                            'options' => $descriptionTemplates, 'canManage' => $canManage,
                        ])
                        <x-ui.field label="Description" for="vl-description" name="description" wide>
                            <x-ui.textarea id="vl-description" name="description" rows="6" :disabled="!$canManage">{{ old('description', \App\Integrations\Listings\ListingContent::editable($listing->description ?? null, (string) $coreDescription)) }}</x-ui.textarea>
                        </x-ui.field>
                        @include('partials.channel-description-pick', [
                            'id' => 'vl-suffix', 'label' => 'Suffix description', 'name' => 'description_suffix_id',
                            'selected' => (int) old('description_suffix_id', $listing->description_suffix_id ?? 0),
                            'options' => $descriptionTemplates, 'canManage' => $canManage,
                        ])
                    </div>
                </section>

                <section class="fm-section">
                    <div class="fm-section__head"><h2 class="fm-section__title">Category on {{ $storeName }} <x-ui.hint label="About the category">The store owns its categories. The product's group picks one, and every push sends it; a product in no group goes up under the catalogue's own category names.</x-ui.hint></h2></div>
                    @if($groupCard && $groupCategory)
                        <p class="fm-section__note"><strong>{{ $groupCategory }}</strong>, from the group <a href="{{ $groupCard['url'] }}">{{ $groupCard['name'] }}</a>.</p>
                    @elseif($groupCard)
                        <p class="fm-section__note">The group <a href="{{ route('ext.venta.product-groups.edit', [$setting->id, $groupCard['id']]) }}">{{ $groupCard['name'] }}</a> has no category yet.</p>
                    @else
                        <p class="fm-section__note">In no product group on this store. <a href="{{ route('ext.venta.product-groups.index', $setting->id) }}">Add it to one</a> to give it a category.</p>
                    @endif
                </section>

                @include('partials.channel-images-card', [
                    'storeName' => $storeName, 'images' => $images, 'limit' => null, 'fetchesOverWeb' => true,
                ])

                @if($found && count($live['variants']) > 0)
                    {{-- What only this store has: the variations it holds
                         right now. The strip is the shape every channel
                         shares; this is not. --}}
                    <section class="fm-section">
                        <div class="fm-section__head">
                            <h2 class="fm-section__title">Variations on {{ $storeName }}</h2>
                        </div>
                    <x-ui.table caption="This product's variations as {{ $storeName }} holds them right now">
                        <thead>
                            <tr>
                                <th>Variation</th>
                                <th>SKU</th>
                                <th class="x-num">Price</th>
                                <th class="x-num">Stock</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($live['variants'] as $v)
                                <tr>
                                    <td>{{ $v['name'] !== '' ? $v['name'] : 'Variation' }}</td>
                                    <td>{{ $v['sku'] !== '' ? $v['sku'] : 'none' }}</td>
                                    <td class="x-num">@if($v['price'] !== null)<x-money :php="$v['price']" />@else none @endif</td>
                                    <td class="x-num">{{ $v['quantity'] ?? 'none' }}</td>
                                    <td><x-ui.badge :tone="$v['active'] ? 'success' : 'warning'">{{ $v['active'] ? 'Active' : 'Unlisted' }}</x-ui.badge></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </x-ui.table>
                    </section>
                @endif

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
                <section class="fm-section">
                    <div class="fm-section__head">
                        <h2 class="fm-section__title">Price rule</h2>
                        <x-ui.hint label="About the price rule">The {{ $storeName }} price is never stored: it is the catalogue price run through this rule at every push and price sync. Variations go through the same rule. Without a rule of its own the listing uses its group's.</x-ui.hint>
                    </div>
                        <div class="fm-fields fm-fields--2">
                            <x-ui.field label="Percent added" for="vl-markup-pct" name="markup_percent">
                                <x-ui.input id="vl-markup-pct" name="markup_percent" type="number" step="0.01" :value="old('markup_percent', $listing->markup_percent ?? '')" placeholder="0" :disabled="!$canManage" />
                            </x-ui.field>
                            <x-ui.field label="Fixed amount added" for="vl-markup-fixed" name="markup_fixed">
                                <x-ui.input id="vl-markup-fixed" name="markup_fixed" type="number" step="0.01" :value="old('markup_fixed', $listing->markup_fixed ?? '')" placeholder="0" :disabled="!$canManage" />
                            </x-ui.field>
                        </div>
                        <div class="fm-stats">
                            <div class="fm-stat"><span class="fm-stat__k">Catalogue price</span><span class="fm-stat__v"><x-money :php="$corePrice" /></span></div>
                            <div class="fm-stat"><span class="fm-stat__k">Price on {{ $storeName }} after push</span><span class="fm-stat__v"><x-money :php="$hasRule ? $nextPrice : $corePrice" /></span></div>
                        </div>
                </section>

                @include('partials.channel-group-card', [
                    'storeName' => $storeName, 'group' => $groupCard,
                    'groupsUrl' => route('ext.venta.product-groups.index', $setting->id),
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
            <a class="fm-cancel" href="{{ \App\Support\BackTo::safe(request('back'), route('ext.venta.listings.index', $setting->id)) }}">{{ $canManage ? 'Cancel' : 'Back' }}</a>
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

@endsection
