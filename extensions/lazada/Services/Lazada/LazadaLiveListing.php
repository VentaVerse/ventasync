<?php

namespace Extensions\lazada\Services\Lazada;

use Extensions\lazada\Models\LazadaApiLog;

/**
 * The live half of the Lazada listing surfaces: what Lazada says about the
 * shop RIGHT NOW, fetched on page load by design (the owner, 2026-08-23) -
 * status is the marketplace's answer, never a cached copy presented as
 * live. When Lazada does not answer, the pages say so instead of
 * substituting stale data. Mirror of Shopee's ShopeeLiveListing.
 */
class LazadaLiveListing
{
    public function __construct(private LazadaClient $client)
    {
    }

    /** @throws \RuntimeException when Lazada does not answer */
    private function signedGet(object $setting, array $creds, string $apiPath, array $extra): array
    {
        $params = array_merge([
            'app_key' => (string) $creds['app_key'],
            'sign_method' => 'sha256',
            'timestamp' => (string) round(microtime(true) * 1000),
            'access_token' => (string) $creds['access_token'],
        ], $extra);
        $params['sign'] = $this->client->sign($apiPath, $params, (string) $creds['app_secret']);

        try {
            $res = $this->client->get(
                (string) $setting->region,
                $apiPath,
                $params,
                (string) ($creds['mode'] ?? 'live')
            );
        } catch (\Throwable $e) {
            // Never the raw message: it carries the signed URL. See TransportError.
            throw new \RuntimeException(\App\Support\TransportError::plain($e, 'Lazada'));
        }

        $body = $res['body'] ?? [];
        $code = is_array($body) ? (string) ($body['code'] ?? '') : '';
        if (!($res['ok'] ?? false) || ($code !== '' && $code !== '0')) {
            $msg = is_array($body) ? (string) ($body['message'] ?? ($body['error'] ?? 'no response')) : 'no response';
            throw new \RuntimeException($msg . ($code !== '' && $code !== '0' ? ' (code ' . $code . ')' : ''));
        }

        return is_array($body) ? ($body['data'] ?? []) : [];
    }

    /** total_products for one seller-center filter, one limit=1 call. */
    public function count(object $setting, array $creds, string $filter): int
    {
        $data = $this->signedGet($setting, $creds, '/products/get', [
            'filter' => $filter, 'limit' => '1', 'offset' => '0',
        ]);

        return (int) ($data['total_products'] ?? 0);
    }

    /** Every item id in one filter, paged at 50, capped at 500 items. */
    public function itemIds(object $setting, array $creds, string $filter): array
    {
        $ids = [];
        for ($pageN = 0; $pageN < 10; $pageN++) {
            $data = $this->signedGet($setting, $creds, '/products/get', [
                'filter' => $filter, 'limit' => '50', 'offset' => (string) ($pageN * 50),
            ]);
            $products = $data['products'] ?? [];
            foreach ($products as $prod) {
                if ($id = $prod['item_id'] ?? null) {
                    $ids[] = (string) $id;
                }
            }
            if (count($products) < 50) {
                break;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * Live item statuses for a page of rows, looked up by seller SKU
     * (Lazada's one batch lookup; 100 SKUs a call). Returns a map keyed by
     * item_id (string) with the lowercased status. A linked item ABSENT
     * from a successful answer is Lazada saying the shop holds no such
     * item - the caller marks it, never papers over it.
     *
     * @param array<string> $skus
     * @return array<string, string>
     * @throws \RuntimeException when Lazada does not answer
     */
    public function statusesBySku(object $setting, array $creds, array $skus): array
    {
        $statuses = [];
        // Chunked at 50, not Lazada's 100-SKU input cap: the response is
        // page-limited to 50 items (error 19 above that), so a 100-SKU ask
        // could silently drop half its answers.
        foreach (array_chunk(array_values(array_unique(array_filter($skus))), 50) as $chunk) {
            $data = $this->signedGet($setting, $creds, '/products/get', [
                'filter' => 'all', 'limit' => '50', 'offset' => '0',
                'sku_seller_list' => json_encode($chunk, JSON_UNESCAPED_UNICODE),
            ]);
            foreach (($data['products'] ?? []) as $prod) {
                if ($id = $prod['item_id'] ?? null) {
                    $statuses[(string) $id] = strtolower((string) ($prod['status'] ?? ''));
                }
            }
        }

        return $statuses;
    }

    /**
     * One item's full live state for the edit page.
     *
     * @return array{live: ?array, error: ?string}
     */
    public function fetch(object $setting, array $creds, string $itemId): array
    {
        try {
            $data = $this->signedGet($setting, $creds, '/product/item/get', [
                'item_id' => $itemId,
            ]);
        } catch (\RuntimeException $e) {
            $this->logFetch($itemId, false, $e->getMessage());

            return ['live' => null, 'error' => 'Lazada did not answer: ' . $e->getMessage()];
        }

        $this->logFetch($itemId, true, null);

        if (empty($data) || (empty($data['item_id']) && empty($data['skus']))) {
            return ['live' => null, 'error' => 'Lazada holds no record for item ' . $itemId . '. It may have been deleted on the marketplace.'];
        }

        $skus = [];
        foreach (($data['skus'] ?? []) as $sku) {
            $skus[] = [
                'seller_sku' => (string) ($sku['SellerSku'] ?? ''),
                'price' => isset($sku['price']) ? (float) $sku['price'] : null,
                'special_price' => isset($sku['special_price']) && (float) $sku['special_price'] > 0
                    ? (float) $sku['special_price'] : null,
                'quantity' => isset($sku['quantity']) ? (int) $sku['quantity'] : null,
                'status' => strtolower((string) ($sku['Status'] ?? '')),
            ];
        }

        return ['live' => [
            'status' => strtolower((string) ($data['status'] ?? '')),
            'sub_status' => (string) ($data['subStatus'] ?? ''),
            'item_name' => (string) ($data['attributes']['name'] ?? ''),
            'skus' => $skus,
        ], 'error' => null];
    }

    private function logFetch(string $itemId, bool $ok, ?string $error): void
    {
        LazadaApiLog::safeCreate([
            'pack' => 'lazada.listings.live', 'method' => 'GET',
            'api_path' => '/product/item/get', 'auth_required' => true,
            'request_params' => ['item_id' => $itemId],
            'response_status' => $ok ? 200 : null,
            'ok' => $ok,
            'response_body' => $error ? ['error' => $error] : null,
            'user_id' => auth()->id(),
        ]);
    }
}
