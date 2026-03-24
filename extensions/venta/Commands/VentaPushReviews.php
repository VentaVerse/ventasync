<?php

namespace Extensions\venta\Commands;

use Extensions\opencart\Models\MarketplaceReview;
use Extensions\venta\Models\VentaProductLink;
use Extensions\venta\Models\VentaSetting;
use Extensions\venta\Services\Venta\VentaClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class VentaPushReviews extends Command
{
    protected $signature = 'venta:push-reviews
        {--store= : Venta store ID (omit to push to all enabled stores)}';

    protected $description = 'Push pending marketplace reviews to Venta stores';

    public function handle(): int
    {
        $query = VentaSetting::query()->where('enabled', true);

        if ($storeId = $this->option('store')) {
            $query->where('id', (int) $storeId);
        }

        $stores = $query->get();

        if ($stores->isEmpty()) {
            $this->warn('No enabled Venta stores found.');
            return 1;
        }

        // Get all pending reviews that have a resolved product_id
        $pendingReviews = MarketplaceReview::query()
            ->whereIn('venta_sync_status', ['pending', 'error'])
            ->whereNotNull('product_id')
            ->get();

        if ($pendingReviews->isEmpty()) {
            $this->info('No pending reviews to push.');
            return 0;
        }

        $this->info('Found ' . $pendingReviews->count() . ' pending review(s) to push.');

        $totalPushed = 0;
        $totalSkipped = 0;
        $totalErrors = 0;

        foreach ($stores as $setting) {
            $this->info("--- Store: {$setting->store_name} (#{$setting->id}) ---");

            $client = new VentaClient($setting);

            // Ping check
            $ping = $client->ping();
            if (!($ping['ok'] ?? false)) {
                $this->warn("  Cannot reach store — skipping.");
                continue;
            }

            $pushed = 0;
            $skipped = 0;
            $errors = 0;

            // Build product link map for this store: product_id => sku
            $linkMap = VentaProductLink::query()
                ->where('venta_setting_id', $setting->id)
                ->pluck('sku', 'product_id')
                ->all();

            foreach ($pendingReviews as $review) {
                $productSku = $linkMap[$review->product_id] ?? null;

                // Skip if already pushed by a previous store in this run
                if (!in_array($review->venta_sync_status, ['pending', 'error'])) {
                    continue;
                }

                if (!$productSku) {
                    // Product not linked to this Venta store — skip silently
                    $skipped++;
                    continue;
                }

                try {
                    $images = [];
                    if (!empty($review->images) && is_array($review->images)) {
                        $images = $review->images;
                    }

                    $res = $client->createReview([
                        'product_sku'        => $productSku,
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
                        $ventaReviewId = $res['body']['data']['review_id'] ?? null;
                        $review->update([
                            'venta_sync_status' => 'pushed',
                            'venta_setting_id'  => $setting->id,
                            'venta_review_id'   => $ventaReviewId,
                            'venta_pushed_at'   => now(),
                            'venta_push_error'  => null,
                        ]);
                        $pushed++;
                    } else {
                        $errorMsg = $res['body']['error'] ?? 'Unknown error';
                        $review->update([
                            'venta_sync_status' => 'error',
                            'venta_setting_id'  => $setting->id,
                            'venta_push_error'  => substr($errorMsg, 0, 500),
                        ]);
                        $errors++;
                    }
                } catch (\Throwable $e) {
                    $review->update([
                        'venta_sync_status' => 'error',
                        'venta_setting_id'  => $setting->id,
                        'venta_push_error'  => substr($e->getMessage(), 0, 500),
                    ]);
                    $errors++;
                    Log::warning('Venta push review failed', [
                        'review_id' => $review->id,
                        'store'     => $setting->id,
                        'error'     => $e->getMessage(),
                    ]);
                }
            }

            $setting->update(['last_review_push_at' => now()]);

            $this->info("  Pushed: {$pushed}, Skipped: {$skipped}" . ($errors > 0 ? ", Errors: {$errors}" : ''));
            $totalPushed += $pushed;
            $totalSkipped += $skipped;
            $totalErrors += $errors;
        }

        $this->info("Total: {$totalPushed} pushed, {$totalSkipped} skipped" . ($totalErrors > 0 ? ", {$totalErrors} errors" : ''));
        $this->info('Done.');
        return 0;
    }
}
