<?php

namespace Extensions\shopee\Services\Shopee;

use Extensions\shopee\Models\ShopeeApiLog;
use Extensions\shopee\Models\ShopeeProductLink;
use Extensions\shopee\Services\ShopeeStockPushService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The one sender for update_stock and update_price (Phase E, #184, #190).
 * Four private copies of this loop existed across two controllers and a
 * cron, and every one of them could lie three different ways:
 *
 *  - an ERP variation whose SKU matched no link row was silently dropped
 *    (the deleted-1pc incident: the push "succeeded" and never said what
 *    it left out);
 *  - a null-model link on a model-level item was sent as model_id 0,
 *    sinking the WHOLE call with "model_id is mandatory" - and nothing
 *    re-read the models, so the only way out was unlink + rematch;
 *  - Shopee's per-model failure_list was never parsed, so an HTTP-200
 *    answer with failed models counted them as successes, stamped
 *    last_sync_ok = true on their rows and flashed green.
 *
 * Now: the plan is built from the LINK ROWS (what Shopee actually has),
 * every ERP variation without a link is reported by SKU, a link that
 * would provoke the model error triggers the same repair Sync ID runs
 * (one get_model_list, rewire, prune) BEFORE the call, a call that still
 * comes back with a model-level refusal repairs and retries ONCE, and
 * per-model verdicts land on each link row from its own failure_list
 * entry. Nothing is retried silently and nothing is skipped silently.
 */
class ShopeeStockPricePush
{
    public function __construct(
        private readonly ShopeeClient $client,
        private readonly ShopeeStockPushService $resolver,
        private readonly ShopeeModelLinkRepair $repair,
    ) {
    }

    private const MODEL_ERROR = '/model_id is mandatory|model level|model_id.*(?:not|doesn\'?t).*exist|invalid model/i';

    /**
     * @param 'stock'|'price' $kind
     * @param iterable $links ShopeeProductLink rows
     * @param ?callable $priceFor fn(int $productId, float $resolvedPrice): ?float
     *        - required for price; null return skips the product BY NAME
     *          (the no-listing-price-rule case, #96).
     * @return array{
     *   ok: int, err: int, last_error: string,
     *   outcomes: array<int, array{ok: bool, error: ?string}>,
     *   skipped_variations: list<array{product_id: int, sku: string, reason: string}>,
     *   skipped_products: list<string>,
     *   healed_items: list<int>,
     * }
     */
    public function push(string $kind, array $auth, $links, string $pfx, ?callable $priceFor = null): array
    {
        $links = collect($links)->values();
        $out = [
            'ok' => 0, 'err' => 0, 'last_error' => '',
            'outcomes' => [], 'skipped_variations' => [], 'skipped_products' => [],
            'healed_items' => [],
        ];
        if ($links->isEmpty()) {
            return $out;
        }

        $productIds = $links->pluck('product_id')->map(fn ($v) => (int) $v)->unique()->values()->all();

        /* The skip ledger's first half: ERP variations whose SKU matches no
         * link row. They were silently dropped before; now they are named. */
        $erpVariationSkus = $this->erpVariationSkus($productIds, $pfx);
        $linkSkusByProduct = $links->groupBy(fn ($l) => (int) $l->product_id)
            ->map(fn ($rows) => $rows->pluck('sku')->map(fn ($s) => strtolower(trim((string) $s)))->filter()->flip());
        foreach ($erpVariationSkus as $pid => $skus) {
            foreach ($skus as $sku) {
                if (!($linkSkusByProduct->get($pid)?->has(strtolower($sku)))) {
                    $out['skipped_variations'][] = [
                        'product_id' => $pid, 'sku' => $sku,
                        'reason' => 'not on Shopee',
                    ];
                }
            }
        }

        /* Pre-call heal: a null-model link on an item the cache knows is
         * model-level WILL be refused - and a null-model link on a product
         * that sells in variations is the stale-parent-link smell (a
         * variation added in Seller Centre flipped the item to model
         * level). Run the repair now, once per item, and work from the
         * fresh rows; if Shopee says the item really is item-level, the
         * repair leaves an honest parent link and nothing else changes. */
        $modelLevelItems = $this->modelLevelItems($links);
        $itemsToHeal = $links
            ->filter(fn ($l) => empty($l->shopee_model_id)
                && (isset($modelLevelItems[(int) $l->shopee_item_id]) || !empty($erpVariationSkus[(int) $l->product_id])))
            ->map(fn ($l) => [(int) $l->product_id, (int) $l->shopee_item_id])
            ->unique(fn ($pair) => $pair[0] . ':' . $pair[1]);
        foreach ($itemsToHeal as [$pid, $itemId]) {
            $r = $this->repair->forItem($auth, $pid, $itemId, $this->skusFor($pid, $pfx, $erpVariationSkus));
            if (!isset($r['error'])) {
                $out['healed_items'][] = $itemId;
            }
        }
        if ($itemsToHeal->isNotEmpty()) {
            $links = ShopeeProductLink::query()->whereIn('product_id', $productIds)->get();
        }

        $rows = $this->resolver->resolve($links, $pfx);

        /* Orphan MODEL links: the product HAS ERP variations, but this
         * model's SKU matches none - pushing the BASE quantity or price to
         * that model would be wrong for a product sold in variations.
         * Skipped by name; the repair (or the catalogue) is the fix. An
         * item-level link still sends the base values - one listing for
         * the whole product is a legitimate shape. */
        $grouped = [];
        foreach ($rows as $row) {
            $pid = (int) $row['link']->product_id;
            if (!$row['matched'] && !empty($erpVariationSkus[$pid]) && (int) $row['model_id'] !== 0) {
                $out['skipped_variations'][] = [
                    'product_id' => $pid, 'sku' => (string) $row['seller_sku'],
                    'reason' => 'on Shopee but not in the catalogue',
                ];
                continue;
            }
            $grouped[(int) $row['item_id']][] = $row;
        }

        foreach ($grouped as $itemId => $itemRows) {
            $this->pushItem($kind, $auth, (int) $itemId, $itemRows, $pfx, $priceFor, $erpVariationSkus, $out, true);
        }

        return $out;
    }

    /** One item's call, with a single repair-and-retry on a model-level refusal. */
    private function pushItem(string $kind, array $auth, int $itemId, array $rows, string $pfx, ?callable $priceFor, array $erpVariationSkus, array &$out, bool $mayHeal): void
    {
        $list = [];
        $sentRows = [];
        foreach ($rows as $row) {
            if ($kind === 'price') {
                $price = $priceFor((int) $row['link']->product_id, (float) $row['price']);
                if ($price === null) {
                    $pid = (int) $row['link']->product_id;
                    $out['skipped_products'][$pid] = '#' . $pid . ': no listing price rule - sync from its product group, or push once from the form';
                    continue;
                }
                $list[] = ['model_id' => (int) $row['model_id'], 'original_price' => round((float) $price, 2)];
            } else {
                $list[] = ['model_id' => (int) $row['model_id'], 'seller_stock' => [['stock' => (int) $row['quantity']]]];
            }
            $sentRows[] = $row;
        }
        if (empty($list)) {
            return;
        }

        $path = $kind === 'price' ? '/api/v2/product/update_price' : '/api/v2/product/update_stock';
        $key = $kind === 'price' ? 'price_list' : 'stock_list';
        $payload = ['item_id' => $itemId, $key => $list];

        $result = $this->client->shopPost(
            (string) $auth['mode'], (int) $auth['partner_id'], (string) $auth['partner_key'],
            (string) $auth['access_token'], (int) $auth['shop_id'], $path, [], $payload
        );

        ShopeeApiLog::safeCreate([
            'pack' => $kind === 'price' ? 'shopee.products.update_price' : 'shopee.products.update_stock',
            'method' => 'POST', 'api_path' => $path,
            'auth_required' => true, 'request_params' => $payload,
            'response_status' => $result['status'] ?? null, 'ok' => (bool) ($result['ok'] ?? false),
            'response_body' => $result['body'] ?? null, 'user_id' => auth()->id(),
        ]);

        $body = $result['body'] ?? [];
        $topError = (string) ($body['error'] ?? '');
        $message = (string) ($body['message'] ?? '');
        $callOk = ($result['ok'] ?? false) && ($topError === '' || ($body['error'] ?? null) === null);

        /* The self-heal: a model-level refusal means the link rows disagree
         * with Shopee's shape. Run the same repair Sync ID runs, rebuild
         * this item's rows from the fresh links, retry ONCE - and say so. */
        if (!$callOk && $mayHeal && preg_match(self::MODEL_ERROR, $topError . ' ' . $message)) {
            $pids = collect($rows)->map(fn ($r) => (int) $r['link']->product_id)->unique();
            $healedAny = false;
            foreach ($pids as $pid) {
                $r = $this->repair->forItem($auth, $pid, $itemId, $this->skusFor($pid, $pfx, $erpVariationSkus));
                if (!isset($r['error'])) {
                    $healedAny = true;
                }
            }
            if ($healedAny) {
                $out['healed_items'][] = $itemId;
                $fresh = ShopeeProductLink::query()
                    ->whereIn('product_id', $pids->all())
                    ->where('shopee_item_id', $itemId)
                    ->get();
                $freshRows = $this->resolver->resolve($fresh, $pfx);
                // Variations the repair pruned join the skip ledger.
                $freshSkus = collect($freshRows)->map(fn ($r) => strtolower((string) $r['seller_sku']))->flip();
                foreach ($rows as $row) {
                    if (!$freshSkus->has(strtolower((string) $row['seller_sku']))) {
                        $out['skipped_variations'][] = [
                            'product_id' => (int) $row['link']->product_id,
                            'sku' => (string) $row['seller_sku'],
                            'reason' => 'removed from Shopee - its link was cleared',
                        ];
                    }
                }
                if (!empty($freshRows)) {
                    $this->pushItem($kind, $auth, $itemId, $freshRows, $pfx, $priceFor, $erpVariationSkus, $out, false);
                }
                return;
            }
        }

        /* Per-model verdicts (the failure_list Shopee has been sending all
         * along): each link row wears its OWN model's outcome, not the
         * item's. The product still fails if any of its models failed. */
        $failures = [];
        foreach ((array) data_get($body, 'response.failure_list', []) as $f) {
            $failures[(int) ($f['model_id'] ?? 0)] = (string) ($f['failed_reason'] ?? $f['message'] ?? 'refused');
        }

        $action = $kind === 'price' ? 'sync_price' : 'sync_qty';
        foreach ($sentRows as $row) {
            $link = $row['link'];
            $pid = (int) $link->product_id;
            $modelFailure = $failures[(int) $row['model_id']] ?? null;
            $rowOk = $callOk && $modelFailure === null;
            $reason = $rowOk ? null : ($modelFailure ?? ($message !== '' ? $message : ($topError !== '' ? $topError : 'Unknown error')));

            $link->update([
                'last_synced_at' => now(),
                'last_sync_action' => $action,
                'last_sync_ok' => $rowOk,
                'last_sync_error_code' => $rowOk ? null : Str::limit($topError !== '' ? $topError : 'model_failed', 80, ''),
                'last_sync_error_message' => $rowOk ? null : Str::limit((string) $reason, 255, ''),
            ]);

            if ($rowOk) {
                $out['ok']++;
                if (!array_key_exists($pid, $out['outcomes'])) {
                    $out['outcomes'][$pid] = ['ok' => true, 'error' => null];
                }
            } else {
                $out['err']++;
                $out['last_error'] = (string) $reason;
                $out['outcomes'][$pid] = ['ok' => false, 'error' => (string) $reason];
            }
        }
    }

    /**
     * The honest suffix for a flash message: what was healed and what was
     * skipped, by name. One builder, so no two pages phrase it apart.
     */
    public static function ledgerClause(array $results): string
    {
        $parts = [];
        if (!empty($results['healed_items'])) {
            $n = count(array_unique($results['healed_items']));
            $parts[] = $n . ' item' . ($n === 1 ? '' : 's') . ' re-read from Shopee and repaired mid-push';
        }
        if (!empty($results['skipped_variations'])) {
            $skips = collect($results['skipped_variations'])->unique(fn ($v) => $v['product_id'] . '|' . $v['sku']);
            $names = $skips->take(5)->map(fn ($v) => $v['sku'] . ' (' . $v['reason'] . ')')->implode(', ');
            $parts[] = $skips->count() . ' variation' . ($skips->count() === 1 ? '' : 's') . ' skipped: '
                . $names . ($skips->count() > 5 ? ', ...' : '');
        }

        return $parts ? ' ' . implode('. ', $parts) . '.' : '';
    }

    /** ERP variation SKUs per product: combinations first, then option values. */
    private function erpVariationSkus(array $productIds, string $pfx): array
    {
        $skus = [];
        foreach (DB::table('product_option_combinations')
            ->whereIn('product_id', $productIds)
            ->whereNotNull('sku')->where('sku', '!=', '')
            ->get(['product_id', 'sku']) as $r) {
            $skus[(int) $r->product_id][trim((string) $r->sku)] = true;
        }
        foreach (DB::table($pfx . 'product_option_value')
            ->whereIn('product_id', $productIds)
            ->whereNotNull('sku')->where('sku', '!=', '')
            ->get(['product_id', 'sku']) as $r) {
            $skus[(int) $r->product_id][trim((string) $r->sku)] = true;
        }

        return array_map(fn ($set) => array_keys($set), $skus);
    }

    /** The SKUs the repair matches models against, lowercased, main SKU included. */
    private function skusFor(int $productId, string $pfx, array $erpVariationSkus): array
    {
        $skus = array_map('strtolower', $erpVariationSkus[$productId] ?? []);
        $main = strtolower(trim((string) DB::table($pfx . 'product')->where('product_id', $productId)->value('sku')));
        if ($main !== '' && !in_array($main, $skus, true)) {
            $skus[] = $main;
        }

        return $skus;
    }

    /** Items known to be model-level: any model-id link, or any model row in the item cache. */
    private function modelLevelItems($links): array
    {
        $itemIds = collect($links)->pluck('shopee_item_id')->map(fn ($v) => (int) $v)->unique()->values();
        $set = [];
        foreach ($links as $l) {
            if (!empty($l->shopee_model_id)) {
                $set[(int) $l->shopee_item_id] = true;
            }
        }
        foreach (DB::table('shopee_item_cache')
            ->whereIn('shopee_item_id', $itemIds->all() ?: [0])
            ->whereNotNull('shopee_model_id')
            ->distinct()->pluck('shopee_item_id') as $id) {
            $set[(int) $id] = true;
        }

        return $set;
    }
}
