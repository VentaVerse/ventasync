<?php

namespace Extensions\shopee\Services\Shopee;

use Extensions\shopee\Models\ShopeeApiLog;
use Extensions\shopee\Models\ShopeeListing;
use Extensions\shopee\Models\ShopeeProductLink;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ShopeeLinkCheck
{
    private array $shopeeSkuIndex = [];

    private array $shopeeItemExistence = [];

    public function runNext(ShopeeClient $client, array $auth, int $storeId): ?array
    {
        return ($ids = $this->storeProductIds($storeId)) !== []
            ? $this->run($client, $auth, \App\Integrations\Support\LinkCheckCursor::rotate('shopee:' . $storeId, $ids))
            : null;
    }

    public function storeProductIds(int $storeId): array
    {
        $listings = \Extensions\shopee\Models\ShopeeListing::query()
            ->withoutGlobalScope('shopeeStore')->where('shopee_setting_id', $storeId)
            ->pluck('product_id');

        return \Extensions\shopee\Models\ShopeeProductLink::query()
            ->withoutGlobalScope('shopeeStore')->where('shopee_setting_id', $storeId)
            ->whereNotNull('shopee_item_id')->pluck('product_id')
            ->merge($listings)
            ->map(fn ($v) => (int) $v)->filter()->unique()->sort()->values()->all();
    }

    private int $actingStore = 0;

    private function ofThisStore(\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder
    {
        return $this->actingStore > 0
            ? $query->withoutGlobalScope('shopeeStore')->where($query->qualifyColumn('shopee_setting_id'), $this->actingStore)
            : $query;
    }

    private function myGroupIds(): ?array
    {
        return $this->actingStore > 0
            ? \Extensions\shopee\Models\ShopeeProductGroup::query()->withoutGlobalScope('shopeeStore')
                ->where('shopee_setting_id', $this->actingStore)->pluck('id')->map(fn ($v) => (int) $v)->all()
            : null;
    }

    public function run(ShopeeClient $client, array $auth, array $productIds): array
    {
        $this->actingStore = (int) ($auth['store_id'] ?? 0);

        $limit = 200;
        $skippedForSize = max(0, count($productIds) - $limit);
        $productIds = array_slice($productIds, 0, $limit);

        // get_item_base_info answers 50 at a time; one call per item overruns the web request time limit.
        $knownItemIds = $this->ofThisStore(ShopeeProductLink::query())
            ->whereIn('product_id', $productIds)
            ->whereNotNull('shopee_item_id')
            ->pluck('shopee_item_id')
            ->map(fn ($v) => (int) $v)->filter()->unique()->values()->all();

        foreach (array_chunk($knownItemIds, 50) as $chunk) {
            try {
                $res = $client->shopGet(
                    $auth['mode'], (int) $auth['partner_id'], (string) $auth['partner_key'],
                    (string) $auth['access_token'], (int) $auth['shop_id'],
                    '/api/v2/product/get_item_base_info',
                    ['item_id_list' => implode(',', $chunk)]
                );
            } catch (\Throwable $e) {
                continue;
            }

            ShopeeApiLog::safeCreate([
                'pack' => 'shopee.groups.check', 'method' => 'GET',
                'api_path' => '/api/v2/product/get_item_base_info', 'auth_required' => true,
                'request_params' => ['item_id_list' => implode(',', $chunk)],
                'response_status' => $res['status'] ?? null,
                'ok' => (bool) ($res['ok'] ?? false),
                'response_body' => $res['body'] ?? null, 'user_id' => auth()->id(),
            ]);

            $body = is_array($res['body'] ?? null) ? $res['body'] : [];
            if (!($res['ok'] ?? false) || (string) ($body['error'] ?? '') !== '') {
                continue;
            }

            $answered = [];
            foreach (($body['response']['item_list'] ?? []) as $item) {
                $iid = (int) ($item['item_id'] ?? 0);
                if ($iid > 0) {
                    $this->shopeeItemExistence[(int) $auth['shop_id']][$iid]
                        = strtoupper((string) ($item['item_status'] ?? '')) === 'DELETED' ? 'no' : 'yes';
                    $answered[$iid] = true;
                }
            }
            foreach ($chunk as $iid) {
                if (!isset($answered[(int) $iid])) {
                    $this->shopeeItemExistence[(int) $auth['shop_id']][(int) $iid] = 'no';
                }
            }
        }

        $pfx = (string) config('catalog.prefix');
        $verdicts = [];
        $problems = [];

        foreach ($productIds as $productId) {
            $verdict = $this->reconcile($client, $auth, (int) $productId, $pfx);
            $state = $verdict['state'];
            $verdicts[$state] = ($verdicts[$state] ?? 0) + 1;

            if ($state === 'unreachable') {
                $problems[] = "#{$productId}: {$verdict['message']}";
                continue;
            }

            ShopeeListing::query()->updateOrCreate(
                $this->actingStore > 0
                    ? ['product_id' => (int) $productId, 'shopee_setting_id' => $this->actingStore]
                    : ['product_id' => (int) $productId],
                ['last_checked_at' => now()]
            );

            if ($state === 'taken') {
                $problems[] = "#{$productId}: {$verdict['message']}";
                $this->writeProductStatus((int) $productId, [
                    'sync_status' => 'error',
                    'push_error' => Str::limit((string) $verdict['message'], 480),
                ]);
                continue;
            }

            if ($state === 'lost' || $state === 'new') {
                $this->writeProductStatus((int) $productId, [
                    'sync_status' => 'pending',
                    'push_error' => null,
                ]);
                continue;
            }

            $this->writeProductStatus((int) $productId, [
                'sync_status' => 'pushed',
                'shopee_item_id' => $verdict['item_id'] ? (string) $verdict['item_id'] : null,
                'push_error' => null,
            ]);
        }

        $checked = count($productIds) - ($verdicts['unreachable'] ?? 0);

        $parts = [];
        if (! empty($verdicts['confirmed'])) {
            $parts[] = $verdicts['confirmed'].' still on Shopee';
        }
        if (! empty($verdicts['adopted'])) {
            $parts[] = $verdicts['adopted'].' found and linked';
        }
        if (! empty($verdicts['repointed'])) {
            $parts[] = $verdicts['repointed'].' pointed at the wrong item and corrected';
        }
        if (! empty($verdicts['lost'])) {
            $parts[] = $verdicts['lost'].' no longer on Shopee, now marked not pushed';
        }
        if (! empty($verdicts['new'])) {
            $parts[] = $verdicts['new'].' not pushed yet';
        }
        if (! empty($verdicts['taken'])) {
            $parts[] = $verdicts['taken'].' blocked by another product holding the same item';
        }
        if (! empty($verdicts['unreachable'])) {
            $parts[] = $verdicts['unreachable'].' could not be checked';
        }

        $summary = "Checked {$checked} against Shopee. ".($parts ? implode('. ', $parts).'.' : 'Nothing to report.');

        if ($skippedForSize > 0) {
            $summary .= " {$skippedForSize} further "
                .($skippedForSize === 1 ? 'product was' : 'products were')
                .' not checked this time. Run it again to continue.';
        }

        if ($problems) {
            $summary .= ' '.implode('; ', array_slice($problems, 0, 2));
            if (count($problems) > 2) {
                $summary .= ' (and '.(count($problems) - 2).' more)';
            }
        }

        $failed = count($problems);
        $tone = $failed === 0 ? 'status' : ($checked > 0 ? 'warning' : 'error');



        return ['tone' => $tone, 'summary' => $summary];
    }

    private function shopeeCandidateSkus(int $productId, string $pfx): array
    {
        $product = DB::table($pfx.'product')->where('product_id', $productId)->first(['sku', 'model']);

        if (! $product) {
            return [];
        }

        $skus = [];
        foreach ([$product->sku ?? '', $product->model ?? ''] as $candidate) {
            $candidate = strtolower(trim((string) $candidate));
            if ($candidate !== '') {
                $skus[] = $candidate;
            }
        }

        $optSkus = DB::table($pfx.'product_option_value')
            ->where('product_id', $productId)
            ->whereNotNull('sku')->where('sku', '!=', '')
            ->pluck('sku')->map(fn ($v) => strtolower(trim((string) $v)))->all();

        $comboSkus = DB::table('product_option_combinations')
            ->where('product_id', $productId)
            ->whereNotNull('sku')->where('sku', '!=', '')
            ->pluck('sku')->map(fn ($v) => strtolower(trim((string) $v)))->all();

        return array_values(array_unique(array_filter(array_merge($skus, $optSkus, $comboSkus))));
    }

    private function shopeeItemStillExists(ShopeeClient $client, array $auth, int $itemId): string
    {
        $shopId = (int) ($auth['shop_id'] ?? 0);
        if (isset($this->shopeeItemExistence[$shopId][$itemId])) {
            return $this->shopeeItemExistence[$shopId][$itemId];
        }

        try {
            $res = $client->shopGet(
                $auth['mode'], (int) $auth['partner_id'], (string) $auth['partner_key'],
                (string) $auth['access_token'], (int) $auth['shop_id'],
                '/api/v2/product/get_item_base_info',
                ['item_id_list' => (string) $itemId]
            );
        } catch (\Throwable $e) {
            return 'unknown';
        }

        $body = is_array($res['body'] ?? null) ? $res['body'] : [];
        $err = strtolower((string) ($body['error'] ?? ''));

        if ($err !== '') {
            // Shopee replies 200 with an error field; only an error naming the item as missing counts as 'no'.
            return str_contains($err, 'not_found') || str_contains($err, 'not_exist') ? 'no' : 'unknown';
        }

        if (! ($res['ok'] ?? false)) {
            return 'unknown';
        }

        $items = $body['response']['item_list'] ?? [];

        foreach ($items as $item) {
            if ((int) ($item['item_id'] ?? 0) !== $itemId) {
                continue;
            }

            return $this->shopeeItemExistence[$shopId][$itemId]
                = strtoupper((string) ($item['item_status'] ?? '')) === 'DELETED' ? 'no' : 'yes';
        }

        return $this->shopeeItemExistence[$shopId][$itemId] = 'no';
    }

    public function skuIndex(ShopeeClient $client, array $auth): array
    {
        $shopId = (int) ($auth['shop_id'] ?? 0);
        if (isset($this->shopeeSkuIndex[$shopId])) {
            return $this->shopeeSkuIndex[$shopId];
        }

        $map = [];
        $complete = true;

        foreach (['NORMAL', 'UNLIST', 'BANNED'] as $status) {
            $offset = 0;

            for ($page = 0; $page < 20; $page++) {
                try {
                    $res = $client->shopGet(
                        $auth['mode'], (int) $auth['partner_id'], (string) $auth['partner_key'],
                        (string) $auth['access_token'], (int) $auth['shop_id'],
                        '/api/v2/product/get_item_list',
                        ['offset' => $offset, 'page_size' => 50, 'item_status' => $status]
                    );
                } catch (\Throwable $e) {
                    $complete = false;
                    break;
                }

                // A failed call is not an empty shop; treating it as one makes a push create a duplicate listing.
                if (! ($res['ok'] ?? false) || (string) ($res['body']['error'] ?? '') !== '') {
                    $complete = false;
                    break;
                }

                $items = $res['body']['response']['item'] ?? [];
                if (empty($items)) {
                    break;
                }

                $itemIds = array_filter(array_map(fn ($i) => $i['item_id'] ?? null, $items));
                if (empty($itemIds)) {
                    break;
                }

                try {
                    $detail = $client->shopGet(
                        $auth['mode'], (int) $auth['partner_id'], (string) $auth['partner_key'],
                        (string) $auth['access_token'], (int) $auth['shop_id'],
                        '/api/v2/product/get_item_base_info',
                        ['item_id_list' => implode(',', $itemIds)]
                    );
                } catch (\Throwable $e) {
                    $complete = false;
                    break;
                }

                if (! ($detail['ok'] ?? false) || (string) ($detail['body']['error'] ?? '') !== '') {
                    $complete = false;
                    break;
                }

                foreach ($detail['body']['response']['item_list'] ?? [] as $item) {
                    $itemId = (int) ($item['item_id'] ?? 0);
                    if ($itemId <= 0) {
                        continue;
                    }

                    $itemSku = strtolower(trim((string) ($item['item_sku'] ?? '')));
                    if ($itemSku !== '' && ! isset($map[$itemSku])) {
                        $map[$itemSku] = $itemId;
                    }

                    if (! empty($item['has_model'])) {
                        foreach ($this->modelSkus($client, $auth, $itemId, $complete) as $modelSku) {
                            if (! isset($map[$modelSku])) {
                                $map[$modelSku] = $itemId;
                            }
                        }
                    }
                }

                if (! ($res['body']['response']['has_next_page'] ?? false)) {
                    break;
                }

                $offset += 50;

                if ($page === 19) {
                    $complete = false;
                }
            }
        }

        return $this->shopeeSkuIndex[$shopId] = ['skus' => $map, 'complete' => $complete];
    }

    // A failed model read marks the index incomplete; never conclude 'not on Shopee' from a map with holes.
    private function modelSkus(ShopeeClient $client, array $auth, int $itemId, bool &$complete): array
    {
        try {
            $res = $client->shopGet(
                $auth['mode'], (int) $auth['partner_id'], (string) $auth['partner_key'],
                (string) $auth['access_token'], (int) $auth['shop_id'],
                '/api/v2/product/get_model_list',
                ['item_id' => $itemId]
            );
        } catch (\Throwable $e) {
            $complete = false;

            return [];
        }

        $body = is_array($res['body'] ?? null) ? $res['body'] : [];
        if (! ($res['ok'] ?? false) || (string) ($body['error'] ?? '') !== '') {
            $complete = false;

            return [];
        }

        $out = [];
        foreach (($body['response']['model'] ?? []) as $model) {
            $sku = strtolower(trim((string) ($model['model_sku'] ?? '')));
            if ($sku !== '') {
                $out[] = $sku;
            }
        }

        return $out;
    }

    public function reconcile(ShopeeClient $client, array $auth, int $productId, string $pfx): array
    {
        $this->actingStore = (int) ($auth['store_id'] ?? 0);

        $link = $this->ofThisStore(ShopeeProductLink::query())
            ->where('product_id', $productId)
            ->whereNotNull('shopee_item_id')
            ->first();

        $knownId = $link ? (int) $link->shopee_item_id : 0;

        if ($knownId > 0) {
            $verdict = $this->shopeeItemStillExists($client, $auth, $knownId);

            if ($verdict === 'unknown') {
                return [
                    'state' => 'unreachable',
                    'item_id' => $knownId,
                    'message' => 'Shopee did not answer, so nothing was checked or changed.',
                ];
            }

            if ($verdict === 'yes') {
                return ['state' => 'confirmed', 'item_id' => $knownId, 'message' => null];
            }
        }

        $skus = $this->shopeeCandidateSkus($productId, $pfx);

        if (empty($skus)) {
            return [
                'state' => 'unreachable',
                'item_id' => null,
                'message' => 'This product has no SKU, so it cannot be matched against Shopee.',
            ];
        }

        $index = $this->skuIndex($client, $auth);

        $found = null;
        foreach ($skus as $sku) {
            if (isset($index['skus'][$sku])) {
                $found = (int) $index['skus'][$sku];
                break;
            }
        }

        if ($found === null) {
            if (! $index['complete']) {
                return [
                    'state' => 'unreachable',
                    'item_id' => $knownId ?: null,
                    'message' => 'The shop could not be read all the way through, so this product was left alone.',
                ];
            }

            if ($knownId > 0) {
                // Dropping a link is destructive: do it only on an answer about this shop, keyed by shop id.
                \App\Integrations\Listings\DroppedLink::write(
                    'Shopee', 'Shopee Product', $productId, $knownId,
                    (int) ($auth['shop_id'] ?? 0), $skus, count($index['skus'])
                );

                ShopeeProductLink::unlinkProduct($productId, (int) ($auth['store_id'] ?? 0));

                $this->writeProductStatus($productId, [
                    'sync_status' => 'pending',
                    'push_error' => null,
                ]);

                $gone = "Shopee item {$knownId} is no longer on Shopee.";
                $this->recordFailure($productId, $gone);

                return ['state' => 'lost', 'item_id' => null, 'message' => $gone];
            }

            return ['state' => 'new', 'item_id' => null, 'message' => null];
        }

        $otherHolder = $this->ofThisStore(ShopeeProductLink::query())
            ->where('shopee_item_id', $found)
            ->where('product_id', '!=', $productId)
            ->value('product_id');

        if ($otherHolder) {
            $held = "Shopee item {$found} is already linked to catalog product #{$otherHolder}. "
                .'Unlink that product first, then send this one again.';
            $this->recordFailure($productId, $held);

            return [
                'state' => 'taken',
                'item_id' => $found,
                'message' => $held,
            ];
        }

        ShopeeProductLink::updateOrCreate(
            ['product_id' => $productId, 'shopee_item_id' => $found, 'shopee_model_id' => null],
            ['sku' => $skus[0] ?? null]
        );

        return [
            'state' => $knownId > 0 ? 'repointed' : 'adopted',
            'item_id' => $found,
            'message' => null,
        ];
    }

    private function recordFailure(int $productId, string $message): void
    {
        app(ShopeeListingStates::class)->forStore($this->actingStore)->recordOutcome($productId, $message);
    }

    private function writeProductStatus(int $productId, array $attributes): void
    {
        \App\Support\ChannelProductStatus::write(
            'shopee_product_group_products',
            'shopee_product_group_id',
            $this->myGroupIds(),
            $productId,
            $attributes
        );
    }
}
