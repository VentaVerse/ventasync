<?php

namespace Extensions\venta\Services\Venta;

use Extensions\venta\Models\VentaListing;
use Extensions\venta\Models\VentaProductLink;
use Extensions\venta\Models\VentaSetting;
use Illuminate\Support\Facades\DB;

/**
 * One product to one Venta store: what is sent, how the link is kept
 * honest, and what the store says it holds (#171).
 *
 * Extracted from VentaProductGroupController::pushProducts so the listing
 * page and the group push send the same payload and reconcile the same
 * way. The group push keeps its per-group bookkeeping (sync status on the
 * pivots, the summary sentence); this owns the part that talks to the
 * store about one product.
 *
 * RECONCILE FIRST, ACT SECOND. Nothing here decides from the local link
 * row alone: a link is the ERP's memory and is wrong in both directions
 * often enough. The store is asked whether it has the product under any
 * of its SKUs, the link is made to agree, and only then is a create or an
 * update sent. Absence of an answer is not an answer: only an explicit
 * 404 from a reachable store may mean the product is gone.
 */
class VentaProductPush
{
    public function __construct(
        private VentaSetting $setting,
        private VentaClient $client,
    ) {
    }

    public static function for(VentaSetting $setting): self
    {
        return new self($setting, new VentaClient($setting));
    }

    // ── The catalogue side ──────────────────────────────────────────────

    /** The catalogue product as the push reads it, or null. */
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

    /** The SKU the store knows this product by: sku first, model as the fallback. */
    public static function skuOf(object $prod): string
    {
        return trim((string) ($prod->sku ?: $prod->model ?: ''));
    }

    /**
     * The SKUs this product could be known by on the store, best first.
     *
     * @return list<string>
     */
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

    /**
     * What the store is sent for this product.
     *
     * $overrides is the listing's own content (name, description,
     * meta_title, meta_description), each replacing the catalogue's value
     * only when present. $priceFor runs the core price, and every
     * variation's price, through the listing's rule; without it the core
     * price goes as it is, which is what the group push has always sent.
     *
     * Never overridden: sku. Reconcile matches by it.
     *
     * @param  array<string, string>  $overrides
     */
    public function payload(object $prod, array $overrides = [], ?callable $priceFor = null): array
    {
        $pfx = (string) config('catalog.prefix');
        $langId = (int) config('catalog.default_language_id');
        $pid = (int) $prod->product_id;
        $sku = self::skuOf($prod);
        $price = fn (float $base) => $priceFor ? round((float) $priceFor($base), 2) : $base;

        $data = [
            'name'        => $overrides['name'] ?? $prod->name,
            'sku'         => $sku,
            'price'       => $price((float) $prod->price),
            'cost'        => (float) ($prod->cost ?? 0),
            'quantity'    => (int) $prod->quantity,
            'weight'      => (float) ($prod->weight ?? 0),
            'length'      => (float) ($prod->length ?? 0),
            'width'       => (float) ($prod->width ?? 0),
            'height'      => (float) ($prod->height ?? 0),
            'status'      => (bool) $prod->status,
            'description' => $overrides['description'] ?? ($prod->description ?? ''),
        ];

        foreach (['meta_title', 'meta_description'] as $meta) {
            $value = $overrides[$meta] ?? ($prod->{$meta} ?? null);
            if ($value !== null && trim((string) $value) !== '') {
                $data[$meta] = (string) $value;
            }
        }

        // Brand and categories go as names; the store finds or creates them.
        if (! empty($prod->brand_name)) {
            $data['brand_name'] = $prod->brand_name;
        }

        $catNames = DB::table($pfx . 'product_to_category as ptc')
            ->join($pfx . 'category_description as cd', function ($j) use ($langId) {
                $j->on('ptc.category_id', '=', 'cd.category_id')->where('cd.language_id', '=', $langId);
            })
            ->where('ptc.product_id', $pid)
            ->pluck('cd.name')
            ->filter()
            ->values()
            ->toArray();
        if (! empty($catNames)) {
            $data['category_names'] = $catNames;
        }

        $images = $this->images($pid);
        if (! empty($images)) {
            $data['images'] = $images;
        }

        $variants = $this->variants($prod, $sku, $price);
        if ($variants !== []) {
            $data['variants'] = $variants;
        }

        return $data;
    }

