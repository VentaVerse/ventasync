<?php

namespace Extensions\shopee\Services\Shopee;

use App\Integrations\Listings\ListingVariations;
use App\Integrations\Push\PushLedger;
use Extensions\shopee\Models\ShopeeApiLog;
use Extensions\shopee\Models\ShopeeItemCache;
use Extensions\shopee\Models\ShopeeProductLink;
use Illuminate\Support\Facades\DB;

final class ShopeeVariationSwitches
{
    public function __construct(private readonly ShopeeVariationPush $variations)
    {
    }

    public function readFor(ShopeeClient $client, array $auth, int $itemId, int $productId): ?array
    {
        if (! DB::table('product_option_combinations')->where('product_id', $productId)->exists()) {
            return null;
        }

        return $this->variations->readModels($client, $auth, $itemId);
    }

    public static function renamed(?array $read, array $plan, array $applied): ?array
    {
        $payload = $plan['payload'] ?? null;
        if ($read === null || ! ($read['ok'] ?? false) || ! ($applied['ok'] ?? false) || ! is_array($payload)) {
            return $read;
        }

        $resp = $read['response'];
        if (isset($payload['tier_variation'])) {
            $resp['tier_variation'] = $payload['tier_variation'];
        }
        if (isset($payload['standardise_tier_variation'])) {
            $resp['standardise_tier_variation'] = $payload['standardise_tier_variation'];
            $legacy = array_values((array) ($resp['tier_variation'] ?? []));
            foreach (array_values((array) $payload['standardise_tier_variation']) as $t => $entry) {
                if (! isset($legacy[$t])) {
                    continue;
                }
                $legacy[$t]['name'] = (string) ($entry['variation_name'] ?? ($legacy[$t]['name'] ?? ''));
                foreach (array_values((array) ($entry['variation_option_list'] ?? [])) as $i => $option) {
                    if (isset($legacy[$t]['option_list'][$i])) {
                        $legacy[$t]['option_list'][$i]['option'] = (string) ($option['variation_option_name'] ?? '');
                    }
                }
            }
            $resp['tier_variation'] = $legacy;
        }
        $read['response'] = $resp;

        return $read;
    }

    public function follow(ShopeeClient $client, array $auth, int $itemId, int $productId, ?array $read, \Closure $priceFor, ?\Closure $uploadImageId = null): array
    {
        $result = ['removed' => [], 'added' => [], 'failure' => null];
        if ($read === null) {
            return $result;
        }

        $storeId = $this->storeId($auth);
        $hidden = ListingVariations::hidden('shopee', $storeId, [$productId])[$productId] ?? [];

        if (! ($read['ok'] ?? false)) {
            if ($this->mayHoldAny($storeId, $productId, $hidden)) {
                $result['failure'] = 'Switched-off variations were not checked on Shopee: ' . $this->bare((string) ($read['reason'] ?? '')) . '.';
            }

            return $result;
        }

        if (ListingVariations::noneSold('shopee', $storeId, $productId)) {
            $result['failure'] = ListingVariations::noneSoldMessage('Shopee');

            return $result;
        }

        $models = array_values(array_filter(
            (array) ($read['response']['model'] ?? []),
            fn ($m) => is_array($m) && (int) ($m['model_id'] ?? 0) > 0
        ));
        if ($models === []) {
            return $result;
        }

        $labels = $this->labels($productId);
        $off = [];
        $kept = [];
        foreach ($models as $m) {
            if (isset($hidden[$this->key($m['model_sku'] ?? '')])) {
                $off[] = $m;
            } else {
                $kept[] = $m;
            }
        }
        $last = ($kept === [] && $off !== []) ? array_pop($off) : null;

        $failures = [];
        $gone = [];
        foreach ($off as $m) {
            $refusal = $this->deleteModel($client, $auth, $itemId, (int) $m['model_id']);
            if ($refusal === null) {
                $gone[] = (int) $m['model_id'];
                $result['removed'][] = $this->label($labels, $m);
            } else {
                $failures[] = 'Not removed from Shopee: ' . $this->label($labels, $m) . ' (' . $this->bare($refusal) . ').';
            }
        }

        $catalog = [];
        foreach (PushLedger::erpVariationSkus([$productId], (string) config('catalog.prefix'))[$productId] ?? [] as $sku) {
            $catalog[$this->key($sku)] = true;
        }
        $held = [];
        $paired = false;
        foreach ($models as $m) {
            $k = $this->key($m['model_sku'] ?? '');
            $paired = $paired || isset($catalog[$k]);
            if ($k !== '' && ! in_array((int) $m['model_id'], $gone, true)) {
                $held[$k] = true;
            }
        }
        $missing = array_values(array_filter(
            ListingVariations::sold('shopee', $storeId, [$productId])[$productId] ?? [],
            fn ($sku) => $this->key($sku) !== '' && ! isset($held[$this->key($sku)])
        ));

        if ($missing !== [] && $paired) {
            $after = $read;
            $after['response']['model'] = array_values(array_filter($models, fn ($m) => ! in_array((int) $m['model_id'], $gone, true)));
            $names = array_map(fn ($sku) => $labels[$this->key($sku)] ?? trim((string) $sku), $missing);

            $added = $this->variations->pushMissingModels($client, $auth, $itemId, $productId, $priceFor, $uploadImageId, $after);
            if (($added['ok'] ?? false) && (int) ($added['added'] ?? 0) > 0) {
                $result['added'] = $names;
            } elseif (! ($added['ok'] ?? false)) {
                $failures[] = 'Not added to Shopee: ' . implode(', ', $names) . ' (' . $this->bare((string) ($added['message'] ?? '')) . ').';
            }
        }

        if ($last !== null) {
            if ($result['added'] === []) {
                $failures[] = 'Not removed from Shopee: ' . $this->label($labels, $last)
                    . ' (a Shopee item keeps at least one variation, and none sold here could be added in its place).';
            } elseif (($refusal = $this->deleteModel($client, $auth, $itemId, (int) $last['model_id'])) === null) {
                $gone[] = (int) $last['model_id'];
                $result['removed'][] = $this->label($labels, $last);
            } else {
                $failures[] = 'Not removed from Shopee: ' . $this->label($labels, $last) . ' (' . $this->bare($refusal) . ').';
            }
        }

        if ($gone !== [] || $result['added'] !== []) {
            $this->remember($auth, $productId, $itemId, $models, $gone, $result['added'] !== [] ? $missing : []);
        }

        $result['failure'] = $failures === [] ? null : implode(' ', $failures);

        return $result;
    }

