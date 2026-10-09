<?php

namespace Extensions\TikTok\Services\TikTok;

use App\Channels\Push\PushLedger;
use Extensions\TikTok\Models\TikTokApiLog;
use Extensions\TikTok\Models\TikTokProductGroup;
use Extensions\TikTok\Models\TikTokProductGroupProduct;

class TikTokStockPricePush
{
    public function __construct(
        private readonly TikTokClient $client,
        private readonly TikTokStockPushService $resolver,
        private readonly TikTokSkuMapRepair $repair,
    ) {
    }

    private const SKU_ERROR = '/sku|inventory.*not.*found|product.*not.*(?:found|exist)/i';

    public function push(string $kind, array $c, $pivots, ?callable $priceFor = null): array
    {
        $pivots = collect($pivots)->values();
        $out = [
            'ok' => 0, 'err' => 0, 'skipped' => 0, 'last_error' => '',
            'outcomes' => [], 'mismatched' => [], 'skipped_variations' => [], 'healed_products' => [],
        ];
        if ($pivots->isEmpty()) {
            return $out;
        }

        $productIds = $pivots->pluck('product_id')->map(fn ($v) => (int) $v)->unique()->values()->all();
        $erpSkus = PushLedger::erpVariationSkus($productIds);
        $storeId = (int) (\Extensions\TikTok\Models\TikTokStore::defaultStore()?->id ?? 0);
        $hidden = \App\Channels\Listings\ListingVariations::hidden('tiktok', $storeId, $productIds);
        $soldSkus = \App\Channels\Listings\ListingVariations::sold('tiktok', $storeId, $productIds);

        foreach ($pivots as $pivot) {
            $pid = (int) $pivot->product_id;
            $map = TikTokVariationPush::existingIds($pivot->tiktok_sku_id ?? null);
            $hasVariations = !empty($erpSkus[$pid]);
            $needsHeal = $map === []
                || ($hasVariations && isset($map['__single__']))
                || ($hasVariations && array_diff(array_map('strval', $soldSkus[$pid] ?? []), array_map('strval', array_keys($map))) !== []);
            if ($needsHeal && !empty($pivot->tiktok_product_id)) {
                $r = $this->repair->forProduct($c, $pid, (string) $pivot->tiktok_product_id);
                if (!isset($r['error'])) {
                    $out['healed_products'][] = (string) $pivot->tiktok_product_id;
                    $pivot->tiktok_sku_id = json_encode($r['map']);
                }
            }
        }

        $resolved = $this->resolver->resolve($pivots, $priceFor);

        foreach ($resolved as $row) {
            $this->pushProduct($kind, $c, $row, $priceFor, $erpSkus, $hidden, $out, true);
        }

        return $out;
    }

