<?php

namespace Extensions\VentaCart\Services\VentaCart;

use Extensions\VentaCart\Models\VentaCartListing;
use Extensions\VentaCart\Models\VentaCartListingLink;
use Extensions\VentaCart\Models\VentaCartStore;
use Illuminate\Support\Facades\DB;

class VentaCartProductPush
{
    public function __construct(
        private VentaCartStore $store,
        private VentaCartClient $client,
    ) {
    }

    public static function for(VentaCartStore $store): self
    {
        return new self($store, new VentaCartClient($store));
    }

    public function product(int $productId): ?object
    {
        $langId = (int) config('catalog.default_language_id');

        return DB::table('products as p')
            ->join('product_descriptions as pd', function ($j) use ($langId) {
                $j->on('p.product_id', '=', 'pd.product_id')->where('pd.language_id', '=', $langId);
            })
            ->leftJoin('brands as m', 'p.brand_id', '=', 'm.brand_id')
            ->where('p.product_id', $productId)
            ->first([
                'p.product_id', 'pd.name', 'pd.description', 'pd.meta_title', 'pd.meta_description',
                'p.sku', 'p.price', 'p.cost', 'p.quantity', 'p.status', 'p.image',
                'p.weight', 'p.length', 'p.width', 'p.height',
                'm.name as brand_name',
            ]);
    }

    public static function skuOf(object $prod): string
    {
        return trim((string) ($prod->sku ?: ''));
    }

    public function candidateSkus(int $productId): array
    {
        $prod = DB::table('products')->where('product_id', $productId)->first(['sku']);

        if (! $prod) {
            return [];
        }

        $skus = [];
        foreach ([$prod->sku ?? ''] as $candidate) {
            $candidate = trim((string) $candidate);
            if ($candidate !== '' && ! in_array($candidate, $skus, true)) {
                $skus[] = $candidate;
            }
        }

        return $skus;
    }

    public function payload(object $prod, array $overrides = [], ?callable $priceFor = null, ?int $categoryId = null): array
    {
        $pid = (int) $prod->product_id;
        $sku = self::skuOf($prod);
        $price = fn (float $base) => $priceFor ? round((float) $priceFor($base), 2) : $base;
        $listing = VentaCartListing::query()->where('store_id', $this->store->id)->where('product_id', $pid)->first();
        $start = VentaCartListing::startFor($listing, (float) $prod->price, VentaCartListing::withVariations([$pid]) !== []);
        $parcel = VentaCartListing::parcelFor($listing, $prod);

        $data = [
            'name'        => $overrides['name'] ?? $prod->name,
            'sku'         => $sku,
            'price'       => $price($start),
            'cost'        => (float) ($prod->cost ?? 0),
            'quantity'    => (int) $prod->quantity,
            'weight'      => $parcel['weight'],
            'length'      => $parcel['length'],
            'width'       => $parcel['width'],
            'height'      => $parcel['height'],
            'status'      => (bool) $prod->status,
            'description' => \App\Channels\Listings\ListingContent::wrapDescription(
                (string) ($overrides['description'] ?? ($prod->description ?? '')),
                'ventacart',
                (int) $this->store->id,
                $listing
            ),
        ];

        foreach (['meta_title', 'meta_description'] as $meta) {
            $value = $overrides[$meta] ?? ($prod->{$meta} ?? null);
            if ($value !== null && trim((string) $value) !== '') {
                $data[$meta] = (string) $value;
            }
        }

        if (! empty($prod->brand_name)) {
            $data['brand_name'] = $prod->brand_name;
        }

        if ($categoryId) {
            $data['category_ids'] = [$categoryId];
        } elseif ($categoryId === 0) {
            $data['category_ids'] = [];
        }

        $images = $this->images($pid);
        if (! empty($images)) {
            $data['images'] = $images;
        }

        $variants = $this->variants($prod, $sku, $price, $parcel['weight']);
        if ($variants !== []) {
            $data['variants'] = $variants;
        }

        return $data;
    }