    /**
     * Every variation with its own SKU, stock and price through the rule.
     * Combinations first (multi-axis products), single option values as
     * the fallback, exactly as the group push has always built them.
     *
     * @return list<array<string, mixed>>
     */
    private function variants(object $prod, string $sku, callable $price): array
    {
        $pfx = (string) config('catalog.prefix');
        $langId = (int) config('catalog.default_language_id');
        $pid = (int) $prod->product_id;

        /* The variation's own image rides the push (#193) - Venta's sync
         * endpoint has accepted variants.*.image all along; the payload
         * simply never carried it, so every variation wore the product
         * photo. A variation without one falls back to the product image
         * rather than pushing blank, exactly the storefront's own rule. */
        $fallbackImage = trim((string) ($prod->image ?? '')) !== '' ? self::imageUrl((string) $prod->image) : null;
        $variantImage = fn (?string $path) => trim((string) $path) !== '' ? self::imageUrl((string) $path) : $fallbackImage;

        $combos = DB::table('product_option_combinations as poc')
            ->where('poc.product_id', $pid)
            ->orderBy('poc.sort_order')
            ->get();

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
                    'weight'      => (float) ($prod->weight ?? 0),
                    'image'       => $variantImage($c->image ?? null),
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
                'weight'      => (float) ($prod->weight ?? 0),
                // Legacy single-axis rows (no combination rows) keep no
                // per-variation image; the product image stands in.
                'image'       => $variantImage(null),
            ];
        }

        return $variants;
    }

    /** @return list<string> absolute image URLs, main image first */
    public function images(int $productId): array
    {
        $pfx = (string) config('catalog.prefix');
        $images = [];

        $main = DB::table($pfx . 'product')->where('product_id', $productId)->value('image');
        if ($main && trim($main) !== '') {
            $images[] = self::imageUrl($main);
        }

        $more = DB::table($pfx . 'product_image')
            ->where('product_id', $productId)
            ->orderBy('sort_order')
            ->pluck('image')
            ->toArray();
        foreach ($more as $img) {
            if ($img && trim($img) !== '') {
                $images[] = self::imageUrl($img);
            }
        }

        return $images;
    }

    public static function imageUrl(string $path): string
    {
        $segments = explode('/', ltrim($path, '/'));

        return asset('storage/' . implode('/', array_map('rawurlencode', $segments)));
    }

    // ── The store side ──────────────────────────────────────────────────

    /**
     * Ask the store what is true and make the local link agree.
     *
     * Returns one of: confirmed, adopted, repointed, lost, new, blocked,
     * unreachable. See VentaProductGroupController's original docblock
     * for what each means; the meanings are unchanged.
     *
     * @return array{state: string, venta_id: int|null, sku: string|null, message: string|null}
     */
    public function reconcileLink(int $productId): array
    {
        $store = (int) $this->setting->id;
        $link = VentaProductLink::where('venta_setting_id', $store)->where('product_id', $productId)->first();

        $found = null;
        $foundSku = null;
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
                    break;
                }
                continue;
            }

            if ($status !== 404) {
                $denied = $status === 0
                    ? 'Venta could not be reached, so nothing was checked or changed.'
                    : "Venta answered {$status} instead of saying whether the product exists, so nothing was changed.";
            }
        }

        if ($found === null && $denied !== null) {
            return ['state' => 'unreachable', 'venta_id' => null, 'sku' => null, 'message' => $denied];
        }

        if ($found === null) {
            if ($link) {
                $link->delete();

                return ['state' => 'lost', 'venta_id' => null, 'sku' => null, 'message' => null];
            }

            return ['state' => 'new', 'venta_id' => null, 'sku' => null, 'message' => null];
        }

        if ($link && (int) $link->venta_product_id === $found) {
            return ['state' => 'confirmed', 'venta_id' => $found, 'sku' => $foundSku, 'message' => null];
        }

        $wasPointingElsewhere = $link !== null;

        if ($clash = $this->linkProductTo($productId, $found, $foundSku)) {
            return ['state' => 'blocked', 'venta_id' => $found, 'sku' => $foundSku, 'message' => $clash];
        }

        return [
            'state' => $wasPointingElsewhere ? 'repointed' : 'adopted',
            'venta_id' => $found,
            'sku' => $foundSku,
            'message' => null,
        ];
    }

    /**
     * Point an ERP product at a store product, or say who is in the way.
     * The conflicting link is never taken over.
     *
     * @return string|null null when linked, or the reason it could not be
     */
    public function linkProductTo(int $productId, $ventaProductId, ?string $sku): ?string
    {
        $store = (int) $this->setting->id;

        $conflict = VentaProductLink::where('venta_setting_id', $store)
            ->where('venta_product_id', $ventaProductId)
            ->where('product_id', '!=', $productId)
            ->first();

        if ($conflict) {
            return "Venta product {$ventaProductId} is already linked to catalog product "
                . "#{$conflict->product_id}. Unlink that product first, then try this one again.";
        }

        VentaProductLink::updateOrCreate(
            ['venta_setting_id' => $store, 'product_id' => $productId],
            ['venta_product_id' => $ventaProductId, 'sku' => $sku]
        );

        return null;
    }

    /**
     * Push one product: reconcile, then create or update, then record.
     *
     * @param  array<string, string>  $overrides  the listing's content
     * @return array{
     *   ok: bool, state: string, message: string, venta_id: int|null,
     *   images: array{sent: int, stored: int, reasons: array<string, int>},
     *   payload: array<string, mixed>
     * }
     */
    public function push(int $productId, array $overrides = [], ?callable $priceFor = null): array
    {
        $prod = $this->product($productId);
        if (! $prod) {
            return self::outcome(false, 'failed', 'The catalog product no longer exists.');
        }

        $sku = self::skuOf($prod);
        if ($sku === '') {
            return self::outcome(false, 'failed', 'This product has no SKU, and the store files products by SKU. Give it one in the catalog first.');
        }

        $data = $this->payload($prod, $overrides, $priceFor);

        $verdict = $this->reconcileLink($productId);
        if ($verdict['state'] === 'blocked' || $verdict['state'] === 'unreachable') {
            return self::outcome(false, $verdict['state'], (string) $verdict['message'], null, [], $data);
        }

        $link = VentaProductLink::where('venta_setting_id', $this->setting->id)->where('product_id', $productId)->first();

        if ($link && $link->venta_product_id) {
            $result = $this->client->updateProduct($sku, $data);
            if (! ($result['ok'] ?? false)) {
                return self::outcome(false, 'failed', self::failureText($result, $sku), (int) $link->venta_product_id, [], $data);
            }

            $this->setting->update(['last_product_sync_at' => now()]);

            return self::outcome(true, 'updated', 'Updated on ' . $this->setting->store_name . '.', (int) $link->venta_product_id, self::imageVerdict($result), $data);
        }

        $result = $this->client->createProduct($data);
        if (! ($result['ok'] ?? false)) {
            return self::outcome(false, 'failed', self::failureText($result, $sku), null, [], $data);
        }

        $ventaId = (int) ($result['body']['id'] ?? $result['body']['data']['id'] ?? 0);
        if ($clash = $this->linkProductTo($productId, $ventaId, $sku)) {
            return self::outcome(false, 'blocked', "Created on the store as {$ventaId}, but {$clash}", $ventaId, self::imageVerdict($result), $data);
        }

        $this->setting->update(['last_product_sync_at' => now()]);

        return self::outcome(true, 'created', 'Created on ' . $this->setting->store_name . '.', $ventaId, self::imageVerdict($result), $data);
    }

    /**
     * What the store holds for this product right now, read fresh.
     *
     * @return array{
     *   state: string, message: string|null, venta_id: int|null, sku: string|null,
     *   status: string|null, name: string, price: float|null, quantity: int|null,
     *   images: int, variants: list<array{sku: string, name: string, price: float|null, quantity: int|null, active: bool}>
     * }
     */
    public function read(int $productId): array
    {
        $none = ['state' => 'missing', 'message' => null, 'venta_id' => null, 'sku' => null,
            'status' => VentaListing::STATUS_MISSING, 'name' => '', 'price' => null, 'quantity' => null, 'images' => 0, 'variants' => []];

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
                    'venta_id' => (int) $body['id'],
                    'sku' => $sku,
                    'status' => array_key_exists('status', $body)
                        ? ($body['status'] ? VentaListing::STATUS_ACTIVE : VentaListing::STATUS_INACTIVE)
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

    /**
     * Turn the product on or off on the store. The status field is the
     * storefront's own is_active; nothing else about the product moves.
     *
     * @return array{ok: bool, message: string}
     */
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

    // ── Reading the store's answers ─────────────────────────────────────

    /**
     * The store's refusal, in words the operator can act on. Two refusals
     * have their own sentence; anything else is the store's own text.
     */
    public static function failureText(array $result, string $sku): string
    {
        $body = $result['body'] ?? [];
        $raw = json_encode($body);

        if (stripos($raw, 'images_unavailable') !== false) {
            return 'Venta will not create a product it cannot fetch images for, so nothing was listed. '
                . 'It reads images over the web from the address this ERP publishes, and that address is a '
                . 'private one, so the images never reach it. Nothing in the catalog needs fixing.';
        }

        if (stripos($raw, 'sku has already been taken') !== false) {
            return "Venta already has a product with SKU {$sku} that this catalog product is not linked to. "
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

    /**
     * The per-image verdict the store returns with every push: what it
     * kept, and each refusal's reason, grouped.
     *
     * @return array{sent: int, stored: int, reasons: array<string, int>}
     */
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

    /** One sentence about the images, or nothing when nothing was sent. */
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

    private static function outcome(bool $ok, string $state, string $message, ?int $ventaId = null, array $images = [], array $payload = []): array
    {
        return [
            'ok' => $ok,
            'state' => $state,
            'message' => $message,
            'venta_id' => $ventaId,
            'images' => $images + ['sent' => 0, 'stored' => 0, 'reasons' => []],
            'payload' => $payload,
        ];
    }
}