    private function pushProduct(string $kind, array $c, array $row, ?callable $priceFor, array $erpSkus, array $hidden, array &$out, bool $mayHeal): void
    {
        $pivot = $row['pivot'];
        $pid = (int) $pivot->product_id;

        if (($row['product_status'] ?? 1) === 0) {
            $out['skipped']++;

            return;
        }

        $sentSkus = [];
        $payload = [];
        foreach ($row['skus'] as $sku) {
            if (!$sku['matched'] && $sku['seller_sku'] !== '' && !empty($erpSkus[$pid])) {
                continue;
            }
            if (!\App\Channels\Listings\ListingVariations::allows($hidden, $pid, (string) $sku['seller_sku'])) {
                continue;
            }
            $payload[] = $kind === 'price'
                ? ['id' => $sku['id'], 'price' => ['amount' => (string) round($sku['price']), 'currency' => 'PHP']]
                : ['id' => $sku['id'], 'inventory' => [array_filter([
                    'warehouse_id' => ($c['warehouse_id'] ?? null) ?: null,
                    'quantity' => $sku['quantity'],
                ], fn ($v) => $v !== null)]];
            $sentSkus[(string) $sku['id']] = (string) $sku['seller_sku'];
        }
        if (empty($payload) && !empty($row['skus'])) {
            $out['skipped']++;

            return;
        }
        if (empty($payload)) {
            $out['err']++;
            $out['last_error'] = 'no TikTok SKU ids are recorded for this product';
            $out['outcomes'][$pid] = ['ok' => false, 'error' => $out['last_error']];

            return;
        }

        $ttId = (string) $pivot->tiktok_product_id;
        $path = '/product/202309/products/' . $ttId . ($kind === 'price' ? '/prices/update' : '/inventory/update');
        try {
            $result = $kind === 'price'
                ? $this->client->updatePrice($c['app_key'], $c['app_secret'], $c['token'], $ttId, $payload, ($c['shop_cipher'] ?? null) ?: null)
                : $this->client->updateInventory($c['app_key'], $c['app_secret'], $c['token'], $ttId, $payload, ($c['shop_cipher'] ?? null) ?: null);
        } catch (\Throwable $e) {
            $result = ['ok' => false, 'status' => 0, 'body' => ['message' => \App\Support\TransportError::plain($e, 'TikTok Shop')]];
        }

        TikTokApiLog::safeCreate([
            'pack' => $kind === 'price' ? 'tiktok.products.update_price' : 'tiktok.products.update_inventory',
            'method' => 'POST', 'api_path' => $path,
            'auth_required' => true, 'request_params' => ['skus' => $payload],
            'response_status' => $result['status'] ?? 0, 'ok' => (bool) ($result['ok'] ?? false),
            'response_body' => $result['body'] ?? [], 'user_id' => auth()->id(),
        ]);

        $body = $result['body'] ?? [];
        $callOk = ($result['ok'] ?? false) && (int) ($body['code'] ?? -1) === 0;
        $message = (string) ($body['message'] ?? 'Unknown error');

        $skuFailures = [];
        foreach ((array) data_get($body, 'data.errors', []) as $e) {
            $skuId = (string) (data_get($e, 'detail.sku_id') ?? data_get($e, 'detail.skus.0.id') ?? '');
            $reason = (string) ($e['message'] ?? 'refused');
            if ($skuId !== '') {
                $skuFailures[$skuId] = $reason;
            } else {
                $skuFailures['*'] = $reason;
            }
        }

        if (!$callOk && $mayHeal && preg_match(self::SKU_ERROR, $message)) {
            $r = $this->repair->forProduct($c, $pid, $ttId);
            if (!isset($r['error'])) {
                $out['healed_products'][] = $ttId;
                $pivot->tiktok_sku_id = json_encode($r['map']);
                $fresh = $this->resolver->resolve([$pivot], $priceFor);
                if (!empty($fresh)) {
                    $this->pushProduct($kind, $c, $fresh[0], $priceFor, $erpSkus, $hidden, $out,false);
                }

                return;
            }
        }

        if ($callOk && $skuFailures === []) {
            $out['ok']++;
            if (!array_key_exists($pid, $out['outcomes'])) {
                $out['outcomes'][$pid] = ['ok' => true, 'error' => null];
            }
        } else {
            $out['err']++;
            $reason = $callOk
                ? implode('; ', array_map(
                    fn ($id, $r) => ($sentSkus[$id] ?? ($id === '*' ? 'some SKUs' : $id)) . ': ' . $r,
                    array_keys($skuFailures), $skuFailures
                ))
                : $message;
            $out['last_error'] = $reason;
            $out['outcomes'][$pid] = ['ok' => false, 'error' => mb_substr($reason, 0, 255)];
        }
    }

    public static function recordOutcomes(string $kind, array $outcomes, \Extensions\TikTok\Models\TikTokStore|int|null $store = null): void
    {
        $store ??= \Extensions\TikTok\Models\TikTokStore::defaultStore();
        if ($store === null) {
            return;
        }
        $states = app(TikTokListingStates::class)->forStore($store);

        foreach ($outcomes as $pid => $outcome) {
            $failure = $outcome['ok'] ? null : ($kind === 'price' ? 'Price push: ' : 'Stock push: ') . (string) $outcome['error'];
            TikTokProductGroupProduct::query()->onStore($store)
                ->where('product_id', (int) $pid)
                ->where(fn ($q) => $q->whereNull('sync_status')->orWhere('sync_status', '!=', 'unlinked'))
                ->update($failure === null
                    ? ['sync_status' => 'pushed', 'push_error' => null, 'last_pushed_at' => now()]
                    : ['sync_status' => 'error', 'push_error' => mb_substr($failure, 0, 255)]);
            $states->recordOutcome((int) $pid, $failure);
        }
    }

    public static function ledgerClause(array $results): string
    {
        return PushLedger::clause($results, 'TikTok Shop');
    }
}
