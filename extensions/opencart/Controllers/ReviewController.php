<?php

namespace Extensions\OpenCart\Controllers;

use Extensions\OpenCart\Models\MarketplaceReview;
use Extensions\OpenCart\Models\OpenCartListingLink;
use Extensions\OpenCart\Models\OpenCartStore;
use Extensions\OpenCart\Services\OpenCart\OpenCartClient;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;

class ReviewController extends Controller
{
    private const CHANNELS = [
        'all'    => ['label' => 'All'],
        'shopee' => ['label' => 'Shopee'],
        'lazada' => ['label' => 'Lazada'],
    ];

    public function index(Request $request)
    {
        $channel   = trim((string) $request->get('channel', 'all'));
        $rating     = (int) $request->get('rating', 0);
        $syncStatus = trim((string) $request->get('sync_status', ''));
        $hasMedia   = trim((string) $request->get('has_media', ''));
        $q          = trim((string) $request->get('q', ''));
        $dateFrom   = trim((string) $request->get('date_from', ''));
        $dateTo     = trim((string) $request->get('date_to', ''));

        $query = MarketplaceReview::query()
            ->leftJoin('product_descriptions as pd', function ($join)  {
                $join->on('ch_marketplace_reviews.product_id', '=', 'pd.product_id')
                     ->where('pd.language_id', '=', config('catalog.default_language_id', 1));
            })
            ->select('ch_marketplace_reviews.*', 'pd.name as product_name')
            ->orderByDesc('ch_marketplace_reviews.reviewed_at');

        if ($channel !== 'all' && isset(self::CHANNELS[$channel])) {
            $query->where('ch_marketplace_reviews.channel', $channel);
        }

        if ($rating > 0 && $rating <= 5) {
            $query->where('ch_marketplace_reviews.rating', $rating);
        }

        if ($syncStatus !== '') {
            $query->where('ch_marketplace_reviews.oc_sync_status', $syncStatus);
        }

        if ($q !== '') {
            $query->where(function ($w) use ($q) {
                $w->where('ch_marketplace_reviews.author', 'like', '%' . $q . '%')
                  ->orWhere('ch_marketplace_reviews.comment', 'like', '%' . $q . '%')
                  ->orWhere('pd.name', 'like', '%' . $q . '%');
            });
        }

        if ($dateFrom !== '') {
            $query->where('ch_marketplace_reviews.reviewed_at', '>=', $dateFrom . ' 00:00:00');
        }

        if ($dateTo !== '') {
            $query->where('ch_marketplace_reviews.reviewed_at', '<=', $dateTo . ' 23:59:59');
        }

        if ($hasMedia === 'photos') {
            $query->whereNotNull('ch_marketplace_reviews.images');
        } elseif ($hasMedia === 'videos') {
            $query->whereNotNull('ch_marketplace_reviews.videos');
        } elseif ($hasMedia === 'any') {
            $query->where(function ($w) {
                $w->whereNotNull('ch_marketplace_reviews.images')
                  ->orWhereNotNull('ch_marketplace_reviews.videos');
            });
        }

        $reviews = $query->paginate(50)->withQueryString();

        $channelCounts = [];
        $channelCounts['all'] = MarketplaceReview::count();
        $channelCounts['shopee'] = MarketplaceReview::where('channel', 'shopee')->count();
        $channelCounts['lazada'] = MarketplaceReview::where('channel', 'lazada')->count();

        $avgRating = MarketplaceReview::avg('rating');
        $pendingCount = MarketplaceReview::where('oc_sync_status', 'pending')->count();

        $channels = self::CHANNELS;

        return view('ext-opencart::reviews.index', compact(
            'reviews', 'channel', 'rating', 'syncStatus', 'hasMedia', 'q', 'dateFrom', 'dateTo',
            'channels', 'channelCounts', 'avgRating', 'pendingCount'
        ));
    }