    public static function note(array $result): string
    {
        $parts = [];
        if (($result['removed'] ?? []) !== []) {
            $parts[] = 'Removed from Shopee: ' . implode(', ', $result['removed']) . '.';
        }
        if (($result['added'] ?? []) !== []) {
            $parts[] = 'Added to Shopee: ' . implode(', ', $result['added']) . '.';
        }
        if (($result['failure'] ?? null) !== null) {
            $parts[] = $result['failure'];
        }

        return implode(' ', $parts);
    }

    private function deleteModel(ShopeeClient $client, array $auth, int $itemId, int $modelId): ?string
    {
        $path = '/api/v2/product/delete_model';
        $payload = ['item_id' => $itemId, 'model_id' => $modelId];
        try {
            $res = $client->shopPost(
                $auth['mode'], (int) $auth['partner_id'], (string) $auth['partner_key'],
                (string) $auth['access_token'], (int) $auth['shop_id'],
                $path, [], $payload
            );
        } catch (\Throwable $e) {
            return \App\Support\TransportError::plain($e, 'Shopee');
        }

        $body = is_array($res['body'] ?? null) ? $res['body'] : [];
        $refused = ! ($res['ok'] ?? false) || trim((string) ($body['error'] ?? '')) !== '';

        ShopeeApiLog::safeCreate([
            'pack' => 'shopee.products.delete_model.switched_off', 'method' => 'POST',
            'api_path' => $path, 'auth_required' => true,
            'request_params' => $payload,
            'response_status' => $res['status'] ?? null, 'ok' => ! $refused,
            'response_body' => $res['body'] ?? null, 'user_id' => auth()->id(),
        ]);

        return $refused
            ? \App\Support\MarketplaceAnswer::plain('Shopee', ['ok' => false, 'status' => $res['status'] ?? 200, 'body' => $body])
            : null;
    }

    private function remember(array $auth, int $productId, int $itemId, array $models, array $gone, array $addedSkus): void
    {
        if ($gone !== []) {
            ShopeeProductLink::query()->where('product_id', $productId)->where('shopee_item_id', $itemId)
                ->whereIn('shopee_model_id', $gone)->delete();
            ShopeeItemCache::query()->where('shopee_item_id', $itemId)->whereIn('shopee_model_id', $gone)->delete();
        }

        $held = [];
        foreach ($models as $m) {
            if (! in_array((int) $m['model_id'], $gone, true)) {
                $held[] = (string) ($m['model_sku'] ?? '');
            }
        }
        ShopeeListingStates::rememberHeld($this->storeId($auth), $productId, array_merge($held, $addedSkus));

        if ($addedSkus !== []) {
            $pfx = (string) config('catalog.prefix');
            $skus = DB::table($pfx . 'product_option_value')
                ->where('product_id', $productId)->whereNotNull('sku')->where('sku', '!=', '')
                ->pluck('sku')->map(fn ($v) => strtolower(trim((string) $v)))
                ->merge(DB::table('product_option_combinations')
                    ->where('product_id', $productId)->whereNotNull('sku')->where('sku', '!=', '')
                    ->pluck('sku')->map(fn ($v) => strtolower(trim((string) $v))))
                ->unique()->values()->all();
            app(ShopeeModelLinkRepair::class)->forItem($auth, $productId, $itemId, $skus);
        }
    }

    private function mayHoldAny(int $storeId, int $productId, array $hidden): bool
    {
        if ($hidden === []) {
            return false;
        }
        $last = DB::table(ListingVariations::STORE_SKUS)
            ->where('channel', 'shopee')->where('store_id', $storeId)->where('product_id', $productId)
            ->value('skus');
        if ($last === null) {
            return true;
        }
        foreach ((array) json_decode((string) $last, true) as $sku) {
            if (isset($hidden[$this->key($sku)])) {
                return true;
            }
        }

        return false;
    }

    private function labels(int $productId): array
    {
        $out = [];
        foreach (\App\Support\VariationRows::forProducts([$productId])->get($productId) ?? [] as $row) {
            $sku = trim((string) ($row->sku ?? ''));
            if ($sku === '') {
                continue;
            }
            $name = trim((string) ($row->option_value_name ?? ''));
            $out[$this->key($sku)] = $name !== '' ? $name . ' (' . $sku . ')' : $sku;
        }

        return $out;
    }

    private function label(array $labels, array $model): string
    {
        $sku = trim((string) ($model['model_sku'] ?? ''));

        return $labels[$this->key($sku)] ?? ($sku !== '' ? $sku : 'model ' . (int) ($model['model_id'] ?? 0));
    }

    private function storeId(array $auth): int
    {
        return (int) ($auth['store_id'] ?? 0) ?: (int) (\Extensions\shopee\Models\ShopeeSetting::defaultStore()?->id ?? 0);
    }

    private function key(mixed $sku): string
    {
        return strtolower(trim((string) $sku));
    }

    private function bare(string $text): string
    {
        return rtrim(trim($text), '.');
    }
}