    private function variants(object $prod, string $sku, callable $price, float $weight): array
    {
        $langId = (int) config('catalog.default_language_id');
        $pid = (int) $prod->product_id;

        $fallbackImage = trim((string) ($prod->image ?? '')) !== '' ? self::imageUrl((string) $prod->image) : null;
        $variantImage = fn (?string $path) => trim((string) $path) !== '' ? self::imageUrl((string) $path) : $fallbackImage;

        $combos = DB::table('product_variations as poc')
            ->where('poc.product_id', $pid)
            ->orderBy('poc.sort_order')
            ->get();

        $hidden = \App\Channels\Listings\ListingVariations::hidden('ventacart', (int) $this->store->id, [$pid]);

        if ($combos->isNotEmpty()) {
            $comboValues = DB::table('product_variation_parts as pocv')
                ->join('product_variation_values as pov', 'pocv.product_variation_value_id', '=', 'pov.product_variation_value_id')
                ->join('variation_value_descriptions as ovd', function ($j) use ($langId) {
                    $j->on('pov.variation_value_id', '=', 'ovd.variation_value_id')->where('ovd.language_id', '=', $langId);
                })
                ->whereIn('pocv.product_variation_id', $combos->pluck('id')->toArray())
                ->select('pocv.product_variation_id', 'ovd.name as value_name')
                ->get()
                ->groupBy('product_variation_id');

            $variants = [];
            foreach ($combos as $c) {
                $valueNames = ($comboValues->get($c->id) ?? collect())->pluck('value_name')->implode(' / ');
                $variants[] = [
                    'sku'         => $c->sku ?: ($sku . '-' . $c->id),
                    'name'        => $valueNames,
                    'option_name' => 'Option',
                    'price'       => $price((float) $c->absolute_price),
                    'cost'        => (float) $c->absolute_cost,
                    'quantity'    => (int) $c->quantity,
                    'weight'      => $weight,
                    'image'       => $variantImage($c->image ?? null),
                    'status'      => \App\Channels\Listings\ListingVariations::allows($hidden, $pid, $c->sku),
                ];
            }

            return $variants;
        }

        $variationValues = DB::table('product_variation_values as pov')
            ->join('variation_value_descriptions as ovd', function ($j) use ($langId) {
                $j->on('pov.variation_value_id', '=', 'ovd.variation_value_id')->where('ovd.language_id', '=', $langId);
            })
            ->join('variation_type_descriptions as od', function ($j) use ($langId) {
                $j->on('pov.variation_type_id', '=', 'od.variation_type_id')->where('od.language_id', '=', $langId);
            })
            ->where('pov.product_id', $pid)
            ->get([
                'pov.product_variation_value_id', 'pov.sku as variant_sku',
                'pov.quantity as variant_qty', 'pov.price as variant_price',
                'pov.price_prefix', 'pov.absolute_price',
                'pov.cost as variant_cost', 'pov.cost_prefix', 'pov.absolute_cost',
                'ovd.name as variation_value_name', 'od.name as variation_type_name',
            ]);

        $variants = [];
        foreach ($variationValues as $ov) {
            if ((float) ($ov->absolute_price ?? 0) > 0) {
                $variantPrice = (float) $ov->absolute_price;
            } else {
                $variantPrice = (float) $prod->price;
                if ($ov->variant_price) {
                    $variantPrice = $ov->price_prefix === '-'
                        ? $variantPrice - (float) $ov->variant_price
                        : $variantPrice + (float) $ov->variant_price;
                }
            }

            if ((float) ($ov->absolute_cost ?? 0) > 0) {
                $variantCost = (float) $ov->absolute_cost;
            } else {
                $variantCost = (float) ($prod->cost ?? 0);
                if ($ov->variant_cost) {
                    $variantCost = $ov->cost_prefix === '-'
                        ? $variantCost - (float) $ov->variant_cost
                        : $variantCost + (float) $ov->variant_cost;
                }
            }

            $variants[] = [
                'sku'         => $ov->variant_sku ?: ($sku . '-' . $ov->product_variation_value_id),
                'name'        => $ov->variation_value_name,
                'option_name' => $ov->variation_type_name,
                'price'       => $price($variantPrice),
                'cost'        => $variantCost,
                'quantity'    => (int) $ov->variant_qty,
                'weight'      => $weight,
                'image'       => $variantImage(null),
                'status'      => \App\Channels\Listings\ListingVariations::allows($hidden, $pid, (string) ($ov->variant_sku ?? '')),
            ];
        }

        return $variants;
    }

