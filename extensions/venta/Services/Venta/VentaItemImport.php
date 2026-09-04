<?php

namespace Extensions\venta\Services\Venta;

use Extensions\venta\Models\VentaProductLink;
use Extensions\venta\Models\VentaSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Import a Venta store item into the catalog (cleanup point 11, the owner
 * 2026-09-04) - the Big 3's #196 import, in Venta's shapes: one full
 * product read plus its variants become a real catalog product with
 * description, images and variations, linked to the store item and
 * arriving enabled, because it is already selling there.
 *
 * Venta variants carry only a display name and their own sku/price/qty -
 * no option axes - so variations reconstruct as a single "Variation"
 * axis, the same honest shape a structure-less source gets everywhere
 * else in this codebase. A SKU the catalog already holds rolls the whole
 * import back and says whose it is.
 */
class VentaItemImport
{
    public function import(VentaSetting $setting, string $importSku): array
    {
        $client = new VentaClient($setting);

        $answer = $client->getProduct($importSku);
        if (! ($answer['ok'] ?? false)) {
            return ['ok' => false, 'message' => 'Venta did not answer for "' . $importSku . '": '
                . \App\Support\MarketplaceAnswer::plain('Venta', $answer)];
        }
        $item = $answer['body']['data'] ?? $answer['body'] ?? [];
        if (empty($item)) {
            return ['ok' => false, 'message' => 'Venta answered with no product for "' . $importSku . '".'];
        }

        $variants = [];
        $vAnswer = $client->getVariants($importSku);
        if ($vAnswer['ok'] ?? false) {
            $variants = array_values((array) ($vAnswer['body']['data'] ?? $vAnswer['body'] ?? []));
        }

        $pfx = (string) config('catalog.prefix');
        $langId = (int) config('catalog.default_language_id');

        $name = trim((string) ($item['name'] ?? '')) ?: ('Venta item ' . $importSku);
        $sku = trim((string) ($item['sku'] ?? $importSku));
        $ventaId = (int) ($item['id'] ?? 0);

        try {
            $productId = DB::transaction(function () use ($pfx, $langId, $item, $variants, $name, $sku, $ventaId, $setting) {
                $productId = DB::table($pfx . 'product')->insertGetId([
                    'model' => $sku,
                    'sku' => $sku,
                    'quantity' => (int) ($item['quantity'] ?? 0),
                    'price' => (float) ($item['price'] ?? 0),
                    /* Already selling on Venta - arriving disabled would
                     * misstate reality (#197's rule). */
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
                    'description' => \App\Support\HtmlSanitizer::sanitize((string) ($item['description'] ?? '')),
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

                VentaProductLink::updateOrCreate(
                    ['venta_setting_id' => $setting->id, 'product_id' => $productId],
                    ['venta_product_id' => $ventaId ?: null, 'sku' => $sku]
                );

                return $productId;
            });
        } catch (\Illuminate\Validation\ValidationException $e) {
            return ['ok' => false, 'message' => 'Not imported: ' . implode(' ', collect($e->errors())->flatten()->take(3)->all())];
        }


        return [
            'ok' => true,
            'product_id' => $productId,
            'message' => "Imported \"{$name}\" as catalog product #{$productId}"
                . ($variants !== [] ? ' with ' . count($variants) . ' variations' : '')
                . ', linked to ' . ($setting->store_name ?: 'Venta') . '.',
        ];
    }

    /** Single "Variation" axis: Venta variants have no option structure. */
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

    /** @return list<string> public-disk relative paths, in the order given */
    private function downloadImages(array $urls, string $sku): array
    {
        $paths = [];
        $slug = Str::slug($sku) ?: 'item';
        foreach (array_values($urls) as $i => $url) {
            $img = \App\Support\RemoteImage::fetch((string) $url);
            if ($img === null) {
                // A photo is never a reason to fail an import - and an
                // unfetchable URL (non-https, private address, redirect,
                // non-image) is refused by the shared SSRF guard.
                continue;
            }
            $rel = 'catalog/import/venta/' . $slug . '/' . $i . '.' . $img['ext'];
            Storage::disk('public')->put($rel, $img['body']);
            $paths[] = $rel;
        }

        return $paths;
    }
}