    public function show($id)
    {

        $review = MarketplaceReview::query()
            ->leftJoin('product_descriptions as pd', function ($join) {
                $join->on('ch_marketplace_reviews.product_id', '=', 'pd.product_id')
                     ->where('pd.language_id', '=', config('catalog.default_language_id', 1));
            })
            ->select('ch_marketplace_reviews.*', 'pd.name as product_name')
            ->where('ch_marketplace_reviews.id', $id)
            ->firstOrFail();

        return view('ext-opencart::reviews.show', compact('review'));
    }

    public function pushToOpenCart(Request $request, $id)
    {
        $review = MarketplaceReview::findOrFail($id);

        if (!in_array($review->oc_sync_status, ['pending', 'error'])) {
            return redirect()->back()->with('error', 'Review is not in pending or error status.');
        }

        if (!$review->product_id) {
            return redirect()->back()->with('error', 'Review has no mapped product.');
        }

        $link = OpenCartListingLink::query()
            ->where('product_id', $review->product_id)
            ->first();

        if (!$link) {
            return redirect()->back()->with('error', 'Product not linked to any OpenCart store.');
        }

        $store = OpenCartStore::find($link->store_id);
        if (!$store || !$store->enabled) {
            return redirect()->back()->with('error', 'OpenCart store not found or disabled.');
        }

        $client = new OpenCartClient($store);
        $autoApprove = (bool) ($store->review_auto_approve ?? true);

        $res = $client->createReview([
            'product_id'         => $link->oc_product_id,
            'author'             => $review->author ?: 'Marketplace Buyer',
            'text'               => $review->comment ?? '',
            'rating'             => $review->rating,
            'status'             => $autoApprove ? 1 : 0,
            'date_added'         => $review->reviewed_at?->format('Y-m-d H:i:s') ?? now()->format('Y-m-d H:i:s'),
            'platform'           => $review->channel,
            'platform_review_id' => $review->channel_review_id,
        ]);

        if ($res['ok'] ?? false) {
            $review->update([
                'oc_sync_status'      => 'pushed',
                'opencart_store_id' => $store->id,
                'oc_review_id'        => $res['body']['data']['review_id'] ?? null,
                'oc_pushed_at'        => now(),
                'oc_push_error'       => null,
            ]);
            return redirect()->back()->with('status', 'Review pushed to OpenCart.');
        }

        $error = $res['body']['error'] ?? 'Unknown error';
        $review->update([
            'oc_sync_status'      => 'error',
            'opencart_store_id' => $store->id,
            'oc_push_error'       => substr($error, 0, 500),
        ]);

        return redirect()->back()->with('error', 'Push failed: ' . $error);
    }

    public function skip(Request $request, $id)
    {
        $review = MarketplaceReview::findOrFail($id);

        if (!in_array($review->oc_sync_status, ['pending', 'error'])) {
            return redirect()->back()->with('error', 'Review is not in pending or error status.');
        }

        $review->update(['oc_sync_status' => 'skipped']);

        return redirect()->back()->with('status', 'Review marked as skipped.');
    }

    public function bulkPush(Request $request)
    {
        $ids = $request->input('ids', []);

        if (empty($ids)) {
            return redirect()->back()->with('error', 'No reviews selected.');
        }

        $reviews = MarketplaceReview::whereIn('id', $ids)
            ->whereIn('oc_sync_status', ['pending', 'error'])
            ->whereNotNull('product_id')
            ->get();

        $pushed = 0;
        $errors = 0;

        $stores = OpenCartStore::where('enabled', true)->get()->keyBy('id');
        $linkMap = OpenCartListingLink::query()
            ->pluck('oc_product_id', DB::raw("CONCAT(store_id, '-', product_id)"))
            ->all();

        foreach ($reviews as $review) {
            $pushed_this = false;
            foreach ($stores as $store) {
                $key = $store->id . '-' . $review->product_id;
                $ocProductId = $linkMap[$key] ?? null;
                if (!$ocProductId) continue;

                $client = new OpenCartClient($store);
                $autoApprove = (bool) ($store->review_auto_approve ?? true);

                $res = $client->createReview([
                    'product_id'         => $ocProductId,
                    'author'             => $review->author ?: 'Marketplace Buyer',
                    'text'               => $review->comment ?? '',
                    'rating'             => $review->rating,
                    'status'             => $autoApprove ? 1 : 0,
                    'date_added'         => $review->reviewed_at?->format('Y-m-d H:i:s') ?? now()->format('Y-m-d H:i:s'),
                    'platform'           => $review->channel,
                    'platform_review_id' => $review->channel_review_id,
                ]);

                if ($res['ok'] ?? false) {
                    $review->update([
                        'oc_sync_status'      => 'pushed',
                        'opencart_store_id' => $store->id,
                        'oc_review_id'        => $res['body']['data']['review_id'] ?? null,
                        'oc_pushed_at'        => now(),
                        'oc_push_error'       => null,
                    ]);
                    $pushed++;
                    $pushed_this = true;
                    break;
                }
            }

            if (!$pushed_this) {
                $review->update([
                    'oc_sync_status' => 'error',
                    'oc_push_error'  => 'No matching OpenCart product link found, or API call failed.',
                ]);
                $errors++;
            }
        }

        return redirect()->back()->with('status', "Pushed {$pushed} review(s)." . ($errors > 0 ? " {$errors} failed." : ''));
    }

