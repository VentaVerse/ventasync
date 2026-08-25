<?php

namespace Extensions\shopee\Services\Shopee;

use Extensions\shopee\Models\ShopeeApiLog;

/**
 * The live half of the individual listing page: what Shopee says about an
 * item RIGHT NOW. Fetched on every page load by design (the owner, 2026-08-23):
 * status must be the marketplace's answer, never a cached copy presented as
 * live. When Shopee does not answer, the page says so instead of
 * substituting stale data.
 */
class ShopeeLiveListing
{
    public function __construct(private ShopeeClient $client)
    {
    }

    /**
     * @return array{live: ?array, error: ?string}
     */
    public function fetch(array $auth, int $itemId): array
    {
        try {
            $r = $this->client->shopGet(
                $auth['mode'],
                (int) $auth['partner_id'], (string) $auth['partner_key'],
                (string) $auth['access_token'], (int) $auth['shop_id'],
                '/api/v2/product/get_item_base_info',
                ['item_id_list' => (string) $itemId]
            );
        } catch (\Throwable $e) {
            // A connection failure must degrade to the page's "did not
            // answer" note, never to a 500 on a page that otherwise works.
            return ['live' => null, 'error' => \App\Support\TransportError::plain($e, 'Shopee')];
        }

        ShopeeApiLog::safeCreate([
            'pack' => 'shopee.listings.live', 'method' => 'GET',
            'api_path' => '/api/v2/product/get_item_base_info', 'auth_required' => true,
            'request_params' => ['item_id_list' => (string) $itemId],
            'response_status' => $r['status'] ?? null,
            'ok' => (bool) ($r['ok'] ?? false),
            'response_body' => $r['body'] ?? null, 'user_id' => auth()->id(),
        ]);

        if (!($r['ok'] ?? false)) {
            $msg = is_array($r['body'] ?? null)
                ? (string) ($r['body']['message'] ?? ($r['body']['error'] ?? 'no response'))
                : 'no response';
            return ['live' => null, 'error' => 'Shopee did not answer: ' . $msg];
        }

        $item = (($r['body'] ?? [])['response']['item_list'] ?? [])[0] ?? null;
        if (!$item) {
            return ['live' => null, 'error' => 'Shopee holds no record for item ' . $itemId . '. It may have been deleted on the marketplace.'];
        }

        $live = [
            'item_status' => (string) ($item['item_status'] ?? ''),
            'item_name' => (string) ($item['item_name'] ?? ''),
            'item_sku' => (string) ($item['item_sku'] ?? ''),
            'has_model' => (bool) ($item['has_model'] ?? false),
            'has_promotion' => (bool) ($item['has_promotion'] ?? false),
            'update_time' => (int) ($item['update_time'] ?? 0),
            'image_url' => (string) (($item['image']['image_url_list'] ?? [])[0] ?? ''),
            'price' => $this->priceOf($item['price_info'] ?? []),
            'stock' => $this->stockOf($item),
            'models' => [],
        ];

        if ($live['has_model']) {
            $live['models'] = $this->fetchModels($auth, $itemId);
        }

        return ['live' => $live, 'error' => null];
    }

    private function fetchModels(array $auth, int $itemId): array
    {
        try {
            $r = $this->client->shopGet(
                $auth['mode'],
                (int) $auth['partner_id'], (string) $auth['partner_key'],
                (string) $auth['access_token'], (int) $auth['shop_id'],
                '/api/v2/product/get_model_list',
                ['item_id' => $itemId]
            );
        } catch (\Throwable) {
            return [];
        }

        if (!($r['ok'] ?? false)) {
            return [];
        }

        $models = (($r['body'] ?? [])['response']['model'] ?? []);

        return array_map(fn ($m) => [
            'model_id' => (int) ($m['model_id'] ?? 0),
            'model_sku' => (string) ($m['model_sku'] ?? ''),
            'name' => (string) ($m['model_name'] ?? ''),
            'price' => $this->priceOf($m['price_info'] ?? []),
            'stock' => $this->stockOf($m),
        ], $models);
    }

    /** @return array{original: ?float, current: ?float} */
    private function priceOf(array $priceInfo): array
    {
        $first = $priceInfo[0] ?? null;
        if (!is_array($first)) {
            return ['original' => null, 'current' => null];
        }
        return [
            'original' => isset($first['original_price']) ? (float) $first['original_price'] : null,
            'current' => isset($first['current_price']) ? (float) $first['current_price'] : null,
        ];
    }

    /**
     * Shopee has shipped three stock shapes across API revisions; read them
     * newest-first so the page works on whichever the shop's region returns.
     */
    private function stockOf(array $item): ?int
    {
        $v2 = $item['stock_info_v2'] ?? null;
        if (is_array($v2)) {
            $summary = $v2['summary_info']['total_available_stock'] ?? null;
            if ($summary !== null) {
                return (int) $summary;
            }
            $seller = ($v2['seller_stock'] ?? [])[0]['stock'] ?? null;
            if ($seller !== null) {
                return (int) $seller;
            }
        }
        $v1 = ($item['stock_info'] ?? [])[0]['current_stock'] ?? null;
        return $v1 !== null ? (int) $v1 : null;
    }
}
