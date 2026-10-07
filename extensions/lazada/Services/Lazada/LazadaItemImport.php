<?php

namespace Extensions\lazada\Services\Lazada;

use Extensions\lazada\Models\LazadaProduct;
use Extensions\lazada\Models\LazadaProductAttribute;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class LazadaItemImport
{
    public function __construct(
        private readonly LazadaLiveListing $live,
        private readonly LazadaClient $client,
        private readonly LazadaItemCache $itemCache,
    ) {
    }

    public function import(object $setting, array $creds, string $itemId): array
    {
        try {
            $data = $this->live->raw($setting, $creds, $itemId);
        } catch (\RuntimeException $e) {
            return ['ok' => false, 'message' => 'Lazada did not hand the item over: ' . $e->getMessage()];
        }
        if (empty($data) || (empty($data['item_id']) && empty($data['skus']))) {
            return ['ok' => false, 'message' => 'Lazada holds no record for item ' . $itemId . '.'];
        }

        $attrs = (array) ($data['attributes'] ?? []);
        $skus = array_values((array) ($data['skus'] ?? []));
        $first = (array) ($skus[0] ?? []);

        $name = trim((string) ($attrs['name'] ?? '')) ?: ('Lazada item ' . $itemId);
        $sku = trim((string) ($first['SellerSku'] ?? ''));
        $price = (float) (($first['special_price'] ?? 0) > 0 ? $first['special_price'] : ($first['price'] ?? 0));
        $qty = array_sum(array_map(fn ($s) => (int) ($s['quantity'] ?? 0), $skus));

        $axes = $this->axes($skus);
        $hasVariations = count($skus) > 1 && $axes !== [];

        $pfx = (string) config('catalog.prefix');
        $langId = (int) config('catalog.default_language_id');

        try {
            $categoryId = (int) ($data['primary_category'] ?? 0);
            if ($categoryId > 0 && ! \Extensions\lazada\Models\LazadaCategoryTemplate::query()->where('primary_category_id', $categoryId)->exists()) {
                try {
                    app(\Extensions\lazada\Controllers\LazadaProductController::class)->readCategoryTemplate($categoryId, $this->client);
                } catch (\Throwable) {
                }
            }

            $productId = DB::transaction(function () use ($pfx, $langId, $data, $attrs, $skus, $first, $name, $sku, $price, $qty, $itemId, $axes, $hasVariations, $setting, $creds) {
                $productId = DB::table($pfx . 'product')->insertGetId([
                    'model' => ($hasVariations ? '' : $sku) ?: ('LZD-' . $itemId),
                    'sku' => $hasVariations ? '' : $sku,
                    'quantity' => $qty,
                    'price' => $price,
                    'status' => 1,
                    'image' => '',
                    'weight' => (float) ($first['package_weight'] ?? 0),
                    'length' => (float) ($first['package_length'] ?? 0),
                    'width' => (float) ($first['package_width'] ?? 0),
                    'height' => (float) ($first['package_height'] ?? 0),
                    'manufacturer_id' => 0,
                    'date_added' => now(), 'date_modified' => now(), 'date_available' => now(),
                ]);

                DB::table($pfx . 'product_description')->insert([
                    'product_id' => $productId,
                    'language_id' => $langId,
                    'name' => Str::limit($name, 250, ''),
                    'description' => \App\Support\Catalog\DescriptionHtml::store((string) ($attrs['description'] ?? $attrs['short_description'] ?? '')),
                    'meta_title' => Str::limit($name, 250, ''),
                    'meta_description' => '', 'meta_keyword' => '', 'tag' => '',
                ]);

                $imageUrls = LazadaImages::ofItem($data);
                $paths = $this->downloadImages($imageUrls, $itemId);
                if ($paths !== []) {
                    DB::table($pfx . 'product')->where('product_id', $productId)->update(['image' => $paths[0]]);
                    foreach (array_slice($paths, 1) as $i => $path) {
                        DB::table($pfx . 'product_image')->insert([
                            'product_id' => $productId, 'image' => $path, 'sort_order' => $i,
                        ]);
                    }
                }

                if ($hasVariations) {
                    $this->writeVariations($productId, $skus, $axes, $itemId);
                }

                $listing = LazadaProduct::create([
                    'product_id' => $productId,
                    'primary_category_id' => (int) ($data['primary_category'] ?? 0) ?: null,
                    'lazada_item_id' => (string) ($data['item_id'] ?? $itemId),
                    'brand_name_override' => trim((string) ($attrs['brand'] ?? '')) ?: null,
                    'live_status' => strtolower((string) ($data['status'] ?? '')) ?: null,
                    'live_checked_at' => now(),
                ]);
                foreach ($attrs as $key => $value) {
                    if (in_array($key, ['name', 'description', 'short_description'], true) || ! is_scalar($value) || trim((string) $value) === '') {
                        continue;
                    }
                    LazadaProductAttribute::create([
                        'lazada_product_id' => $listing->id,
                        'attribute_key' => (string) $key,
                        'value' => Str::limit((string) $value, 2000, ''),
                    ]);
                }

                $this->itemCache->fetchAndCacheVariants($listing, $setting, $this->client, 'lazada.product.item.get.import');

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
                . ($hasVariations ? ' with ' . count($skus) . ' variations' : '')
                . ', linked to Lazada item ' . $itemId . '.' . (\App\Support\Catalog\SkuFallback::note($minted) !== '' ? ' ' . \App\Support\Catalog\SkuFallback::note($minted) : ''),
        ];
    }

    private function axes(array $skus): array
    {
        $axes = [];
        foreach ($skus as $s) {
            foreach ((array) ($s['saleProp'] ?? []) as $axis => $v) {
                if (! in_array($axis, $axes, true)) {
                    $axes[] = (string) $axis;
                }
            }
        }

        return array_slice($axes, 0, 2);
    }

    private function writeVariations(int $productId, array $skus, array $axes, string $itemId): void
    {
        $axisValues = fn (string $axis) => array_values(array_unique(array_map(
            fn ($s) => trim((string) data_get($s, 'saleProp.' . $axis, '')),
            $skus
        )));

        $rowFor = function (array $s) use ($itemId) {
            $imagePath = '';
            $img = LazadaImages::secure((string) (data_get($s, 'Images.0') ?? ''));
            if ($img !== '') {
                $imagePath = $this->downloadImages([$img], $itemId, 'v' . substr(md5($img), 0, 6))[0] ?? '';
            }

            return [
                'sku' => trim((string) ($s['SellerSku'] ?? '')),
                'status' => strtolower((string) ($s['Status'] ?? 'active')) === 'active' ? 1 : 0,
                'quantity' => (int) ($s['quantity'] ?? 0),
                'absolute_price' => (float) ((($s['special_price'] ?? 0) > 0) ? $s['special_price'] : ($s['price'] ?? 0)),
                'cost_amount' => 0, 'cost_additional' => 0,
                'image' => $imagePath,
            ];
        };

        $data = ['sku' => ''];
        if (count($axes) === 2) {
            $data['option1_name'] = ucfirst(str_replace('_', ' ', $axes[0]));
            $data['option2_name'] = ucfirst(str_replace('_', ' ', $axes[1]));
            $data['option1_values'] = $axisValues($axes[0]);
            $data['option2_values'] = $axisValues($axes[1]);
            $data['combinations'] = array_map(fn ($s) => $rowFor($s) + [
                'opt1' => trim((string) data_get($s, 'saleProp.' . $axes[0], '')),
                'opt2' => trim((string) data_get($s, 'saleProp.' . $axes[1], '')),
            ], $skus);
        } else {
            $data['option_name'] = ucfirst(str_replace('_', ' ', $axes[0]));
            $data['values'] = array_map(fn ($s) => $rowFor($s) + [
                'name' => trim((string) data_get($s, 'saleProp.' . $axes[0], '')) ?: trim((string) ($s['SellerSku'] ?? '')),
            ], $skus);
        }

        app(\App\Http\Controllers\Catalog\ProductController::class)
            ->saveAbsoluteOptions($productId, Request::create('/', 'POST', $data));
    }

    private function downloadImages(array $urls, string $itemId, string $suffix = ''): array
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
            $rel = 'catalog/import/lazada/' . $itemId . '/' . ($suffix !== '' ? $suffix . '-' : '') . $i . '.' . $img['ext'];
            Storage::disk('public')->put($rel, $img['body']);
            $paths[] = $rel;
        }

        return $paths;
    }
}