    public function fetch(Request $request)
    {
        $data = $request->validate([
            'date_from' => 'required|date',
            'date_to'   => 'required|date|after_or_equal:date_from',
            'channel'  => 'nullable|string|in:all,shopee,lazada',
        ]);

        $from = $data['date_from'];
        $channel = $data['channel'] ?? 'all';
        $daysBack = max(1, (int) abs(now()->diffInDays(\Carbon\Carbon::parse($from))) + 1);

        $php = PHP_BINARY ?: '/usr/bin/php';
        $artisan = base_path('artisan');
        $launched = [];

        if ($channel === 'all' || $channel === 'shopee') {
            \Extensions\Shopee\Models\ShopeeStore::query()->update(['last_review_sync_at' => $from . ' 00:00:00']);
            Process::start([$php, $artisan, 'shopee:sync-reviews', '--days=' . $daysBack]);
            $launched[] = 'Shopee';
        }

        if ($channel === 'all' || $channel === 'lazada') {
            \Extensions\Lazada\Models\LazadaStore::query()->update(['last_review_sync_at' => $from . ' 00:00:00']);
            Process::start([$php, $artisan, 'lazada:sync-reviews', '--days=' . $daysBack, '--limit=100']);
            $launched[] = 'Lazada';
        }

        $channelLabel = implode(' + ', $launched);

        return redirect()->route('ext.opencart.reviews.index')->with('review_result', [
            'ok'      => true,
            'message' => "Fetching reviews in background ({$channelLabel}). Refresh the page in a few minutes to see results.",
        ]);
    }

    public function pushAll(Request $request)
    {
        $count = MarketplaceReview::whereIn('oc_sync_status', ['pending', 'error'])
            ->whereNotNull('product_id')
            ->count();

        if ($count === 0) {
            return redirect()->back()->with('review_result', [
                'ok'      => false,
                'message' => 'No pending or error reviews with mapped products to push.',
            ]);
        }

        $php = PHP_BINARY ?: '/usr/bin/php';
        $artisan = base_path('artisan');
        Process::start([$php, $artisan, 'opencart:push-reviews']);

        return redirect()->route('ext.opencart.reviews.index')->with('review_result', [
            'ok'      => true,
            'message' => "Pushing {$count} review(s) to OpenCart in background. Refresh the page in a few minutes.",
        ]);
    }

    public function deleteAll(Request $request)
    {
        $count = MarketplaceReview::count();
        MarketplaceReview::query()->truncate();

        \Extensions\Shopee\Models\ShopeeStore::query()->update(['last_review_sync_at' => null]);
        \Extensions\Lazada\Models\LazadaStore::query()->update(['last_review_sync_at' => null]);

        return redirect()->route('ext.opencart.reviews.index')->with('review_result', [
            'ok'      => true,
            'message' => "Deleted {$count} review(s). Sync timestamps reset.",
        ]);
    }
}
