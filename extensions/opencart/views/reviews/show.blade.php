@extends('layouts.blotter')
@section('title', 'Review ' . $review->platform_review_id)
@section('breadcrumb', $review->platform_review_id)

@section('content')
@php
    use App\Support\ChannelStatusTone;

    // Push and skip both sit behind `perm:manage_reviews`; the Venta push
    // sits behind its own tier in extensions/venta/routes/web.php. Both
    // replace a hardcoded `true`, which rendered every control for a
    // read-tier operator and only turned them away on submit.
    $user = auth()->user();
    $canManage = $user?->hasPermission('manage_opencart/review') ?? false;
    $canManageVenta = $user?->hasPermission('manage_opencart/review') ?? false;

    // The controller refuses a push or a skip in any other state.
    $ocOpen = in_array($review->oc_sync_status, ['pending', 'error'], true);
    $ventaStatus = $review->venta_sync_status ?? 'pending';
    $ventaOpen = in_array($ventaStatus, ['pending', 'error'], true)
        && Route::has('ext.venta.reviews.push')
        && $canManageVenta;

    $images = $review->images ?? [];
    $videos = $review->videos ?? [];
    $author = $review->author ?: 'Anonymous';
@endphp

<div class="od-page rv-page">

    @include('ext-opencart::reviews.partials.flash')

    <a class="od-back" href="{{ route('ext.opencart.reviews.index') }}">
        <x-ui.icon name="chevron-left" size="14" />
        Reviews
    </a>

    <header class="od-head">
        <div class="od-head__id">
            {{-- Not `.od-title`: that face is mono because every other record
                 on this anatomy is fronted by an identifier. This one is a
                 person's name. --}}
            <h1 class="x-page-title">{{ $author }}</h1>
            @include('ext-opencart::reviews.partials.stars', ['rating' => $review->rating, 'size' => 15])
            <x-ui.badge :tone="ChannelStatusTone::toneFor('opencart.reviews', $review->oc_sync_status)">{{ ChannelStatusTone::labelFor('opencart.reviews', $review->oc_sync_status) }}</x-ui.badge>
        </div>

        <div class="od-head__actions">
            @if($canManage && $ocOpen)
                <x-ui.button variant="primary" type="submit" form="rv-push">
                    {{ $review->oc_sync_status === 'error' ? 'Try the push again' : 'Push to OpenCart' }}
                </x-ui.button>
            @endif

            @if(($canManage && $ocOpen) || $ventaOpen)
            <x-ui.menu label="Review actions">
                @if($ventaOpen)
                    <button type="submit" class="x-menu__item" form="rv-venta">Push to Venta</button>
                @endif
                @if($canManage && $ocOpen)
                    <button type="submit" class="x-menu__item" form="rv-skip">Skip this review</button>
                @endif
            </x-ui.menu>
            @endif
        </div>
    </header>

    <div class="od-stamps">
        <span class="od-stamp">
            <span class="od-stamp__k">Channel</span>
            <span class="od-stamp__v">{{ Str::headline($review->platform) }}</span>
        </span>
        <span class="od-stamp">
            <span class="od-stamp__k">Reviewed</span>
            <span class="od-stamp__v">{{ $review->reviewed_at ? $review->reviewed_at->format('Y-m-d H:i') : 'Unknown' }}</span>
        </span>
        <span class="od-stamp">
            <span class="od-stamp__k">Rating</span>
            <span class="od-stamp__v">{{ (int) $review->rating }} of 5</span>
        </span>
        <span class="od-stamp">
            <span class="od-stamp__k">Product</span>
            <span class="od-stamp__v">{{ $review->product_name ?? 'Not matched to a product' }}</span>
        </span>
    </div>

    <div class="od-body">
        <div class="od-col od-col--main">

            <section class="od-section">
                <div class="od-section__head">
                    <h2 class="od-section__title">What they wrote</h2>
                </div>
                {{-- Customer-controlled text. Rendered through Blade's echo,
                     which escapes it, and never through any raw output: this
                     string arrives from Shopee and Lazada and is exactly the
                     kind of value that must never become markup. --}}
                <p class="rv-quote @unless($review->comment) rv-quote--empty @endunless">{{ $review->comment ?: 'They left a rating but no words.' }}</p>
            </section>

            @if(!empty($images))
            <section class="od-section">
                <div class="od-section__head">
                    <h2 class="od-section__title">
                        Photos
                        <span class="od-section__count">{{ count($images) }}</span>
                    </h2>
                </div>
                <div class="rv-gallery">
                    @foreach($images as $image)
                        <a class="rv-gallery__item" href="{{ $image }}" target="_blank" rel="noopener noreferrer">
                            <img src="{{ $image }}" alt="Photo {{ $loop->iteration }} from this review" loading="lazy" referrerpolicy="no-referrer">
                        </a>
                    @endforeach
                </div>
            </section>
            @endif

            @if(!empty($videos))
            <section class="od-section">
                <div class="od-section__head">
                    <h2 class="od-section__title">
                        Videos
                        <span class="od-section__count">{{ count($videos) }}</span>
                    </h2>
                </div>
                <div class="rv-videos">
                    @foreach($videos as $video)
                        <x-ui.button size="sm" :href="$video" target="_blank" rel="noopener noreferrer">
                            Video {{ $loop->iteration }} <x-ui.icon name="external-link" size="12" />
                        </x-ui.button>
                    @endforeach
                </div>
            </section>
            @endif

            @if($review->reply)
            <section class="od-section">
                <div class="od-section__head">
                    <h2 class="od-section__title">Your reply</h2>
                </div>
                <p class="rv-reply">{{ $review->reply }}@if($review->replied_at)<span class="rv-reply__when">Sent {{ $review->replied_at->format('Y-m-d H:i') }}</span>@endif</p>
            </section>
            @endif

            @if($review->raw)
            <section class="od-section">
                <div class="od-section__head">
                    <h2 class="od-section__title">What the channel sent</h2>
                </div>
                <details class="od-disclose">
                    <summary>Show the raw response</summary>
                    <pre class="rv-raw">{{ json_encode($review->raw, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
                </details>
            </section>
            @endif
        </div>

        <div class="od-col od-col--side">
            <section class="od-card">
                <div class="od-card__head">
                    <h2 class="od-card__title">OpenCart</h2>
                </div>
                <div class="od-card__body od-card__body--tight">
                    <div class="od-fact">
                        <span class="od-fact__k">State</span>
                        <span class="od-fact__v">{{ ChannelStatusTone::labelFor('opencart.reviews', $review->oc_sync_status) }}</span>
                    </div>
                    @if($review->opencart_setting_id)
                        <div class="od-fact">
                            <span class="od-fact__k">Store</span>
                            <span class="od-fact__v od-fact__v--mono">{{ $review->opencart_setting_id }}</span>
                        </div>
                    @endif
                    @if($review->oc_review_id)
                        <div class="od-fact">
                            <span class="od-fact__k">Review ID there</span>
                            <span class="od-fact__v od-fact__v--mono">{{ $review->oc_review_id }}</span>
                        </div>
                    @endif
                    @if($review->oc_pushed_at)
                        <div class="od-fact">
                            <span class="od-fact__k">Pushed</span>
                            <span class="od-fact__v">{{ $review->oc_pushed_at->format('Y-m-d H:i') }}</span>
                        </div>
                    @endif
                    @if($review->oc_push_error)
                        <hr class="od-divider">
                        <p class="od-note od-note--fail"><span class="od-note__body">{{ $review->oc_push_error }}</span></p>
                    @endif
                </div>
            </section>

            <section class="od-card">
                <div class="od-card__head">
                    <h2 class="od-card__title">Venta</h2>
                </div>
                <div class="od-card__body od-card__body--tight">
                    <div class="od-fact">
                        <span class="od-fact__k">State</span>
                        <span class="od-fact__v">{{ ChannelStatusTone::labelFor('opencart.reviews', $ventaStatus) }}</span>
                    </div>
                    @if($review->venta_setting_id)
                        <div class="od-fact">
                            <span class="od-fact__k">Store</span>
                            <span class="od-fact__v od-fact__v--mono">{{ $review->venta_setting_id }}</span>
                        </div>
                    @endif
                    @if($review->venta_review_id)
                        <div class="od-fact">
                            <span class="od-fact__k">Review ID there</span>
                            <span class="od-fact__v od-fact__v--mono">{{ $review->venta_review_id }}</span>
                        </div>
                    @endif
                    @if($review->venta_pushed_at)
                        <div class="od-fact">
                            <span class="od-fact__k">Pushed</span>
                            <span class="od-fact__v">{{ $review->venta_pushed_at->format('Y-m-d H:i') }}</span>
                        </div>
                    @endif
                    @if($review->venta_push_error)
                        <hr class="od-divider">
                        <p class="od-note od-note--fail"><span class="od-note__body">{{ $review->venta_push_error }}</span></p>
                    @endif
                </div>
            </section>

            <section class="od-card">
                <div class="od-card__head">
                    <h2 class="od-card__title">Where it came from</h2>
                </div>
                <div class="od-card__body od-card__body--tight">
                    <div class="od-fact">
                        <span class="od-fact__k">Review</span>
                        <span class="od-fact__v od-fact__v--mono">{{ $review->platform_review_id }}</span>
                    </div>
                    @if($review->platform_item_id)
                        <div class="od-fact">
                            <span class="od-fact__k">Listing</span>
                            <span class="od-fact__v od-fact__v--mono">{{ $review->platform_item_id }}</span>
                        </div>
                    @endif
                    @if($review->platform_order_id)
                        <div class="od-fact">
                            <span class="od-fact__k">Order</span>
                            <span class="od-fact__v od-fact__v--mono">{{ $review->platform_order_id }}</span>
                        </div>
                    @endif
                    @if($review->product_id)
                        <div class="od-fact">
                            <span class="od-fact__k">Catalogue product</span>
                            <span class="od-fact__v od-fact__v--mono">{{ $review->product_id }}</span>
                        </div>
                    @endif
                </div>
            </section>
        </div>
    </div>

    {{-- Out of band: a <form> cannot live inside the menu panel or beside
         the header's primary button, and each is rendered only in the state
         its controller accepts. --}}
    @if($canManage && $ocOpen)
        <form id="rv-push" method="POST" action="{{ route('ext.opencart.reviews.push', $review->id) }}" class="x-sr">@csrf</form>
        <form id="rv-skip" method="POST" action="{{ route('ext.opencart.reviews.skip', $review->id) }}" class="x-sr">@csrf</form>
    @endif
    @if($ventaOpen)
        <form id="rv-venta" method="POST" action="{{ route('ext.venta.reviews.push', $review->id) }}" class="x-sr">@csrf</form>
    @endif
</div>
@endsection
