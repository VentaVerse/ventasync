<?php

namespace Extensions\tiktok\Services\TikTok;

use Extensions\tiktok\Models\TikTokApiLog;

/**
 * What TikTok says about a product RIGHT NOW (#156, mirroring Shopee's
 * ShopeeLiveListing): fetched on the page load that shows it, never
 * cached, and when TikTok does not answer the page says so instead of
 * showing yesterday. One product for the listing page; the whole shop
 * by status for the index's tabs and row chips.
 */
class TikTokLiveListing
{
    /** TikTok's product status words -> the seller center's, with a badge tone. */
    public const STATUS_MAP = [
        'ACTIVATE' => ['Live', 'success'],
        'SELLER_DEACTIVATED' => ['Deactivated', 'neutral'],
        'PLATFORM_DEACTIVATED' => ['Deactivated by TikTok', 'danger'],
        'IN_REVIEW' => ['Under TikTok review', 'warning'],
        'PENDING' => ['Under TikTok review', 'warning'],
        'FAILED' => ['Failed review', 'danger'],
        'FREEZE' => ['Frozen', 'danger'],
        'DRAFT' => ['Draft', 'neutral'],
        'DELETED' => ['Deleted', 'neutral'],
    ];

    /** The index's tabs: key => [label, TikTok status filter or null for all]. */
    public const TABS = [
        'all' => ['All', null],
        'live' => ['Live', 'ACTIVATE'],
        'deactivated' => ['Deactivated', 'SELLER_DEACTIVATED'],
        'review' => ['Under review', 'IN_REVIEW'],
        'failed' => ['Failed review', 'FAILED'],
        'frozen' => ['Frozen', 'FREEZE'],
    ];

    public function __construct(private TikTokClient $client) {}

    /** @return array{live: ?array, error: ?string} */
    public function fetch(string $tiktokProductId, array $c): array
    {
        try {
            $r = $this->client->getProduct($c['app_key'], $c['app_secret'], $c['token'], $tiktokProductId, $c['shop_cipher'] ?: null);
        } catch (\Throwable $e) {
            return ['live' => null, 'error' => \App\Support\TransportError::plain($e, 'TikTok Shop')];
        }
        $this->log('GET', '/product/202309/products/' . $tiktokProductId, ['product_id' => $tiktokProductId], $r);

        if (!($r['ok'] ?? false) || (int) ($r['body']['code'] ?? -1) !== 0) {
            return ['live' => null, 'error' => 'TikTok Shop did not answer: ' . (string) ($r['body']['message'] ?? 'no response')];
        }
        $d = $r['body']['data'] ?? [];
        $skus = [];
        foreach ((array) ($d['skus'] ?? []) as $sku) {
            $skus[] = [
                'id' => (string) ($sku['id'] ?? ''),
                'seller_sku' => (string) ($sku['seller_sku'] ?? ''),
                'price' => (float) ($sku['price']['sale_price'] ?? $sku['price']['tax_exclusive_price'] ?? 0),
                'stock' => array_sum(array_map(fn ($i) => (int) ($i['quantity'] ?? 0), (array) ($sku['inventory'] ?? []))),
                'name' => implode(' / ', array_map(fn ($a) => (string) ($a['value_name'] ?? ''), (array) ($sku['sales_attributes'] ?? []))),
            ];
        }

        return ['live' => [
            'status' => (string) ($d['status'] ?? ''),
            'title' => (string) ($d['title'] ?? ''),
            'skus' => $skus,
            'stock' => array_sum(array_column($skus, 'stock')),
            'update_time' => (int) ($d['update_time'] ?? 0),
        ], 'error' => null];
    }

    /**
     * Every product on the shop with its status, page by page (100 per
     * page, ten pages at most), keyed by TikTok product id.
     * @return array{statuses: ?array<string, string>, error: ?string}
     */
    public function statuses(array $c, ?string $status = null): array
    {
        $out = [];
        $token = null;
        for ($page = 0; $page < 10; $page++) {
            $body = ['page_size' => 100];
            if ($status) {
                $body['status'] = $status;
            }
            try {
                $r = $this->client->post($c['app_key'], $c['app_secret'], $c['token'], '/product/202309/products/search',
                    array_filter(['page_size' => 100, 'page_token' => $token]), array_filter($body, fn ($v) => $v !== null), $c['shop_cipher'] ?: null);
            } catch (\Throwable $e) {
                return ['statuses' => null, 'error' => \App\Support\TransportError::plain($e, 'TikTok Shop')];
            }
            $this->log('POST', '/product/202309/products/search', $body + ['page_token' => $token], $r);
            if (!($r['ok'] ?? false) || (int) ($r['body']['code'] ?? -1) !== 0) {
                return ['statuses' => null, 'error' => 'TikTok Shop did not answer: ' . (string) ($r['body']['message'] ?? 'no response')];
            }
            foreach ((array) ($r['body']['data']['products'] ?? []) as $p) {
                if (!empty($p['id'])) {
                    $out[(string) $p['id']] = (string) ($p['status'] ?? '');
                }
            }
            $token = $r['body']['data']['next_page_token'] ?? null;
            if (!$token) {
                break;
            }
        }

        return ['statuses' => $out, 'error' => null];
    }

    private function log(string $method, string $path, array $params, array $result): void
    {
        TikTokApiLog::safeCreate([
            'pack' => 'tiktok.listings.live', 'method' => $method,
            'api_path' => $path, 'auth_required' => true,
            'request_params' => $params,
            'response_status' => $result['status'] ?? 0,
            'ok' => (bool) ($result['ok'] ?? false),
            'response_body' => $result['body'] ?? [], 'user_id' => auth()->id(),
        ]);
    }
}
