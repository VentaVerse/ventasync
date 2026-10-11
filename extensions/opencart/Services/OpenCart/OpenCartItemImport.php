<?php

namespace Extensions\OpenCart\Services\OpenCart;

use Extensions\OpenCart\Models\OpenCartListingLink;
use Extensions\OpenCart\Models\OpenCartStore;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class OpenCartItemImport
{
    public function import(OpenCartStore $store, int $ocProductId): array
    {
        if ($refused = \App\Plans\Quota::importRefusal()) {
            return $refused;
        }

        $client = new OpenCartClient($store);

        $answer = $client->getProducts(1, 1, null, (int) $ocProductId);
        if (! ($answer['ok'] ?? false)) {
            return ['ok' => false, 'message' => 'OpenCart did not answer for product ' . $ocProductId . ': '
                . \App\Support\MarketplaceAnswer::plain('OpenCart', $answer)];
        }
        $item = ($answer['body']['data'] ?? [])[0] ?? [];
        if (empty($item)) {
            return ['ok' => false, 'message' => 'OpenCart answered with no product for ' . $ocProductId . '.'];
        }
        $langId = (int) config('catalog.default_language_id');

        $name = trim(html_entity_decode((string) ($item['name'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?: ('OpenCart product ' . $ocProductId);
        $sku = trim((string) ($item['sku'] ?? '')) ?: trim((string) ($item['model'] ?? ''));

        $variationValues = array_values((array) ($item['option_values'] ?? []));
        $typeNames = array_values(array_unique(array_map(fn ($v) => trim((string) ($v['option_name'] ?? '')), $variationValues)));
        $multiType = count($typeNames) > 1;

        try {
            $productId = DB::transaction(function () use ( $langId, $item, $variationValues, $multiType, $name, $sku, $store, $ocProductId) {
                $productId = DB::table('products')->insertGetId([
                    'model' => trim((string) ($item['model'] ?? '')) ?: $sku,
                    'sku' => $sku,
                    'quantity' => (int) ($item['quantity'] ?? 0),
                    'price' => (float) ($item['price'] ?? 0),
                    'status' => 1,
                    'image' => '',
                    'weight' => (float) ($item['weight'] ?? 0),
                    'length' => (float) ($item['length'] ?? 0),
                    'width' => (float) ($item['width'] ?? 0),
                    'height' => (float) ($item['height'] ?? 0),
                    'brand_id' => 0,
                    'date_added' => now(), 'date_modified' => now(), 'date_available' => now(),
                ]);

                DB::table('product_descriptions')->insert([
                    'product_id' => $productId,
                    'language_id' => $langId,
                    'name' => Str::limit($name, 250, ''),
                    'description' => \App\Support\Catalog\DescriptionHtml::store(html_entity_decode((string) ($item['description'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8')),
                    'meta_title' => Str::limit($name, 250, ''),
                    'meta_description' => '', 'meta_keyword' => '', 'tag' => '',
                ]);

                $base = rtrim((string) $store->base_url, '/');
                $urls = [];
                if (trim((string) ($item['image'] ?? '')) !== '') {
                    $urls[] = $base . '/image/' . ltrim((string) $item['image'], '/');
                }
                foreach ((array) ($item['images'] ?? []) as $img) {
                    $rel = is_array($img) ? (string) ($img['image'] ?? '') : (string) $img;
                    if (trim($rel) !== '') {
                        $urls[] = $base . '/image/' . ltrim($rel, '/');
                    }
                }
                $paths = $this->downloadImages($urls, (string) $ocProductId);
                if ($paths !== []) {
                    DB::table('products')->where('product_id', $productId)->update(['image' => $paths[0]]);
                    foreach (array_slice($paths, 1) as $i => $path) {
                        DB::table('product_images')->insert([
                            'product_id' => $productId, 'image' => $path, 'sort_order' => $i,
                        ]);
                    }
                }

                if ($variationValues !== [] && ! $multiType) {
                    $this->writeVariations($productId, $sku, (float) ($item['price'] ?? 0), $variationValues);
                }

                OpenCartListingLink::updateOrCreate(
                    ['store_id' => $store->id, 'oc_product_id' => (int) $ocProductId],
                    ['product_id' => $productId, 'sku' => $sku ?: null]
                );

                return $productId;
            });
        } catch (\Illuminate\Validation\ValidationException $e) {
            return ['ok' => false, 'message' => 'Not imported: ' . implode(' ', collect($e->errors())->flatten()->take(3)->all())];
        }

        $minted = \App\Support\Catalog\SkuFallback::fill((int) $productId);

        return [
            'ok' => true,
            'product_id' => $productId,
            'message' => "Imported \"{$name}\" as Master Catalog product #{$productId}"
                . ($variationValues !== [] && ! $multiType ? ' with ' . count($variationValues) . ' variations' : '')
                . ($multiType ? ' (its ' . count($typeNames) . ' independent option sets could not be carried; set variations up by hand)' : '')
                . ', linked to ' . ($store->store_name ?: 'OpenCart') . '.' . (\App\Support\Catalog\SkuFallback::note($minted) !== '' ? ' ' . \App\Support\Catalog\SkuFallback::note($minted) : ''),
        ];
    }

    private function writeVariations(int $productId, string $parentSku, float $basePrice, array $variationValues): void
    {
        $values = [];
        foreach ($variationValues as $i => $v) {
            $delta = (float) ($v['price'] ?? 0);
            $absolute = ((string) ($v['price_prefix'] ?? '+')) === '-' ? $basePrice - $delta : $basePrice + $delta;
            $values[] = [
                'name' => trim(html_entity_decode((string) ($v['option_value_name'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?: ('Variation ' . ($i + 1)),
                'sku' => trim((string) ($v['sku'] ?? '')),
                'status' => 1,
                'quantity' => max(0, (int) ($v['quantity'] ?? 0)),
                'absolute_price' => round(max(0, $absolute), 4),
                'cost_amount' => 0, 'cost_additional' => 0,
                'image' => '',
            ];
        }

        app(\App\Http\Controllers\Catalog\ProductController::class)
            ->saveAbsoluteVariations($productId, Request::create('/', 'POST', [
                'sku' => $parentSku,
                'variation_type_name' => trim((string) ($variationValues[0]['option_name'] ?? '')) ?: 'Variation',
                'values' => $values,
            ]));
    }

    private function downloadImages(array $urls, string $key): array
    {
        $paths = [];
        foreach (array_values($urls) as $i => $url) {
            $img = \App\Support\RemoteImage::fetch((string) $url);
            if ($img === null) {
                continue;
            }
            $rel = 'catalog/import/opencart/' . $key . '/' . $i . '.' . $img['ext'];
            Storage::disk('public')->put($rel, $img['body']);
            $paths[] = $rel;
        }

        return $paths;
    }
}
