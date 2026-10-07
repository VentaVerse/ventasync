<?php

namespace Extensions\shopee\Services\Shopee;

use Extensions\shopee\Models\ShopeeApiLog;
use Illuminate\Support\Facades\DB;

class ShopeeVariationRename
{
    private const TYPE_NAME_LIMIT = 26;

    private const VALUE_NAME_LIMIT = 50;

    public function plan(ShopeeClient $client, array $auth, int $itemId, int $productId, ?array $read = null): array
    {
        $catalog = $this->catalog($productId);
        if ($catalog === null) {
            return $this->nothing();
        }

        $read ??= app(ShopeeVariationPush::class)->readModels($client, $auth, $itemId);
        if (!($read['ok'] ?? false)) {
            return $this->refuse('Shopee did not say what variations the item has (' . $this->bare((string) ($read['reason'] ?? '')) . ')');
        }
        $resp = (array) ($read['response'] ?? []);

        $legacy = array_values((array) ($resp['tier_variation'] ?? []));
        $standardise = array_values((array) ($resp['standardise_tier_variation'] ?? []));
        $models = array_values((array) ($resp['model'] ?? []));

        if ($legacy === [] && $standardise === []) {
            return $this->nothing();
        }
        if ($legacy !== [] && $standardise !== [] && count($legacy) !== count($standardise)) {
            return $this->refuse('Shopee described the item\'s variation types in two ways that do not agree');
        }

        $shape = $standardise !== [] ? 'standardise' : 'legacy';
        $tiers = [];
        foreach ($shape === 'standardise' ? $standardise : $legacy as $entry) {
            $tiers[] = $shape === 'standardise'
                ? [
                    'name' => (string) ($entry['variation_name'] ?? ''),
                    'values' => array_map(fn ($o) => (string) ($o['variation_option_name'] ?? ''), array_values((array) ($entry['variation_option_list'] ?? []))),
                    'standard' => (int) ($entry['variation_id'] ?? 0) > 0,
                ]
                : [
                    'name' => (string) ($entry['name'] ?? ''),
                    'values' => array_map(fn ($o) => (string) ($o['option'] ?? ''), array_values((array) ($entry['option_list'] ?? []))),
                    'standard' => false,
                ];
        }

        $axisIds = array_keys($catalog['axes']);
        if (count($axisIds) !== count($tiers)) {
            return $this->refuse('the catalog has ' . count($axisIds) . ' variation ' . (count($axisIds) === 1 ? 'type' : 'types')
                . ' and the Shopee item has ' . count($tiers));
        }
        if (!in_array(false, array_column($tiers, 'standard'), true)) {
            return $this->nothing();
        }

        $pairing = $this->pair($tiers, $axisIds, $catalog, $models);
        if (isset($pairing['refused'])) {
            return $this->refuse($pairing['refused']);
        }

        $renamed = [];
        foreach ($tiers as $t => $tier) {
            if ($tier['standard']) {
                $renamed[$t] = ['name' => $tier['name'], 'values' => $tier['values']];
                continue;
            }
            $axisId = $pairing['axisFor'][$t];
            $values = $tier['values'];
            foreach ($pairing['values'][$t] as $i => $povId) {
                $values[$i] = mb_substr($catalog['values'][$axisId][$povId], 0, self::VALUE_NAME_LIMIT);
            }
            $renamed[$t] = ['name' => mb_substr($catalog['axes'][$axisId], 0, self::TYPE_NAME_LIMIT), 'values' => $values];
        }

        if ($this->hasDuplicate(array_column($renamed, 'name'))) {
            return $this->refuse('the catalog\'s names would give two of the item\'s variation types the same name');
        }
        foreach ($renamed as $t => $r) {
            if (!$tiers[$t]['standard'] && $this->hasDuplicate($r['values'])) {
                return $this->refuse('the catalog\'s names would give two of the item\'s variations the same name');
            }
        }

        $changed = false;
        foreach ($tiers as $t => $tier) {
            if ($tier['name'] !== $renamed[$t]['name'] || $tier['values'] !== $renamed[$t]['values']) {
                $changed = true;
                break;
            }
        }
        if (!$changed) {
            return $this->nothing();
        }

        $modelList = [];
        foreach ($models as $m) {
            $modelId = (int) ($m['model_id'] ?? 0);
            if ($modelId > 0) {
                $modelList[] = ['model_id' => $modelId, 'tier_index' => array_map('intval', (array) ($m['tier_index'] ?? []))];
            }
        }

        $payload = ['item_id' => $itemId, 'model_list' => $modelList];
        if ($shape === 'standardise') {
            $payload['standardise_tier_variation'] = array_map(function ($entry, $t) use ($renamed, $tiers) {
                if ($tiers[$t]['standard']) {
                    return $entry;
                }
                $entry['variation_name'] = $renamed[$t]['name'];
                $entry['variation_option_list'] = array_map(function ($o, $i) use ($renamed, $t) {
                    $o['variation_option_name'] = $renamed[$t]['values'][$i];

                    return $o;
                }, array_values((array) ($entry['variation_option_list'] ?? [])), array_keys(array_values((array) ($entry['variation_option_list'] ?? []))));

                return $entry;
            }, $standardise, array_keys($standardise));
        } else {
            $payload['tier_variation'] = array_map(function ($entry, $t) use ($renamed) {
                $entry['name'] = $renamed[$t]['name'];
                $entry['option_list'] = array_map(function ($o, $i) use ($renamed, $t) {
                    $o['option'] = $renamed[$t]['values'][$i];

                    return $o;
                }, array_values((array) ($entry['option_list'] ?? [])), array_keys(array_values((array) ($entry['option_list'] ?? []))));

                return $entry;
            }, $legacy, array_keys($legacy));
        }

        return ['ok' => true, 'message' => '', 'payload' => $payload];
    }

