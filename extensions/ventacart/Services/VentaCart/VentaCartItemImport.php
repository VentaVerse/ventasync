<?php

namespace Extensions\ventacart\Services\VentaCart;

use Extensions\ventacart\Models\VentaCartProductLink;
use Extensions\ventacart\Models\VentaCartSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class VentaCartItemImport
{
    public function import(VentaCartSetting $setting, string $importSku): array
    {
        if ($refused = \App\Plans\Quota::importRefusal()) {
            return $refused;
        }

        $client = new VentaCartClient($setting);

        $answer = $client->getProduct($importSku);
        if (! ($answer['ok'] ?? false)) {
            return ['ok' => false, 'message' => 'VentaCart did not answer for "' . $importSku . '": '
                . \App\Support\MarketplaceAnswer::plain('VentaCart', $answer)];
        }
        $item = $answer['body']['data'] ?? $answer['body'] ?? [];
        if (empty($item)) {
            return ['ok' => false, 'message' => 'VentaCart answered with no product for "' . $importSku . '".'];
        }

        $variants = [];
        $vAnswer = $client->getVariants($importSku);
        if ($vAnswer['ok'] ?? false) {
            $variants = array_values((array) ($vAnswer['body']['data'] ?? $vAnswer['body'] ?? []));
        }

        $pfx = (string) config('catalog.prefix');
        $langId = (int) config('catalog.default_language_id');

        $name = trim((string) ($item['name'] ?? '')) ?: ('VentaCart item ' . $importSku);
        $sku = trim((string) ($item['sku'] ?? $importSku));
        $ventaCartId = (int) ($item['id'] ?? 0);

        try {
            $productId = DB::transaction(function () use ($pfx, $langId, $item, $variants, $name, $sku, $ventaCartId, $setting) {
                $productId = DB::table($pfx . 'product')->insertGetId([
                    'model' => $sku,
                    'sku' => $sku,
                    'quantity' => (int) ($item['quantity'] ?? 0),
                    'price' => (float) ($item['price'] ?? 0),
                    'status' => 1,
                    'image' => '',
                    'weight' => (float) ($item['weight'] ?? 0),
                    'length' => 0, 'width' => 0, 'height' => 0,
                    'manufacturer_id' => 0,
                    'date_added' => now(), 'date_modified' => now(), 'date_available' => now(),
                ]);

                DB::table($pfx . 'product_description')->insert([
                    'product_id' => $productId,
                    'language_id' => $langId,
                    'name' => Str::limit($name, 250, ''),
                    'description' => \App\Support\Catalog\DescriptionHtml::store((string) ($item['description'] ?? '')),
                    'meta_title' => Str::limit($name, 250, ''),
                    'meta_description' => '', 'meta_keyword' => '', 'tag' => '',
                ]);

                $urls = array_map(
                    fn ($img) => is_array($img) ? (string) ($img['url'] ?? $img['src'] ?? '') : (string) $img,
                    (array) ($item['images'] ?? [])
                );
                $paths = $this->downloadImages($urls, $sku);
                if ($paths !== []) {
                    DB::table($pfx . 'product')->where('product_id', $productId)->update(['image' => $paths[0]]);
                    foreach (array_slice($paths, 1) as $i => $path) {
                        DB::table($pfx . 'product_image')->insert([
                            'product_id' => $productId, 'image' => $path, 'sort_order' => $i,
                        ]);
                    }
                }

                if ($variants !== []) {
                    $this->writeVariations($productId, $sku, $variants);
                }

                VentaCartProductLink::updateOrCreate(
                    ['ventacart_setting_id' => $setting->id, 'product_id' => $productId],
                    ['ventacart_product_id' => $ventaCartId ?: null, 'sku' => $sku]
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
            'message' => "Imported \"{$name}\" as catalog product #{$productId}"
                . ($variants !== [] ? ' with ' . count($variants) . ' variations' : '')
                . ', linked to ' . ($setting->store_name ?: 'VentaCart') . '.' . (\App\Support\Catalog\SkuFallback::note($minted) !== '' ? ' ' . \App\Support\Catalog\SkuFallback::note($minted) : ''),
        ];
    }

    private function writeVariations(int $productId, string $parentSku, array $variants): void
    {
        $values = [];
        foreach ($variants as $i => $v) {
            $values[] = [
                'name' => trim((string) ($v['name'] ?? '')) ?: ('Variation ' . ($i + 1)),
                'sku' => trim((string) ($v['sku'] ?? '')),
                'status' => 1,
                'quantity' => (int) ($v['quantity'] ?? 0),
                'absolute_price' => (float) ($v['price'] ?? 0),
                'cost_amount' => 0, 'cost_additional' => 0,
                'image' => '',
            ];
        }

        app(\App\Http\Controllers\Catalog\ProductController::class)
            ->saveAbsoluteOptions($productId, Request::create('/', 'POST', [
                'sku' => $parentSku,
                'option_name' => 'Variation',
                'values' => $values,
            ]));
    }

    private function downloadImages(array $urls, string $sku): array
    {
        $paths = [];
        $slug = Str::slug($sku) ?: 'item';
        foreach (array_values($urls) as $i => $url) {
            $img = \App\Support\RemoteImage::fetch((string) $url);
            if ($img === null) {
                continue;
            }
            $rel = 'catalog/import/ventacart/' . $slug . '/' . $i . '.' . $img['ext'];
            Storage::disk('public')->put($rel, $img['body']);
            $paths[] = $rel;
        }

        return $paths;
    }
}
