<?php

namespace Extensions\tiktok\Services\TikTok;

use App\Integrations\Push\PushLedger;
use Extensions\tiktok\Models\TikTokApiLog;
use Extensions\tiktok\Models\TikTokProductGroup;
use Extensions\tiktok\Models\TikTokProductGroupProduct;

/**
 * The one sender for TikTok Shop stock and price updates (Phase F, #184)
 * - Shopee's ShopeeStockPricePush, ported. Three near-identical payload
 * loops (row menu, group page, cron) shared four lies:
 *
 *  - an ERP variation whose SKU was not in the tiktok_sku_id map was
 *    never enumerated at all - the purest deleted-1pc silence;
 *  - a map key the catalogue no longer had was pushed qty 0 (stock) or
 *    the base price (price), silently;
 *  - the __single__ degradation pushed a whole variation product's
 *    aggregate figures onto one SKU;
 *  - TikTok's per-SKU data.errors[] was never parsed, so a partially
 *    refused call counted as a full success and could flash
 *    "0 ok, 40 failed" in green.
 *
 * Now: the plan is diffed against the catalogue both ways and every gap
 * is named; a wrong-shaped map heals itself with ONE product read
 * (TikTokSkuMapRepair) before the call, and once more with a retry on a
 * refusal; per-SKU refusals produce per-product verdicts; and the flash
 * carries the shared ledger clause in the shared tone.
 */
class TikTokStockPricePush
{
    public function __construct(
        private readonly TikTokClient $client,
        private readonly TikTokStockPushService $resolver,
        private readonly TikTokSkuMapRepair $repair,
    ) {
    }

    private const SKU_ERROR = '/sku|inventory.*not.*found|product.*not.*(?:found|exist)/i';

    /**
     * @param 'stock'|'price' $kind
     * @param iterable $pivots rows exposing product_id, tiktok_product_id, tiktok_sku_id
     * @param ?callable $priceFor fn(float $base, object $pivot): float
     * @return array{
     *   ok: int, err: int, skipped: int, last_error: string,
     *   outcomes: array<int, array{ok: bool, error: ?string}>,
     *   mismatched: list<array{product_id: int, sku: string, reason: string}>,
     *   skipped_variations: list<array{product_id: int, sku: string, reason: string}>,
     *   healed_products: list<string>,
     * }
     */
    public function push(string $kind, array $c, $pivots, string $pfx, ?callable $priceFor = null): array
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
        $erpSkus = PushLedger::erpVariationSkus($productIds, $pfx);
        $storeId = (int) (\Extensions\tiktok\Models\TikTokSetting::defaultStore()?->id ?? 0);
        $hidden = \App\Integrations\Listings\ListingVariations::hidden('tiktok', $storeId, $productIds);
        $soldSkus = \App\Integrations\Listings\ListingVariations::sold('tiktok', $storeId, $productIds, $pfx);

        /* Pre-call heal: a map that is empty, single-keyed on a product
         * that sells in variations, or missing a SKU this store sells will
         * push the wrong figures. One product read fixes it - the old
         * code's skus[0].id hack, done properly. */
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

        $resolved = $this->resolver->resolve($pivots, $pfx, $priceFor);

        foreach ($resolved as $row) {
            $this->pushProduct($kind, $c, $row, $pfx, $priceFor, $erpSkus, $hidden, $out, true);
        }

        return $out;
    }

    private function pushProduct(string $kind, array $c, array $row, string $pfx, ?callable $priceFor, array $erpSkus, array $hidden, array &$out, bool $mayHeal): void
    {
        $pivot = $row['pivot'];
        $pid = (int) $pivot->product_id;

        if (($row['product_status'] ?? 1) === 0) {
            $out['skipped']++;

            return;
        }

        /* Only what this store sells is sent: a SKU the catalogue does not
         * have and a variation switched off are left alone, and a catalogue
         * variation the shop does not carry is simply not asked of it.
         * None of these is an error. */
        $sentSkus = [];
        $payload = [];
        foreach ($row['skus'] as $sku) {
            if (!$sku['matched'] && $sku['seller_sku'] !== '' && !empty($erpSkus[$pid])) {
                continue;
            }
            if (!\App\Integrations\Listings\ListingVariations::allows($hidden, $pid, (string) $sku['seller_sku'])) {
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

        /* Per-SKU refusals inside an accepted call (data.errors[]): the
         * detail TikTok has been sending all along, read at last. */
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
                $fresh = $this->resolver->resolve([$pivot], $pfx, $priceFor);
                if (!empty($fresh)) {
                    $this->pushProduct($kind, $c, $fresh[0], $pfx, $priceFor, $erpSkus, $hidden, $out,false);
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

    /**
     * Each product's verdict onto the store's group pivots and its listing
     * error line. A success clears the line, a failure writes it. The store
     * is the one the push went to; without one named, the store in hand.
     *
     * @param array<int, array{ok: bool, error: ?string}> $outcomes
     */
    public static function recordOutcomes(string $kind, array $outcomes, \Extensions\tiktok\Models\TikTokSetting|int|null $store = null): void
    {
        $store ??= \Extensions\tiktok\Models\TikTokSetting::defaultStore();
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

    /** The shared flash suffix, in TikTok Shop's name. */
    public static function ledgerClause(array $results): string
    {
        return PushLedger::clause($results, 'TikTok Shop');
    }
}
