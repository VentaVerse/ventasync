<?php

namespace Extensions\Shopee\Services\Shopee;

use Extensions\Shopee\Models\ShopeeApiLog;
use Extensions\Shopee\Models\ShopeeListingLink;
use Extensions\Shopee\Services\ShopeeStockPushService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// A null-model link sent as model_id 0 sinks the whole call; repair the links first.
// HTTP 200 can carry failed models, so parse failure_list per model.
class ShopeeStockPricePush
{
    public function __construct(
        private readonly ShopeeClient $client,
        private readonly ShopeeStockPushService $resolver,
        private readonly ShopeeModelLinkRepair $repair,
    ) {
    }

    private const MODEL_ERROR = '/model_id is mandatory|model level|model_id.*(?:not|doesn\'?t).*exist|invalid model/i';

    private const DEAD_MODEL = '/model.*(?:not|no|doesn\'?t|does not).*(?:exist|found)|invalid model|no such model|model.*deleted/i';

    public function push(string $kind, array $auth, $links, ?callable $priceFor = null): array
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

        $erpVariationSkus = $this->erpVariationSkus($productIds);

        $modelLevelItems = $this->modelLevelItems($links);
        $itemsToHeal = $links
            ->filter(fn ($l) => empty($l->shopee_model_id)
                && (isset($modelLevelItems[(int) $l->shopee_item_id]) || !empty($erpVariationSkus[(int) $l->product_id])))
            ->map(fn ($l) => [(int) $l->product_id, (int) $l->shopee_item_id])
            ->unique(fn ($pair) => $pair[0] . ':' . $pair[1]);
        foreach ($itemsToHeal as [$pid, $itemId]) {
            $r = $this->repair->forItem($auth, $pid, $itemId, $this->skusFor($pid, $erpVariationSkus));
            if (!isset($r['error'])) {
                $out['healed_items'][] = $itemId;
            }
        }
        if ($itemsToHeal->isNotEmpty()) {
            $links = ShopeeListingLink::query()->whereIn('product_id', $productIds)->get();
        }

        $rows = $this->resolver->resolve($links);

        $hidden = \App\Channels\Listings\ListingVariations::hidden('shopee', (int) ($auth['store_id'] ?? 0), $productIds);
        $grouped = [];
        foreach ($this->sendable($rows, $erpVariationSkus, $hidden) as $row) {
            $grouped[(int) $row['item_id']][] = $row;
        }

        foreach ($grouped as $itemId => $itemRows) {
            $this->pushItem($kind, $auth, (int) $itemId, $itemRows, $priceFor, $erpVariationSkus, $hidden, $out, true);
        }

        $states = app(ShopeeListingStates::class)->forStore((int) ($auth['store_id'] ?? 0));
        foreach ($out['outcomes'] as $pid => $outcome) {
            if (! empty($outcome['ok'])) {
                $states->recordOutcome((int) $pid, null);
            }
        }

        return $out;
    }

    private function pushItem(string $kind, array $auth, int $itemId, array $rows, ?callable $priceFor, array $erpVariationSkus, array $hidden, array &$out, bool $mayHeal): void
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

        if (!$callOk && $mayHeal && preg_match(self::MODEL_ERROR, $topError . ' ' . $message)) {
            $pids = collect($rows)->map(fn ($r) => (int) $r['link']->product_id)->unique();
            $healedAny = false;
            foreach ($pids as $pid) {
                $r = $this->repair->forItem($auth, $pid, $itemId, $this->skusFor($pid, $erpVariationSkus));
                if (!isset($r['error'])) {
                    $healedAny = true;
                }
            }
            if ($healedAny) {
                $out['healed_items'][] = $itemId;
                $fresh = ShopeeListingLink::query()
                    ->whereIn('product_id', $pids->all())
                    ->where('shopee_item_id', $itemId)
                    ->get();
                $freshRows = $this->sendable($this->resolver->resolve($fresh), $erpVariationSkus, $hidden);
                if (!empty($freshRows)) {
                    $this->pushItem($kind, $auth, $itemId, $freshRows, $priceFor, $erpVariationSkus, $hidden, $out, false);
                }
                return;
            }
        }

        $failures = [];
        foreach ((array) data_get($body, 'response.failure_list', []) as $f) {
            $failures[(int) ($f['model_id'] ?? 0)] = (string) ($f['failed_reason'] ?? $f['message'] ?? 'refused');
        }

        $action = $kind === 'price' ? 'sync_price' : 'sync_qty';

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

    private function sendable(array $rows, array $erpVariationSkus, array $hidden): array
    {
        return array_values(array_filter($rows, function ($row) use ($erpVariationSkus, $hidden) {
            $pid = (int) $row['link']->product_id;
            if (!empty($erpVariationSkus[$pid]) && ((int) $row['model_id'] === 0 || !$row['matched'])) {
                return false;
            }

            return \App\Channels\Listings\ListingVariations::allows($hidden, $pid, (string) $row['seller_sku']);
        }));
    }

    public static function ledgerClause(array $results): string
    {
        return \App\Channels\Push\PushLedger::clause($results, 'Shopee');
    }

    private function erpVariationSkus(array $productIds): array
    {
        return \App\Channels\Push\PushLedger::erpVariationSkus($productIds);
    }

    private function skusFor(int $productId, array $erpVariationSkus): array
    {
        $skus = array_map('strtolower', $erpVariationSkus[$productId] ?? []);
        $main = strtolower(trim((string) DB::table('products')->where('product_id', $productId)->value('sku')));
        if ($main !== '' && !in_array($main, $skus, true)) {
            $skus[] = $main;
        }

        return $skus;
    }

    private function modelLevelItems($links): array
    {
        $itemIds = collect($links)->pluck('shopee_item_id')->map(fn ($v) => (int) $v)->unique()->values();
        $set = [];
        foreach ($links as $l) {
            if (!empty($l->shopee_model_id)) {
                $set[(int) $l->shopee_item_id] = true;
            }
        }
        foreach (DB::table('ch_shopee_item_cache')
            ->when(app()->bound('shopee.route-store'), fn ($q) => $q->where('store_id', app('shopee.route-store')->id))
            ->whereIn('shopee_item_id', $itemIds->all() ?: [0])
            ->whereNotNull('shopee_model_id')
            ->distinct()->pluck('shopee_item_id') as $id) {
            $set[(int) $id] = true;
        }

        return $set;
    }
}
