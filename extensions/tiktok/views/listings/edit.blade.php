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

    <div class="fm-head">
        <div class="fm-head__main">
            <a class="fm-back" href="{{ route('ext.tiktok.product-groups.index') }}">
                <x-ui.icon name="chevron-left" size="14" /> Product groups
            </a>
            <h1 class="fm-title">{{ ($product->name ?? '') !== '' ? $product->name : 'Product ' . $product->product_id }}</h1>
            <div class="fm-stamps">
                <span class="fm-stamp">
                    <span class="fm-stamp__k">Channel</span>
                    <span class="fm-stamp__v">TikTok Shop</span>
                </span>
                <span class="fm-stamp">
                    <span class="fm-stamp__k">SKU</span>
                    <span class="fm-stamp__v">{{ ($product->sku ?? '') !== '' ? $product->sku : 'none' }}</span>
                </span>
                @if($listed)
                    <span class="fm-stamp">
                        <span class="fm-stamp__k">TikTok product ID</span>
                        <span class="fm-stamp__v">{{ $truth->tiktok_product_id }}</span>
                    </span>
                @endif
            </div>
        </div>
    </div>

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
                    @if($truth->last_pushed_at)
                        <div class="fm-stat">
                            <span class="fm-stat__k">Pushed</span>
                            <span class="fm-stat__v">{{ \Illuminate\Support\Carbon::parse($truth->last_pushed_at)->diffForHumans() }} from the {{ $truth->group_name }} group</span>
                        </div>
                    @endif
                @endif
            </div>
            {{-- Honest about what was asked: this is the push record, not a
                 live answer. TikTok itself is not consulted on this page. --}}
            <p class="fm-section__note">{{ $listed
                ? 'From the last push record. TikTok Shop itself is not asked on this page; the group pages check it.'
                : 'Push it from one of its product groups first; the discount joins the live listing.' }}</p>
        </div>
    </div>

    <form id="listing-form" method="POST" action="{{ route('ext.tiktok.listings.update', $product->product_id) }}">
        @csrf
        @method('PUT')

        @php
            $discount = [
                'channel' => 'TikTok Shop', 'prefix' => 'tt',
                'canManage' => $canManage, 'onMarketplace' => $listed,
                'step' => '1', 'note' => 'Whole percents only; TikTok cuts the live price by this percent while the window is open.',
                'savedPct' => $listing?->sale_percent,
                'savedFrom' => $listing?->sale_starts_at, 'savedTo' => $listing?->sale_ends_at,
                // TikTok applies the percent to the live price itself, so
                // no sale price is computed here: promising one from the
                // catalog price would be a guess when a group pushed with
                // a markup.
                'salePreview' => null,
                'remoteId' => $listing?->tiktok_activity_id ?: null,
                'tracked' => true, 'endClearsRule' => false,
                'action' => route('ext.tiktok.listings.push_discount', $product->product_id),
            ];
        @endphp
        {{-- The shared Discount card (#146): the rule rides Save, the
             push is its own confirmed act. Partial in
             resources/views/partials/channel-discount-card. --}}
        @include('partials.channel-discount-card', $discount)
    </form>

    @include('partials.channel-discount-forms', $discount)

    <div class="fm-bar">
        <span class="fm-bar__meta">{{ !$canManage
            ? 'You can read this. Editing needs the TikTok manage permission.'
            : 'Saving changes this record only. TikTok Shop sees it when the discount is pushed.' }}</span>
        <div class="fm-bar__actions">
            <a class="fm-cancel" href="{{ route('ext.tiktok.product-groups.index') }}">{{ $canManage ? 'Cancel' : 'Back' }}</a>
            @if($canManage)
                <x-ui.button type="submit" form="listing-form" variant="primary">Save discount</x-ui.button>
            @endif
        </div>
    </div>

</div>
@endsection