    public function withCatalogNames(object $prod, array $data): array
    {
        if (empty($data['variants']) || ! is_array($data['variants'])) {
            return $data;
        }

        $names = $this->catalogVariationNames($prod);
        if ($names === []) {
            return $data;
        }

        foreach ($data['variants'] as $variant) {
            if (! isset($names[(string) ($variant['sku'] ?? '')])) {
                return $data;
            }
        }

        foreach ($data['variants'] as $i => $variant) {
            $data['variants'][$i] = array_merge($variant, $names[(string) $variant['sku']]);
        }

        return $data;
    }

    private function catalogVariationNames(object $prod): array
    {
        $langId = (int) config('catalog.default_language_id');
        $pid = (int) $prod->product_id;
        $sku = self::skuOf($prod);

        $combos = DB::table('product_variations')
            ->where('product_id', $pid)
            ->orderBy('sort_order')
            ->get(['id', 'sku']);

        if ($combos->isEmpty()) {
            return [];
        }

        $values = DB::table('product_variation_parts as pocv')
            ->join('product_variation_values as pov', 'pocv.product_variation_value_id', '=', 'pov.product_variation_value_id')
            ->join('variation_type_descriptions as od', function ($j) use ($langId) {
                $j->on('pov.variation_type_id', '=', 'od.variation_type_id')->where('od.language_id', '=', $langId);
            })
            ->join('variation_value_descriptions as ovd', function ($j) use ($langId) {
                $j->on('pov.variation_value_id', '=', 'ovd.variation_value_id')->where('ovd.language_id', '=', $langId);
            })
            ->whereIn('pocv.product_variation_id', $combos->pluck('id')->all())
            ->get(['pocv.product_variation_id', 'pov.variation_type_id', 'od.name as variation_type_name', 'ovd.name as value_name'])
            ->groupBy('product_variation_id');

        if ($values->flatten(1)->pluck('variation_type_id')->unique()->count() !== 1) {
            return [];
        }

        $names = [];
        foreach ($combos as $c) {
            $rows = $values->get($c->id);
            if (! $rows || $rows->count() !== 1) {
                return [];
            }
            $typeName = trim((string) $rows->first()->variation_type_name);
            $value = trim((string) $rows->first()->value_name);
            if ($typeName === '' || $value === '') {
                return [];
            }
            $names[$c->sku ?: ($sku . '-' . $c->id)] = ['option_name' => $typeName, 'name' => $value];
        }

        return $names;
    }

    public function images(int $productId): array
    {
        $marking = VentaCartListing::query()->where('store_id', $this->store->id)->where('product_id', $productId)
            ->first(['watermark_template_id', 'watermark_all_images']);
        $paths = \App\Services\Media\ListingWatermark::paths(
            \App\Support\Catalog\ProductImages::paths($productId, $this->ownImageOrder($productId)),
            $marking,
            'ventacart',
            (int) $this->store->id
        );

        return array_map([\App\Support\Catalog\ProductImages::class, 'url'], \App\Services\Media\ImageCache::pushPaths($paths));
    }

    private function ownImageOrder(int $productId): ?array
    {
        return VentaCartListing::query()
            ->where('store_id', $this->store->id)
            ->where('product_id', $productId)
            ->value('image_order');
    }

    public static function imageUrl(string $path): string
    {
        return \App\Services\Media\ImageCache::publicUrl(\App\Services\Media\ImageCache::path($path, \App\Services\Media\ImageCache::PUSH) ?? $path);
    }

