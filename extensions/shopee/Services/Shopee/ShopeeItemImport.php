<?php

namespace Extensions\shopee\Services\Shopee;

use App\Support\MarketplaceAnswer;
use Extensions\shopee\Models\ShopeeListing;
use Extensions\shopee\Models\ShopeeProductLink;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ShopeeItemImport
{
    public function __construct(private readonly ShopeeClient $client)
    {
    }

    public function import(array $auth, int $itemId): array
    {
        if ($refused = \App\Plans\Quota::importRefusal()) {
            return $refused;
        }

        $base = $this->fetchBase($auth, $itemId);
        if (isset($base['error'])) {
            return ['ok' => false, 'message' => $base['error']];
        }
        $item = $base['item'];

        $models = null;
        if (! empty($item['has_model'])) {
            $models = $this->fetchModels($auth, $itemId);
            if (isset($models['error'])) {
                return ['ok' => false, 'message' => $models['error']];
            }
        }

        $pfx = (string) config('catalog.prefix');
        $langId = (int) config('catalog.default_language_id');

        $name = trim((string) ($item['item_name'] ?? '')) ?: ('Shopee item ' . $itemId);
        $sku = trim((string) ($item['item_sku'] ?? ''));
        $price = (float) (data_get($item, 'price_info.0.current_price') ?? 0);
        $qty = (int) (data_get($item, 'stock_info_v2.summary_info.total_available_stock') ?? 0);

        try {
            try {
                app(\Extensions\shopee\Controllers\ShopeeProductGroupController::class)->ensureTemplate((int) ($item['category_id'] ?? 0), $this->client);
            } catch (\Throwable) {
            }

            $productId = DB::transaction(function () use ($pfx, $langId, $item, $models, $name, $sku, $price, $qty, $itemId, $auth) {
                $productId = DB::table($pfx . 'product')->insertGetId([
                    'model' => $sku !== '' ? $sku : ('SHP-' . $itemId),
                    'sku' => $sku,
                    'quantity' => $qty,
                    'price' => $price,
                    'status' => 1,
                    'image' => '',
                    'weight' => (float) ($item['weight'] ?? 0),
                    'length' => (float) data_get($item, 'dimension.package_length', 0),
                    'width' => (float) data_get($item, 'dimension.package_width', 0),
                    'height' => (float) data_get($item, 'dimension.package_height', 0),
                    'manufacturer_id' => 0,
                    'date_added' => now(), 'date_modified' => now(), 'date_available' => now(),
                ]);

                DB::table($pfx . 'product_description')->insert([
                    'product_id' => $productId,
                    'language_id' => $langId,
                    'name' => Str::limit($name, 250, ''),
                    'description' => \App\Support\Catalog\DescriptionHtml::store(
                        str_contains(\Extensions\shopee\Services\Shopee\ShopeeDescription::textOf($item), '<')
                            ? \Extensions\shopee\Services\Shopee\ShopeeDescription::textOf($item)
                            : nl2br(\Extensions\shopee\Services\Shopee\ShopeeDescription::textOf($item))
                    ),
                    'meta_title' => Str::limit($name, 250, ''),
                    'meta_description' => '', 'meta_keyword' => '', 'tag' => '',
                ]);

                $paths = $this->downloadImages((array) data_get($item, 'image.image_url_list', []), $itemId);
                if ($paths !== []) {
                    DB::table($pfx . 'product')->where('product_id', $productId)->update(['image' => $paths[0]]);
                    foreach (array_slice($paths, 1) as $i => $path) {
                        DB::table($pfx . 'product_image')->insert([
                            'product_id' => $productId, 'image' => $path, 'sort_order' => $i,
                        ]);
                    }
                }

                if ($models !== null) {
                    $this->writeVariations($productId, $sku, $models, $itemId);
                }

                ShopeeListing::updateOrCreate(
                    ['product_id' => $productId],
                    [
                        'shopee_category_id' => (int) ($item['category_id'] ?? 0) ?: null,
                        'shopee_brand_id' => (int) data_get($item, 'brand.brand_id', 0) ?: null,
                        'logistic_ids' => collect((array) ($item['logistic_info'] ?? []))
                            ->filter(fn ($l) => is_array($l) && ! empty($l['enabled']) && (int) ($l['logistic_id'] ?? 0) > 0)
                            ->map(fn ($l) => (int) $l['logistic_id'])->values()->all() ?: null,
                        'attribute_values' => collect((array) data_get($item, 'attribute_list', []))
                            ->mapWithKeys(fn ($a) => [(string) ($a['attribute_id'] ?? '') => (string) (data_get($a, 'attribute_value_list.0.original_value_name') ?? '')])
                            ->filter(fn ($v, $k) => $k !== '' && $v !== '')
                            ->toArray() ?: null,
                        'item_name' => Str::limit($name, 120, ''),
                    ]
                );

                $liveStatus = strtoupper((string) ($item['item_status'] ?? '')) ?: null;
                ShopeeProductLink::updateOrCreate(
                    ['product_id' => $productId, 'shopee_item_id' => $itemId, 'shopee_model_id' => null],
                    ['sku' => $sku ?: null, 'live_status' => $liveStatus, 'live_checked_at' => now()]
                );
                foreach (($models['models'] ?? []) as $m) {
                    ShopeeProductLink::updateOrCreate(
                        ['product_id' => $productId, 'shopee_item_id' => $itemId, 'shopee_model_id' => (int) $m['model_id']],
                        ['sku' => trim((string) ($m['model_sku'] ?? '')) ?: null, 'live_status' => $liveStatus, 'live_checked_at' => now()]
                    );
                }

                return $productId;
            });
        } catch (\Illuminate\Validation\ValidationException $e) {
            return ['ok' => false, 'message' => 'Not imported: ' . implode(' ', collect($e->errors())->flatten()->take(3)->all())];
        }

        $minted = \App\Support\Catalog\SkuFallback::fill((int) $productId);

        return [
            'ok' => true,
            'product_id' => $productId,
            'message' => "Imported \"{$name}\" as catalog product #{$productId}"
                . ($models !== null ? ' with ' . count($models['models']) . ' variations' : '')
                . ', linked to Shopee item ' . $itemId . '.' . (\App\Support\Catalog\SkuFallback::note($minted) !== '' ? ' ' . \App\Support\Catalog\SkuFallback::note($minted) : ''),
        ];
    }

    private function fetchBase(array $auth, int $itemId): array
    {
        $res = $this->client->shopGet(
            $auth['mode'], (int) $auth['partner_id'], (string) $auth['partner_key'],
            (string) $auth['access_token'], (int) $auth['shop_id'],
            '/api/v2/product/get_item_base_info',
            ['item_id_list' => (string) $itemId, 'need_complex_description' => 'true']
        );
        $item = data_get($res, 'body.response.item_list.0');
        if (! is_array($item)) {
            return ['error' => 'Shopee did not hand the item over: ' . MarketplaceAnswer::errorText('Shopee', is_array($res) ? $res : ['ok' => false, 'body' => []])];
        }

        return ['item' => $item];
    }

    private function fetchModels(array $auth, int $itemId): array
    {
        $res = $this->client->shopGet(
            $auth['mode'], (int) $auth['partner_id'], (string) $auth['partner_key'],
            (string) $auth['access_token'], (int) $auth['shop_id'],
            '/api/v2/product/get_model_list',
            ['item_id' => $itemId]
        );
        $models = data_get($res, 'body.response.model');
        if (! is_array($models)) {
            return ['error' => 'Shopee did not hand the variations over: ' . MarketplaceAnswer::errorText('Shopee', is_array($res) ? $res : ['ok' => false, 'body' => []])];
        }

        return [
            'models' => $models,
            'tiers' => (array) data_get($res, 'body.response.tier_variation', []),
        ];
    }

    private function writeVariations(int $productId, string $parentSku, array $models, int $itemId): void
    {
        $tiers = $models['tiers'];
        $tierNames = array_map(fn ($t) => trim((string) ($t['name'] ?? '')) ?: 'Variation', $tiers);
        $tierOptions = array_map(fn ($t) => array_map(
            fn ($o) => trim((string) (is_array($o) ? ($o['option'] ?? '') : $o)),
            (array) ($t['option_list'] ?? [])
        ), $tiers);

        $data = ['sku' => $parentSku];
        if (count($tiers) >= 2) {
            $data['option1_name'] = $tierNames[0];
            $data['option2_name'] = $tierNames[1];
            $data['option1_values'] = $tierOptions[0];
            $data['option2_values'] = $tierOptions[1];
            $combos = [];
            foreach ($models['models'] as $m) {
                $idx = (array) ($m['tier_index'] ?? []);
                $combos[] = [
                    'opt1' => $tierOptions[0][$idx[0] ?? 0] ?? '',
                    'opt2' => $tierOptions[1][$idx[1] ?? 0] ?? '',
                    'sku' => trim((string) ($m['model_sku'] ?? '')),
                    'status' => 1,
                    'quantity' => (int) data_get($m, 'stock_info_v2.summary_info.total_available_stock', 0),
                    'absolute_price' => (float) data_get($m, 'price_info.0.current_price', 0),
                    'cost_amount' => 0, 'cost_additional' => 0,
                    'image' => '',
                ];
            }
            $data['combinations'] = $combos;
        } else {
            $data['option_name'] = $tierNames[0] ?? 'Variation';
            $optionImages = array_map(
                fn ($o) => is_array($o) ? (string) data_get($o, 'image.image_url', '') : '',
                (array) ($tiers[0]['option_list'] ?? [])
            );
            $values = [];
            foreach ($models['models'] as $m) {
                $idx = (int) (data_get($m, 'tier_index.0') ?? 0);
                $imagePath = '';
                if (($optionImages[$idx] ?? '') !== '') {
                    $imagePath = $this->downloadImages([$optionImages[$idx]], $itemId, 'v' . $idx)[0] ?? '';
                }
                $values[] = [
                    'name' => $tierOptions[0][$idx] ?? ('Variation ' . ($idx + 1)),
                    'sku' => trim((string) ($m['model_sku'] ?? '')),
                    'status' => 1,
                    'quantity' => (int) data_get($m, 'stock_info_v2.summary_info.total_available_stock', 0),
                    'absolute_price' => (float) data_get($m, 'price_info.0.current_price', 0),
                    'cost_amount' => 0, 'cost_additional' => 0,
                    'image' => $imagePath,
                ];
            }
            $data['values'] = $values;
        }

        app(\App\Http\Controllers\Catalog\ProductController::class)
            ->saveAbsoluteOptions($productId, Request::create('/', 'POST', $data));
    }

    private function downloadImages(array $urls, int $itemId, string $suffix = ''): array
    {
        $paths = [];
        foreach (array_values($urls) as $i => $url) {
            $url = trim((string) $url);
            if ($url === '' || ! preg_match('#^https://#i', $url)) {
                continue;
            }
            $img = \App\Support\RemoteImage::fetch($url);
            if ($img === null) {
                continue;
            }
            $rel = 'catalog/import/shopee/' . $itemId . '/' . ($suffix !== '' ? $suffix . '-' : '') . $i . '.' . $img['ext'];
            Storage::disk('public')->put($rel, $img['body']);
            $paths[] = $rel;
        }

        return $paths;
    }
}
