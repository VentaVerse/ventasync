@extends('layouts.app')
@section('breadcrumb', 'Integrations / Reviews')

@section('content')

{{-- Fetch / Delete bar --}}
<div class="card mb-16">
    <form method="POST" action="{{ route('ext.opencart.reviews.fetch') }}" id="formFetchReviews" class="d-flex gap-12 flex-wrap items-center">
        @csrf
        <div>
            <label class="text-xs text-secondary">From</label>
            <input type="date" name="date_from" value="{{ old('date_from') }}" class="input" style="width:auto;">
        </div>
        <div>
            <label class="text-xs text-secondary">To</label>
            <input type="date" name="date_to" value="{{ old('date_to') }}" class="input" style="width:auto;">
        </div>
        <div>
            <label class="text-xs text-secondary">Platform</label>
            <select class="input" name="platform">
                <option value="all">Shopee + Lazada</option>
                <option value="shopee">Shopee only</option>
                <option value="lazada">Lazada only</option>
            </select>
        </div>
        <div class="d-flex gap-8" style="align-self:flex-end;">
            <button type="submit" class="btn" id="btnFetchReviews">Fetch Reviews</button>
            <form method="POST" action="{{ route('ext.opencart.reviews.delete_all') }}" style="margin:0; display:inline;" data-confirm="Delete ALL reviews? This cannot be undone.">
                @csrf
                <button type="submit" class="btn danger">Delete All</button>
            </form>
        </div>
    </form>
</div>

@if(session('review_result'))
    @php $rr = session('review_result'); @endphp
    <div class="alert {{ ($rr['ok'] ?? false) ? 'success' : 'error' }} mb-16">{{ $rr['message'] ?? '' }}</div>
@endif