    public function reconcileLink(int $productId): array
    {
        $store = (int) $this->store->id;
        $link = VentaCartListingLink::where('store_id', $store)->where('product_id', $productId)->first();

        $found = null;
        $foundSku = null;
        $foundVariants = null;
        $denied = null;

        foreach ($this->candidateSkus($productId) as $sku) {
            $result = $this->client->get("products/{$sku}");
            $status = (int) ($result['status'] ?? 0);

            if ($result['ok'] ?? false) {
                $body = $result['body']['data'] ?? $result['body'] ?? [];
                $id = (int) ($body['id'] ?? 0);
                if ($id > 0) {
                    $found = $id;
                    $foundSku = $sku;
                    $foundVariants = is_array($body['variants'] ?? null) ? array_column($body['variants'], 'sku') : null;
                    break;
                }
                continue;
            }

            if ($status !== 404) {
                $denied = $status === 0
                    ? 'VentaCart could not be reached, so nothing was checked or changed.'
                    : "VentaCart answered {$status} instead of saying whether the product exists, so nothing was changed.";
            }
        }

        if ($found === null && $denied !== null) {
            return ['state' => 'unreachable', 'ventacart_id' => null, 'sku' => null, 'message' => $denied];
        }

        if ($found === null) {
            if ($link) {
                $link->delete();

                return ['state' => 'lost', 'ventacart_id' => null, 'sku' => null, 'message' => null];
            }

            return ['state' => 'new', 'ventacart_id' => null, 'sku' => null, 'message' => null];
        }

        if ($link && (int) $link->ventacart_product_id === $found) {
            if ($foundVariants !== null) {
                $this->rememberHeld($productId, $foundVariants);
            }

            return ['state' => 'confirmed', 'ventacart_id' => $found, 'sku' => $foundSku, 'message' => null];
        }

        $wasPointingElsewhere = $link !== null;

        if ($clash = $this->linkProductTo($productId, $found, $foundSku)) {
            return ['state' => 'blocked', 'ventacart_id' => $found, 'sku' => $foundSku, 'message' => $clash];
        }
        if ($foundVariants !== null) {
            $this->rememberHeld($productId, $foundVariants);
        }

        return [
            'state' => $wasPointingElsewhere ? 'repointed' : 'adopted',
            'ventacart_id' => $found,
            'sku' => $foundSku,
            'message' => null,
        ];
    }

    public function linkProductTo(int $productId, $ventaCartProductId, ?string $sku): ?string
    {
        $store = (int) $this->store->id;

        $conflict = VentaCartListingLink::where('store_id', $store)
            ->where('ventacart_product_id', $ventaCartProductId)
            ->where('product_id', '!=', $productId)
            ->first();

        if ($conflict) {
            return "VentaCart product {$ventaCartProductId} is already linked to Master Catalog product "
                . "#{$conflict->product_id}. Unlink that product first, then try this one again.";
        }

        VentaCartListingLink::updateOrCreate(
            ['store_id' => $store, 'product_id' => $productId],
            ['ventacart_product_id' => $ventaCartProductId, 'sku' => $sku]
        );

        return null;
    }

    public function push(int $productId, array $overrides = [], ?callable $priceFor = null, ?int $categoryId = null): array
    {
        $prod = $this->product($productId);
        if (! $prod) {
            return self::outcome(false, 'failed', 'The Master Catalog product no longer exists.');
        }

        if (\App\Channels\Listings\ListingVariations::noneSold('ventacart', (int) $this->store->id, $productId)) {
            return self::outcome(false, 'failed', \App\Channels\Listings\ListingVariations::noneSoldMessage((string) $this->store->store_name));
        }

        $readiness = VentaCartListingReadiness::forProducts([$productId])[$productId] ?? ['ready' => false, 'missing' => []];
        if (! $readiness['ready']) {
            return self::outcome(false, 'failed', \App\Channels\Listings\CatalogGaps::refusal($readiness));
        }
        $sku = self::skuOf($prod);

        $data = $this->payload($prod, $overrides, $priceFor, $categoryId);

        $verdict = $this->reconcileLink($productId);
        if ($verdict['state'] === 'blocked' || $verdict['state'] === 'unreachable') {
            return self::outcome(false, $verdict['state'], (string) $verdict['message'], null, [], $data);
        }

        $link = VentaCartListingLink::where('store_id', $this->store->id)->where('product_id', $productId)->first();

        if ($link && $link->ventacart_product_id) {
            $data = $this->withCatalogNames($prod, $data);
            $result = $this->client->updateProduct($sku, $data);
            if (! ($result['ok'] ?? false)) {
                return self::outcome(false, 'failed', self::failureText($result, $sku), (int) $link->ventacart_product_id, [], $data);
            }

            $this->store->update(['last_product_sync_at' => now()]);
            $this->rememberAnswer($productId, $result);

            return self::outcome(true, 'updated','Updated on ' . $this->store->store_name . '.', (int) $link->ventacart_product_id, self::imageVerdict($result), $data);
        }

        $result = $this->client->createProduct($data);
        if (! ($result['ok'] ?? false)) {
            return self::outcome(false, 'failed', self::failureText($result, $sku), null, [], $data);
        }

        $ventaCartId = (int) ($result['body']['id'] ?? $result['body']['data']['id'] ?? 0);
        if ($clash = $this->linkProductTo($productId, $ventaCartId, $sku)) {
            return self::outcome(false, 'blocked', "Created on the store as {$ventaCartId}, but {$clash}", $ventaCartId, self::imageVerdict($result), $data);
        }

        $this->store->update(['last_product_sync_at' => now()]);
        $this->rememberAnswer($productId, $result);

        return self::outcome(true, 'created','Created on ' . $this->store->store_name . '.', $ventaCartId, self::imageVerdict($result), $data);
    }

