<?php

namespace Extensions\tiktok\Services\TikTok;

use Extensions\tiktok\Models\TikTokApiLog;
use Illuminate\Support\Facades\DB;

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
    public function __construct(private TikTokVariationPush $variations) {}

    /** @return array{payload?: array, built?: array, refused?: string} */
    public function build(object $erp, array $opts, array $c, TikTokClient $client, ?array $existingIds = null): array
    {
        $pfx = (string) config('catalog.prefix');

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

        // Main images: the product's, then the gallery, nine at most.
        $paths = [];
        if (trim((string) ($erp->image ?? '')) !== '') {
            $paths[] = trim((string) $erp->image);
        }
        foreach (DB::table($pfx . 'product_image')->where('product_id', $erp->product_id)->orderBy('sort_order')->pluck('image') as $img) {
            if (trim((string) $img) !== '') {
                $paths[] = trim((string) $img);
            }
        }
        $imageUris = [];
        foreach (array_slice(array_values(array_unique($paths)), 0, 9) as $path) {
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

        $rawDesc = trim((string) ($opts['description'] ?? '')) !== '' ? (string) $opts['description'] : (string) ($erp->description ?? '');
        $rawDesc = preg_replace('/<br\s*\/?>/i', "\n", $rawDesc);
        $rawDesc = preg_replace('/<\/p>\s*/i', "\n\n", $rawDesc);
        $desc = trim(strip_tags(html_entity_decode($rawDesc, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
        if ($desc === '') {
            $desc = $title;
        }
        $desc = mb_substr($desc, 0, 10000);

        $payload = [
            'title' => $title,
            'description' => $desc,
            'category_id' => (string) $opts['category_id'],
            'category_version' => 'v2',
            'package_weight' => ['unit' => 'GRAM', 'value' => (string) max(1, (int) round((float) ($erp->weight ?? 0) * 1000))],
            'package_dimensions' => [
                'unit' => 'CENTIMETER',
                'length' => (string) max(1, (int) round((float) ($erp->length ?? 0))),
                'width' => (string) max(1, (int) round((float) ($erp->width ?? 0))),
                'height' => (string) max(1, (int) round((float) ($erp->height ?? 0))),
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

    /** @return array{ok: bool, message: string, sku_ids?: ?string, result: array} */
    public function edit(object $erp, string $tiktokProductId, ?string $existingRaw, array $opts, array $c, TikTokClient $client): array
    {
        $b = $this->build($erp, $opts, $c, $client, TikTokVariationPush::existingIds($existingRaw));
        if (isset($b['refused'])) {
            return ['ok' => false, 'message' => $b['refused'], 'result' => []];
        }
        try {
            $result = $client->editProduct($c['app_key'], $c['app_secret'], $c['token'], $tiktokProductId, $b['payload'], $c['shop_cipher'] ?: null);
        } catch (\Throwable $e) {
            $result = ['ok' => false, 'status' => 0, 'body' => ['message' => \App\Support\TransportError::plain($e, 'TikTok Shop')]];
        }
        $this->log('PUT', '/product/202309/products/' . $tiktokProductId, $b['payload'], $result);

        if (!$this->ok($result)) {
            return ['ok' => false, 'message' => $this->message($result), 'result' => $result];
        }

        return [
            'ok' => true, 'message' => 'updated',
            'sku_ids' => !empty($result['body']['data']['skus'])
                ? $this->variations->storedIds($b['built'], $result['body']['data']['skus'])
                : null,
            'result' => $result,
        ];
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
