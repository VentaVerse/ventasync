<?php

namespace Extensions\tiktok\Services\TikTok;

use Extensions\tiktok\Models\TikTokApiLog;

class TikTokLiveListing
{
    public const TABS = [
        'all' => ['All', null],
        'live' => ['Live', 'ACTIVATE'],
        'deactivated' => ['Deactivated', 'SELLER_DEACTIVATED'],
        'review' => ['Under review', 'IN_REVIEW'],
        'failed' => ['Failed review', 'FAILED'],
        'frozen' => ['Frozen', 'FREEZE'],
    ];

    public function __construct(private TikTokClient $client) {}

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

    public function variationSkus(string $tiktokProductId, array $c): array
    {
        try {
            $r = $this->client->getProduct($c['app_key'], $c['app_secret'], $c['token'], $tiktokProductId, $c['shop_cipher'] ?: null);
        } catch (\Throwable $e) {
            return ['skus' => null, 'error' => \App\Support\TransportError::plain($e, 'TikTok Shop')];
        }
        $this->log('GET', '/product/202309/products/' . $tiktokProductId, ['product_id' => $tiktokProductId], $r);

        if (!($r['ok'] ?? false) || (int) ($r['body']['code'] ?? -1) !== 0) {
            return ['skus' => null, 'error' => 'TikTok Shop did not answer: ' . (string) ($r['body']['message'] ?? 'no response')];
        }

        $skus = [];
        foreach ((array) ($r['body']['data']['skus'] ?? []) as $sku) {
            if (!is_array($sku)) {
                continue;
            }
            $attributes = [];
            foreach ((array) ($sku['sales_attributes'] ?? []) as $a) {
                if (is_array($a)) {
                    $attributes[] = [
                        'id' => (string) ($a['id'] ?? ''), 'name' => (string) ($a['name'] ?? ''),
                        'value_id' => (string) ($a['value_id'] ?? ''), 'value_name' => (string) ($a['value_name'] ?? ''),
                    ];
                }
            }
            $skus[] = ['id' => (string) ($sku['id'] ?? ''), 'seller_sku' => (string) ($sku['seller_sku'] ?? ''), 'sales_attributes' => $attributes];
        }

        return ['skus' => $skus, 'error' => null];
    }

    public function shop(array $c, ?string $status = null): array
    {
        $out = [];
        $token = null;
        for ($page = 0; $page < 10; $page++) {
            $body = [];
            if ($status) {
                $body['status'] = $status;
            }
            try {
                $r = $this->client->post($c['app_key'], $c['app_secret'], $c['token'], '/product/202309/products/search',
                    array_filter(['page_size' => 100, 'page_token' => $token]), $body, $c['shop_cipher'] ?: null);
            } catch (\Throwable $e) {
                return ['products' => null, 'error' => \App\Support\TransportError::plain($e, 'TikTok Shop')];
            }
            $this->log('POST', '/product/202309/products/search', $body + ['page_token' => $token], $r);
            if (!($r['ok'] ?? false) || (int) ($r['body']['code'] ?? -1) !== 0) {
                return ['products' => null, 'error' => 'TikTok Shop did not answer: ' . (string) ($r['body']['message'] ?? 'no response')];
            }
            foreach ((array) ($r['body']['data']['products'] ?? []) as $p) {
                if (empty($p['id'])) {
                    continue;
                }
                $skus = [];
                foreach ((array) ($p['skus'] ?? []) as $sku) {
                    $seller = trim((string) ($sku['seller_sku'] ?? ''));
                    if ($seller !== '') {
                        $skus[] = $seller;
                    }
                }
                $out[(string) $p['id']] = [
                    'status' => (string) ($p['status'] ?? ''),
                    'title' => (string) ($p['title'] ?? ''),
                    'skus' => $skus,
                    'image' => (string) (data_get($p, 'main_images.0.urls.0') ?? data_get($p, 'main_images.0.url') ?? ''),
                ];
            }
            $token = $r['body']['data']['next_page_token'] ?? null;
            if (!$token) {
                break;
            }
        }

        return ['products' => $out, 'error' => null, 'truncated' => $token !== null];
    }

    public function statuses(array $c, ?string $status = null): array
    {
        $shop = $this->shop($c, $status);

        return [
            'statuses' => $shop['products'] === null ? null : array_map(fn ($p) => $p['status'], $shop['products']),
            'error' => $shop['error'],
        ];
    }

    public function refreshMirror(array $c): array
    {
        $answer = $this->statuses($c);
        if ($answer['statuses'] === null) {
            return ['error' => (string) $answer['error'], 'counts' => [], 'links' => 0];
        }
        $statuses = $answer['statuses'];
        $now = now();

        $withRow = \Extensions\tiktok\Models\TikTokListing::query()->pluck('product_id')->map(fn ($v) => (int) $v)->all();
        $pivots = \Extensions\tiktok\Models\TikTokProductGroupProduct::query()->onStore($c['setting'] ?? null)
            ->whereNotNull('tiktok_product_id')
            ->whereNotIn('product_id', $withRow ?: [0])
            ->orderByDesc('last_pushed_at')
            ->get()
            ->unique('product_id');
        foreach ($pivots as $pv) {
            \Extensions\tiktok\Models\TikTokListing::query()->firstOrCreate(
                ['product_id' => (int) $pv->product_id],
                ['tiktok_product_id' => (string) $pv->tiktok_product_id, 'tiktok_sku_id' => $pv->tiktok_sku_id, 'last_pushed_at' => $pv->last_pushed_at, 'last_push_source' => 'group']
            );
        }
        $blank = \Extensions\tiktok\Models\TikTokListing::query()->whereNull('tiktok_product_id')->get();
        foreach ($blank as $row) {
            $pv = \Extensions\tiktok\Models\TikTokProductGroupProduct::query()->onStore($c['setting'] ?? null)
                ->where('product_id', $row->product_id)->whereNotNull('tiktok_product_id')
                ->orderByDesc('last_pushed_at')->first();
            if ($pv) {
                $row->forceFill(['tiktok_product_id' => (string) $pv->tiktok_product_id])->save();
            }
        }

        $counts = [];
        $rows = \Extensions\tiktok\Models\TikTokListing::query()->whereNotNull('tiktok_product_id')->get(['id', 'tiktok_product_id']);
        foreach ($rows as $row) {
            $status = $statuses[(string) $row->tiktok_product_id] ?? 'MISSING';
            \Extensions\tiktok\Models\TikTokListing::query()->whereKey($row->id)
                ->update(['live_status' => $status, 'live_checked_at' => $now]);
            $counts[$status] = ($counts[$status] ?? 0) + 1;
        }

        return ['error' => null, 'counts' => $counts, 'links' => $rows->count()];
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
