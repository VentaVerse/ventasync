<?php

namespace Extensions\lazada\Commands;

use Extensions\lazada\Models\LazadaApiLog;
use Extensions\lazada\Models\LazadaProduct;
use Extensions\lazada\Models\LazadaSetting;
use Extensions\opencart\Models\MarketplaceReview;
use Extensions\lazada\Services\Lazada\LazadaClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class LazadaSyncReviews extends Command
{
    protected $signature = 'lazada:sync-reviews
        {--days=7 : How many days back to sync on first run}
        {--limit=0 : Max products to check (0 = all)}';

    protected $description = 'Sync product reviews from Lazada';

    public function handle(LazadaClient $client): int
    {
        $setting = LazadaSetting::defaultStore()?->decrypted();
        $creds = LazadaSetting::activeCredentials($setting);
        if (!$setting || !$creds['complete'] || empty($creds['region']) || empty($creds['access_token'])) {
            $modeLabel = ($creds['mode'] ?? 'live') === 'sandbox' ? 'Sandbox' : 'Production';
            $this->error("Missing Lazada {$modeLabel} credentials/token. Configure Lazada settings for the active mode.");
            return 1;
        }

        $expiresAt = $creds['expires_at'] ?? null;
        if (!empty($expiresAt) && strtotime((string) $expiresAt) <= time()) {
            $this->warn("Lazada access token expired ({$expiresAt}). Refresh it from Lazada Settings.");
            return 1;
        }

        if ($paused = Cache::get('lazada_sync_paused')) {
            $this->warn('Lazada sync paused due to recent API error (' . $paused . '). Will retry automatically.');
            return 1;
        }

        $this->info('--- Syncing Lazada product reviews ---');

        $days = (int) $this->option('days');
        if ($days < 1) $days = 7;

        $lastSync = !empty($setting->last_review_sync_at)
            ? $setting->last_review_sync_at
            : null;

        $tz = new \DateTimeZone('Asia/Manila');

        if ($lastSync) {
            $from = new \DateTime($lastSync, $tz);
            $from->modify('-1 hour');
        } else {
            $from = (new \DateTime('now', $tz))->modify("-{$days} days");
        }
        $to = new \DateTime('now', $tz);

        $this->info('  Review window: ' . $from->format('Y-m-d H:i:s') . ' to ' . $to->format('Y-m-d H:i:s'));

        $limit = (int) $this->option('limit');

        $lpQuery = LazadaProduct::query()
            ->whereNotNull('lazada_item_id')
            ->where('lazada_item_id', '!=', '')
            ->orderByRaw('CAST(lazada_item_id AS UNSIGNED) DESC');

        if ($limit > 0) $lpQuery->limit($limit);

        $lazadaProducts = $lpQuery->get(['lazada_item_id', 'product_id']);

        if ($lazadaProducts->isEmpty()) {
            $this->info('  No Lazada products found.');
            LazadaSetting::defaultStore()?->forceFill(['last_review_sync_at' => now()])->save();
            return 0;
        }

        $totalItems = $lazadaProducts->count();
        $this->info("  Found {$totalItems} Lazada product(s). Fetching reviews per item...");

        $lazadaProductMap = $lazadaProducts->pluck('product_id', 'lazada_item_id')->all();

        $windowMaxDays = 7;
        $created = 0;
        $updated = 0;
        $errors = 0;
        $itemsChecked = 0;

        foreach ($lazadaProducts as $lp) {
            if (Cache::get('lazada_sync_paused')) break;

            $itemId = (string) $lp->lazada_item_id;
            $itemsChecked++;
            $itemReviewIds = [];

            $windowFrom = clone $from;
            while ($windowFrom < $to) {
                $windowTo = clone $windowFrom;
                $windowTo->modify("+{$windowMaxDays} days");
                if ($windowTo > $to) $windowTo = clone $to;

                $page = 1;

                for ($p = 0; $p < 100; $p++) {
                    $params = [
                        'item_id'    => $itemId,
                        'start_time' => (string) ($windowFrom->getTimestamp() * 1000),
                        'end_time'   => (string) ($windowTo->getTimestamp() * 1000),
                        'current'    => $page,
                    ];

                    $res = $this->runSignedApiCall($client, $creds, 'GET', '/review/seller/history/list', $params, 'lazada.review.history_list');

                    $body = $res['body'] ?? [];
                    $bodyCode = is_array($body) ? ($body['code'] ?? '') : '';

                    if ($bodyCode === 'SellerCallLimit' || $bodyCode === 'ApiCallLimit') {
                        $this->warn("  Rate limited at item {$itemsChecked}/{$totalItems}. Pausing...");
                        Cache::put('lazada_sync_paused', 'ApiCallLimit', now()->addMinutes(10));
                        break 3;
                    }

                    if (!($res['ok'] ?? false) || ($bodyCode !== '' && $bodyCode !== '0')) {
                        break;
                    }

                    $dataNode = $body['data'] ?? $body;
                    $idList = $dataNode['id_list'] ?? [];
                    if (!is_array($idList)) $idList = [];

                    foreach ($idList as $rid) {
                        $rid = (string) $rid;
                        if ($rid !== '') $itemReviewIds[] = $rid;
                    }

                    $total = (int) ($dataNode['total'] ?? 0);
                    $pageSize = (int) ($dataNode['page_size'] ?? 10);
                    if ($pageSize < 1) $pageSize = 10;
                    if ($page * $pageSize >= $total) break;
                    $page++;
                }

                $windowFrom = clone $windowTo;
            }

            if (!empty($itemReviewIds)) {
                foreach (array_chunk($itemReviewIds, 20) as $batch) {
                    $res = $this->runSignedApiCall($client, $creds, 'GET', '/review/seller/list/v2', [
                        'id_list' => json_encode(array_map('intval', $batch)),
                    ], 'lazada.review.detail');

                    if (!($res['ok'] ?? false)) {
                        $errors += count($batch);
                        continue;
                    }

                    $body = $res['body'] ?? [];
                    $dataNode = $body['data'] ?? $body;
                    $reviewList = $dataNode['list'] ?? $dataNode['review_list'] ?? $dataNode['data'] ?? [];
                    if (!is_array($reviewList)) $reviewList = [];

                    foreach ($reviewList as $r) {
                        if (!is_array($r)) continue;
                        $reviewId = (string) ($r['review_id'] ?? $r['id'] ?? '');
                        if ($reviewId === '') continue;

                        try {
                            $wasNew = $this->saveReview($r, $reviewId, $lazadaProductMap);
                            if ($wasNew) $created++;
                            else $updated++;
                        } catch (\Throwable $e) {
                            $errors++;
                            Log::warning('Lazada review sync: failed for review ' . $reviewId, ['error' => $e->getMessage()]);
                        }
                    }
                }

                $this->info("  [{$itemsChecked}/{$totalItems}] item {$itemId}: " . count($itemReviewIds) . " review(s), total: {$created} new, {$updated} updated");
            }
        }

        LazadaSetting::defaultStore()?->forceFill(['last_review_sync_at' => now()])->save();

        $this->info("  Checked {$itemsChecked} item(s). Reviews: {$created} new, {$updated} updated" . ($errors > 0 ? ", {$errors} error(s)" : ''));
        $this->info('Done.');
        return 0;
    }

    private function saveReview(array $r, string $reviewId, array $lazadaProductMap): bool
    {
        $itemId = (string) ($r['item_id'] ?? $r['product_id'] ?? '');
        $productId = $lazadaProductMap[$itemId] ?? null;

        $images = [];
        $imageList = $r['images'] ?? $r['image_list'] ?? $r['review_images'] ?? [];
        if (is_array($imageList)) {
            foreach ($imageList as $img) {
                $url = is_string($img) ? $img : ($img['image_url'] ?? $img['url'] ?? null);
                if ($url) $images[] = $url;
            }
        }

        $videos = [];
        $videoList = $r['review_videos'] ?? $r['videos'] ?? $r['video_list'] ?? [];
        if (is_array($videoList)) {
            foreach ($videoList as $vid) {
                $url = is_string($vid) ? $vid : ($vid['video_url'] ?? $vid['url'] ?? null);
                if ($url) $videos[] = $url;
            }
        }

        $reply = null;
        $repliedAt = null;
        $sellerReply = $r['seller_reply'] ?? $r['reply'] ?? null;
        if (is_array($sellerReply) && !empty($sellerReply['reply_content'] ?? $sellerReply['content'] ?? $sellerReply['comment'] ?? null)) {
            $reply = (string) ($sellerReply['reply_content'] ?? $sellerReply['content'] ?? $sellerReply['comment']);
            $repliedAt = !empty($sellerReply['reply_date'] ?? $sellerReply['date'])
                ? date('Y-m-d H:i:s', strtotime($sellerReply['reply_date'] ?? $sellerReply['date']))
                : null;
        } elseif (is_string($sellerReply) && $sellerReply !== '') {
            $reply = $sellerReply;
        }

        $ratings = $r['ratings'] ?? [];
        $rating = (int) ($ratings['product_rating'] ?? $r['product_rating'] ?? $ratings['overall_rating'] ?? $r['rating'] ?? $r['overall_rating'] ?? 5);
        $rating = max(1, min(5, $rating));

        $reviewedAt = $r['create_time'] ?? $r['submit_time'] ?? $r['review_date'] ?? $r['created_at'] ?? null;
        if ($reviewedAt && is_numeric($reviewedAt)) {
            $ts = (int) $reviewedAt;
            if ($ts > 9999999999) $ts = (int) ($ts / 1000);
            $reviewedAt = date('Y-m-d H:i:s', $ts);
        } elseif ($reviewedAt && !is_numeric($reviewedAt)) {
            $ts = strtotime($reviewedAt);
            $reviewedAt = $ts ? date('Y-m-d H:i:s', $ts) : null;
        }

        $reviewData = [
            'product_id'        => $productId,
            'platform_item_id'  => $itemId ?: null,
            'platform_order_id' => (string) ($r['trade_order_id'] ?? $r['order_id'] ?? ''),
            'author'            => (string) ($r['buyer_name'] ?? $r['reviewer_name'] ?? $r['buyer'] ?? '') ?: 'Lazada Buyer',
            'rating'            => $rating,
            'comment'           => (string) ($r['review_content'] ?? $r['content'] ?? $r['comment'] ?? ''),
            'images'            => !empty($images) ? $images : null,
            'videos'            => !empty($videos) ? $videos : null,
            'reply'             => $reply,
            'replied_at'        => $repliedAt,
            'reviewed_at'       => $reviewedAt,
            'raw'               => $r,
        ];

        $model = MarketplaceReview::query()->updateOrCreate(
            [
                'platform'           => 'lazada',
                'platform_review_id' => $reviewId,
            ],
            $reviewData
        );

        return $model->wasRecentlyCreated;
    }

    private function runSignedApiCall(LazadaClient $client, array $creds, string $method, string $apiPath, array $customParams, ?string $pack = null): array
    {
        $apiPath = trim($apiPath);
        if (!str_starts_with($apiPath, '/')) $apiPath = '/' . $apiPath;

        $timestamp = (string) round(microtime(true) * 1000);
        $params = [
            'app_key'      => (string) $creds['app_key'],
            'sign_method'  => 'sha256',
            'timestamp'    => $timestamp,
            'access_token' => (string) $creds['access_token'],
        ];

        foreach ($customParams as $k => $v) {
            if (!is_string($k) || $k === '' || in_array($k, ['sign', 'app_key', 'sign_method', 'timestamp'], true)) continue;
            $params[$k] = is_scalar($v) || $v === null ? $v : json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        $method = strtoupper($method);
        $region = (string) ($creds['region'] ?? 'ph');
        $rateKey = 'lazada_api_last_call:' . $region . ':' . $creds['app_key'];
        $lockKey = 'lazada_api_lock:' . $region . ':' . $creds['app_key'];
        $minIntervalMs = 1200;

        $callOnce = function () use ($client, $creds, $region, $apiPath, &$params, $method) {
            $params['sign'] = $client->sign($apiPath, $params, (string) $creds['app_secret']);
            return $method === 'POST'
                ? $client->post($region, $apiPath, $params)
                : $client->get($region, $apiPath, $params);
        };

        $result = null;
        $maxAttempts = 6;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $lock = null;
            try {
                if (method_exists(Cache::class, 'lock')) {
                    $lock = Cache::lock($lockKey, 15);
                    $lock->block(15);
                }

                $nowMs = (int) round(microtime(true) * 1000);
                $lastMs = (int) Cache::get($rateKey, 0);
                $waitMs = $minIntervalMs - ($nowMs - $lastMs);
                if ($waitMs > 0) usleep($waitMs * 1000);

                $result = $callOnce();
                Cache::put($rateKey, (int) round(microtime(true) * 1000), 60);
            } finally {
                if ($lock) {
                    try { $lock->release(); } catch (\Throwable $e) {}
                }
            }

            $body = $result['body'] ?? null;
            $code = is_array($body) ? ($body['code'] ?? null) : null;
            if ($code === 'SellerCallLimit' || $code === 'ApiCallLimit') {
                usleep((1300 + ($attempt - 1) * 350) * 1000);
                continue;
            }

            break;
        }

        if ($result === null) {
            $result = ['status' => 0, 'ok' => false, 'body' => ['message' => 'API call failed']];
        }

        $isOk = (bool) ($result['ok'] ?? false);
        $bodyCode = is_array($result['body'] ?? null) ? ($result['body']['code'] ?? '') : '';
        $shouldLog = !$isOk || ($bodyCode !== '' && $bodyCode !== '0') || $pack === 'lazada.review.detail';

        if ($shouldLog) {
            LazadaApiLog::safeCreate([
                'pack'            => $pack,
                'method'          => $method,
                'api_path'        => $apiPath,
                'auth_required'   => true,
                'request_params'  => $params,
                'response_status' => (int) ($result['status'] ?? 0),
                'ok'              => $isOk,
                'response_body'   => $result['body'] ?? null,
                'user_id'         => null,
            ]);
        }

        return $result;
    }
}
