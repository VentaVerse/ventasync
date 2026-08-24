@extends('layouts.channel')
@section('title', 'TikTok Listing')
@section('breadcrumb', ($product->name ?? '') !== '' ? $product->name : 'Listing')

{{-- The per-product TikTok page (#146). Lean on purpose: TikTok's
     listing truth is derived across the group pivots and pushes happen
     from the groups, so this page only tells that truth for one product
     and manages the facts that belong to the product itself - today,
     the discount rule. Built on the shared fm- anatomy, no new CSS.

     TikTok's DIRECT_DISCOUNT takes the percent itself and cuts the
     LIVE price at the marketplace, so no sale price is computed or
     shown here - promising one from the catalog price would be a guess
     when a group pushed with a markup. --}}
@section('content')
@php
    $canManage = auth()->user()?->hasPermission('manage_tiktok/listing') ?? false;
    $listed = (bool) $truth;
@endphp

<div class="fm-page">

    <div class="fm-card">
        <div class="fm-card__head"><h2 class="fm-card__title">On TikTok Shop</h2></div>
        <div class="fm-card__body">
            <div class="fm-stats">
                <div class="fm-stat">
                    <span class="fm-stat__k">Product</span>
                    <span class="fm-stat__v">{{ $product->name ?: 'Unnamed product' }} ({{ $product->sku ?: $product->model }})</span>
                </div>
                <div class="fm-stat">
                    <span class="fm-stat__k">Listing</span>
                    <span class="fm-stat__v">{{ $listed ? 'Listed' : 'Not listed' }}</span>
                </div>
                @if($listed)
                    <div class="fm-stat">
                        <span class="fm-stat__k">TikTok product ID</span>
                        <span class="fm-stat__v">{{ $truth->tiktok_product_id }}</span>
                    </div>
                    @if($truth->last_pushed_at)
                        <div class="fm-stat">
                            <span class="fm-stat__k">Pushed</span>
                            <span class="fm-stat__v">{{ \Illuminate\Support\Carbon::parse($truth->last_pushed_at)->diffForHumans() }} from the {{ $truth->group_name }} group</span>
                        </div>
                    @endif
                @endif
            </div>
            @unless($listed)
                <p class="fm-section__note">Push it from one of its product groups first; the discount joins the live listing.</p>
            @endunless
        </div>
    </div>

    <form id="listing-form" method="POST" action="{{ route('ext.tiktok.listings.update', $product->product_id) }}">
        @csrf
        @method('PUT')

        {{-- The rule rides Save; the push is its own confirmed act, and
             its forms live after this one (nested forms are illegal HTML,
             the buttons reach them by the form attribute). --}}
        <div class="fm-card">
            <div class="fm-card__head"><h2 class="fm-card__title">Discount</h2></div>
            <div class="fm-card__body">
                @php
                    $ttSalePct = old('sale_percent', $listing->sale_percent !== null ? (int) $listing->sale_percent : null);
                    $ttSaleFrom = old('sale_starts_at', $listing?->sale_starts_at?->format('Y-m-d\TH:i'));
                    $ttSaleTo = old('sale_ends_at', $listing?->sale_ends_at?->format('Y-m-d\TH:i'));
                @endphp
                <p class="fm-section__note">A whole percent off, for a window. TikTok cuts the live price by this percent while the window is open. Leave the percent empty for no discount.</p>
                <div class="fm-fields fm-fields--2">
                    <x-ui.field label="Percent off" for="tt-sale-percent" name="sale_percent">
                        <x-ui.input id="tt-sale-percent" name="sale_percent" type="number" step="1" min="1" max="99"
                                    :value="$ttSalePct" placeholder="0" :disabled="!$canManage" />
                    </x-ui.field>
                </div>
                <div class="fm-fields fm-fields--2">
                    <x-ui.field label="From" for="tt-sale-from" name="sale_starts_at">
                        <x-ui.input id="tt-sale-from" name="sale_starts_at" type="datetime-local" :value="$ttSaleFrom" :disabled="!$canManage" />
                    </x-ui.field>
                    <x-ui.field label="To" for="tt-sale-to" name="sale_ends_at">
                        <x-ui.input id="tt-sale-to" name="sale_ends_at" type="datetime-local" :value="$ttSaleTo" :disabled="!$canManage" />
                    </x-ui.field>
                </div>
                @if(($listing->tiktok_activity_id ?? null))
                    <div class="fm-stats">
                        <div class="fm-stat">
                            <span class="fm-stat__k">On TikTok as</span>
                            <span class="fm-stat__v">promotion {{ $listing->tiktok_activity_id }}</span>
                        </div>
                    </div>
                @endif
                @if($canManage && $listed)
                    <div class="cc-head-actions">
                        <x-ui.button type="submit" form="tt-discount-push" variant="primary">Push discount to TikTok Shop</x-ui.button>
                        @if($listing->tiktok_activity_id ?? null)
                            <x-ui.button type="submit" form="tt-discount-end">End discount now</x-ui.button>
                        @endif
                    </div>
                @endif
            </div>
        </div>
    </form>

    @if($canManage && $listed)
        <form id="tt-discount-push" method="POST" action="{{ route('ext.tiktok.listings.push_discount', $product->product_id) }}"
              data-confirm="Push this discount to TikTok Shop? The promotion is created for the saved window and this product joins it at the saved percent off.">
            @csrf
            <input type="hidden" name="action" value="push">
        </form>
        <form id="tt-discount-end" method="POST" action="{{ route('ext.tiktok.listings.push_discount', $product->product_id) }}"
              data-confirm="End this discount on TikTok Shop now? The product leaves the promotion and buyers pay the full price again.">
            @csrf
            <input type="hidden" name="action" value="end">
        </form>
    @endif

    <div class="fm-bar">
        <span class="fm-bar__meta">{{ !$canManage
            ? 'You can read this. Editing needs the TikTok manage permission.'
            : 'Saving changes this record only. TikTok Shop sees it when the discount is pushed.' }}</span>
        <div class="fm-bar__actions">
            <a class="fm-cancel" href="{{ url()->previous() }}">{{ $canManage ? 'Cancel' : 'Back' }}</a>
            @if($canManage)
                <x-ui.button type="submit" form="listing-form" variant="primary">Save discount</x-ui.button>
            @endif
        </div>
    </div>

</div>
@endsection
