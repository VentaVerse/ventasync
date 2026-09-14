<?php

namespace Extensions\tiktok\Services\TikTok;

use Extensions\tiktok\Models\TikTokApiLog;

/**
 * The one builder of a TikTok product payload (#156): the group push,
 * the group update, and the listing page's own push and update all
 * hand it a catalog product and their settings and get back what
 * TikTok is sent. Images, title and description shaping, package
 * figures, SKUs (TikTokVariationPush), category, brand and attribute
 * answers (TikTokAttributes) live here once, so the four paths cannot
 * drift.
 *
 * Settings ($opts):
 *   category_id   string, required
 *   brand_id      ?string
 *   attributes    array  product_attributes already built by TikTokAttributes::payload()
 *   priceFor      \Closure float $base => float
 *   warehouse_id  ?string
 *   title         ?string override; description ?string override
 */
class TikTokProductPush
{
    /** How many main images TikTok Shop takes on a product; the push sends the first nine and says so. */
    public const MAX_IMAGES = 9;

    public function __construct(private TikTokVariationPush $variations, private TikTokVariationRename $rename) {}

    /** @return array{payload?: array, built?: array, refused?: string} */
    public function build(object $erp, array $opts, array $c, TikTokClient $client, ?array $existingIds = null): array
    {
        /* TikTok reads category_id as an Int64: anything else comes back as
         * "CategoryId is invalid, the value must be convertible to Int64"
         * after the images were already uploaded (the owner, 2026-09-06). Said
         * here, first, with the value that was about to go. */
        $categoryId = trim((string) ($opts['category_id'] ?? ''));
        if ($categoryId === '' || ! preg_match('/^\d+$/', $categoryId)) {
            return ['refused' => $categoryId === ''
                ? 'No TikTok category is set on this listing. Pick one on the listing page and save.'
                : 'The TikTok category on this listing is "' . $categoryId . '", which is not a category id TikTok can read. Pick the category again on the listing page and save.'];
        }

        $uploadUri = function (string $imgPath) use ($client, $c): ?string {
            // Fitted first, so the squared copy below stays a modest file.
            $imgPath = \App\Services\Media\StoreReadyImages::fit($imgPath, \App\Services\Media\StoreReadyImages::TIKTOK) ?? $imgPath;
            $fullPath = public_path('storage/' . ltrim($imgPath, '/'));
            if (!file_exists($fullPath)) {
                return null;
            }
            $imageData = $this->squareImage($fullPath) ?: file_get_contents($fullPath);
            try {
                $imgResult = $client->uploadImage($c['app_key'], $c['app_secret'], $c['token'], $imageData);
            } catch (\Throwable $e) {
                $imgResult = ['ok' => false, 'status' => 0, 'body' => ['message' => \App\Support\TransportError::plain($e, 'TikTok Shop')]];
            }
            $this->log('POST', '/product/202309/images/upload', ['image' => $imgPath], $imgResult);

            return ($imgResult['ok'] ?? false) ? ($imgResult['body']['data']['uri'] ?? null) : null;
        };

        /* The pictures, in the order this listing sends them: its own
         * arrangement when it has one, the catalog product's gallery when
         * it does not. The order is decided in one place for every channel
         * (App\Support\Catalog\ProductImages) since VENT2-131; only the
         * upload is TikTok's own, because TikTok is handed bytes.
         *
         * A group push carries no listing, so the product's own row is
         * read for it - the arrangement belongs to the listing whatever
         * pressed the button. */
        $own = array_key_exists('image_order', $opts)
            ? $opts['image_order']
            : \Extensions\tiktok\Models\TikTokListing::query()->where('product_id', (int) $erp->product_id)->value('image_order');
        $paths = \App\Support\Catalog\ProductImages::paths((int) $erp->product_id, $own);
        $marking = \Extensions\tiktok\Models\TikTokListing::query()->where('product_id', (int) $erp->product_id)
            ->first(['tiktok_setting_id', 'watermark_template_id', 'watermark_all_images']);
        $paths = \App\Services\Media\ListingWatermark::paths(array_slice($paths, 0, self::MAX_IMAGES), $marking, \App\Services\Media\ListingWatermark::groupTemplateId('tiktok_product_groups', 'tiktok_product_group_products', 'tiktok_product_group_id', (int) $erp->product_id, 'tiktok_setting_id', (int) ($marking?->tiktok_setting_id ?? 0) ?: null));
        $imageUris = [];
        foreach (array_slice($paths, 0, self::MAX_IMAGES) as $path) {
            $uri = $uploadUri($path);
            if ($uri) {
                $imageUris[] = $uri;
            }
        }
        if (empty($imageUris) && $existingIds === null) {
            return ['refused' => 'No product image reached TikTok Shop; it requires at least one main image.'];
        }

        $built = $this->variations->skus($erp, $opts['priceFor'], $opts['warehouse_id'] ?? null, $uploadUri, $existingIds);
        if ($built['refused']) {
            return ['refused' => $built['refused']];
        }

        $title = trim((string) ($opts['title'] ?? '')) !== '' ? trim((string) $opts['title']) : ($erp->name ?: ('Product ' . $erp->product_id));
        $title = html_entity_decode($title, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        // TikTok wants 25 characters at least; the model or the id finishes
        // a short name in words that belong to the product.
        if (mb_strlen($title) < 25) {
            $title = rtrim($title . ' ' . ($erp->model ?: $erp->sku ?: ('#' . $erp->product_id)));
            while (mb_strlen($title) < 25) {
                $title .= ' .';
            }
        }
        $title = mb_substr($title, 0, 255);

        /* TikTok takes a RICH description - paragraphs, headings, lists
         * and up to thirty pictures - and this used to strip_tags() the
         * lot and send the words alone, so a description somebody had laid
         * out arrived as a wall of text with its photographs deleted and
         * nothing said about it (VENT2-129). Only what TikTok forbids is
         * dropped now; App\Integrations\Listings\RichDescription owns the
         * allowance. */
        $rawDesc = trim((string) ($opts['description'] ?? '')) !== '' ? (string) $opts['description'] : (string) ($erp->description ?? '');
        $desc = \App\Integrations\Listings\RichDescription::forTikTok($rawDesc);
        if (trim(strip_tags($desc)) === '') {
            $desc = $title;
        }

        /* The store's prefix and suffix templates (2026-09-07): its own words
         * above and below whatever description won. */
        $ttListing = \Extensions\tiktok\Models\TikTokListing::query()->where('product_id', (int) $erp->product_id)->first();
        $desc = \App\Integrations\Listings\ListingContent::wrapDescription(
            $desc, 'tiktok', (int) ($ttListing->tiktok_setting_id ?? 0), $ttListing
        );

        $desc = mb_substr($desc, 0, 10000);

        // The parcel: the listing's own figure for each field it fills, the catalog's for each it leaves blank.
        $weight = (float) ($ttListing?->weight ?? 0) > 0 ? (float) $ttListing->weight : (float) ($erp->weight ?? 0);
        $side = fn (string $own, string $core): string => (string) max(1, (int) ($ttListing?->{$own} ?? 0) > 0
            ? (int) $ttListing->{$own}
            : (int) round((float) ($erp->{$core} ?? 0)));

        $payload = [
            'title' => $title,
            'description' => $desc,
            'category_id' => (string) $opts['category_id'],
            'category_version' => 'v2',
            'package_weight' => ['unit' => 'GRAM', 'value' => (string) max(1, (int) round($weight * 1000))],
            'package_dimensions' => [
                'unit' => 'CENTIMETER',
                'length' => $side('package_length', 'length'),
                'width' => $side('package_width', 'width'),
                'height' => $side('package_height', 'height'),
            ],
            'skus' => $built['skus'],
        ];
        if (!empty($imageUris)) {
            $payload['main_images'] = array_map(fn ($uri) => ['uri' => $uri], $imageUris);
        }
        if (!empty($opts['brand_id'])) {
            $payload['brand_id'] = (string) $opts['brand_id'];
        }
        if (!empty($opts['attributes'])) {
            $payload['product_attributes'] = $opts['attributes'];
        }

        return ['payload' => $payload, 'built' => $built];
    }

    /** @return array{ok: bool, message: string, product_id?: string, sku_ids?: ?string, result: array} */
    public function create(object $erp, array $opts, array $c, TikTokClient $client): array
    {
        $b = $this->build($erp, $opts, $c, $client, null);
        if (isset($b['refused'])) {
            return ['ok' => false, 'message' => $b['refused'], 'result' => []];
        }
        try {
            $result = $client->createProduct($c['app_key'], $c['app_secret'], $c['token'], $b['payload'], $c['shop_cipher'] ?: null);
        } catch (\Throwable $e) {
            $result = ['ok' => false, 'status' => 0, 'body' => ['message' => \App\Support\TransportError::plain($e, 'TikTok Shop')]];
        }
        $this->log('POST', '/product/202309/products', $b['payload'], $result);

        if (!$this->ok($result) || empty($result['body']['data']['product_id'])) {
            return ['ok' => false, 'message' => $this->message($result), 'result' => $result];
        }

        return [
            'ok' => true, 'message' => 'created',
            'product_id' => (string) $result['body']['data']['product_id'],
            'sku_ids' => $this->variations->storedIds($b['built'], $result['body']['data']['skus'] ?? []),
            'result' => $result,
        ];
    }

    /**
     * warning is set when the update went up with the item's variation
     * names kept, and says why.
     *
     * @return array{ok: bool, message: string, sku_ids?: ?string, warning?: ?string, result: array}
     */
    public function edit(object $erp, string $tiktokProductId, ?string $existingRaw, array $opts, array $c, TikTokClient $client): array
    {
        $existing = TikTokVariationPush::existingIds($existingRaw);
        $builtIn = $this->builtInTypes((string) ($opts['category_id'] ?? ''), $client, $c);

        /* An item with variations is read first, so its variation types
         * can be paired with the catalog's options (TikTokVariationRename).
         * An edit overwrites every variation it sends and cannot leave them
         * out, so an item that cannot be read is refused before any
         * picture is uploaded. */
        $live = null;
        $probe = $this->variations->skus($erp, $opts['priceFor'], $opts['warehouse_id'] ?? null, null, $existing);
        if (!$probe['refused'] && $probe['kind'] !== 'single') {
            $read = (new TikTokLiveListing($client))->variationSkus($tiktokProductId, $c);
            if ($read['skus'] === null) {
                return ['ok' => false, 'message' => 'TikTok Shop did not say which variations this item has (' . $read['error'] . '), so nothing was sent.', 'result' => []];
            }
            $live = $read['skus'];
        }

        $b = $this->build($erp, $opts, $c, $client, $existing);
        if (isset($b['refused'])) {
            return ['ok' => false, 'message' => $b['refused'], 'result' => []];
        }
        $built = $b['payload']['skus'];
        $shaped = ['renamed' => false, 'skipped' => null];
        if ($live !== null) {
            $shaped = $this->rename->shape($built, $live, $builtIn);
            $b['payload']['skus'] = $shaped['skus'];
        }
        $skipped = $shaped['skipped'];
        $result = $this->sendEdit($client, $c, $tiktokProductId, $b['payload']);

        // TikTok answered no to an edit with a rename in it: the same update once more, under the item's own names.
        if (!$this->ok($result) && $shaped['renamed'] && (int) ($result['status'] ?? 0) > 0) {
            $kept = $this->rename->shape($built, $live, $builtIn, 'TikTok Shop refused them (' . $this->message($result) . ')');
            $b['payload']['skus'] = $kept['skus'];
            $skipped = $kept['skipped'];
            $result = $this->sendEdit($client, $c, $tiktokProductId, $b['payload']);
        }

        if (!$this->ok($result)) {
            return ['ok' => false, 'message' => $this->message($result), 'result' => $result];
        }
        $warning = $skipped !== null ? 'Variation names were not changed: ' . $skipped . '.' : null;

        $skuIds = !empty($result['body']['data']['skus'])
            ? $this->variations->storedIds($b['built'], $result['body']['data']['skus'])
            : null;
        // An answer without SKUs: the item is read again, so the map follows the ids it holds now.
        if ($skuIds === null && $live !== null) {
            $skuIds = $this->heldIds((new TikTokLiveListing($client))->variationSkus($tiktokProductId, $c)['skus'] ?? []);
        }

        return ['ok' => true, 'message' => $warning ?? 'updated', 'warning' => $warning, 'sku_ids' => $skuIds, 'result' => $result];
    }

    private function sendEdit(TikTokClient $client, array $c, string $tiktokProductId, array $payload): array
    {
        try {
            $result = $client->editProduct($c['app_key'], $c['app_secret'], $c['token'], $tiktokProductId, $payload, $c['shop_cipher'] ?: null);
        } catch (\Throwable $e) {
            $result = ['ok' => false, 'status' => 0, 'body' => ['message' => \App\Support\TransportError::plain($e, 'TikTok Shop')]];
        }
        $this->log('PUT', '/product/202309/products/' . $tiktokProductId, $payload, $result);

        return $result;
    }

    /** seller_sku => id for every SKU the item holds, as the stored map keeps it; null when none. */
    private function heldIds(array $liveSkus): ?string
    {
        $map = [];
        foreach ($liveSkus as $sku) {
            $seller = trim((string) ($sku['seller_sku'] ?? ''));
            $id = trim((string) ($sku['id'] ?? ''));
            if ($seller !== '' && $id !== '') {
                $map[$seller] = $id;
            }
        }

        return $map ? json_encode($map) : null;
    }

    /** The category's own variation type ids, read once and only when a pairing asks. */
    private function builtInTypes(string $categoryId, TikTokClient $client, array $c): \Closure
    {
        $asked = false;
        $ids = null;

        return function () use (&$asked, &$ids, $categoryId, $client, $c): ?array {
            if (!$asked) {
                $asked = true;
                $attributes = app(TikTokAttributes::class);
                $ids = $categoryId !== '' ? $attributes->salesPropertyIds($attributes->ensureTemplate($categoryId, $client, $c)) : null;
            }

            return $ids;
        };
    }

    private function ok(array $result): bool
    {
        return ($result['ok'] ?? false) && (int) ($result['body']['code'] ?? -1) === 0;
    }

    private function message(array $result): string
    {
        // The one reader that knows where every channel hides its reason.
        return \App\Support\MarketplaceAnswer::errorText('TikTok Shop', $result);
    }

    private function log(string $method, string $path, array $params, array $result): void
    {
        TikTokApiLog::safeCreate([
            'pack' => 'tiktok.products.push', 'method' => $method,
            'api_path' => $path, 'auth_required' => true,
            'request_params' => $params,
            'response_status' => $result['status'] ?? 0,
            'ok' => (bool) ($result['ok'] ?? false),
            'response_body' => $result['body'] ?? [], 'user_id' => auth()->id(),
        ]);
    }

    /** TikTok wants square pictures; a rectangle is centred on a white canvas. */
    private function squareImage(string $filePath): ?string
    {
        $info = @getimagesize($filePath);
        if (!$info) {
            return null;
        }
        [$w, $h] = $info;
        $src = match ($info[2]) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($filePath),
            IMAGETYPE_PNG => @imagecreatefrompng($filePath),
            IMAGETYPE_GIF => @imagecreatefromgif($filePath),
            IMAGETYPE_WEBP => @imagecreatefromwebp($filePath),
            default => null,
        };
        if (!$src) {
            return null;
        }
        $size = max($w, $h, 300);
        $canvas = imagecreatetruecolor($size, $size);
        $white = imagecolorallocate($canvas, 255, 255, 255);
        imagefill($canvas, 0, 0, $white);
        imagecopyresampled($canvas, $src, (int) (($size - $w) / 2), (int) (($size - $h) / 2), 0, 0, $w, $h, $w, $h);
        imagedestroy($src);
        ob_start();
        imagejpeg($canvas, null, 90);
        $data = ob_get_clean();
        imagedestroy($canvas);

        return $data ?: null;
    }
}
