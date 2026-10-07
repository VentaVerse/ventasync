<?php

namespace Extensions\ventacart\Controllers;

use App\Http\Controllers\Controller;
use Extensions\opencart\Models\MarketplaceReview;
use Extensions\ventacart\Models\VentaCartProductLink;
use Extensions\ventacart\Models\VentaCartSetting;
use Extensions\ventacart\Services\VentaCart\VentaCartClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Process;

class VentaCartReviewController extends Controller
{
    public function push(Request $request, $id)
    {
        $review = MarketplaceReview::findOrFail($id);

        if (!in_array($review->ventacart_sync_status, ['pending', 'error'])) {
            return redirect()->back()->with('error', 'Review VentaCart status is not pending or error.');
        }

        if (!$review->product_id) {
            return redirect()->back()->with('error', 'Review has no mapped product.');
        }

        $link = VentaCartProductLink::query()
            ->where('product_id', $review->product_id)
            ->first();

        if (!$link) {
            return redirect()->back()->with('error', 'Product not linked to any VentaCart store.');
        }

        $setting = VentaCartSetting::find($link->ventacart_setting_id);
        if (!$setting || !$setting->enabled) {
            return redirect()->back()->with('error', 'VentaCart store not found or disabled.');
        }

        $client = new VentaCartClient($setting);

        $images = [];
        if (!empty($review->images) && is_array($review->images)) {
            $images = $review->images;
        }

        $res = $client->createReview([
            'product_sku'        => $link->sku,
            'author'             => $review->author ?: 'Marketplace Buyer',
            'rating'             => $review->rating,
            'title'              => '',
            'body'               => $review->comment ?? '',
            'status'             => 'approved',
            'date_added'         => $review->reviewed_at?->format('Y-m-d H:i:s') ?? now()->format('Y-m-d H:i:s'),
            'platform'           => $review->platform,
            'platform_review_id' => $review->platform_review_id,
            'images'             => $images,
        ]);

        if ($res['ok'] ?? false) {
            $review->update([
                'ventacart_sync_status' => 'pushed',
                'ventacart_setting_id'  => $setting->id,
                'ventacart_review_id'   => $res['body']['data']['review_id'] ?? $res['body']['review_id'] ?? null,
                'ventacart_pushed_at'   => now(),
                'ventacart_push_error'  => null,
            ]);
            return redirect()->back()->with('status', 'Review pushed to VentaCart.');
        }

        $error = $res['body']['error'] ?? 'Unknown error';
        $review->update([
            'ventacart_sync_status' => 'error',
            'ventacart_setting_id'  => $setting->id,
            'ventacart_push_error'  => substr($error, 0, 500),
        ]);

        return redirect()->back()->with('error', 'VentaCart push failed: ' . $error);
    }

    public function pushAll(Request $request)
    {
        $count = MarketplaceReview::whereIn('ventacart_sync_status', ['pending', 'error'])
            ->whereNotNull('product_id')
            ->count();

        if ($count === 0) {
            return redirect()->back()->with('review_result', [
                'ok'      => false,
                'message' => 'No pending or error reviews with mapped products to push to VentaCart.',
            ]);
        }

        $php = PHP_BINARY ?: '/usr/bin/php';
        $artisan = base_path('artisan');
        Process::start([$php, $artisan, 'ventacart:push-reviews']);

        return redirect()->back()->with('review_result', [
            'ok'      => true,
            'message' => "Pushing {$count} review(s) to VentaCart in background. Refresh the page in a few minutes.",
        ]);
    }
}
