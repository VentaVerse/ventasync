<?php

namespace Extensions\lazada\Services\Lazada;

use Extensions\lazada\Controllers\LazadaProductController;
use Extensions\lazada\Models\LazadaCategoryTemplate;
use Extensions\lazada\Models\LazadaProductAttribute;
use Extensions\lazada\Models\LazadaSetting;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Can this listing's push succeed? (#101, Lazada half.) Answered from the
 * ERP's own records before anything is pressed, mirroring exactly what the
 * payload builder refuses at push time: category, brand, catalog SKU and
 * image, and the category template's required attributes. One computation
 * for the index rows, the readiness filter and the bulk upload's refusal.
 *
 * Honesty rule, same as Shopee's: a category whose template has never been
 * read is NOT ready - opening the listing reads and caches it.
 */
class LazadaListingReadiness
{
    /**
     * @param Collection $listings LazadaProduct rows
     * @return array<int, array{ready: bool, missing: array<string>}> keyed by listing id
     */
    public function forListings(Collection $listings): array
    {
        if ($listings->isEmpty()) {
            return [];
        }

        $pfx = (string) config('catalog.prefix');
        $region = (string) (LazadaSetting::query()->first()?->region ?? '');
        $controller = app(LazadaProductController::class);

        $products = DB::table($pfx . 'product')
            ->whereIn('product_id', $listings->pluck('product_id')->filter()->all())
            ->get(['product_id', 'sku', 'model', 'image'])
            ->keyBy('product_id');

        $templates = LazadaCategoryTemplate::query()
            ->where('region', $region)
            ->whereIn('primary_category_id', $listings->pluck('primary_category_id')->filter()->unique()->all() ?: [0])
            ->get()
            ->keyBy('primary_category_id');

        $savedAttrs = LazadaProductAttribute::query()
            ->whereIn('lazada_product_id', $listings->pluck('id')->all())
            ->get()
            ->groupBy('lazada_product_id');

        $out = [];
        foreach ($listings as $listing) {
            $missing = [];
            $product = $products->get((int) $listing->product_id);

            if (!$listing->primary_category_id) {
                $missing[] = 'a Lazada category';
            }

            /* Brand is not demanded here: the payload builder defaults an
             * unchosen brand to "No Brand", exactly as Shopee's does, so a
             * listing without one pushes fine. Demanding it anyway made a
             * pushable listing read "Not ready" (#175). */

            if (!$product || (trim((string) $product->sku) === '' && trim((string) $product->model) === '')) {
                $missing[] = 'a SKU on the catalog product';
            }
            if (!$product || trim((string) $product->image) === '') {
                $missing[] = 'a product image on the catalog product';
            }

            if ($listing->primary_category_id) {
                $template = $templates->get((int) $listing->primary_category_id);
                if (!$template || !$template->template_body) {
                    $missing[] = "its category's attribute template (open the listing once to read it)";
                } else {
                    $answered = ($savedAttrs->get($listing->id) ?? collect())
                        ->pluck('value', 'attribute_key');
                    $unanswered = [];
                    foreach ($controller->extractAttributes($template->template_body) as $attr) {
                        if (empty($attr['required'])) {
                            continue;
                        }
                        $key = (string) ($attr['key'] ?? '');
                        if ($key === '' || $controller->isSkuLevelRequiredKey($key)) {
                            continue;
                        }
                        if (strtolower($key) === 'brand' || LazadaProductController::isAutoFilledAttributeKey($key)) {
                            // Brand defaults to No Brand; name, description
                            // and friends fill from the catalogue at push.
                            continue;
                        }
                        if (trim((string) ($answered[$key] ?? '')) === '') {
                            $unanswered[] = (string) ($attr['name'] ?? $key);
                        }
                    }
                    if (!empty($unanswered)) {
                        $missing[] = 'answers for ' . count($unanswered) . ' required '
                            . \Illuminate\Support\Str::plural('attribute', count($unanswered))
                            . ' (' . implode(', ', array_slice($unanswered, 0, 5))
                            . (count($unanswered) > 5 ? ' and more' : '') . ')';
                    }
                }
            }

            $out[(int) $listing->id] = ['ready' => empty($missing), 'missing' => $missing];
        }

        return $out;
    }
}
