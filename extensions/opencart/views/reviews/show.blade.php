@extends('layouts.app')
@section('breadcrumb', 'Integrations / Reviews / Detail')

@section('content')
<div class="card">
    <div class="page-header">
        <h2>Review Detail</h2>
        <div class="page-header-actions d-flex gap-8">
            <a href="{{ route('ext.opencart.reviews.index') }}" class="btn secondary">Back to Reviews</a>
            @if(in_array($review->oc_sync_status, ['pending', 'error']))
                <form method="POST" action="{{ route('ext.opencart.reviews.push', $review->id) }}" style="display:inline;">
                    @csrf
                    <button class="btn" type="submit">Push to OpenCart</button>
                </form>
            @endif
            @if(in_array($review->venta_sync_status ?? 'pending', ['pending', 'error']) && Route::has('ext.venta.reviews.push'))
                <form method="POST" action="{{ route('ext.venta.reviews.push', $review->id) }}" style="display:inline;">
                    @csrf
                    <button class="btn" type="submit">Push to Venta</button>
                </form>
            @endif
            @if(in_array($review->oc_sync_status, ['pending', 'error']))
                <form method="POST" action="{{ route('ext.opencart.reviews.skip', $review->id) }}" style="display:inline;">
                    @csrf
                    <button class="btn secondary" type="submit">Skip</button>
                </form>
            @endif
        </div>
    </div>

    <div class="review-detail">
        {{-- Header --}}
        <div class="d-flex gap-16 items-start mb-16" style="flex-wrap:wrap;">
            <div style="flex:1; min-width:200px;">
                <div class="text-xs text-secondary mb-4">Platform</div>
                <span class="review-platform review-platform--{{ $review->platform }}">{{ ucfirst($review->platform) }}</span>
            </div>
            <div style="flex:1; min-width:200px;">
                <div class="text-xs text-secondary mb-4">Product</div>
                <strong>{{ $review->product_name ?? '(unmapped)' }}</strong>
                @if($review->product_id)
                    <span class="text-xs text-secondary">(#{{ $review->product_id }})</span>
                @endif
            </div>
            <div style="flex:1; min-width:200px;">
                <div class="text-xs text-secondary mb-4">Author</div>
                <strong>{{ $review->author ?: '—' }}</strong>
            </div>
            <div style="flex:1; min-width:120px;">
                <div class="text-xs text-secondary mb-4">Date</div>
                {{ $review->reviewed_at ? $review->reviewed_at->format('M d, Y H:i') : '—' }}
            </div>
        </div>

        {{-- Rating --}}
        <div class="mb-16">
            <div class="text-xs text-secondary mb-4">Rating</div>
            <span class="review-stars" style="font-size:24px;">
                @for($i = 1; $i <= 5; $i++)
                    <span class="{{ $i <= $review->rating ? 'star-filled' : 'star-empty' }}">&#9733;</span>
                @endfor
            </span>
            <span class="text-secondary" style="margin-left:8px;">{{ $review->rating }}/5</span>
        </div>

        {{-- Comment --}}
        <div class="mb-16">
            <div class="text-xs text-secondary mb-4">Comment</div>
            <div style="background:var(--bg-subtle); padding:12px 16px; border-radius:8px; line-height:1.6;">
                {{ $review->comment ?: '(no comment)' }}
            </div>
        </div>

        {{-- Images --}}
        @if(!empty($review->images))
        <div class="mb-16">
            <div class="text-xs text-secondary mb-4">Images ({{ count($review->images) }})</div>
            <div class="d-flex gap-8" style="flex-wrap:wrap;">
                @foreach($review->images as $imgUrl)
                    <a href="{{ $imgUrl }}" target="_blank" rel="noopener">
                        <img src="{{ $imgUrl }}" alt="Review image" class="review-thumb">
                    </a>
                @endforeach
            </div>
        </div>
        @endif

        {{-- Videos --}}
        @if(!empty($review->videos))
        <div class="mb-16">
            <div class="text-xs text-secondary mb-4">Videos ({{ count($review->videos) }})</div>
            <div class="d-flex gap-8" style="flex-wrap:wrap;">
                @foreach($review->videos as $vidUrl)
                    <a href="{{ $vidUrl }}" target="_blank" rel="noopener" class="btn secondary" style="font-size:12px;">
                        Video {{ $loop->iteration }}
                    </a>
                @endforeach
            </div>
        </div>
        @endif

        {{-- Seller Reply --}}
        @if($review->reply)
        <div class="mb-16">
            <div class="text-xs text-secondary mb-4">Seller Reply</div>
            <div style="background:#f0fdf4; padding:12px 16px; border-radius:8px; border-left:3px solid #16a34a; line-height:1.6;">
                {{ $review->reply }}
                @if($review->replied_at)
                    <div class="text-xs text-secondary mt-4">Replied: {{ $review->replied_at->format('M d, Y H:i') }}</div>
                @endif
            </div>
        </div>
        @endif

        {{-- Sync Status --}}
        <div class="mb-16" style="background:var(--bg-subtle); padding:16px; border-radius:8px;">
            <div class="text-xs text-secondary mb-8" style="font-weight:600;">OpenCart Sync</div>
            <div class="d-flex gap-16" style="flex-wrap:wrap;">
                <div>
                    <span class="text-xs text-secondary">Status:</span>
                    @php
                        $statusColors = ['pending' => '#64748b', 'pushed' => '#16a34a', 'skipped' => '#d97706', 'error' => '#dc2626'];
                        $sc = $statusColors[$review->oc_sync_status] ?? '#64748b';
                    @endphp
                    <span style="display:inline-block; padding:2px 8px; border-radius:99px; font-size:11px; font-weight:600; background:{{ $sc }}15; color:{{ $sc }};">
                        {{ ucfirst($review->oc_sync_status) }}
                    </span>
                </div>
                @if($review->opencart_setting_id)
                <div><span class="text-xs text-secondary">Store ID:</span> {{ $review->opencart_setting_id }}</div>
                @endif
                @if($review->oc_review_id)
                <div><span class="text-xs text-secondary">OC Review ID:</span> {{ $review->oc_review_id }}</div>
                @endif
                @if($review->oc_pushed_at)
                <div><span class="text-xs text-secondary">Pushed:</span> {{ $review->oc_pushed_at->format('M d, Y H:i') }}</div>
                @endif
                @if($review->oc_push_error)
                <div style="color:#dc2626;"><span class="text-xs text-secondary">Error:</span> {{ $review->oc_push_error }}</div>
                @endif
            </div>
        </div>

        {{-- Venta Sync Status --}}
        <div class="mb-16" style="background:var(--bg-subtle); padding:16px; border-radius:8px;">
            <div class="text-xs text-secondary mb-8" style="font-weight:600;">Venta Sync</div>
            <div class="d-flex gap-16" style="flex-wrap:wrap;">
                <div>
                    <span class="text-xs text-secondary">Status:</span>
                    @php
                        $vStatusColors = ['pending' => '#64748b', 'pushed' => '#16a34a', 'skipped' => '#d97706', 'error' => '#dc2626'];
                        $vsc = $vStatusColors[$review->venta_sync_status ?? 'pending'] ?? '#64748b';
                    @endphp
                    <span style="display:inline-block; padding:2px 8px; border-radius:99px; font-size:11px; font-weight:600; background:{{ $vsc }}15; color:{{ $vsc }};">
                        {{ ucfirst($review->venta_sync_status ?? 'pending') }}
                    </span>
                </div>
                @if($review->venta_setting_id)
                <div><span class="text-xs text-secondary">Store ID:</span> {{ $review->venta_setting_id }}</div>
                @endif
                @if($review->venta_review_id)
                <div><span class="text-xs text-secondary">Venta Review ID:</span> {{ $review->venta_review_id }}</div>
                @endif
                @if($review->venta_pushed_at)
                <div><span class="text-xs text-secondary">Pushed:</span> {{ $review->venta_pushed_at->format('M d, Y H:i') }}</div>
                @endif
                @if($review->venta_push_error)
                <div style="color:#dc2626;"><span class="text-xs text-secondary">Error:</span> {{ $review->venta_push_error }}</div>
                @endif
            </div>
        </div>

        {{-- Platform IDs --}}
        <div class="mb-16">
            <div class="d-flex gap-16" style="flex-wrap:wrap; font-size:12px;">
                <div><span class="text-secondary">Platform Review ID:</span> {{ $review->platform_review_id }}</div>
                @if($review->platform_item_id)
                <div><span class="text-secondary">Item ID:</span> {{ $review->platform_item_id }}</div>
                @endif
                @if($review->platform_order_id)
                <div><span class="text-secondary">Order ID:</span> {{ $review->platform_order_id }}</div>
                @endif
            </div>
        </div>

        {{-- Raw JSON --}}
        @if($review->raw)
        <details style="margin-top:16px;">
            <summary class="text-xs text-secondary" style="cursor:pointer;">Raw API Response</summary>
            <pre style="margin-top:8px; background:var(--bg-subtle); padding:12px; border-radius:8px; font-size:11px; overflow-x:auto; max-height:400px;">{{ json_encode($review->raw, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
        </details>
        @endif
    </div>
</div>
@endsection