    public function rememberHeld(int $productId, iterable $skus): void
    {
        if ((\App\Channels\Push\PushLedger::catalogVariationSkus([$productId])[$productId] ?? []) === []) {
            return;
        }
        $own = DB::table('products')->where('product_id', $productId)->first(['sku']);
        $parent = array_filter([strtolower(trim((string) ($own->sku ?? '')))]);
        $held = [];
        foreach ($skus as $sku) {
            $sku = trim((string) $sku);
            if ($sku !== '' && ! in_array(strtolower($sku), $parent, true)) {
                $held[] = $sku;
            }
        }
        \App\Channels\Listings\ListingVariations::remember('ventacart', (int) $this->store->id, $productId, $held);
    }

    private function rememberAnswer(int $productId, array $result): void
    {
        $body = $result['body'] ?? [];
        $body = is_array($body) ? ($body['data'] ?? $body) : [];
        if (is_array($body['variants'] ?? null)) {
            $this->rememberHeld($productId, array_column($body['variants'], 'sku'));
        } elseif (array_key_exists('has_variants', $body) && ! $body['has_variants']) {
            $this->rememberHeld($productId, []);
        }
    }

    public function read(int $productId): array
    {
        $none = ['state' => 'missing', 'message' => null, 'ventacart_id' => null, 'sku' => null,
            'status' => VentaCartListing::STATUS_MISSING, 'name' => '', 'price' => null, 'quantity' => null, 'images' => 0, 'variants' => []];

        $denied = null;
        foreach ($this->candidateSkus($productId) as $sku) {
            $result = $this->client->get("products/{$sku}");
            $status = (int) ($result['status'] ?? 0);

            if ($result['ok'] ?? false) {
                $body = $result['body']['data'] ?? $result['body'] ?? [];
                if ((int) ($body['id'] ?? 0) <= 0) {
                    continue;
                }

                $variants = [];
                foreach ((array) ($body['variants'] ?? []) as $v) {
                    $label = collect((array) ($v['option_values'] ?? []))->pluck('value')->filter()->implode(' / ');
                    $variants[] = [
                        'sku' => (string) ($v['sku'] ?? ''),
                        'name' => $label !== '' ? $label : (string) ($v['name'] ?? ''),
                        'price' => isset($v['price']) ? (float) $v['price'] : null,
                        'quantity' => isset($v['quantity']) ? (int) $v['quantity'] : null,
                        'active' => (bool) ($v['is_active'] ?? true),
                    ];
                }

                return [
                    'state' => 'found',
                    'message' => null,
                    'ventacart_id' => (int) $body['id'],
                    'sku' => $sku,
                    'status' => array_key_exists('status', $body)
                        ? ($body['status'] ? VentaCartListing::STATUS_ACTIVE : VentaCartListing::STATUS_INACTIVE)
                        : null,
                    'name' => (string) ($body['name'] ?? ''),
                    'price' => isset($body['price']) ? (float) $body['price'] : null,
                    'quantity' => isset($body['quantity']) ? (int) $body['quantity'] : null,
                    'images' => is_array($body['images'] ?? null) ? count($body['images']) : 0,
                    'variants' => $variants,
                ];
            }

            if ($status !== 404) {
                $denied = $status === 0
                    ? 'The store could not be reached.'
                    : self::failureText($result, $sku);
            }
        }

        if ($denied !== null) {
            return ['state' => 'unreachable', 'message' => $denied, 'status' => null] + $none;
        }

        return $none;
    }

