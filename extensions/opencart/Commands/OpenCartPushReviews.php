<?php

namespace Extensions\OpenCart\Commands;

use Extensions\OpenCart\Models\MarketplaceReview;
use Extensions\OpenCart\Models\OpenCartListingLink;
use Extensions\OpenCart\Models\OpenCartStore;
use Extensions\OpenCart\Services\OpenCart\OpenCartClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class OpenCartPushReviews extends Command
{
    protected $signature = 'opencart:push-reviews
        {--store= : OpenCart store ID (omit to push to all enabled stores)}';

    protected $description = 'Push pending marketplace reviews to OpenCart stores';

    public function handle(): int
    {
        $query = OpenCartStore::query()->where('enabled', true);

        if ($storeId = $this->option('store')) {
            $query->where('id', (int) $storeId);
        }

        $stores = $query->get();

        if ($stores->isEmpty()) {
            $this->warn('No enabled OpenCart stores found.');
            return 1;
        }

        $pendingReviews = MarketplaceReview::query()
            ->whereIn('oc_sync_status', ['pending', 'error'])
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

        foreach ($stores as $store) {
            $this->info("--- Store: {$store->store_name} (#{$store->id}) ---");

            $client = new OpenCartClient($store);

            $ping = $client->ping();
            if (!($ping['ok'] ?? false)) {
                $this->warn("  Cannot reach store, skipping.");
                continue;
            }

            $autoApprove = (bool) ($store->review_auto_approve ?? true);
            $pushed = 0;
            $skipped = 0;
            $errors = 0;

            $linkMap = OpenCartListingLink::query()
                ->where('store_id', $store->id)
                ->pluck('oc_product_id', 'product_id')
                ->all();

            foreach ($pendingReviews as $review) {
                $ocProductId = $linkMap[$review->product_id] ?? null;

                if (!in_array($review->oc_sync_status, ['pending', 'error'])) {
                    continue;
                }

                if (!$ocProductId) {
                    $skipped++;
                    continue;
                }

                try {
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
                        $ocReviewId = $res['body']['data']['review_id'] ?? null;
                        $review->update([
                            'oc_sync_status'      => 'pushed',
                            'opencart_store_id' => $store->id,
                            'oc_review_id'        => $ocReviewId,
                            'oc_pushed_at'        => now(),
                            'oc_push_error'       => null,
                        ]);
                        $pushed++;
                    } else {
                        $errorMsg = $res['body']['error'] ?? 'Unknown error';
                        $review->update([
                            'oc_sync_status'      => 'error',
                            'opencart_store_id' => $store->id,
                            'oc_push_error'       => substr($errorMsg, 0, 500),
                        ]);
                        $errors++;
                    }
                } catch (\Throwable $e) {
                    $review->update([
                        'oc_sync_status'      => 'error',
                        'opencart_store_id' => $store->id,
                        'oc_push_error'       => substr($e->getMessage(), 0, 500),
                    ]);
                    $errors++;
                    Log::warning('OpenCart push review failed', [
                        'review_id' => $review->id,
                        'store'     => $store->id,
                        'error'     => $e->getMessage(),
                    ]);
                }
            }

            $store->update(['last_review_push_at' => now()]);

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
