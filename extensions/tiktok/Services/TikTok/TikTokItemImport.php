<?php

namespace Extensions\tiktok\Services\TikTok;

use App\Support\MarketplaceAnswer;
use Extensions\tiktok\Models\TikTokListing;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Import a TikTok Shop product into the core catalog (#196), Shopee's
 * importer in TikTok's anatomy: core fields map to the core - name,
 * SKU, price, stock, parcel, description, images, variations from
 * sales attributes - TikTok-only details ride the product's tiktok
 * listing record (category, the seller_sku -> id map that stock and
 * price pushes need), and the link is the listing row itself.
 */
class TikTokItemImport
{
    public function __construct(private readonly TikTokClient $client)
    {
    }

    /** @return array{ok: bool, product_id?: int, message: string} */
    public function import(array $c, string $tiktokProductId): array
    {
        try {
            $r = $this->client->getProduct($c['app_key'], $c['app_secret'], $c['token'], $tiktokProductId, ($c['shop_cipher'] ?? null) ?: null);
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => 'TikTok Shop did not hand the product over: ' . \App\Support\TransportError::plain($e, 'TikTok Shop')];
        }
        $d = data_get($r, 'body.data');
        if (! is_array($d) || (int) data_get($r, 'body.code', -1) !== 0) {
            return ['ok' => false, 'message' => 'TikTok Shop did not hand the product over: ' . MarketplaceAnswer::errorText('TikTok Shop', is_array($r) ? $r : ['ok' => false, 'body' => []])];
        }

        $skus = array_values((array) ($d['skus'] ?? []));
        $first = (array) ($skus[0] ?? []);
        $name = trim((string) ($d['title'] ?? '')) ?: ('TikTok product ' . $tiktokProductId);
        $price = $this->priceOf($first);
        $qty = array_sum(array_map(fn ($s) => $this->qtyOf($s), $skus));
        $sellerSku = trim((string) ($first['seller_sku'] ?? ''));
        $hasVariations = count($skus) > 1;

        // The leaf of the category chain, or the bare id, whichever answers.
        $categoryId = (string) (collect((array) data_get($d, 'category_chains', []))
            ->firstWhere('is_leaf', true)['id'] ?? data_get($d, 'category_chains.0.id') ?? data_get($d, 'category_id') ?? '');

        $pfx = (string) config('catalog.prefix');
        $langId = (int) config('catalog.default_language_id');

        try {
            /* Ready the moment it lands (the owner, 2026-09-07): the category's
             * attribute sheet is read now, so readiness can check the answers
             * the product brought. A sheet TikTok will not hand over changes nothing. */
            if ($categoryId !== '') {
                try {
                    app(TikTokAttributes::class)->ensureTemplate($categoryId, $this->client, $c);
                } catch (\Throwable) {
                }
            }

            $productId = DB::transaction(function () use ($pfx, $langId, $d, $skus, $name, $price, $qty, $sellerSku, $hasVariations, $tiktokProductId, $categoryId) {
                $productId = DB::table($pfx . 'product')->insertGetId([
                    'model' => ($hasVariations ? '' : $sellerSku) ?: ('TTS-' . $tiktokProductId),
                    'sku' => $hasVariations ? '' : $sellerSku,
                    'quantity' => $qty,
                    'price' => $price,
                    // Live on TikTok Shop already; arriving disabled would misstate reality.
                    'status' => 1,
                    'image' => '',
                    'weight' => (float) (data_get($d, 'package_weight.value') ?? data_get($d, 'package_weight') ?? 0),
                    'length' => (float) (data_get($d, 'package_dimensions.length') ?? 0),
                    'width' => (float) (data_get($d, 'package_dimensions.width') ?? 0),
                    'height' => (float) (data_get($d, 'package_dimensions.height') ?? 0),
                    'manufacturer_id' => 0,
                    'date_added' => now(), 'date_modified' => now(), 'date_available' => now(),
                ]);

                DB::table($pfx . 'product_description')->insert([
                    'product_id' => $productId,
                    'language_id' => $langId,
                    'name' => Str::limit($name, 250, ''),
                    'description' => \App\Support\HtmlSanitizer::sanitize((string) ($d['description'] ?? '')),
                    'meta_title' => Str::limit($name, 250, ''),
                    'meta_description' => '', 'meta_keyword' => '', 'tag' => '',
                ]);

                $imageUrls = collect((array) data_get($d, 'main_images', []))
                    ->map(fn ($img) => (string) (data_get($img, 'urls.0') ?? data_get($img, 'url') ?? ''))
                    ->filter()->values()->all();
                $paths = $this->downloadImages($imageUrls, $tiktokProductId);
                if ($paths !== []) {
                    DB::table($pfx . 'product')->where('product_id', $productId)->update(['image' => $paths[0]]);
                    foreach (array_slice($paths, 1) as $i => $path) {
                        DB::table($pfx . 'product_image')->insert([
                            'product_id' => $productId, 'image' => $path, 'sort_order' => $i,
                        ]);
                    }
                }

                if ($hasVariations) {
                    $this->writeVariations($productId, $skus, $tiktokProductId);
                }

                /* TikTok-only details ride the listing record: the category,
                 * the title, and the seller_sku -> id map every stock and
                 * price push reads. The listing row IS the link on this
                 * channel, so the import is listed the moment it lands. */
                $skuMap = [];
                foreach ($skus as $s) {
                    $ss = trim((string) ($s['seller_sku'] ?? ''));
                    $id = (string) ($s['id'] ?? '');
                    if ($id !== '') {
                        $skuMap[$ss !== '' ? $ss : '__single__'] = $id;
                    }
                }
                TikTokListing::updateOrCreate(
                    ['product_id' => $productId],
                    [
                        'tiktok_product_id' => $tiktokProductId,
                        'tiktok_sku_id' => $skuMap !== [] ? json_encode($skuMap) : null,
                        'tiktok_category_id' => $categoryId !== '' ? $categoryId : null,
                        // The brand and the attribute answers the product carries, keyed the
                        // way the sheet keys them (attribute id => value name), so the listing
                        // is ready at once and the next Update sends them back unchanged.
                        'brand_id' => trim((string) data_get($d, 'brand.id', '')) ?: null,
                        'brand_name' => trim((string) data_get($d, 'brand.name', '')) ?: null,
                        'attribute_values' => collect((array) ($d['product_attributes'] ?? []))
                            ->filter(fn ($a) => is_array($a) && trim((string) ($a['id'] ?? '')) !== '')
                            ->mapWithKeys(fn ($a) => [(string) $a['id'] => trim((string) (data_get($a, 'values.0.name') ?? ''))])
                            ->filter(fn ($v) => $v !== '')
                            ->toArray() ?: null,
                        'title' => Str::limit($name, 250, ''),
                        'live_status' => strtoupper((string) ($d['status'] ?? '')) ?: null,
                        'live_checked_at' => now(),
                    ]
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
                . ($hasVariations ? ' with ' . count($skus) . ' variations' : '')
                . ', linked to TikTok Shop product ' . $tiktokProductId . '.',
        ];
    }

    private function priceOf(array $sku): float
    {
        foreach (['price.sale_price', 'price.tax_exclusive_price', 'price.amount', 'price.original_price'] as $key) {
            $v = data_get($sku, $key);
            if ($v !== null && (float) $v > 0) {
                return (float) $v;
            }
        }

        return 0.0;
    }

    private function qtyOf(array $sku): int
    {
        return (int) array_sum(array_map(fn ($inv) => (int) ($inv['quantity'] ?? 0), (array) ($sku['inventory'] ?? [])));
    }

    /** Variations from sales_attributes: one or two axes, the form writer's shapes. */
    private function writeVariations(int $productId, array $skus, string $tiktokProductId): void
    {
        $axes = [];
        foreach ($skus as $s) {
            foreach ((array) ($s['sales_attributes'] ?? []) as $a) {
                $axis = trim((string) ($a['name'] ?? ''));
                if ($axis !== '' && ! in_array($axis, $axes, true)) {
                    $axes[] = $axis;
                }
            }
        }
        $axes = array_slice($axes, 0, 2) ?: ['Variation'];
        $valueOf = fn (array $s, string $axis) => trim((string) (collect((array) ($s['sales_attributes'] ?? []))
            ->first(fn ($a) => trim((string) ($a['name'] ?? '')) === $axis)['value_name'] ?? ''));

        $rowFor = fn (array $s) => [
            'sku' => trim((string) ($s['seller_sku'] ?? '')),
            'status' => 1,
            'quantity' => $this->qtyOf($s),
            'absolute_price' => $this->priceOf($s),
            'cost_amount' => 0, 'cost_additional' => 0,
            'image' => '',
        ];

        $data = ['sku' => ''];
        if (count($axes) === 2) {
            $uniq = fn (string $axis) => array_values(array_unique(array_filter(array_map(fn ($s) => $valueOf($s, $axis), $skus))));
            $data['option1_name'] = $axes[0];
            $data['option2_name'] = $axes[1];
            $data['option1_values'] = $uniq($axes[0]);
            $data['option2_values'] = $uniq($axes[1]);
            $data['combinations'] = array_map(fn ($s) => $rowFor($s) + [
                'opt1' => $valueOf($s, $axes[0]),
                'opt2' => $valueOf($s, $axes[1]),
            ], $skus);
        } else {
            $data['option_name'] = $axes[0];
            $data['values'] = array_map(function ($s, $i) use ($rowFor, $valueOf, $axes) {
                return $rowFor($s) + ['name' => $valueOf($s, $axes[0]) ?: trim((string) ($s['seller_sku'] ?? '')) ?: ('Variation ' . ($i + 1))];
            }, $skus, array_keys($skus));
        }

        app(\App\Http\Controllers\Catalog\ProductController::class)
            ->saveAbsoluteOptions($productId, Request::create('/', 'POST', $data));
    }

    /** @return list<string> public-disk relative paths, in the order given */
    private function downloadImages(array $urls, string $productId): array
    {
        $paths = [];
        foreach (array_values($urls) as $i => $url) {
            $url = trim((string) $url);
            if ($url === '' || ! preg_match('#^https://#i', $url)) {
                continue;
            }
            /* The shared SSRF guard (App\Support\RemoteImage,
             * 2026-09-04): the URL is the channel's data, so it is
             * https-only, resolved-and-checked against private ranges,
             * and never allowed to redirect. */
            $img = \App\Support\RemoteImage::fetch($url);
            if ($img === null) {
                continue; // a photo is never a reason to fail an import
            }
            $rel = 'catalog/import/tiktok/' . $productId . '/' . $i . '.' . $img['ext'];
            Storage::disk('public')->put($rel, $img['body']);
            $paths[] = $rel;
        }

        return $paths;
    }
}
