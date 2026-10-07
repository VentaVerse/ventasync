<?php

namespace Extensions\ventacart\Services\VentaCart;

use Extensions\ventacart\Models\VentaCartListing;
use Extensions\ventacart\Models\VentaCartProductLink;
use Extensions\ventacart\Models\VentaCartSetting;
use Illuminate\Support\Facades\DB;

class VentaCartProductPush
{
    public function __construct(
        private VentaCartSetting $setting,
        private VentaCartClient $client,
    ) {
    }

    public static function for(VentaCartSetting $setting): self
    {
        return new self($setting, new VentaCartClient($setting));
    }

    public function product(int $productId): ?object
    {
        $pfx = (string) config('catalog.prefix');
        $langId = (int) config('catalog.default_language_id');

        return DB::table($pfx . 'product as p')
            ->join($pfx . 'product_description as pd', function ($j) use ($langId) {
                $j->on('p.product_id', '=', 'pd.product_id')->where('pd.language_id', '=', $langId);
            })
            ->leftJoin($pfx . 'manufacturer as m', 'p.manufacturer_id', '=', 'm.manufacturer_id')
            ->where('p.product_id', $productId)
            ->first([
                'p.product_id', 'pd.name', 'pd.description', 'pd.meta_title', 'pd.meta_description',
                'p.model', 'p.sku', 'p.price', 'p.cost', 'p.quantity', 'p.status', 'p.image',
                'p.weight', 'p.length', 'p.width', 'p.height',
                'm.name as brand_name',
            ]);
    }

    public static function skuOf(object $prod): string
    {
        return trim((string) ($prod->sku ?: $prod->model ?: ''));
    }

