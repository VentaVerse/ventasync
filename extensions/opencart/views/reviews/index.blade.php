@extends('layouts.blotter')
@section('title', 'Marketplace reviews')

@section('content')
@php
    use App\Support\ChannelStatusTone;

    $canManage = auth()->user()?->hasPermission('manage_opencart/review') ?? false;

    $hasFilters = $q !== '' || $rating > 0 || $syncStatus !== '' || $hasMedia !== '' || $dateFrom !== '' || $dateTo !== '';

    $pushable = ['pending', 'error'];
@endphp

<div class="rv-page" data-reviews-page>

    <div class="x-list-head">
        <div>
            <h1 class="x-page-title">Marketplace reviews</h1>
            <p class="x-page-sub">
                {{ number_format($reviews->total()) }} {{ Str::plural('review', $reviews->total()) }} pulled from Shopee and Lazada,
                averaging {{ number_format($avgRating ?? 0, 1) }} of 5.
            </p>
        </div>

        @if($canManage)
        <div class="od-head__actions">
            <x-ui.button variant="primary" type="button" data-fetch-toggle
                         aria-controls="rv-fetch" aria-expanded="false">Fetch reviews</x-ui.button>

            <x-ui.menu label="Review actions">
                <button type="button" class="x-menu__item x-menu__item--danger"
                        data-confirm="Delete every review stored here? This removes all {{ number_format($platformCounts['all'] ?? 0) }} of them from VentaSync. Reviews already pushed to OpenCart stay there. This cannot be undone."
                        data-confirm-submit="rv-delete-all">Delete every review</button>
            </x-ui.menu>
        </div>
        @endif
    </div>

    @include('ext-opencart::reviews.partials.flash')

    @if($canManage)
    <div class="rv-fetch" id="rv-fetch" hidden>
        <form method="POST" action="{{ route('ext.opencart.reviews.fetch') }}" class="rv-fetch__row">
            @csrf
            <div class="rv-fetch__field">
                <label class="rv-fetch__label" for="rv-from">From</label>
                <x-ui.input type="date" id="rv-from" name="date_from" value="{{ old('date_from') }}" required />
            </div>
            <div class="rv-fetch__field">
                <label class="rv-fetch__label" for="rv-to">To</label>
                <x-ui.input type="date" id="rv-to" name="date_to" value="{{ old('date_to') }}" required />
            </div>
            <div class="rv-fetch__field">
                <label class="rv-fetch__label" for="rv-platform">Channel</label>
                <div class="x-select-wrap">
                    <select id="rv-platform" name="platform" class="x-input">
                        <option value="all">Shopee and Lazada</option>
                        <option value="shopee">Shopee only</option>
                        <option value="lazada">Lazada only</option>
                    </select>
                    <x-ui.icon name="chevron-down" size="14" class="x-select-wrap__chevron" />
                </div>
            </div>
            <x-ui.button variant="primary" type="submit">Fetch</x-ui.button>
        </form>
        <p class="rv-fetch__note">
            Fetching runs in the background and can take a few minutes.
            Reload this page afterwards to see what came in.
        </p>
    </div>
    @endif

    <div class="x-segment-bar">
        <div class="x-segment">
            @foreach($platforms as $key => $definition)
                <a class="x-segment__item @if($platform === $key) is-active @endif"
                   href="{{ route('ext.opencart.reviews.index', array_merge(request()->except('platform', 'page'), ['platform' => $key])) }}">
                    {{ $definition['label'] }}
                    @if(($platformCounts[$key] ?? 0) > 0)
                        <span class="x-segment__count">{{ number_format($platformCounts[$key]) }}</span>
                    @endif
                </a>
            @endforeach
        </div>
    </div>

    <form method="GET" action="{{ route('ext.opencart.reviews.index') }}" class="x-filters">
        <input type="hidden" name="platform" value="{{ $platform }}">

        <label class="x-sr" for="rv-q">Search</label>
        <x-ui.input type="search" id="rv-q" name="q" value="{{ $q }}"
                    placeholder="Author, comment or product" class="x-filters__search" />

        <div class="x-select-wrap x-filters__select x-filters__select--narrow">
            <label class="x-sr" for="rv-rating">Rating</label>
            <select id="rv-rating" name="rating" class="x-input" data-autosubmit>
                <option value="0">Any rating</option>
                @for($i = 5; $i >= 1; $i--)
                    <option value="{{ $i }}" @selected($rating === $i)>{{ $i }} {{ Str::plural('star', $i) }}</option>
                @endfor
            </select>
            <x-ui.icon name="chevron-down" size="14" class="x-select-wrap__chevron" />
        </div>

        <div class="x-select-wrap x-filters__select">
            <label class="x-sr" for="rv-sync">Sync state</label>
            <select id="rv-sync" name="sync_status" class="x-input" data-autosubmit>
                <option value="">Any sync state</option>
                @foreach(['pending', 'pushed', 'skipped', 'error'] as $state)
                    <option value="{{ $state }}" @selected($syncStatus === $state)>{{ ChannelStatusTone::labelFor('opencart.reviews', $state) }}</option>
                @endforeach
            </select>
            <x-ui.icon name="chevron-down" size="14" class="x-select-wrap__chevron" />
        </div>

        <div class="x-select-wrap x-filters__select x-filters__select--narrow">
            <label class="x-sr" for="rv-media">Media</label>
            <select id="rv-media" name="has_media" class="x-input" data-autosubmit>
                <option value="">With or without media</option>
                <option value="any" @selected($hasMedia === 'any')>Has media</option>
                <option value="photos" @selected($hasMedia === 'photos')>Has photos</option>
                <option value="videos" @selected($hasMedia === 'videos')>Has videos</option>
            </select>
            <x-ui.icon name="chevron-down" size="14" class="x-select-wrap__chevron" />
        </div>

        <div class="rp-range">
            <label class="x-sr" for="rv-date-from">Reviewed from</label>
            <input id="rv-date-from" type="date" name="date_from" value="{{ $dateFrom }}">
            <span class="rp-range__sep" aria-hidden="true">to</span>
            <label class="x-sr" for="rv-date-to">Reviewed to</label>
            <input id="rv-date-to" type="date" name="date_to" value="{{ $dateTo }}">
        </div>

        <x-ui.button type="submit">Search</x-ui.button>

        @if($hasFilters)
            <a class="x-filters__reset" href="{{ route('ext.opencart.reviews.index', ['platform' => $platform]) }}">Clear</a>
        @endif
    </form>

    @if($canManage && $pendingCount > 0)
        <div class="rv-nudge">
            <span>{{ number_format($pendingCount) }} {{ Str::plural('review', $pendingCount) }} {{ $pendingCount === 1 ? 'has' : 'have' }} not been pushed to OpenCart yet.</span>
            <x-ui.button type="button"
                         data-confirm="Push every review that is waiting or that failed? This runs in the background against your OpenCart stores."
                         data-confirm-submit="rv-push-all">Push them all</x-ui.button>
        </div>
    @endif

    @if($reviews->isEmpty())
        <x-ui.empty title="{{ $hasFilters ? 'No reviews match those filters' : 'No reviews stored yet' }}"
                    description="{{ $hasFilters ? 'Try a different search, any rating, or a wider date range.' : 'Use Fetch reviews to pull what Shopee and Lazada already hold for your listings.' }}" />
    @else
        @if($canManage)
        <form method="POST" action="{{ route('ext.opencart.reviews.bulk_push') }}" id="rv-bulk-form"
              data-confirm="Push the selected reviews to OpenCart?">@csrf</form>

        <div class="rv-bulk" data-bulk-bar hidden>
            <span class="rv-bulk__count" data-bulk-count>0 reviews selected</span>
            <x-ui.button variant="primary" type="submit" form="rv-bulk-form">Push selected</x-ui.button>
        </div>
        @endif

        <x-ui.table>
            <x-slot:head>
                <tr>
                    @if($canManage)
                        <th scope="col" class="rv-col-check">
                            <input type="checkbox" class="rv-check" data-select-all aria-label="Select every pushable review on this page">
                        </th>
                    @endif
                    <th scope="col" class="rv-col-date">Reviewed</th>
                    <th scope="col" class="rv-col-plat">Channel</th>
                    <th scope="col">Product</th>
                    <th scope="col" class="rv-col-rate">Rating</th>
                    <th scope="col">Comment</th>
                    <th scope="col" class="rv-col-media">Media</th>
                    <th scope="col" class="rv-col-stat">OpenCart</th>
                    <th scope="col" class="x-td-actions"><span class="x-sr">Actions</span></th>
                </tr>
            </x-slot:head>

            @foreach($reviews as $review)
                @php
                    $images = $review->images ?? [];
                    $videos = $review->videos ?? [];
                    $canPush = in_array($review->oc_sync_status, $pushable, true);
                @endphp
                <tr>
                    @if($canManage)
                        <td class="rv-col-check">
                            @if($canPush)
                                <input type="checkbox" class="rv-check" data-review-check
                                       name="ids[]" value="{{ $review->id }}" form="rv-bulk-form"
                                       aria-label="Select review {{ $review->platform_review_id }}">
                            @endif
                        </td>
                    @endif

                    <td class="rv-col-date x-cell-muted x-num" data-label="Reviewed">
                        <a class="x-row-link" href="{{ route('ext.opencart.reviews.show', $review->id) }}">
                            {{ $review->reviewed_at ? $review->reviewed_at->format('Y-m-d') : 'Unknown' }}
                        </a>
                    </td>
                    <td class="rv-col-plat" data-label="Channel">
                        <x-ui.badge :tone="$review->platform">{{ Str::headline($review->platform) }}</x-ui.badge>
                    </td>
                    <td class="x-cell-strong" data-label="Product">{{ $review->product_name ?? 'Not matched to a product' }}</td>
                    <td class="rv-col-rate" data-label="Rating">
                        @include('ext-opencart::reviews.partials.stars', ['rating' => $review->rating])
                    </td>
                    {{-- Customer-written text: keep it escaped, never raw. --}}
                    <td data-label="Comment">
                        <span class="rv-snippet">{{ $review->comment ?: 'No comment' }}</span>
                    </td>
                    <td class="rv-col-media" data-label="Media">
                        @if(!empty($images) || !empty($videos))
                            <span class="rv-media">
                                @foreach(array_slice($images, 0, 2) as $image)
                                    <img class="rv-thumb" src="{{ $image }}" alt="" loading="lazy" referrerpolicy="no-referrer">
                                @endforeach
                                @if(count($images) > 2)
                                    <span class="rv-media__more">+{{ count($images) - 2 }}</span>
                                @endif
                                @if(!empty($videos))
                                    <span class="rv-media__vid" title="{{ count($videos) }} {{ Str::plural('video', count($videos)) }}">
                                        <x-ui.icon name="chevron-right" size="10" />{{ count($videos) }}
                                    </span>
                                @endif
                            </span>
                        @else
                            <span class="rv-none">&ndash;</span>
                        @endif
                    </td>
                    <td class="rv-col-stat" data-label="OpenCart">
                        <x-ui.badge :tone="ChannelStatusTone::toneFor('opencart.reviews', $review->oc_sync_status)">{{ ChannelStatusTone::labelFor('opencart.reviews', $review->oc_sync_status) }}</x-ui.badge>
                    </td>
                    <td class="x-td-actions">
                        @if($canManage && $canPush)
                            <x-ui.menu label="Actions for review {{ $review->platform_review_id }}">
                                <a class="x-menu__item" href="{{ route('ext.opencart.reviews.show', $review->id) }}">Open</a>
                                <div class="x-menu__sep"></div>
                                <button type="submit" class="x-menu__item" form="rv-push-{{ $review->id }}">
                                    {{ $review->oc_sync_status === 'error' ? 'Try the push again' : 'Push to OpenCart' }}
                                </button>
                                <button type="submit" class="x-menu__item" form="rv-skip-{{ $review->id }}">Skip this one</button>
                            </x-ui.menu>
                        @endif
                    </td>
                </tr>
            @endforeach
        </x-ui.table>

        <x-ui.pager :paginator="$reviews" />

        @if($canManage)
            @foreach($reviews as $review)
                @if(in_array($review->oc_sync_status, $pushable, true))
                    <form id="rv-push-{{ $review->id }}" method="POST" action="{{ route('ext.opencart.reviews.push', $review->id) }}" class="x-sr">@csrf</form>
                    <form id="rv-skip-{{ $review->id }}" method="POST" action="{{ route('ext.opencart.reviews.skip', $review->id) }}" class="x-sr">@csrf</form>
                @endif
            @endforeach
        @endif
    @endif

    @if($canManage)
        <form id="rv-push-all" method="POST" action="{{ route('ext.opencart.reviews.push_all') }}" class="x-sr">@csrf</form>
        <form id="rv-delete-all" method="POST" action="{{ route('ext.opencart.reviews.delete_all') }}" class="x-sr">@csrf</form>
    @endif
</div>
@endsection
