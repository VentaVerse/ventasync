<?php

namespace Extensions\opencart\Commands;

use Extensions\opencart\Models\MarketplaceReview;
use Extensions\shopee\Models\ShopeeApiLog;
use Extensions\shopee\Models\ShopeeProductLink;
use Extensions\shopee\Models\ShopeeSetting;
use Extensions\shopee\Services\Shopee\ShopeeClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ShopeeSyncReviews extends Command
{
    protected $signature = 'shopee:sync-reviews
        {--days=7 : How many days back to sync on first run}';

    protected $description = 'Sync product reviews/comments from Shopee';

    public function handle(ShopeeClient $client): int
    {
        $setting = ShopeeSetting::query()->first()?->decrypted();
        if (!$setting || !$setting->partner_id || !$setting->partner_key || !$setting->access_token || !$setting->shop_id) {
            $this->error('Missing Shopee credentials/token. Configure Shopee settings first.');
            return 1;
        }

        if ($paused = Cache::get('shopee_sync_paused')) {
            $this->warn('Shopee sync paused due to recent API error (' . $paused . '). Will retry automatically.');
            return 1;
        }

        $this->info('--- Syncing Shopee product reviews ---');

        // Get unique item IDs from product links
        $itemIds = ShopeeProductLink::query()
            ->whereNotNull('shopee_item_id')
            ->where('shopee_item_id', '!=', '')
            ->distinct()
            ->pluck('shopee_item_id')
            ->all();

        if (empty($itemIds)) {
            $this->info('  No Shopee product links found.');
            return 0;
        }

        $this->info('  Found ' . count($itemIds) . ' linked Shopee item(s).');

        $lastSync = !empty($setting->last_review_sync_at)
            ? strtotime($setting->last_review_sync_at)
            : null;

        $days = (int) $this->option('days');
        if ($days < 1) $days = 7;

        $created = 0;
        $updated = 0;
        $errors = 0;

        foreach ($itemIds as $itemId) {
            if (Cache::get('shopee_sync_paused')) break;

            try {
                [$c, $u] = $this->syncItemReviews($client, $setting, $itemId, $lastSync, $days);
                $created += $c;
                $updated += $u;
            } catch (\Throwable $e) {
                $errors++;
                Log::warning('Shopee review sync: failed for item ' . $itemId, ['error' => $e->getMessage()]);
                $this->warn("  Error for item {$itemId}: " . $e->getMessage());
            }

            usleep(200000); // 200ms rate limit between items
        }

        ShopeeSetting::query()->update(['last_review_sync_at' => now()]);

        $this->info("  Reviews: {$created} new, {$updated} updated" . ($errors > 0 ? ", {$errors} item error(s)" : ''));
        $this->info('Done.');
        return 0;
    }

    /**
     * Sync reviews for a single Shopee item using cursor-based pagination.
     *
     * @return array [created, updated]
     */
    private function syncItemReviews(ShopeeClient $client, object $setting, string $itemId, ?int $lastSync, int $days): array
    {
        // Resolve product_id from product links
        $link = ShopeeProductLink::query()
            ->where('shopee_item_id', $itemId)
            ->first();
        $productId = $link?->product_id;

        $created = 0;
        $updated = 0;
        $cursor = 0; // comment_id cursor; 0 = start from newest
        $pageSize = 100;
        $maxPages = 20;

        for ($page = 0; $page < $maxPages; $page++) {
            $params = [
                'item_id'    => (int) $itemId,
                'comment_id' => $cursor,
                'page_size'  => $pageSize,
            ];

            $res = $client->shopGet(
                $setting->mode ?? 'live',
                (int) $setting->partner_id,
                (string) $setting->partner_key,
                (string) $setting->access_token,
                (int) $setting->shop_id,
                '/api/v2/product/get_comment',
                $params
            );

            ShopeeApiLog::safeCreate([
                'pack'            => 'shopee.sync.get_comment',
                'method'          => 'GET',
                'api_path'        => '/api/v2/product/get_comment',
                'auth_required'   => true,
                'request_params'  => $params,
                'response_status' => (int) ($res['status'] ?? 0),
                'ok'              => (bool) ($res['ok'] ?? false),
                'response_body'   => $res['body'] ?? null,
            ]);

            if (!($res['ok'] ?? false)) {
                $body = $res['body'] ?? [];
                $msg = is_array($body) ? ($body['message'] ?? 'API error') : 'API error';
                $errorStr = is_array($body) ? ($body['error'] ?? '') : '';

                // If item not found or no permission, skip silently
                if (in_array($errorStr, ['error_not_found', 'error_permission'])) {
                    break;
                }

                Cache::put('shopee_sync_paused', $msg, now()->addMinutes(10));
                throw new \RuntimeException($msg);
            }

            $body = $res['body'] ?? [];
            $respData = $body['response'] ?? $body;
            $commentList = $respData['item_comment_list'] ?? [];
            if (!is_array($commentList) || empty($commentList)) {
                break;
            }

            $hitOldReview = false;

            foreach ($commentList as $c) {
                if (!is_array($c)) continue;

                $commentId = (string) ($c['comment_id'] ?? '');
                if ($commentId === '') continue;

                // Check if this review is older than our last sync
                $createTime = $c['create_time'] ?? null;
                if ($lastSync && $createTime && (int) $createTime < $lastSync) {
                    $hitOldReview = true;
                    continue; // Skip saving reviews older than the target date
                }

                // Extract images (check media.image_url_list + legacy fields)
                $images = [];
                $media = $c['media'] ?? [];
                $imageList = $media['image_url_list'] ?? $c['images'] ?? $c['image_list'] ?? [];
                if (is_array($imageList)) {
                    foreach ($imageList as $img) {
                        $url = is_string($img) ? $img : ($img['image_url'] ?? null);
                        if ($url) $images[] = $url;
                    }
                }

                // Extract videos (check media.video_url_list + legacy fields)
                $videos = [];
                $videoList = $media['video_url_list'] ?? $c['videos'] ?? $c['video_list'] ?? [];
                if (is_array($videoList)) {
                    foreach ($videoList as $vid) {
                        $url = is_string($vid) ? $vid : ($vid['video_url'] ?? $vid['url'] ?? null);
                        if ($url) $videos[] = $url;
                    }
                }

                // Seller reply
                $reply = null;
                $repliedAt = null;
                $sellerReply = $c['reply'] ?? $c['seller_reply'] ?? null;
                if (is_array($sellerReply) && !empty($sellerReply['comment'])) {
                    $reply = (string) $sellerReply['comment'];
                    $repliedAt = isset($sellerReply['create_time'])
                        ? date('Y-m-d H:i:s', (int) $sellerReply['create_time'])
                        : null;
                } elseif (is_string($sellerReply) && $sellerReply !== '') {
                    $reply = $sellerReply;
                }

                $reviewData = [
                    'product_id'        => $productId,
                    'platform_item_id'  => $itemId,
                    'platform_order_id' => (string) ($c['order_sn'] ?? ''),
                    'author'            => (string) ($c['buyer_username'] ?? $c['username'] ?? ''),
                    'rating'            => max(1, min(5, (int) ($c['rating_star'] ?? ($c['rating'] ?? 5)))),
                    'comment'           => (string) ($c['comment'] ?? ''),
                    'images'            => !empty($images) ? $images : null,
                    'videos'            => !empty($videos) ? $videos : null,
                    'reply'             => $reply,
                    'replied_at'        => $repliedAt,
                    'reviewed_at'       => $createTime ? date('Y-m-d H:i:s', (int) $createTime) : null,
                    'raw'               => $c,
                ];

                $existing = MarketplaceReview::query()
                    ->where('platform', 'shopee')
                    ->where('platform_review_id', $commentId)
                    ->first();

                if ($existing) {
                    $existing->fill($reviewData)->save();
                    $updated++;
                } else {
                    MarketplaceReview::query()->create(array_merge($reviewData, [
                        'platform'           => 'shopee',
                        'platform_review_id' => $commentId,
                    ]));
                    $created++;
                }
            }

            // Stop paginating if we've hit old reviews from a previous sync
            if ($hitOldReview && $lastSync) {
                break;
            }

            // Check for more pages
            $hasMore = (bool) ($respData['has_more'] ?? ($respData['more'] ?? false));
            if (!$hasMore) break;

            // Set cursor to last comment_id for next page
            $lastComment = end($commentList);
            $nextCursor = (int) ($lastComment['comment_id'] ?? 0);
            if ($nextCursor === 0 || $nextCursor === $cursor) break;
            $cursor = $nextCursor;

            usleep(200000); // 200ms between pages
        }

        return [$created, $updated];
    }
}
