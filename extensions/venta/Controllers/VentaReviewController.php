<?php

namespace Extensions\venta\Controllers;

use App\Http\Controllers\Controller;
use Extensions\opencart\Models\MarketplaceReview;
use Extensions\venta\Models\VentaProductLink;
use Extensions\venta\Models\VentaSetting;
use Extensions\venta\Services\Venta\VentaClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Process;

class VentaReviewController extends Controller
{
    /**
     * Push a single marketplace review to Venta.
     */
    public function push(Request $request, $id)
    {
        $review = MarketplaceReview::findOrFail($id);

        if (!in_array($review->venta_sync_status, ['pending', 'error'])) {
            return redirect()->back()->with('error', 'Review Venta status is not pending or error.');
        }

        if (!$review->product_id) {
            return redirect()->back()->with('error', 'Review has no mapped product.');
        }

        $link = VentaProductLink::query()
            ->where('product_id', $review->product_id)
            ->first();

        if (!$link) {
            return redirect()->back()->with('error', 'Product not linked to any Venta store.');
        }

        $setting = VentaSetting::find($link->venta_setting_id);
        if (!$setting || !$setting->enabled) {
            return redirect()->back()->with('error', 'Venta store not found or disabled.');
        }

        $client = new VentaClient($setting);

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
                'venta_sync_status' => 'pushed',
                'venta_setting_id'  => $setting->id,
                'venta_review_id'   => $res['body']['data']['review_id'] ?? $res['body']['review_id'] ?? null,
                'venta_pushed_at'   => now(),
                'venta_push_error'  => null,
            ]);
            return redirect()->back()->with('status', 'Review pushed to Venta.');
        }

        $error = $res['body']['error'] ?? 'Unknown error';
        $review->update([
            'venta_sync_status' => 'error',
            'venta_setting_id'  => $setting->id,
            'venta_push_error'  => substr($error, 0, 500),
        ]);

        return redirect()->back()->with('error', 'Venta push failed: ' . $error);
    }

    /**
     * Push all pending reviews to Venta in background.
     */
    public function pushAll(Request $request)
    {
        $count = MarketplaceReview::whereIn('venta_sync_status', ['pending', 'error'])
            ->whereNotNull('product_id')
            ->count();

        if ($count === 0) {
            return redirect()->back()->with('review_result', [
                'ok'      => false,
                'message' => 'No pending or error reviews with mapped products to push to Venta.',
            ]);
        }

        $php = PHP_BINARY ?: '/usr/bin/php';
        $artisan = base_path('artisan');
        Process::start([$php, $artisan, 'venta:push-reviews']);

        return redirect()->back()->with('review_result', [
            'ok'      => true,
            'message' => "Pushing {$count} review(s) to Venta in background. Refresh the page in a few minutes.",
        ]);
    }
}
