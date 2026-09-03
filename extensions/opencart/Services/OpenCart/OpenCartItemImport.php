<?php

namespace Extensions\opencart\Services\OpenCart;

use Extensions\opencart\Models\OpenCartProductLink;
use Extensions\opencart\Models\OpenCartSetting;
use Extensions\opencart\Models\OpenCartUnmatchedItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Import an OpenCart product into the catalog (cleanup point 11, the owner
 * 2026-09-04). The api/erp module ships with this repo, so the shapes
 * are certain: OpenCart-native product rows plus option_values with
 * RELATIVE prices (+/- over the base). One option becomes a real axis
 * with absolute prices computed from base and prefix; a multi-option
 * product imports flat and says so - OpenCart options are independent
 * (no combination rows), and inventing a cartesian would falsify stock.
 * Image paths are store-relative; they resolve against the store's own
 * base URL through the shared SSRF guard.
 */
class OpenCartItemImport
{
    public function import(OpenCartSetting $setting, OpenCartUnmatchedItem $row): array
    {
        $client = new OpenCartClient($setting);

        $answer = $client->getProducts(1, 1, null, (int) $row->oc_product_id);
        if (! ($answer['ok'] ?? false)) {
            return ['ok' => false, 'message' => 'OpenCart did not answer for product ' . $row->oc_product_id . ': '
                . \App\Support\MarketplaceAnswer::plain('OpenCart', $answer)];
        }
        $item = ($answer['body']['data'] ?? [])[0] ?? [];
        if (empty($item)) {
            return ['ok' => false, 'message' => 'OpenCart answered with no product for ' . $row->oc_product_id . '.'];
        }

        $pfx = (string) config('catalog.prefix');
        $langId = (int) config('catalog.default_language_id');

        $name = trim(html_entity_decode((string) ($item['name'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?: ('OpenCart product ' . $row->oc_product_id);
        $sku = trim((string) ($item['sku'] ?? '')) ?: trim((string) ($item['model'] ?? ''));

        $optionValues = array_values((array) ($item['option_values'] ?? []));
        $optionNames = array_values(array_unique(array_map(fn ($v) => trim((string) ($v['option_name'] ?? '')), $optionValues)));
        $multiOption = count($optionNames) > 1;

        try {
            $productId = DB::transaction(function () use ($pfx, $langId, $item, $optionValues, $multiOption, $name, $sku, $setting, $row) {
                $productId = DB::table($pfx . 'product')->insertGetId([
                    'model' => trim((string) ($item['model'] ?? '')) ?: $sku,
                    'sku' => $sku,
                    'quantity' => (int) ($item['quantity'] ?? 0),
                    'price' => (float) ($item['price'] ?? 0),
                    'status' => 1, // already selling there (#197's rule)
                    'image' => '',
                    'weight' => (float) ($item['weight'] ?? 0),
                    'length' => (float) ($item['length'] ?? 0),
                    'width' => (float) ($item['width'] ?? 0),
                    'height' => (float) ($item['height'] ?? 0),
                    'manufacturer_id' => 0,
                    'date_added' => now(), 'date_modified' => now(), 'date_available' => now(),
                ]);

                DB::table($pfx . 'product_description')->insert([
                    'product_id' => $productId,
                    'language_id' => $langId,
                    'name' => Str::limit($name, 250, ''),
                    'description' => \App\Support\HtmlSanitizer::sanitize(html_entity_decode((string) ($item['description'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8')),
                    'meta_title' => Str::limit($name, 250, ''),
                    'meta_description' => '', 'meta_keyword' => '', 'tag' => '',
                ]);

                // Store-relative paths become absolute against the store.
                $base = rtrim((string) $setting->base_url, '/');
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
                $paths = $this->downloadImages($urls, (string) $row->oc_product_id);
                if ($paths !== []) {
                    DB::table($pfx . 'product')->where('product_id', $productId)->update(['image' => $paths[0]]);
                    foreach (array_slice($paths, 1) as $i => $path) {
                        DB::table($pfx . 'product_image')->insert([
                            'product_id' => $productId, 'image' => $path, 'sort_order' => $i,
                        ]);
                    }
                }

                if ($optionValues !== [] && ! $multiOption) {
                    $this->writeVariations($productId, $sku, (float) ($item['price'] ?? 0), $optionValues);
                }

                OpenCartProductLink::updateOrCreate(
                    ['opencart_setting_id' => $setting->id, 'oc_product_id' => (int) $row->oc_product_id],
                    ['product_id' => $productId, 'sku' => $sku ?: null]
                );

                return $productId;
            });
        } catch (\Illuminate\Validation\ValidationException $e) {
            return ['ok' => false, 'message' => 'Not imported: ' . implode(' ', collect($e->errors())->flatten()->take(3)->all())];
        }

        $row->update(['status' => 'linked', 'linked_product_id' => $productId]);

        return [
            'ok' => true,
            'product_id' => $productId,
            'message' => "Imported \"{$name}\" as catalog product #{$productId}"
                . ($optionValues !== [] && ! $multiOption ? ' with ' . count($optionValues) . ' variations' : '')
                . ($multiOption ? ' (its ' . count($optionNames) . ' independent option sets could not be carried; set variations up by hand)' : '')
                . ', linked to ' . ($setting->store_name ?: 'OpenCart') . '.',
        ];
    }

    /** One OpenCart option: a real axis, absolute prices from base ± the relative price. */
    private function writeVariations(int $productId, string $parentSku, float $basePrice, array $optionValues): void
    {
        $values = [];
        foreach ($optionValues as $i => $v) {
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
            ->saveAbsoluteOptions($productId, Request::create('/', 'POST', [
                'sku' => $parentSku,
                'option_name' => trim((string) ($optionValues[0]['option_name'] ?? '')) ?: 'Variation',
                'values' => $values,
            ]));
    }

    /** @return list<string> public-disk relative paths, in the order given */
    private function downloadImages(array $urls, string $key): array
    {
        $paths = [];
        foreach (array_values($urls) as $i => $url) {
            $img = \App\Support\RemoteImage::fetch((string) $url);
            if ($img === null) {
                continue; // a photo is never a reason to fail an import
            }
            $rel = 'catalog/import/opencart/' . $key . '/' . $i . '.' . $img['ext'];
            Storage::disk('public')->put($rel, $img['body']);
            $paths[] = $rel;
        }

        return $paths;
    }
}