<div class="card">
    <div class="page-header">
        <h2>Marketplace Reviews</h2>
        <div class="page-header-actions d-flex gap-8">
            <span class="text-secondary" style="font-size:13px; line-height:36px;">
                Avg: <strong>{{ number_format($avgRating ?? 0, 1) }}</strong>/5
                &middot; {{ $pendingCount }} pending
            </span>
            @if($pendingCount > 0)
                <form method="POST" action="{{ route('ext.opencart.reviews.push_all') }}" style="display:inline;" data-confirm="Push ALL pending/error reviews to OpenCart? This runs in the background.">
                    @csrf
                    <button class="btn" type="submit">Push All to OC</button>
                </form>
                <form method="POST" action="{{ route('ext.opencart.reviews.bulk_push') }}" id="bulkForm" data-confirm="Push all selected reviews to OpenCart?">
                    @csrf
                    <button class="btn secondary" type="submit" id="bulkPushBtn" disabled>Push Selected</button>
                </form>
            @endif
        </div>
    </div>

    {{-- Platform Tabs --}}
    <div class="tabs mb-16">
        @foreach($platforms as $key => $plat)
            <a class="tab {{ $platform === $key ? 'active' : '' }}"
               href="{{ route('ext.opencart.reviews.index', array_merge(request()->except('platform', 'page'), ['platform' => $key])) }}">
                {{ $plat['label'] }}
                @if(isset($platformCounts[$key]) && $platformCounts[$key] > 0)
                    <span style="font-size:11px; color:var(--text-muted); margin-left:4px;">({{ number_format($platformCounts[$key]) }})</span>
                @endif
            </a>
        @endforeach
    </div>

    {{-- Filters --}}
    <form method="GET" action="{{ route('ext.opencart.reviews.index') }}" class="d-flex gap-8 items-end mb-16" style="flex-wrap:wrap;">
        <input type="hidden" name="platform" value="{{ $platform }}">
        <div>
            <label class="text-xs text-secondary">Search</label>
            <input class="input" name="q" value="{{ $q }}" placeholder="Author, comment, product..." style="width:200px;">
        </div>
        <div>
            <label class="text-xs text-secondary">Rating</label>
            <select class="input" name="rating" style="width:100px;">
                <option value="0">All</option>
                @for($i = 5; $i >= 1; $i--)
                    <option value="{{ $i }}" {{ $rating === $i ? 'selected' : '' }}>{{ $i }} Star{{ $i > 1 ? 's' : '' }}</option>
                @endfor
            </select>
        </div>
        <div>
            <label class="text-xs text-secondary">Sync Status</label>
            <select class="input" name="sync_status" style="width:130px;">
                <option value="">All</option>
                <option value="pending" {{ $syncStatus === 'pending' ? 'selected' : '' }}>Pending</option>
                <option value="pushed" {{ $syncStatus === 'pushed' ? 'selected' : '' }}>Pushed</option>
                <option value="skipped" {{ $syncStatus === 'skipped' ? 'selected' : '' }}>Skipped</option>
                <option value="error" {{ $syncStatus === 'error' ? 'selected' : '' }}>Error</option>
            </select>
        </div>
        <div>
            <label class="text-xs text-secondary">Media</label>
            <select class="input" name="has_media" style="width:120px;">
                <option value="">All</option>
                <option value="any" {{ $hasMedia === 'any' ? 'selected' : '' }}>With Media</option>
                <option value="photos" {{ $hasMedia === 'photos' ? 'selected' : '' }}>Photos</option>
                <option value="videos" {{ $hasMedia === 'videos' ? 'selected' : '' }}>Videos</option>
            </select>
        </div>
        <div>
            <label class="text-xs text-secondary">From</label>
            <input class="input" type="date" name="date_from" value="{{ $dateFrom }}" style="width:150px;">
        </div>
        <div>
            <label class="text-xs text-secondary">To</label>
            <input class="input" type="date" name="date_to" value="{{ $dateTo }}" style="width:150px;">
        </div>
        <button class="btn" type="submit">Filter</button>
        @if($q || $rating || $syncStatus || $hasMedia || $dateFrom || $dateTo)
            <a class="btn secondary" href="{{ route('ext.opencart.reviews.index', ['platform' => $platform]) }}">Reset</a>
        @endif
    </form>

    @if($reviews->isEmpty())
        <div class="text-secondary text-center py-32">No reviews found.</div>
    @else
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th style="width:32px;"><input type="checkbox" id="selectAll"></th>
                        <th style="width:130px;">Date</th>
                        <th style="width:80px;">Platform</th>
                        <th>Product</th>
                        <th style="width:120px;">Author</th>
                        <th style="width:90px;">Rating</th>
                        <th>Comment</th>
                        <th style="width:120px;">Media</th>
                        <th style="width:80px;">Status</th>
                        <th style="width:100px;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($reviews as $review)
                        <tr>
                            <td>
                                @if(in_array($review->oc_sync_status, ['pending', 'error']))
                                    <input type="checkbox" class="review-cb" value="{{ $review->id }}">
                                @endif
                            </td>
                            <td class="text-xs text-secondary">
                                {{ $review->reviewed_at ? $review->reviewed_at->format('M d, Y') : '—' }}
                            </td>
                            <td>
                                <span class="review-platform review-platform--{{ $review->platform }}">{{ ucfirst($review->platform) }}</span>
                            </td>
                            <td class="text-xs">
                                {{ \Illuminate\Support\Str::limit($review->product_name ?? '(unmapped)', 40) }}
                            </td>
                            <td class="text-xs">{{ \Illuminate\Support\Str::limit($review->author ?? '—', 20) }}</td>
                            <td>
                                <span class="review-stars">
                                    @for($i = 1; $i <= 5; $i++)
                                        <span class="{{ $i <= $review->rating ? 'star-filled' : 'star-empty' }}">&#9733;</span>
                                    @endfor
                                </span>
                            </td>
                            <td class="text-xs">{{ \Illuminate\Support\Str::limit($review->comment ?? '', 60) }}</td>
                            <td>
                                @php
                                    $imgs = $review->images ?? [];
                                    $vids = $review->videos ?? [];
                                @endphp
                                @if(!empty($imgs) || !empty($vids))
                                    <div class="review-media-inline">
                                        @foreach(array_slice($imgs, 0, 3) as $imgUrl)
                                            <img src="{{ $imgUrl }}" alt="" class="review-thumb-sm" loading="lazy">
                                        @endforeach
                                        @if(count($imgs) > 3)
                                            <span class="review-media-more">+{{ count($imgs) - 3 }}</span>
                                        @endif
                                        @if(!empty($vids))
                                            <span class="review-media-badge" title="{{ count($vids) }} video(s)">&#9654; {{ count($vids) }}</span>
                                        @endif
                                    </div>
                                @else
                                    <span class="text-secondary text-xs">&mdash;</span>
                                @endif
                            </td>
                            <td>
                                @php
                                    $statusColors = [
                                        'pending' => '#64748b',
                                        'pushed'  => '#16a34a',
                                        'skipped' => '#d97706',
                                        'error'   => '#dc2626',
                                    ];
                                    $sc = $statusColors[$review->oc_sync_status] ?? '#64748b';
                                @endphp
                                <span style="display:inline-block; padding:2px 8px; border-radius:99px; font-size:11px; font-weight:600; background:{{ $sc }}15; color:{{ $sc }};">
                                    {{ ucfirst($review->oc_sync_status) }}
                                </span>
                            </td>
                            <td class="d-flex gap-4">
                                <a href="{{ route('ext.opencart.reviews.show', $review->id) }}" class="btn secondary" style="font-size:11px; padding:2px 8px;">View</a>
                                @if(in_array($review->oc_sync_status, ['pending', 'error']))
                                    <form method="POST" action="{{ route('ext.opencart.reviews.push', $review->id) }}" style="display:inline;">
                                        @csrf
                                        <button class="btn{{ $review->oc_sync_status === 'error' ? ' danger' : '' }}" style="font-size:11px; padding:2px 8px;" type="submit">{{ $review->oc_sync_status === 'error' ? 'Retry' : 'Push' }}</button>
                                    </form>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="mt-16">
            {{ $reviews->onEachSide(1)->links('vendor.pagination.simple') }}
        </div>
    @endif
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const selectAll = document.getElementById('selectAll');
    const bulkForm = document.getElementById('bulkForm');
    const bulkBtn = document.getElementById('bulkPushBtn');
    const checkboxes = document.querySelectorAll('.review-cb');

    if (selectAll) {
        selectAll.addEventListener('change', function() {
            checkboxes.forEach(cb => cb.checked = this.checked);
            updateBulkBtn();
        });
    }

    checkboxes.forEach(cb => cb.addEventListener('change', updateBulkBtn));

    function updateBulkBtn() {
        if (!bulkBtn) return;
        const checked = document.querySelectorAll('.review-cb:checked');
        bulkBtn.disabled = checked.length === 0;
        bulkBtn.textContent = checked.length > 0 ? 'Push Selected (' + checked.length + ')' : 'Push Selected';
    }

    if (bulkForm) {
        bulkForm.addEventListener('submit', function() {
            const checked = document.querySelectorAll('.review-cb:checked');
            checked.forEach(cb => {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'ids[]';
                input.value = cb.value;
                bulkForm.appendChild(input);
            });
        });
    }
});
</script>
@endsection
