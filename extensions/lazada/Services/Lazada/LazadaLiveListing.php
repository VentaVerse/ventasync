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
     * Which items hold these seller SKUs, batched at 50 (#156, Check
     * against Lazada): lowercased seller SKU => ['item_id', 'status'].
     * A SKU absent from a successful answer is Lazada saying no item
     * carries it.
     *
     * @param array<string> $skus
     * @return array<string, array{item_id: string, status: string}>
     * @throws \RuntimeException when Lazada does not answer
     */
    /**
     * Refresh the status mirror: one walk of the shop (filter all, 50 a
     * page) plus one of the deleted bucket, and every uploaded listing
     * row gets Lazada's own status word. A listing the shop no longer
     * returns becomes missing. One walk serves every listings and group
     * page until the next; a page view costs Lazada nothing (the owner,
     * 2026-08-28).
     *
     * @return array{error: ?string, counts: array<string, int>, links: int}
     */
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

    /** item_id => lowercased status for every item under one filter, 50 a page, up to 2,000 items. Throws when Lazada refuses. */
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

    /**
     * Every item on the shop with its seller SKUs (#156, Unlinked on
     * Lazada): item_id => [skus], paged at 50, twenty pages at most, with
     * a flag saying whether the walk finished.
     *
     * @return array{items: array<string, array<int, string>>, complete: bool}
     */
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

    /**
     * One item's full live state for the edit page.
     *
     * @return array{live: ?array, error: ?string}
     */
    /**
     * The FULL /product/item/get answer, for callers that need the whole
     * item rather than the condensed status view - the importer (#196)
     * reads name, description, brand, images, parcel and variation
     * properties from it. Throws the same plain RuntimeException the
     * other reads throw.
     */
    public function raw(object $setting, array $creds, string $itemId): array
    {
        return $this->signedGet($setting, $creds, '/product/item/get', ['item_id' => $itemId]);
    }

    /**
     * The variation keys the item's SKUs carry now, in their order, from
     * each SKU's saleProp map ({"Length": "1m"}); [] when the item has none.
     * Throws when Lazada does not answer.
     *
     * @return list<string>
     */
    public function salePropKeys(object $setting, array $creds, string $itemId): array
    {
        return $this->itemShape($setting, $creds, $itemId)['keys'];
    }

    /**
     * What an update has to respect on an item already on Lazada, from one
     * /product/item/get read. See shapeOf(). Throws when Lazada does not
     * answer.
     *
     * @return array{keys: list<string>, packages: list<array<string, string>>, variation: ?list<array{slot: int, name: string, customize: bool, has_image: mixed}>}
     */
    public function itemShape(object $setting, array $creds, string $itemId): array
    {
        return self::shapeOf($this->signedGet($setting, $creds, '/product/item/get', ['item_id' => $itemId]));
    }

    public const PACKAGE_FIELDS = ['package_weight', 'package_length', 'package_width', 'package_height'];

    /**
     * An item/get answer reduced to what an update has to respect:
     * - keys: the variation keys the SKUs carry, in their order, from each
     *   SKU's saleProp map ({"Length": "1m"}); [] when the item has none;
     * - packages: each SKU's package_weight/length/width/height, only the
     *   ones it carries;
     * - variation: the item's variation types (variation1..4) with name,
     *   customize (true for a seller-defined type) and has_image, in slot
     *   order; null when the answer carries no variation object;
     * - status: the item's own status, lowercased;
     * - skus: each SKU's id, seller SKU and lowercased status.
     *
     * @return array{keys: list<string>, packages: list<array<string, string>>, variation: ?list<array{slot: int, name: string, customize: bool, has_image: mixed}>, status: string, skus: list<array{sku_id: ?int, seller_sku: string, status: string}>}
     */
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
