<?php

namespace Extensions\lazada\Services\Lazada;

use App\Integrations\Push\PushLedger;
use Extensions\lazada\Models\LazadaApiLog;
use Extensions\lazada\Models\LazadaProduct;
use Extensions\lazada\Services\LazadaStockPushService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The one sender for Lazada stock and price updates (Phase F, #184) -
 * Shopee's ShopeeStockPricePush, ported. Seven push loops across two
 * controllers shared these lies:
 *
 *  - a variation whose SKU had no Lazada sku_id was counted as an
 *    anonymous error ("Success: 2, Error: 1") with no name, and the
 *    listing wore a synthetic PARTIAL_FAILURE code with that same
 *    count sentence for a message;
 *  - the group paths never enumerated an ERP variation with no variant
 *    row at all, silently pushed qty 0 onto Lazada variants the
 *    catalogue no longer had, and defeated the resolver's own null
 *    price by substituting the base price one line later;
 *  - every flash arrived green, "0 success, 12 error" included;
 *  - stock/price failures never reached the group pivot.
 *
 * Now: the plan is diffed against the catalogue both ways and every gap
 * is named; stale sku_ids heal with ONE item read (the cache staleness
 * diff finally counts combinations); a refusal naming a sku heals once
 * and retries once; per-SKU verdicts roll up to an honest listing
 * message; and the flash carries the shared ledger clause in the shared
 * tone.
 */
class LazadaStockPricePush
{
    public function __construct(
        private readonly LazadaClient $client,
        private readonly LazadaStockPushService $resolver,
        private readonly LazadaItemCache $itemCache,
        private readonly LazadaPushPayload $payload,
    ) {
    }

    private const SKU_ERROR = '/E0207|sku.*(?:not|doesn\'?t).*exist|invalid.*sku|SellerSku.*not.*found/i';

    /**
     * @param 'stock'|'price' $kind
     * @param iterable $listings LazadaProduct rows
     * @param ?callable $priceFor fn(int $productId, float $base): float - required for price
     * @return array{
     *   ok: int, err: int, skipped: int, last_error: string,
     *   outcomes: array<int, array{ok: bool, error: ?string}>,
     *   mismatched: list<array{product_id: int, sku: string, reason: string}>,
     *   skipped_variations: list<array{product_id: int, sku: string, reason: string}>,
     *   healed_listings: list<int>,
     * }
     */
    public function push(string $kind, object $setting, array $creds, $listings, string $pfx, ?callable $priceFor = null): array
    {
        $listings = collect($listings)->values();
        $out = [
            'ok' => 0, 'err' => 0, 'skipped' => 0, 'last_error' => '',
            'outcomes' => [], 'mismatched' => [], 'skipped_variations' => [], 'healed_listings' => [],
        ];
        if ($listings->isEmpty()) {
            return $out;
        }

        $productIds = $listings->pluck('product_id')->map(fn ($v) => (int) $v)->unique()->values()->all();
        $erpSkus = PushLedger::erpVariationSkus($productIds, $pfx);
        $products = DB::table($pfx . 'product')
            ->whereIn('product_id', $productIds)
            ->get(['product_id', 'sku', 'price', 'quantity', 'status'])
            ->keyBy('product_id');

        foreach ($listings as $listing) {
            $pid = (int) $listing->product_id;
            $product = $products->get($pid);
            if (!$product) {
                $out['err']++;
                $out['outcomes'][$pid] = ['ok' => false, 'error' => 'ERP product not found'];
                continue;
            }
            if ((int) $product->status === 0) {
                $out['skipped']++;
                continue;
            }
            if (empty($listing->lazada_item_id)) {
                $out['err']++;
                $out['last_error'] = 'no Lazada item id - Sync Lazada ID first';
                $out['outcomes'][$pid] = ['ok' => false, 'error' => $out['last_error']];
                continue;
            }

            /* Pre-call heal: resolveSkuIds' staleness diff (now counting
             * combinations) refetches the variants when the catalogue
             * grew a SKU the cache never saw. */
            $this->itemCache->resolveSkuIds($listing, $setting, $this->client, 'lazada.product.item.get.variants');
            $listing->load('variants');

            $this->pushListing($kind, $setting, $creds, $listing, $product, $pfx, $priceFor, $erpSkus, $out, true);
        }

        // Each product's verdict becomes its error line on this store.
        $states = LazadaListingStates::on($setting);
        $label = $kind === 'price' ? 'Price push: ' : 'Stock push: ';
        foreach ($out['outcomes'] as $pid => $outcome) {
            $states->recordOutcome((int) $pid, $outcome['ok'] ? null : $label . (string) $outcome['error']);
        }

        return $out;
    }

    private function pushListing(string $kind, object $setting, array $creds, LazadaProduct $listing, object $product, string $pfx, ?callable $priceFor, array $erpSkus, array &$out, bool $mayHeal): void
    {
        $pid = (int) $listing->product_id;
        $rows = $this->resolver->buildPushItems($listing, $product, $pfx);

        /* Only what this store sells is sent: a variant the catalogue does
         * not have is left alone, a variation switched off never reached
         * $rows, and a catalogue variation Lazada does not carry is simply
         * not asked of it. None of these is an error. */
        $sendRows = !empty($erpSkus[$pid])
            ? array_values(array_filter($rows, fn ($row) => $row['matched'] ?? true))
            : $rows;

        if (empty($rows) && $listing->variants->whereNotNull('sku_id')->isEmpty()) {
            $reason = 'could not resolve Lazada SkuIds for this product';
            $out['err']++;
            $out['last_error'] = $reason;
            $out['outcomes'][$pid] = ['ok' => false, 'error' => $reason];
            $this->stamp($listing, $kind, false, 'NO_SKUS', $reason);

            return;
        }
        if (empty($sendRows)) {
            $out['skipped']++;

            return;
        }

        $apiPath = '/product/price_quantity/update';
        $okCount = 0;
        $failures = [];

        foreach ($sendRows as $row) {
            if ($kind === 'price') {
                $base = $row['price'] !== null ? (float) $row['price'] : (float) ($product->price ?? 0);
                $final = $priceFor !== null ? $priceFor($pid, $base) : $base;
                if ($final === null) {
                    $out['skipped_variations'][] = [
                        'product_id' => $pid, 'sku' => (string) $row['seller_sku'],
                        'reason' => 'no price rule',
                    ];
                    continue;
                }
                $xml = $this->payload->buildPriceUpdateXml((int) $row['sku_id'], number_format((float) $final, 2, '.', ''));
            } else {
                $xml = $this->payload->buildQuantityUpdateXml((int) $row['sku_id'], (int) $row['quantity']);
            }

            $timestamp = (string) round(microtime(true) * 1000);
            $params = [
                'app_key' => (string) $creds['app_key'],
                'sign_method' => 'sha256',
                'timestamp' => $timestamp,
                'access_token' => (string) $creds['access_token'],
                'payload' => $xml,
            ];
            $params['sign'] = $this->client->sign($apiPath, $params, (string) $creds['app_secret']);
            $result = $this->client->post((string) $setting->region, $apiPath, $params);

            LazadaApiLog::safeCreate([
                'pack' => $kind === 'price' ? 'lazada.product.price.update' : 'lazada.product.quantity.update',
                'method' => 'POST', 'api_path' => $apiPath,
                'auth_required' => true, 'request_params' => $params,
                'response_status' => (int) ($result['status'] ?? 0), 'ok' => (bool) ($result['ok'] ?? false),
                'response_body' => $result['body'] ?? $result, 'user_id' => auth()->id(),
            ]);

            $e = $this->payload->extractLazadaError($result);
            if ($e['ok']) {
                $okCount++;
                continue;
            }

            $reason = (string) ($e['message'] ?? $e['code'] ?? 'refused');

            /* The self-heal: a refusal naming the sku means the cached
             * sku_id disagrees with Lazada. One item read rewires the
             * variants; this listing's plan rebuilds; ONE retry. */
            if ($mayHeal && preg_match(self::SKU_ERROR, $reason . ' ' . (string) ($e['code'] ?? ''))) {
                $this->itemCache->fetchAndCacheVariants($listing, $setting, $this->client, 'lazada.product.item.get.variants');
                $listing->load('variants');
                $out['healed_listings'][] = (int) $listing->id;
                $this->pushListing($kind, $setting, $creds, $listing, $product, $pfx, $priceFor, $erpSkus, $out, false);

                return;
            }

            $failures[] = ((string) $row['seller_sku'] ?: ('sku_id ' . $row['sku_id'])) . ': ' . $reason;
        }

        $errCount = count($failures);
        if ($errCount === 0) {
            $out['ok']++;
            if (!array_key_exists($pid, $out['outcomes'])) {
                $out['outcomes'][$pid] = ['ok' => true, 'error' => null];
            }
            $this->stamp($listing, $kind, true, null, null);
        } else {
            $out['err']++;
            $message = $okCount . ' of ' . ($okCount + $errCount) . ' SKUs updated; ' . implode('; ', array_slice($failures, 0, 3));
            $out['last_error'] = $message;
            $out['outcomes'][$pid] = ['ok' => false, 'error' => Str::limit($message, 255, '')];
            $this->stamp($listing, $kind, false, 'SKU_FAILURES', $message);
        }
    }

    /** The listing wears an honest verdict - never a synthetic count code. */
    private function stamp(LazadaProduct $listing, string $kind, bool $ok, ?string $code, ?string $message): void
    {
        $listing->forceFill([
            'last_synced_at' => now(),
            'last_sync_action' => $kind === 'price' ? 'sync_price' : 'sync_quantity',
            'last_sync_ok' => $ok,
            'last_sync_error_code' => $ok ? null : Str::limit((string) $code, 80, ''),
            'last_sync_error_message' => $ok ? null : Str::limit((string) $message, 255, ''),
        ])->save();
    }

    /** The shared flash suffix, in Lazada's name. */
    public static function ledgerClause(array $results): string
    {
        return PushLedger::clause($results, 'Lazada');
    }
}
