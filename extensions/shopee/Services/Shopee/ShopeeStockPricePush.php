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
 * a link that would provoke the model error triggers the same repair Sync
 * ID runs (one get_model_list, rewire, prune) BEFORE the call, a call
 * that still comes back with a model-level refusal repairs and retries
 * ONCE, and per-model verdicts land on each link row from its own
 * failure_list entry. Nothing is retried silently.
 *
 * And nothing is partial (the owner, 2026-09-07): a product whose variations
 * differ from Shopee's, in either direction, fails whole and names the
 * difference, and none of its models is sent. The old skip-by-name push
 * sent what matched, and the operator could not tell which product the
 * skipped variation belonged to.
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

    /** A per-model refusal that says the model is gone from Shopee (the seller deleted the variation there). */
    private const DEAD_MODEL = '/model.*(?:not|no|doesn\'?t|does not).*(?:exist|found)|invalid model|no such model|model.*deleted/i';

    /**
     * @param 'stock'|'price' $kind
     * @param iterable $links ShopeeProductLink rows
     * @param ?callable $priceFor fn(int $productId, float $resolvedPrice): ?float
     *        - required for price; null return skips the product BY NAME
     *          (the no-listing-price-rule case, #96).
     * @return array{
     *   ok: int, err: int, last_error: string,
     *   outcomes: array<int, array{ok: bool, error: ?string}>,
     *   mismatched: list<array{product_id: int, sku: string, reason: string}>,
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
            'outcomes' => [], 'mismatched' => [], 'skipped_variations' => [], 'skipped_products' => [],
            'healed_items' => [],
        ];
        if ($links->isEmpty()) {
            return $out;
        }

        $productIds = $links->pluck('product_id')->map(fn ($v) => (int) $v)->unique()->values()->all();

        $erpVariationSkus = $this->erpVariationSkus($productIds, $pfx);

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

        /* Only what this store sells is sent. A variation switched off, a
         * model the catalogue does not have, and a parent row on an item
         * sold in variations are left alone; a variation Shopee does not
         * carry is simply not asked of it. None of these is an error. */
        $hidden = \App\Integrations\Listings\ListingVariations::hidden('shopee', (int) ($auth['store_id'] ?? 0), $productIds);
        $grouped = [];
        foreach ($this->sendable($rows, $erpVariationSkus, $hidden) as $row) {
            $grouped[(int) $row['item_id']][] = $row;
        }

        foreach ($grouped as $itemId => $itemRows) {
            $this->pushItem($kind, $auth, (int) $itemId, $itemRows, $pfx, $priceFor, $erpVariationSkus, $hidden, $out, true);
        }

        /* A failure is already on its link rows with its own time; a
         * product that went through whole clears its error line. */
        $states = app(ShopeeListingStates::class)->forStore((int) ($auth['store_id'] ?? 0));
        foreach ($out['outcomes'] as $pid => $outcome) {
            if (! empty($outcome['ok'])) {
                $states->recordOutcome((int) $pid, null);
            }
        }

        return $out;
    }

    /** One item's call, with a single repair-and-retry on a model-level refusal. */
    private function pushItem(string $kind, array $auth, int $itemId, array $rows, string $pfx, ?callable $priceFor, array $erpVariationSkus, array $hidden, array &$out, bool $mayHeal): void
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
                // What the repair left unmatched is left alone; the rest retry once.
                $freshRows = $this->sendable($this->resolver->resolve($fresh, $pfx), $erpVariationSkus, $hidden);
                if (!empty($freshRows)) {
                    $this->pushItem($kind, $auth, $itemId, $freshRows, $pfx, $priceFor, $erpVariationSkus, $hidden, $out, false);
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

        /* A model Shopee says is gone (the seller deleted that variation in
         * the seller centre) is not a failure: its link is dropped and the
         * variation is simply no longer on Shopee. */
        $dead = [];
        foreach ($sentRows as $row) {
            $reason = $failures[(int) $row['model_id']] ?? null;
            if ($reason !== null && (int) $row['model_id'] !== 0 && preg_match(self::DEAD_MODEL, $reason)) {
                $dead[(int) $row['model_id']] = true;
                $row['link']->delete();
            }
        }

        foreach ($sentRows as $row) {
            $link = $row['link'];
            $pid = (int) $link->product_id;
            if (isset($dead[(int) $row['model_id']])) {
                continue;
            }
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
     * The rows this store sells. A variation switched off, a model the
     * catalogue does not have, and an item-level row on a product sold in
     * variations are not sent; everything else is.
     */
    private function sendable(array $rows, array $erpVariationSkus, array $hidden): array
    {
        return array_values(array_filter($rows, function ($row) use ($erpVariationSkus, $hidden) {
            $pid = (int) $row['link']->product_id;
            if (!empty($erpVariationSkus[$pid]) && ((int) $row['model_id'] === 0 || !$row['matched'])) {
                return false;
            }

            return \App\Integrations\Listings\ListingVariations::allows($hidden, $pid, (string) $row['seller_sku']);
        }));
    }

    /**
     * The honest suffix for a flash message: what was healed and what was
     * skipped, by name. One builder, so no two pages phrase it apart.
     */
    public static function ledgerClause(array $results): string
    {
        return \App\Integrations\Push\PushLedger::clause($results, 'Shopee');
    }

    /** ERP variation SKUs per product: combinations first, then option values. */
    private function erpVariationSkus(array $productIds, string $pfx): array
    {
        return \App\Integrations\Push\PushLedger::erpVariationSkus($productIds, $pfx);
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
            ->when(app()->bound('shopee.route-store'), fn ($q) => $q->where('shopee_setting_id', app('shopee.route-store')->id))
            ->whereIn('shopee_item_id', $itemIds->all() ?: [0])
            ->whereNotNull('shopee_model_id')
            ->distinct()->pluck('shopee_item_id') as $id) {
            $set[(int) $id] = true;
        }

        return $set;
    }
}
