@extends('layouts.channel')
@section('title', $setting->store_name . ' Listing')
@section('breadcrumb', ($product->name ?? '') !== '' ? $product->name : 'Listing')

{{-- The individual Venta listing page (#171), on the same anatomy as
     Shopee's and TikTok's: the LIVE state at the top, read from the store
     on this load; the record beneath it (price rule, content on this store),
     which the next push from here uses; and provenance. Saving never writes
     the catalogue product. Minus what the storefront has no notion of: no
     category attribute tree, no courier list, no promotion object. --}}
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

    {{-- ── The live half ─────────────────────────────────────── --}}
    @if($onStore)
        <section class="fm-section">
            <div class="fm-section__head">
                <h2 class="fm-section__title">On {{ $storeName }} right now</h2>
                @if($canManage && $setting->enabled)
                    <div class="fm-section__aside">
                        <form method="POST" action="{{ route('ext.venta.listings.push', [$setting->id, $product->product_id]) }}"
                              data-confirm="Push this listing's content and price rule onto the live {{ $storeName }} product? Name, description, price, stock, images and variations are updated in place."
                              data-confirm-tone="primary" data-confirm-verb="Push update"
                              data-busy="Pushing to {{ $storeName }}">
                            @csrf
                            <x-ui.button type="submit">Push update to {{ $storeName }}</x-ui.button>
                        </form>
                        @if($live && $live['status'] === \Extensions\venta\Models\VentaListing::STATUS_ACTIVE)
                            <form method="POST" action="{{ route('ext.venta.listings.toggle', [$setting->id, $product->product_id]) }}"
                                  data-confirm="Unlist this product on {{ $storeName }}? Buyers stop seeing it. It can be relisted at any time."
                                  data-confirm-verb="Unlist" data-busy="Unlisting on {{ $storeName }}">
                                @csrf
                                <input type="hidden" name="action" value="unlist">
                                <x-ui.button type="submit">Unlist on {{ $storeName }}</x-ui.button>
                            </form>
                        @elseif($live && $live['status'] === \Extensions\venta\Models\VentaListing::STATUS_INACTIVE)
                            <form method="POST" action="{{ route('ext.venta.listings.toggle', [$setting->id, $product->product_id]) }}"
                                  data-confirm="Relist this product on {{ $storeName }}? Buyers see it again at once."
                                  data-confirm-tone="primary" data-confirm-verb="Relist" data-busy="Relisting on {{ $storeName }}">
                                @csrf
                                <input type="hidden" name="action" value="relist">
                                <x-ui.button type="submit" variant="primary">Relist on {{ $storeName }}</x-ui.button>
                            </form>
                        @endif
                    </div>
                @endif
            </div>

            @if(!$setting->enabled)
                <div class="fm-note">
                    <div class="fm-note__body">This store is turned off, so it was not asked. The record below can still be edited; enable the store in its settings to push or to read what it holds.</div>
                </div>
            @elseif($liveError)
                <div class="fm-note fm-note--fail">
                    <div class="fm-note__body">@include('partials.channel-answer', ['channel' => $storeName, 'raw' => $liveError, 'settingsRoute' => null]) Nothing below is stale data standing in for it: the live state is simply unknown until the store answers.</div>
                </div>
            @endif

            @if($live && $live['state'] === 'found')
                <p class="fm-section__note">Read from {{ $storeName }} as this page loaded.</p>
                <div class="fm-stats">
                    <div class="fm-stat">
                        <span class="fm-stat__k">Status</span>
                        <span class="fm-stat__v"><x-ui.badge :tone="$statusTone">{{ $statusLabel }}</x-ui.badge></span>
                    </div>
                    <div class="fm-stat">
                        <span class="fm-stat__k">Price on {{ $storeName }}</span>
                        <span class="fm-stat__v">@if($live['price'] !== null)<x-money :php="$live['price']" />@else none @endif</span>
                    </div>
                    <div class="fm-stat">
                        <span class="fm-stat__k">Stock on {{ $storeName }}</span>
                        <span class="fm-stat__v">{{ $live['quantity'] ?? 'none' }}</span>
                    </div>
                    <div class="fm-stat">
                        <span class="fm-stat__k">Images on {{ $storeName }}</span>
                        <span class="fm-stat__v">{{ $live['images'] }}</span>
                    </div>
                    @if($live['name'] !== '')
                        <div class="fm-stat">
                            <span class="fm-stat__k">Name on {{ $storeName }}</span>
                            <span class="fm-stat__v">{{ $live['name'] }}</span>
                        </div>
                    @endif
                </div>
                @if(count($live['variants']) > 0)
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
                @endif
            @elseif($live && $live['state'] === 'missing')
                <div class="fm-note">
                    <div class="fm-note__body">{{ $storeName }} no longer has a product with this SKU. The link the ERP held has been dropped; Push creates it again.</div>
                </div>
            @endif
        </section>
    @else
        <section class="fm-section">
            <div class="fm-section__head">
                <h2 class="fm-section__title">Not on {{ $storeName }} yet</h2>
                @if($canManage && $setting->enabled && !$noSku)
                    <div class="fm-section__aside">
                        <form method="POST" action="{{ route('ext.venta.listings.push', [$setting->id, $product->product_id]) }}"
                              data-confirm="Push this product to {{ $storeName }} now? It goes up with the price rule and content saved on this page."
                              data-confirm-tone="primary" data-confirm-verb="Push to {{ $storeName }}"
                              data-busy="Pushing to {{ $storeName }}">
                            @csrf
                            <x-ui.button type="submit" variant="primary">Push to {{ $storeName }}</x-ui.button>
                        </form>
                    </div>
                @endif
            </div>
            @if($noSku)
                <p class="fm-section__note">This product has no SKU, and {{ $storeName }} files products by SKU. Give it one in the catalogue before pushing.</p>
            @elseif(!$setting->enabled)
                <p class="fm-section__note">This store is turned off. Enable it in its settings to push.</p>
            @elseif($liveError)
                <div class="fm-note fm-note--fail">
                    <div class="fm-note__body">@include('partials.channel-answer', ['channel' => $storeName, 'raw' => $liveError, 'settingsRoute' => null]) Whether the store already holds this SKU is unknown until it answers; Push asks again before it creates anything.</div>
                </div>
            @else
                <p class="fm-section__note">Everything the push needs is set. The one-click push uses the price rule and content below, and creates the product on the store with its variations and images.</p>
            @endif
        </section>
    @endif

    <form id="listing-form" method="POST" action="{{ route('ext.venta.listings.update', [$setting->id, $product->product_id]) }}">
        @csrf
        @method('PUT')
        <div class="fm-body">
            <div class="fm-col">

                <section class="fm-section">
                    <div class="fm-section__head"><h2 class="fm-section__title">Content on this store</h2></div>
                    <p class="fm-section__note">A filled field is this listing's own wording and follows nobody. An empty field follows the catalogue product, shown greyed as the placeholder. Clearing a field returns it to the catalogue. Nothing here ever edits the catalogue product itself.</p>
                    <div class="fm-fields">
                        <x-ui.field label="Name" for="vl-name" name="name" wide>
                            <x-ui.input id="vl-name" name="name" :value="old('name', $listing->name ?? '')" :placeholder="$product->name" maxlength="255" :disabled="!$canManage" />
                        </x-ui.field>
                        <x-ui.field label="Description" for="vl-description" name="description" wide>
                            <x-ui.textarea id="vl-description" name="description" rows="6" :placeholder="\Illuminate\Support\Str::limit($coreDescription, 300)" :disabled="!$canManage">{{ old('description', $listing->description ?? '') }}</x-ui.textarea>
                        </x-ui.field>
                        <x-ui.field label="Meta title" for="vl-meta-title" name="meta_title" hint="What search engines show as the page title on the store.">
                            <x-ui.input id="vl-meta-title" name="meta_title" :value="old('meta_title', $listing->meta_title ?? '')" :placeholder="$product->meta_title ?? $product->name" maxlength="255" :disabled="!$canManage" />
                        </x-ui.field>
                        <x-ui.field label="Meta description" for="vl-meta-description" name="meta_description" hint="The line under the title in search results.">
                            <x-ui.input id="vl-meta-description" name="meta_description" :value="old('meta_description', $listing->meta_description ?? '')" :placeholder="\Illuminate\Support\Str::limit((string) ($product->meta_description ?? ''), 120)" maxlength="500" :disabled="!$canManage" />
                        </x-ui.field>
                    </div>
                </section>

                <div class="fm-card">
                    <div class="fm-card__head"><h2 class="fm-card__title">Price rule</h2></div>
                    <div class="fm-card__body">
                        <p class="fm-section__note">The {{ $storeName }} price is never stored: it is the catalogue price run through this rule at every push from this page. Variations go through the same rule. A group push sends the catalogue price as it is.</p>
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
                    </div>
                </div>

                <div class="fm-card">
                    <div class="fm-card__head"><h2 class="fm-card__title">Images that go up</h2></div>
                    <div class="fm-card__body">
                        @if(count($images) === 0)
                            <p class="fm-section__note">The catalogue product has no images, so none go up. The store keeps whatever it already holds.</p>
                        @else
                            <p class="fm-section__note">{{ count($images) }} {{ count($images) === 1 ? 'image' : 'images' }} from the catalogue, main image first. The store fetches them over the web from this ERP's public address; a store that cannot reach that address refuses the push and says so.</p>
                            <ul class="vl-images">
                                @foreach($images as $url)
                                    <li class="vl-images__item"><img src="{{ $url }}" alt="" loading="lazy"></li>
                                @endforeach
                            </ul>
                        @endif
                    </div>
                </div>
            </div>

            <div class="fm-col fm-col--side">
                <div class="fm-card">
                    <div class="fm-card__head"><h2 class="fm-card__title">Last push from this page</h2></div>
                    <div class="fm-card__body">
                        @if($listing?->last_pushed_at)
                            @php $sent = $listing->last_push_settings ?? []; @endphp
                            <div class="fm-stats">
                                <div class="fm-stat"><span class="fm-stat__k">When</span><span class="fm-stat__v">{{ $listing->last_pushed_at->diffForHumans() }}</span></div>
                                <div class="fm-stat"><span class="fm-stat__k">What happened</span><span class="fm-stat__v">{{ ucfirst((string) ($sent['state'] ?? 'pushed')) }}</span></div>
                                @if(isset($sent['price']))
                                    <div class="fm-stat"><span class="fm-stat__k">Price sent</span><span class="fm-stat__v"><x-money :php="(float) $sent['price']" /></span></div>
                                @endif
                                <div class="fm-stat"><span class="fm-stat__k">Content sent</span><span class="fm-stat__v">{{ !empty($sent['overrides']) ? 'This page\'s ' . implode(', ', array_map(fn ($k) => str_replace('_', ' ', $k), $sent['overrides'])) : 'The catalogue\'s' }}</span></div>
                                @if(isset($sent['variants']))
                                    <div class="fm-stat"><span class="fm-stat__k">Variations sent</span><span class="fm-stat__v">{{ (int) $sent['variants'] }}</span></div>
                                @endif
                            </div>
                        @else
                            <p class="fm-section__note">Never pushed from this page.</p>
                        @endif
                        @if($listing?->live_checked_at)
                            <p class="fm-section__note">Store status last read {{ $listing->live_checked_at->diffForHumans() }}.</p>
                        @endif
                    </div>
                </div>

                <div class="fm-card">
                    <div class="fm-card__head"><h2 class="fm-card__title">Groups on {{ $storeName }}</h2></div>
                    <div class="fm-card__body">
                        @if($groups->isEmpty())
                            <p class="fm-section__note">In no product group on this store. Group pushes will not touch it; only this page does.</p>
                        @else
                            <ul class="vl-groups">
                                @foreach($groups as $g)
                                    <li class="vl-groups__item">
                                        <a href="{{ route('ext.venta.product-groups.products', [$setting->id, $g->id]) }}">{{ $g->name }}</a>
                                        <span class="vl-groups__meta">{{ $g->last_pushed_at ? 'pushed ' . \Illuminate\Support\Carbon::parse($g->last_pushed_at)->diffForHumans() : 'never pushed' }}</span>
                                    </li>
                                @endforeach
                            </ul>
                            <p class="fm-section__note">A group push sends the catalogue's content and price; this page's record only goes when pushed from here.</p>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    </form>

    <div class="fm-bar">
        <span class="fm-bar__meta">{{ !$canManage
            ? 'You can read this. Editing needs the Venta listing manage permission.'
            : ($onStore
                ? 'Saving changes this record only. Push update sends it onto the live product.'
                : 'Saving changes this record only. ' . $storeName . ' sees it when the product is pushed.') }}</span>
        <div class="fm-bar__actions">
            <a class="fm-cancel" href="{{ route('ext.venta.product-groups.index', $setting->id) }}">{{ $canManage ? 'Cancel' : 'Back' }}</a>
            @if($canManage)
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
@endsection