    public function apply(ShopeeClient $client, array $auth, array $plan): array
    {
        if (!($plan['ok'] ?? false)) {
            return ['ok' => false, 'message' => (string) ($plan['message'] ?? '')];
        }
        $payload = $plan['payload'] ?? null;
        if ($payload === null) {
            return ['ok' => true, 'message' => ''];
        }

        $path = '/api/v2/product/update_tier_variation';
        try {
            $res = $client->shopPost(
                $auth['mode'], (int) $auth['partner_id'], (string) $auth['partner_key'],
                (string) $auth['access_token'], (int) $auth['shop_id'],
                $path, [], $payload
            );
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => $this->warning('they did not reach Shopee (' . $this->bare(\App\Support\TransportError::plain($e, 'Shopee')) . ')')];
        }

        ShopeeApiLog::safeCreate([
            'pack' => 'shopee.products.update_tier_variation.rename', 'method' => 'POST',
            'api_path' => $path, 'auth_required' => true,
            'request_params' => $payload,
            'response_status' => $res['status'] ?? null, 'ok' => (bool) ($res['ok'] ?? false),
            'response_body' => $res['body'] ?? null, 'user_id' => auth()->id(),
        ]);

        $body = is_array($res['body'] ?? null) ? $res['body'] : [];
        if (!($res['ok'] ?? false) || trim((string) ($body['error'] ?? '')) !== '') {
            return ['ok' => false, 'message' => $this->warning('Shopee refused them ('
                . $this->bare(\App\Support\MarketplaceAnswer::plain('Shopee', ['ok' => false, 'body' => $body])) . ')')];
        }

