<?php

namespace Extensions\lazada\Services\Lazada;

use Extensions\lazada\Models\LazadaApiLog;

class LazadaLiveListing
{
    public function __construct(private LazadaClient $client)
    {
    }

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

    public function count(object $setting, array $creds, string $filter): int
    {
        $data = $this->signedGet($setting, $creds, '/products/get', [
            'filter' => $filter, 'limit' => '1', 'offset' => '0',
        ]);

        return (int) ($data['total_products'] ?? 0);
    }

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

    public function statusesBySku(object $setting, array $creds, array $skus): array
    {
        $statuses = [];
        // Chunk at 50: the response is capped at 50 items even though the input accepts 100 SKUs.
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

    public function refreshMirror(object $setting, array $creds): array
    {
        try {
            $statusById = $this->statusIndex($setting, $creds, 'all');
            foreach ($this->statusIndex($setting, $creds, 'deleted') as $id => $status) {
                $statusById[$id] ??= $status ?: 'deleted';
            }
        } catch (\Throwable $e) {
            return ['error' => $e->getMessage(), 'counts' => [], 'links' => 0];
        }

        $now = now();
        $counts = [];
        $byStatus = [];
        foreach ($statusById as $id => $status) {
            $byStatus[$status][] = (string) $id;
        }
        foreach ($byStatus as $status => $ids) {
            $counts[$status] = 0;
            foreach (array_chunk($ids, 500) as $chunk) {
                $counts[$status] += \Extensions\lazada\Models\LazadaProduct::query()
                    ->whereIn('lazada_item_id', $chunk)
                    ->update(['live_status' => $status, 'live_checked_at' => $now]);
            }
        }
        $missing = \Extensions\lazada\Models\LazadaProduct::query()
            ->whereNotNull('lazada_item_id')->where('lazada_item_id', '!=', '')
            ->whereNotIn('lazada_item_id', array_map('strval', array_keys($statusById)) ?: ['-none-'])
            ->update(['live_status' => 'missing', 'live_checked_at' => $now]);
        if ($missing > 0) {
            $counts['missing'] = $missing;
        }

        return [
            'error' => null,
            'counts' => array_filter($counts),
            'links' => (int) \Extensions\lazada\Models\LazadaProduct::query()
                ->whereNotNull('lazada_item_id')->where('lazada_item_id', '!=', '')->count(),
        ];
    }

    public function statusIndex(object $setting, array $creds, string $filter): array
    {
        $out = [];
        for ($pageN = 0; $pageN < 40; $pageN++) {
            $data = $this->signedGet($setting, $creds, '/products/get', [
                'filter' => $filter, 'limit' => '50', 'offset' => (string) ($pageN * 50),
            ]);
            $products = $data['products'] ?? [];
            foreach ($products as $prod) {
                $id = (string) ($prod['item_id'] ?? '');
                if ($id !== '') {
                    $out[$id] = strtolower((string) ($prod['status'] ?? ''));
                }
            }
            if (count($products) < 50) {
                break;
            }
        }
        return $out;
    }

    public function itemsBySkus(object $setting, array $creds, array $skus): array
    {
        $out = [];
        foreach (array_chunk(array_values(array_unique(array_filter($skus))), 50) as $chunk) {
            $data = $this->signedGet($setting, $creds, '/products/get', [
                'filter' => 'all', 'limit' => '50', 'offset' => '0',
                'sku_seller_list' => json_encode($chunk, JSON_UNESCAPED_UNICODE),
            ]);
            foreach (($data['products'] ?? []) as $prod) {
                $itemId = (string) ($prod['item_id'] ?? '');
                if ($itemId === '') {
                    continue;
                }
                foreach ((array) ($prod['skus'] ?? []) as $sku) {
                    $seller = strtolower(trim((string) ($sku['SellerSku'] ?? $sku['seller_sku'] ?? '')));
                    if ($seller !== '') {
                        $out[$seller] = ['item_id' => $itemId, 'status' => strtolower((string) ($prod['status'] ?? ''))];
                    }
                }
            }
        }

        return $out;
    }

    public function skuIndex(object $setting, array $creds): array
    {
        $items = [];
        $complete = true;
        for ($pageN = 0; $pageN < 20; $pageN++) {
            try {
                $data = $this->signedGet($setting, $creds, '/products/get', [
                    'filter' => 'all', 'limit' => '50', 'offset' => (string) ($pageN * 50),
                ]);
            } catch (\Throwable) {
                $complete = false;
                break;
            }
            $products = $data['products'] ?? [];
            foreach ($products as $prod) {
                $itemId = (string) ($prod['item_id'] ?? '');
                if ($itemId === '') {
                    continue;
                }
                $items[$itemId] ??= [];
                foreach ((array) ($prod['skus'] ?? []) as $sku) {
                    $seller = trim((string) ($sku['SellerSku'] ?? $sku['seller_sku'] ?? ''));
                    if ($seller !== '' && !in_array($seller, $items[$itemId], true)) {
                        $items[$itemId][] = $seller;
                    }
                }
            }
            if (count($products) < 50) {
                break;
            }
            if ($pageN === 19) {
                $complete = false;
            }
        }

        return ['items' => $items, 'complete' => $complete];
    }

    public function raw(object $setting, array $creds, string $itemId): array
    {
        return $this->signedGet($setting, $creds, '/product/item/get', ['item_id' => $itemId]);
    }

    public function salePropKeys(object $setting, array $creds, string $itemId): array
    {
        return $this->itemShape($setting, $creds, $itemId)['keys'];
    }

    public function itemShape(object $setting, array $creds, string $itemId): array
    {
        return self::shapeOf($this->signedGet($setting, $creds, '/product/item/get', ['item_id' => $itemId]));
    }

    public const PACKAGE_FIELDS = ['package_weight', 'package_length', 'package_width', 'package_height'];

    public static function shapeOf(array $data): array
    {
        $keys = [];
        $packages = [];
        $skus = [];
        foreach ((array) ($data['skus'] ?? []) as $sku) {
            if (! is_array($sku)) {
                continue;
            }
            $skuId = $sku['SkuId'] ?? $sku['sku_id'] ?? null;
            $skus[] = [
                'sku_id' => is_numeric($skuId) ? (int) $skuId : null,
                'seller_sku' => trim((string) ($sku['SellerSku'] ?? $sku['seller_sku'] ?? '')),
                'status' => strtolower(trim((string) ($sku['Status'] ?? $sku['status'] ?? ''))),
            ];
            foreach ((array) ($sku['saleProp'] ?? []) as $key => $value) {
                if (is_string($key) && trim($key) !== '' && ! in_array($key, $keys, true)) {
                    $keys[] = $key;
                }
            }
            $package = [];
            foreach (self::PACKAGE_FIELDS as $field) {
                $value = $sku[$field] ?? null;
                if (is_scalar($value) && trim((string) $value) !== '') {
                    $package[$field] = trim((string) $value);
                }
            }
            $packages[] = $package;
        }

        $variation = null;
        if (isset($data['variation']) && is_array($data['variation'])) {
            $variation = [];
            foreach ($data['variation'] as $slotKey => $type) {
                if (! is_array($type) || ! preg_match('/^variation([1-9])$/i', (string) $slotKey, $m)) {
                    continue;
                }
                $name = trim((string) ($type['name'] ?? ''));
                if ($name === '') {
                    continue;
                }
                $customize = $type['customize'] ?? false;
                $variation[] = [
                    'slot' => (int) $m[1],
                    'name' => $name,
                    'customize' => $customize === true || (is_string($customize) && strtolower(trim($customize)) === 'true') || $customize === 1,
                    'has_image' => $type['has_image'] ?? null,
                ];
            }
            usort($variation, fn ($a, $b) => $a['slot'] <=> $b['slot']);
        }

        return [
            'keys' => $keys, 'packages' => $packages, 'variation' => $variation,
            'status' => strtolower(trim((string) ($data['status'] ?? ''))),
            'skus' => $skus,
        ];
    }

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
