<?php

namespace Extensions\shopee\Commands;

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

    private string $pauseKey = 'shopee_sync_paused';

    public function handle(ShopeeClient $client): int
    {
        $store = ShopeeSetting::defaultStore();
        $setting = $store?->decrypted();
        $this->pauseKey = 'shopee_sync_paused' . ($store ? ':' . $store->id : '');
        $auth = ShopeeSetting::activeAuth($setting);
        if (!$setting || !$auth['complete']) {
            $modeLabel = $auth['mode'] === 'sandbox' ? 'Sandbox' : 'Production';
            $this->error("Missing Shopee {$modeLabel} credentials/token. Configure Shopee settings for the active mode.");
            return 1;
        }

        if ($paused = Cache::get($this->pauseKey)) {
            $this->warn('Shopee sync paused due to recent API error (' . $paused . '). Will retry automatically.');
            return 1;
        }

        $this->info('--- Syncing Shopee product reviews ---');

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
            if (Cache::get($this->pauseKey)) break;

            try {
                [$c, $u] = $this->syncItemReviews($client, $auth, $itemId, $lastSync, $days);
                $created += $c;
                $updated += $u;
            } catch (\Throwable $e) {
                $errors++;
                Log::warning('Shopee review sync: failed for item ' . $itemId, ['error' => $e->getMessage()]);
                $this->warn("  Error for item {$itemId}: " . $e->getMessage());
            }

            usleep(200000);
        }

        $store->forceFill(['last_review_sync_at' => now()])->save();

        $this->info("  Reviews: {$created} new, {$updated} updated" . ($errors > 0 ? ", {$errors} item error(s)" : ''));
        $this->info('Done.');
        return 0;
    }

    private function syncItemReviews(ShopeeClient $client, array $auth, string $itemId, ?int $lastSync, int $days): array
    {
        $link = ShopeeProductLink::query()
            ->where('shopee_item_id', $itemId)
            ->first();
        $productId = $link?->product_id;

        $created = 0;
        $updated = 0;
        $cursor = 0;
        $pageSize = 100;
        $maxPages = 20;

        for ($page = 0; $page < $maxPages; $page++) {
            $params = [
                'item_id'    => (int) $itemId,
                'comment_id' => $cursor,
                'page_size'  => $pageSize,
            ];

            $res = $client->shopGet(
                $auth['mode'],
                (int) $auth['partner_id'],
                (string) $auth['partner_key'],
                (string) $auth['access_token'],
                (int) $auth['shop_id'],
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

                if (in_array($errorStr, ['error_not_found', 'error_permission'])) {
                    break;
                }

                Cache::put($this->pauseKey, $msg, now()->addMinutes(10));
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

                $createTime = $c['create_time'] ?? null;
                if ($lastSync && $createTime && (int) $createTime < $lastSync) {
                    $hitOldReview = true;
                    continue;
                }

                $images = [];
                $media = $c['media'] ?? [];
                $imageList = $media['image_url_list'] ?? $c['images'] ?? $c['image_list'] ?? [];
                if (is_array($imageList)) {
                    foreach ($imageList as $img) {
                        $url = is_string($img) ? $img : ($img['image_url'] ?? null);
                        if ($url) $images[] = $url;
                    }
                }

                $videos = [];
                $videoList = $media['video_url_list'] ?? $c['videos'] ?? $c['video_list'] ?? [];
                if (is_array($videoList)) {
                    foreach ($videoList as $vid) {
                        $url = is_string($vid) ? $vid : ($vid['video_url'] ?? $vid['url'] ?? null);
                        if ($url) $videos[] = $url;
                    }
                }

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

            if ($hitOldReview && $lastSync) {
                break;
            }

            $hasMore = (bool) ($respData['has_more'] ?? ($respData['more'] ?? false));
            if (!$hasMore) break;

            $lastComment = end($commentList);
            $nextCursor = (int) ($lastComment['comment_id'] ?? 0);
            if ($nextCursor === 0 || $nextCursor === $cursor) break;
            $cursor = $nextCursor;

            usleep(200000);
        }

        return [$created, $updated];
    }
}