        return ['ok' => true, 'message' => ''];
    }

    private function pair(array $tiers, array $axisIds, array $catalog, array $models): array
    {
        $evidence = [];
        foreach ($models as $m) {
            $sku = strtolower(trim((string) ($m['model_sku'] ?? '')));
            $index = array_values((array) ($m['tier_index'] ?? []));
            if ($sku === '' || !isset($catalog['combos'][$sku]) || count($index) !== count($tiers)) {
                continue;
            }
            $evidence[] = ['index' => array_map('intval', $index), 'values' => $catalog['combos'][$sku]];
        }
        if ($evidence === []) {
            return ['refused' => 'none of the item\'s variations on Shopee carries a SKU the catalog holds'];
        }

        $fits = [];
        foreach ($this->orders($axisIds) as $order) {
            $values = [];
            $taken = [];
            foreach ($evidence as $e) {
                foreach ($order as $t => $axisId) {
                    $povId = $e['values'][$axisId] ?? null;
                    if ($povId === null) {
                        continue 3;
                    }
                    $i = $e['index'][$t];
                    if ((isset($values[$t][$i]) && $values[$t][$i] !== $povId)
                        || (isset($taken[$t][$povId]) && $taken[$t][$povId] !== $i)) {
                        continue 3;
                    }
                    $values[$t][$i] = $povId;
                    $taken[$t][$povId] = $i;
                }
            }
            $fits[] = ['axisFor' => $order, 'values' => $values];
        }

        if ($fits === []) {
            return ['refused' => 'the item\'s variations on Shopee are arranged differently from the catalog\'s'];
        }
        if (count($fits) === 1) {
            return $fits[0];
        }

        $scored = [];
        foreach ($fits as $k => $fit) {
            $score = 0;
            foreach ($fit['axisFor'] as $t => $axisId) {
                if (mb_strtolower(trim($tiers[$t]['name'])) === mb_strtolower(trim($catalog['axes'][$axisId]))) {
                    $score++;
                }
            }
            $scored[$k] = $score;
        }
        arsort($scored);
        $best = array_key_first($scored);
        $counts = array_count_values($scored);
        if ($scored[$best] > 0 && $counts[$scored[$best]] === 1) {
            return $fits[$best];
        }

        return ['refused' => 'the item\'s SKUs do not show which of its variation types is which catalog option'];
    }

    private function orders(array $axisIds): array
    {
        if (count($axisIds) === 2) {
            return [[$axisIds[0], $axisIds[1]], [$axisIds[1], $axisIds[0]]];
        }

        return [$axisIds];
    }

    private function catalog(int $productId): ?array
    {
        $pfx = (string) config('catalog.prefix');
        $langId = (int) config('catalog.default_language_id');

        $combos = DB::table('product_option_combinations')
            ->where('product_id', $productId)
            ->get(['id', 'sku']);
        if ($combos->isEmpty()) {
            return null;
        }

        $rows = DB::table('product_option_combination_values as cv')
            ->join($pfx . 'product_option_value as pov', 'cv.product_option_value_id', '=', 'pov.product_option_value_id')
            ->join($pfx . 'option_value_description as ovd', function ($j) use ($langId) {
                $j->on('pov.option_value_id', '=', 'ovd.option_value_id')
                  ->where('ovd.language_id', '=', $langId);
            })
            ->join($pfx . 'option_description as od', function ($j) use ($langId) {
                $j->on('pov.option_id', '=', 'od.option_id')
                  ->where('od.language_id', '=', $langId);
            })
            ->whereIn('cv.combination_id', $combos->pluck('id')->all())
            ->orderBy('pov.product_option_id')
            ->orderBy('pov.product_option_value_id')
            ->get([
                'cv.combination_id', 'pov.product_option_id', 'pov.product_option_value_id',
                'ovd.name as value_name', 'od.name as option_name',
            ]);

        $skuOf = [];
        foreach ($combos as $c) {
            $sku = strtolower(trim((string) ($c->sku ?? '')));
            if ($sku !== '') {
                $skuOf[(int) $c->id] = $sku;
            }
        }

        $axes = [];
        $values = [];
        $bySku = [];
        foreach ($rows as $r) {
            $axisId = (int) $r->product_option_id;
            $povId = (int) $r->product_option_value_id;
            $axes[$axisId] ??= html_entity_decode((string) $r->option_name, ENT_QUOTES | ENT_HTML5, 'UTF-8') ?: 'Variation';
            $values[$axisId][$povId] ??= html_entity_decode((string) $r->value_name, ENT_QUOTES | ENT_HTML5, 'UTF-8') ?: 'Unnamed';
            if (isset($skuOf[(int) $r->combination_id])) {
                $bySku[$skuOf[(int) $r->combination_id]][$axisId] = $povId;
            }
        }

        return $axes === [] ? null : ['axes' => $axes, 'values' => $values, 'combos' => $bySku];
    }

    private function hasDuplicate(array $names): bool
    {
        $seen = [];
        foreach ($names as $n) {
            $k = mb_strtolower(trim((string) $n));
            if (isset($seen[$k])) {
                return true;
            }
            $seen[$k] = true;
        }

        return false;
    }

    private function nothing(): array
    {
        return ['ok' => true, 'message' => '', 'payload' => null];
    }

    private function refuse(string $reason): array
    {
        return ['ok' => false, 'message' => $this->warning($reason), 'payload' => null];
    }

    private function warning(string $reason): string
    {
        return 'Variation names were not changed: ' . $this->bare($reason) . '.';
    }

    private function bare(string $text): string
    {
        return rtrim(trim($text), '.');
    }
}