    public function candidateSkus(int $productId): array
    {
        $pfx = (string) config('catalog.prefix');
        $prod = DB::table($pfx . 'product')->where('product_id', $productId)->first(['sku', 'model']);

        if (! $prod) {
            return [];
        }

        $skus = [];
        foreach ([$prod->sku ?? '', $prod->model ?? ''] as $candidate) {
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
        $listing = VentaCartListing::query()->where('ventacart_setting_id', $this->setting->id)->where('product_id', $pid)->first();
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
            'description' => \App\Integrations\Listings\ListingContent::wrapDescription(
                (string) ($overrides['description'] ?? ($prod->description ?? '')),
                'ventacart',
                (int) $this->setting->id,
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
        $pfx = (string) config('catalog.prefix');
        $langId = (int) config('catalog.default_language_id');
        $pid = (int) $prod->product_id;

        $fallbackImage = trim((string) ($prod->image ?? '')) !== '' ? self::imageUrl((string) $prod->image) : null;
        $variantImage = fn (?string $path) => trim((string) $path) !== '' ? self::imageUrl((string) $path) : $fallbackImage;

        $combos = DB::table('product_option_combinations as poc')
            ->where('poc.product_id', $pid)
            ->orderBy('poc.sort_order')
            ->get();

        $hidden = \App\Integrations\Listings\ListingVariations::hidden('ventacart', (int) $this->setting->id, [$pid]);

        if ($combos->isNotEmpty()) {
            $comboValues = DB::table('product_option_combination_values as pocv')
                ->join($pfx . 'product_option_value as pov', 'pocv.product_option_value_id', '=', 'pov.product_option_value_id')
                ->join($pfx . 'option_value_description as ovd', function ($j) use ($langId) {
                    $j->on('pov.option_value_id', '=', 'ovd.option_value_id')->where('ovd.language_id', '=', $langId);
                })
                ->whereIn('pocv.combination_id', $combos->pluck('id')->toArray())
                ->select('pocv.combination_id', 'ovd.name as value_name')
                ->get()
                ->groupBy('combination_id');

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
                    'status'      => \App\Integrations\Listings\ListingVariations::allows($hidden, $pid, $c->sku),
                ];
            }

            return $variants;
        }

        $optionValues = DB::table($pfx . 'product_option_value as pov')
            ->join($pfx . 'option_value_description as ovd', function ($j) use ($langId) {
                $j->on('pov.option_value_id', '=', 'ovd.option_value_id')->where('ovd.language_id', '=', $langId);
            })
            ->join($pfx . 'option_description as od', function ($j) use ($langId) {
                $j->on('pov.option_id', '=', 'od.option_id')->where('od.language_id', '=', $langId);
            })
            ->where('pov.product_id', $pid)
            ->get([
                'pov.product_option_value_id', 'pov.sku as variant_sku',
                'pov.quantity as variant_qty', 'pov.price as variant_price',
                'pov.price_prefix', 'pov.absolute_price',
                'pov.cost as variant_cost', 'pov.cost_prefix', 'pov.absolute_cost',
                'ovd.name as option_value_name', 'od.name as option_name',
            ]);

        $variants = [];
        foreach ($optionValues as $ov) {
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
                'sku'         => $ov->variant_sku ?: ($sku . '-' . $ov->product_option_value_id),
                'name'        => $ov->option_value_name,
                'option_name' => $ov->option_name,
                'price'       => $price($variantPrice),
                'cost'        => $variantCost,
                'quantity'    => (int) $ov->variant_qty,
                'weight'      => $weight,
                'image'       => $variantImage(null),
                'status'      => \App\Integrations\Listings\ListingVariations::allows($hidden, $pid, (string) ($ov->variant_sku ?? '')),
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
        $pfx = (string) config('catalog.prefix');
        $langId = (int) config('catalog.default_language_id');
        $pid = (int) $prod->product_id;
        $sku = self::skuOf($prod);

        $combos = DB::table('product_option_combinations')
            ->where('product_id', $pid)
            ->orderBy('sort_order')
            ->get(['id', 'sku']);

        if ($combos->isEmpty()) {
            return [];
        }

        $values = DB::table('product_option_combination_values as pocv')
            ->join($pfx . 'product_option_value as pov', 'pocv.product_option_value_id', '=', 'pov.product_option_value_id')
            ->join($pfx . 'option_description as od', function ($j) use ($langId) {
                $j->on('pov.option_id', '=', 'od.option_id')->where('od.language_id', '=', $langId);
            })
            ->join($pfx . 'option_value_description as ovd', function ($j) use ($langId) {
                $j->on('pov.option_value_id', '=', 'ovd.option_value_id')->where('ovd.language_id', '=', $langId);
            })
            ->whereIn('pocv.combination_id', $combos->pluck('id')->all())
            ->get(['pocv.combination_id', 'pov.option_id', 'od.name as option_name', 'ovd.name as value_name'])
            ->groupBy('combination_id');

        if ($values->flatten(1)->pluck('option_id')->unique()->count() !== 1) {
            return [];
        }

        $names = [];
        foreach ($combos as $c) {
            $rows = $values->get($c->id);
            if (! $rows || $rows->count() !== 1) {
                return [];
            }
            $option = trim((string) $rows->first()->option_name);
            $value = trim((string) $rows->first()->value_name);
            if ($option === '' || $value === '') {
                return [];
            }
            $names[$c->sku ?: ($sku . '-' . $c->id)] = ['option_name' => $option, 'name' => $value];
        }

        return $names;
    }

    public function images(int $productId): array
    {
        $marking = VentaCartListing::query()->where('ventacart_setting_id', $this->setting->id)->where('product_id', $productId)
            ->first(['watermark_template_id', 'watermark_all_images']);
        $paths = \App\Services\Media\ListingWatermark::paths(
            \App\Support\Catalog\ProductImages::paths($productId, $this->ownImageOrder($productId)),
            $marking,
            \App\Services\Media\ListingWatermark::groupTemplateId('ventacart_product_groups', 'ventacart_product_group_products', 'ventacart_product_group_id', $productId, 'ventacart_setting_id', (int) $this->setting->id),
            'ventacart',
            (int) $this->setting->id
        );

        return array_map([\App\Support\Catalog\ProductImages::class, 'url'], \App\Services\Media\ImageCache::pushPaths($paths));
    }

    private function ownImageOrder(int $productId): ?array
    {
        return VentaCartListing::query()
            ->where('ventacart_setting_id', $this->setting->id)
            ->where('product_id', $productId)
            ->value('image_order');
    }

    public static function imageUrl(string $path): string
    {
        return \App\Services\Media\ImageCache::publicUrl(\App\Services\Media\ImageCache::path($path, \App\Services\Media\ImageCache::PUSH) ?? $path);
    }

    public function reconcileLink(int $productId): array
    {
        $store = (int) $this->setting->id;
        $link = VentaCartProductLink::where('ventacart_setting_id', $store)->where('product_id', $productId)->first();

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
        $store = (int) $this->setting->id;

        $conflict = VentaCartProductLink::where('ventacart_setting_id', $store)
            ->where('ventacart_product_id', $ventaCartProductId)
            ->where('product_id', '!=', $productId)
            ->first();

        if ($conflict) {
            return "VentaCart product {$ventaCartProductId} is already linked to catalog product "
                . "#{$conflict->product_id}. Unlink that product first, then try this one again.";
        }

        VentaCartProductLink::updateOrCreate(
            ['ventacart_setting_id' => $store, 'product_id' => $productId],
            ['ventacart_product_id' => $ventaCartProductId, 'sku' => $sku]
        );

        return null;
    }

    public function push(int $productId, array $overrides = [], ?callable $priceFor = null, ?int $categoryId = null): array
    {
        $prod = $this->product($productId);
        if (! $prod) {
            return self::outcome(false, 'failed', 'The catalog product no longer exists.');
        }

        if (\App\Integrations\Listings\ListingVariations::noneSold('ventacart', (int) $this->setting->id, $productId)) {
            return self::outcome(false, 'failed', \App\Integrations\Listings\ListingVariations::noneSoldMessage((string) $this->setting->store_name));
        }

        $readiness = VentaCartListingReadiness::forProducts([$productId])[$productId] ?? ['ready' => false, 'missing' => []];
        if (! $readiness['ready']) {
            return self::outcome(false, 'failed', \App\Integrations\Listings\CatalogGaps::refusal($readiness));
        }
        $sku = self::skuOf($prod);

        $data = $this->payload($prod, $overrides, $priceFor, $categoryId);

        $verdict = $this->reconcileLink($productId);
        if ($verdict['state'] === 'blocked' || $verdict['state'] === 'unreachable') {
            return self::outcome(false, $verdict['state'], (string) $verdict['message'], null, [], $data);
        }

        $link = VentaCartProductLink::where('ventacart_setting_id', $this->setting->id)->where('product_id', $productId)->first();

        if ($link && $link->ventacart_product_id) {
            $data = $this->withCatalogNames($prod, $data);
            $result = $this->client->updateProduct($sku, $data);
            if (! ($result['ok'] ?? false)) {
                return self::outcome(false, 'failed', self::failureText($result, $sku), (int) $link->ventacart_product_id, [], $data);
            }

            $this->setting->update(['last_product_sync_at' => now()]);
            $this->rememberAnswer($productId, $result);

            return self::outcome(true, 'updated','Updated on ' . $this->setting->store_name . '.', (int) $link->ventacart_product_id, self::imageVerdict($result), $data);
        }

        $result = $this->client->createProduct($data);
        if (! ($result['ok'] ?? false)) {
            return self::outcome(false, 'failed', self::failureText($result, $sku), null, [], $data);
        }

        $ventaCartId = (int) ($result['body']['id'] ?? $result['body']['data']['id'] ?? 0);
        if ($clash = $this->linkProductTo($productId, $ventaCartId, $sku)) {
            return self::outcome(false, 'blocked', "Created on the store as {$ventaCartId}, but {$clash}", $ventaCartId, self::imageVerdict($result), $data);
        }

        $this->setting->update(['last_product_sync_at' => now()]);
        $this->rememberAnswer($productId, $result);

        return self::outcome(true, 'created','Created on ' . $this->setting->store_name . '.', $ventaCartId, self::imageVerdict($result), $data);
    }

    public function rememberHeld(int $productId, iterable $skus): void
    {
        $pfx = (string) config('catalog.prefix');
        if ((\App\Integrations\Push\PushLedger::erpVariationSkus([$productId], $pfx)[$productId] ?? []) === []) {
            return;
        }
        $own = DB::table($pfx . 'product')->where('product_id', $productId)->first(['sku', 'model']);
        $parent = array_filter([strtolower(trim((string) ($own->sku ?? ''))), strtolower(trim((string) ($own->model ?? '')))]);
        $held = [];
        foreach ($skus as $sku) {
            $sku = trim((string) $sku);
            if ($sku !== '' && ! in_array(strtolower($sku), $parent, true)) {
                $held[] = $sku;
            }
        }
        \App\Integrations\Listings\ListingVariations::remember('ventacart', (int) $this->setting->id, $productId, $held);
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
            ? 'Relisted on ' . $this->setting->store_name . '. Buyers see it again.'
            : 'Unlisted on ' . $this->setting->store_name . '. Buyers stop seeing it; it can be relisted at any time.'];
    }

    public static function failureText(array $result, string $sku): string
    {
        $body = $result['body'] ?? [];
        $raw = json_encode($body);

        if (stripos($raw, 'images_unavailable') !== false) {
            return 'VentaCart will not create a product it cannot fetch images for, so nothing was listed. '
                . 'It reads images over the web from the address this ERP publishes, and that address is a '
                . 'private one, so the images never reach it. Nothing in the catalog needs fixing.';
        }

        if (stripos($raw, 'sku has already been taken') !== false) {
            return "VentaCart already has a product with SKU {$sku} that this catalog product is not linked to. "
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
