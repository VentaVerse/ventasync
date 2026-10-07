<?php

namespace Extensions\ventacart\Commands;

use Extensions\opencart\Models\MarketplaceReview;
use Extensions\ventacart\Models\VentaCartProductLink;
use Extensions\ventacart\Models\VentaCartSetting;
use Extensions\ventacart\Services\VentaCart\VentaCartClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class VentaCartPushReviews extends Command
{
    protected $signature = 'ventacart:push-reviews
        {--store= : VentaCart store ID (omit to push to all enabled stores)}';

    protected $description = 'Push pending marketplace reviews to VentaCart stores';

    public function handle(): int
    {
        $query = VentaCartSetting::query()->where('enabled', true);

        if ($storeId = $this->option('store')) {
            $query->where('id', (int) $storeId);
        }

        $stores = $query->get();

        if ($stores->isEmpty()) {
            $this->warn('No enabled VentaCart stores found.');
            return 1;
        }

        $pendingReviews = MarketplaceReview::query()
            ->whereIn('ventacart_sync_status', ['pending', 'error'])
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

            $client = new VentaCartClient($setting);

            $ping = $client->ping();
            if (!($ping['ok'] ?? false)) {
                $this->warn("  Cannot reach store, skipping.");
                continue;
            }

            $pushed = 0;
            $skipped = 0;
            $errors = 0;

            $linkMap = VentaCartProductLink::query()
                ->where('ventacart_setting_id', $setting->id)
                ->pluck('sku', 'product_id')
                ->all();

            foreach ($pendingReviews as $review) {
                $productSku = $linkMap[$review->product_id] ?? null;

                if (!in_array($review->ventacart_sync_status, ['pending', 'error'])) {
                    continue;
                }

                if (!$productSku) {
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
                        $ventaCartReviewId = $res['body']['data']['review_id'] ?? null;
                        $review->update([
                            'ventacart_sync_status' => 'pushed',
                            'ventacart_setting_id'  => $setting->id,
                            'ventacart_review_id'   => $ventaCartReviewId,
                            'ventacart_pushed_at'   => now(),
                            'ventacart_push_error'  => null,
                        ]);
                        $pushed++;
                    } else {
                        $errorMsg = $res['body']['error'] ?? 'Unknown error';
                        $review->update([
                            'ventacart_sync_status' => 'error',
                            'ventacart_setting_id'  => $setting->id,
                            'ventacart_push_error'  => substr($errorMsg, 0, 500),
                        ]);
                        $errors++;
                    }
                } catch (\Throwable $e) {
                    $review->update([
                        'ventacart_sync_status' => 'error',
                        'ventacart_setting_id'  => $setting->id,
                        'ventacart_push_error'  => substr($e->getMessage(), 0, 500),
                    ]);
                    $errors++;
                    Log::warning('VentaCart push review failed', [
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
