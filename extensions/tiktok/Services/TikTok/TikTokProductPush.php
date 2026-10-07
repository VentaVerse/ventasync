<?php

namespace Extensions\tiktok\Services\TikTok;

use Extensions\tiktok\Models\TikTokApiLog;

class TikTokProductPush
{
    public function __construct(
        private TikTokVariationPush $variations,
        private TikTokVariationRename $rename,
        private TikTokDescriptionImages $descriptionImages,
    ) {}

    public function build(object $erp, array $opts, array $c, TikTokClient $client, ?array $existingIds = null): array
    {
        $categoryId = trim((string) ($opts['category_id'] ?? ''));
        if ($categoryId === '' || ! preg_match('/^\d+$/', $categoryId)) {
            return ['refused' => $categoryId === ''
                ? 'No TikTok category is set on this listing. Pick one on the listing page and save.'
                : 'The TikTok category on this listing is "' . $categoryId . '", which is not a category id TikTok can read. Pick the category again on the listing page and save.'];
        }

        $uploadUri = function (string $imgPath) use ($client, $c): ?string {
            $imgPath = \App\Services\Media\ImageCache::path($imgPath, \App\Services\Media\ImageCache::PUSH) ?? $imgPath;
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

        $own = array_key_exists('image_order', $opts)
            ? $opts['image_order']
            : \Extensions\tiktok\Models\TikTokListing::query()->where('product_id', (int) $erp->product_id)->value('image_order');
        $paths = \App\Support\Catalog\ProductImages::paths((int) $erp->product_id, $own);
        $marking = \Extensions\tiktok\Models\TikTokListing::query()->where('product_id', (int) $erp->product_id)
            ->first(['tiktok_setting_id', 'watermark_template_id', 'watermark_all_images']);
        $paths = \App\Services\Media\ListingWatermark::paths(\App\Integrations\Listings\ListingImages::sending($paths, $marking), $marking, \App\Services\Media\ListingWatermark::groupTemplateId('tiktok_product_groups', 'tiktok_product_group_products', 'tiktok_product_group_id', (int) $erp->product_id, 'tiktok_setting_id', (int) ($marking?->tiktok_setting_id ?? 0) ?: null), 'tiktok', (int) ($marking?->tiktok_setting_id ?? 0));
        $imageUris = [];
        foreach ($paths as $path) {
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
        if (mb_strlen($title) < 25) {
            $title = rtrim($title . ' ' . ($erp->model ?: $erp->sku ?: ('#' . $erp->product_id)));
            while (mb_strlen($title) < 25) {
                $title .= ' .';
            }
        }
        $title = mb_substr($title, 0, 255);

        $rawDesc = trim((string) ($opts['description'] ?? '')) !== '' ? (string) $opts['description'] : (string) ($erp->description ?? '');
        $desc = \App\Integrations\Listings\RichDescription::forTikTok($rawDesc);
        $leftOut = max(0, \App\Integrations\Listings\RichDescription::imageCount($rawDesc) - \App\Integrations\Listings\RichDescription::imageCount($desc));
        $blank = fn (string $html): bool => trim(strip_tags($html)) === '' && stripos($html, '<img') === false;
        if ($blank($desc)) {
            $desc = $title;
        }

        $ttListing = \Extensions\tiktok\Models\TikTokListing::query()->where('product_id', (int) $erp->product_id)->first();
        $desc = \App\Integrations\Listings\ListingContent::wrapDescription(
            $desc, 'tiktok', (int) ($ttListing->tiktok_setting_id ?? 0), $ttListing
        );

        $store = ($c['setting'] ?? null) instanceof \Extensions\tiktok\Models\TikTokSetting ? $c['setting'] : \Extensions\tiktok\Models\TikTokSetting::defaultStore();
        $hosted = $this->descriptionImages->host($desc, $c, $client, (int) ($store?->id ?? 0));
        $desc = $hosted['html'];
        $leftOut += $hosted['left_out'];
        if ($blank($desc)) {
            $desc = $title;
        }

        $desc = mb_substr($desc, 0, 10000);

        $weight = (float) ($ttListing?->weight ?? 0) > 0 ? (float) $ttListing->weight : (float) ($erp->weight ?? 0);
        $side = fn (string $own, string $core): string => (string) \App\Integrations\Listings\ParcelPrecision::of(
            $ttListing?->{$own}, $erp->{$core} ?? 0, \App\Integrations\Listings\ParcelPrecision::TIKTOK['dimension']
        );

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

        return ['payload' => $payload, 'built' => $built, 'left_out' => $leftOut];
    }

    public static function picturesNote(int $leftOut): ?string
    {
        if ($leftOut <= 0) {
            return null;
        }

        return $leftOut === 1
            ? '1 picture in the description could not be sent to TikTok Shop and was left out.'
            : $leftOut . ' pictures in the description could not be sent to TikTok Shop and were left out.';
    }

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

        $pictures = self::picturesNote((int) ($b['left_out'] ?? 0));

        return [
            'ok' => true, 'message' => 'created',
            'product_id' => (string) $result['body']['data']['product_id'],
            'sku_ids' => $this->variations->storedIds($b['built'], $result['body']['data']['skus'] ?? []),
            'pictures' => $pictures, 'warning' => $pictures,
            'result' => $result,
        ];
    }

    public function edit(object $erp, string $tiktokProductId, ?string $existingRaw, array $opts, array $c, TikTokClient $client): array
    {
        $existing = TikTokVariationPush::existingIds($existingRaw);
        $builtIn = $this->builtInTypes((string) ($opts['category_id'] ?? ''), $client, $c);

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

        if (!$this->ok($result) && $shaped['renamed'] && (int) ($result['status'] ?? 0) > 0) {
            $kept = $this->rename->shape($built, $live, $builtIn, 'TikTok Shop refused them (' . $this->message($result) . ')');
            $b['payload']['skus'] = $kept['skus'];
            $skipped = $kept['skipped'];
            $result = $this->sendEdit($client, $c, $tiktokProductId, $b['payload']);
        }

        if (!$this->ok($result)) {
            return ['ok' => false, 'message' => $this->message($result), 'result' => $result];
        }
        $pictures = self::picturesNote((int) ($b['left_out'] ?? 0));
        $warning = implode(' ', array_filter([
            $skipped !== null ? 'Variation names were not changed: ' . $skipped . '.' : null,
            $pictures,
        ])) ?: null;

        $skuIds = !empty($result['body']['data']['skus'])
            ? $this->variations->storedIds($b['built'], $result['body']['data']['skus'])
            : null;
        if ($skuIds === null && $live !== null) {
            $skuIds = $this->heldIds((new TikTokLiveListing($client))->variationSkus($tiktokProductId, $c)['skus'] ?? []);
        }

        return ['ok' => true, 'message' => $warning ?? 'updated', 'warning' => $warning, 'pictures' => $pictures, 'sku_ids' => $skuIds, 'result' => $result];
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