    public function setActive(int $productId, bool $active): array
    {
        $skus = $this->candidateSkus($productId);
        if ($skus === []) {
            return ['ok' => false, 'message' => 'This product has no SKU, so the store cannot find it.'];
        }

        $result = $this->client->updateProduct($skus[0], ['status' => $active]);
        if (! ($result['ok'] ?? false)) {
            return ['ok' => false, 'message' => self::failureText($result, $skus[0])];
        }

        return ['ok' => true, 'message' => $active
            ? 'Relisted on ' . $this->store->store_name . '. Customers see it again.'
            : 'Unlisted on ' . $this->store->store_name . '. Customers stop seeing it; it can be relisted at any time.'];
    }

    public static function failureText(array $result, string $sku): string
    {
        $body = $result['body'] ?? [];
        $raw = json_encode($body);

        if (stripos($raw, 'images_unavailable') !== false) {
            return 'VentaCart will not create a product it cannot fetch images for, so nothing was listed. '
                . 'It reads images over the web from the address VentaSync publishes, and that address is a '
                . 'private one, so the images never reach it. Nothing in the Master Catalog needs fixing.';
        }

        if (stripos($raw, 'sku has already been taken') !== false) {
            return "VentaCart already has a product with SKU {$sku} that this Master Catalog product is not linked to. "
                . 'Press Send again and it will find that product and link to it.';
        }

        if (is_array($body)) {
            foreach (['error', 'message'] as $k) {
                if (is_string($body[$k] ?? null) && trim($body[$k]) !== '') {
                    return trim($body[$k]);
                }
            }
        }

        return $raw ?: 'The store did not answer.';
    }

    public static function imageVerdict(array $result): array
    {
        $body = $result['body'] ?? [];
        $body = is_array($body) ? ($body['data'] ?? $body) : [];

        $kept = $body['images'] ?? null;
        $failed = $body['images_failed'] ?? null;

        if ($kept === null && $failed === null) {
            return ['sent' => 0, 'stored' => 0, 'reasons' => []];
        }

        $keptCount = is_array($kept) ? count($kept) : (int) $kept;
        $failedList = is_array($failed) ? $failed : [];
        $reasons = [];

        foreach ($failedList as $entry) {
            $reason = is_array($entry) ? trim((string) ($entry['reason'] ?? 'no reason given')) : trim((string) $entry);
            if ($reason === '') {
                $reason = 'no reason given';
            }
            $reasons[$reason] = ($reasons[$reason] ?? 0) + 1;
        }

        return ['sent' => $keptCount + count($failedList), 'stored' => $keptCount, 'reasons' => $reasons];
    }

    public static function imagesSentence(array $images): string
    {
        $sent = (int) ($images['sent'] ?? 0);
        $stored = (int) ($images['stored'] ?? 0);

        if ($sent === 0) {
            return '';
        }
        if ($stored >= $sent) {
            return "{$stored} images stored.";
        }

        $reasons = $images['reasons'] ?? [];
        arsort($reasons);
        $lines = [];
        foreach (array_slice($reasons, 0, 2, true) as $reason => $count) {
            $lines[] = "{$count}x {$reason}";
        }

        return ($sent - $stored) . " of {$sent} images were rejected by the store: " . implode('; ', $lines) . '.';
    }

    private static function outcome(bool $ok, string $state, string $message, ?int $ventaCartId = null, array $images = [], array $payload = []): array
    {
        return [
            'ok' => $ok,
            'state' => $state,
            'message' => $message,
            'ventacart_id' => $ventaCartId,
            'images' => $images + ['sent' => 0, 'stored' => 0, 'reasons' => []],
            'payload' => $payload,
        ];
    }
}
