<?php

namespace Extensions\lazada\Services\Lazada;

use Extensions\lazada\Models\LazadaApiLog;
use Extensions\lazada\Models\LazadaImageLink;
use Extensions\lazada\Models\LazadaSetting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Image plumbing for Lazada, out of the god controller (Phase C, #184):
 * URL shapes for display and for the wire, and the inlink migration that
 * re-hosts catalog images on Lazada's own CDN before a payload carries
 * them.
 */
class LazadaImages
{
    /**
     * Convert an ERP product image path into an absolute URL that Lazada can fetch.
     *
     * - If value is already an absolute URL, keep it.
     * - Otherwise, assume the stored path is already relative to the public web root
     *   (e.g. "catalog/...").
     */
    /**
     * A product image URL for the OPERATOR'S BROWSER, not for Lazada.
     *
     * toPublicImageUrl() below builds an ABSOLUTE url, deliberately: Lazada's
     * servers fetch these over the internet, so they need a host, and that
     * host comes from catalog.public_url. That is correct for the payload and
     * wrong for this admin page, which is what emptied the listing grid.
     *
     * catalog.public_url was pointing at 192.168.122.8:8000, an address from
     * an earlier network. Every thumbnail on the page was requested from a
     * host the operator's browser cannot reach, so 2,074 products with images
     * on disk rendered as empty frames. Correcting that value would have fixed
     * it today and broken it again on the next address change, because the
     * page was asking a config setting a question it already knew the answer
     * to: the browser is already on the right host.
     *
     * So this returns a ROOT-RELATIVE path. It resolves against whatever
     * address the operator actually used - LAN ip, hostname, localhost, or a
     * tunnel - and cannot drift from it. An absolute url that is genuinely
     * somebody else's (a CDN, a marketplace cdn) is kept as it is; only
     * loopback is rewritten, because no browser but the server's own can
     * follow it.
     */
    public function toDisplayImageUrl(?string $imagePath): ?string
    {
        $imagePath = trim((string) ($imagePath ?? ''));

        if ($imagePath === '' || str_contains($imagePath, '..')) {
            return null;
        }

        if (Str::startsWith($imagePath, ['http://', 'https://'])) {
            $host = strtolower((string) parse_url($imagePath, PHP_URL_HOST));

            if (! in_array($host, ['localhost', '127.0.0.1', '0.0.0.0'], true)) {
                return $imagePath;
            }

            $path = ltrim((string) parse_url($imagePath, PHP_URL_PATH), '/');

            return ($path === '' || str_contains($path, '..'))
                ? null
                : '/' . $this->encodeUrlPath($path);
        }

        $path = ltrim($imagePath, '/');

        // Already carries a public prefix.
        if (Str::startsWith($path, ['storage/', 'image/'])) {
            return '/' . $this->encodeUrlPath($path);
        }

        // The Laravel public disk is where this install actually keeps them.
        try {
            if (file_exists(public_path('storage/' . $path))) {
                return '/' . $this->encodeUrlPath('storage/' . $path);
            }
        } catch (\Throwable $e) {
            // Fall through to the configured prefix.
        }

        $prefix = trim((string) config('catalog.image_prefix', 'image'), '/');

        return '/' . $this->encodeUrlPath(($prefix === '' ? 'image' : $prefix) . '/' . $path);
    }

    public function toPublicImageUrl(?string $imagePath): ?string
    {
        $imagePath = trim((string)($imagePath ?? ''));
        if ($imagePath === '') {
            return null;
        }

        // Basic safety: never generate URLs that include path traversal.
        if (str_contains($imagePath, '..')) {
            return null;
        }

        // If the DB already stores a full URL, keep it — except for localhost/loopback URLs.
        // Some imports (or older code) may have saved http://localhost/... which will break Lazada.
        // In that case, rewrite the host to the configured APP_URL host (or request host) while preserving the path.
        if (Str::startsWith($imagePath, ['http://', 'https://'])) {
            $host = strtolower((string) parse_url($imagePath, PHP_URL_HOST));
            if (in_array($host, ['localhost', '127.0.0.1', '0.0.0.0'], true)) {
                $base = $this->imageBaseUrl();
                $path = (string) parse_url($imagePath, PHP_URL_PATH);
                $path = ltrim($path, '/');

                if ($path === '' || str_contains($path, '..')) {
                    return null;
                }

                return $base . '/' . $path;
            }

            return $imagePath;
        }

        $base = $this->imageBaseUrl();
        $path = ltrim($imagePath, '/');

        // Many ERP installs store uploaded product images on the Laravel "public" disk
        // (public/storage -> storage/app/public). In that case, the publicly reachable URL
        // is typically "/storage/{path}" (e.g. /storage/catalog/Brand/file.jpg).
        //
        // Historically, some code assumed OpenCart-style "/image/{path}". Lazada requires
        // these URLs to return HTTP 200, so we must generate the correct public prefix.

        // If the stored path already includes a known public prefix, keep it.
        if (Str::startsWith($path, ['storage/', 'image/'])) {
            return $base . '/' . $this->encodeUrlPath($path);
        }

        // Prefer Laravel public disk if the file exists there: public/storage/{path}
        $storageCandidate = 'storage/' . $path;
        try {
            if (file_exists(public_path($storageCandidate))) {
                return $base . '/' . $this->encodeUrlPath($storageCandidate);
            }
        } catch (\Throwable $e) {
            // Ignore filesystem errors; fall back below.
        }

        // Fall back to configured prefix (default: "image").
        $prefix = trim((string) config('catalog.image_prefix', 'image'), '/');
        if ($prefix === '') {
            $prefix = 'image';
        }
        $prefixed = $prefix . '/' . $path;

        return $base . '/' . $this->encodeUrlPath($prefixed);
    }

    /**
     * Encode a relative URL path safely (encode each segment, preserve slashes).
     */
    private function encodeUrlPath(string $path): string
    {
        $path = ltrim($path, '/');
        $parts = array_map('rawurlencode', array_filter(explode('/', $path), fn($p) => $p !== ''));
        return implode('/', $parts);
    }

    /**
     * Returns the base URL used for building publicly reachable image URLs.
     *
     * We prefer config('app.url') (APP_URL), but if that is missing or somehow set to localhost,
     * we fall back to the current HTTP host.
     */
    private function imageBaseUrl(): string
    {
        // Prefer the storefront/public catalog URL. The ERP may run on a different subdomain.
        $base = rtrim((string) config('catalog.public_url', config('app.url')), '/');
        $host = strtolower((string) (parse_url($base, PHP_URL_HOST) ?? ''));

        if ($base === '' || $host === '' || in_array($host, ['localhost', '127.0.0.1', '0.0.0.0'], true)) {
            try {
                $base = rtrim(request()->getSchemeAndHttpHost(), '/');
            } catch (\Throwable $e) {
                // Ignore and keep whatever we have.
            }
        }

        return $base;
    }

    /**
     * Normalize an image URL/path into a publicly reachable URL based on APP_URL.
     * This is a last-resort guard to prevent localhost/loopback URLs from leaking into payloads.
     */
    public function normalizeImageUrl(string $urlOrPath): ?string
    {
        $urlOrPath = trim($urlOrPath);
        if ($urlOrPath === '') return null;

        // If it's a full URL, rewrite localhost/loopback hosts to APP_URL host.
        if (Str::startsWith($urlOrPath, ['http://', 'https://'])) {
            $host = strtolower((string) parse_url($urlOrPath, PHP_URL_HOST));
            if (in_array($host, ['localhost', '127.0.0.1', '0.0.0.0'], true)) {
                $base = $this->imageBaseUrl();
                $path = (string) parse_url($urlOrPath, PHP_URL_PATH);
                $path = ltrim($path, '/');
                if ($path === '' || str_contains($path, '..')) return null;
                return $base . '/' . $path;
            }
            return $urlOrPath;
        }

        // Relative path
        return $this->toPublicImageUrl($urlOrPath);
    }

public function getProductImageUrls(int $productId, ?object $productRow): array
    {
        $pfx = (string) config('catalog.prefix');

        $images = [];
        $main = $this->normalizeImageUrl((string)($productRow?->image ?? ''));
        if ($main) {
            $images[] = $main;
        }

        $additional = DB::table($pfx.'product_image')
            ->where('product_id', $productId)
            ->orderBy('sort_order')
            ->pluck('image')
            ->toArray();

        foreach ($additional as $img) {
            $url = $this->normalizeImageUrl((string) $img);
            if ($url && !in_array($url, $images, true)) {
                $images[] = $url;
            }
        }

        // Lazada takes at most eight images on a product (its seller guide);
        // the first eight go, main image first, and the push is never refused
        // for the rest (the owner, 2026-09-05).
        return array_slice($images, 0, self::MAX_IMAGES);
    }

    /** How many images Lazada accepts on a product. */
    public const MAX_IMAGES = 8;

    /**
     * Lazada requires "inlink" images for product create/update.
     * If we send non-Lazada URLs, QC returns BIZ_CHECK_EXIST_OUTER_MAIN_IMAGE.
     */
    public function ensureLazadaInlinkImages(array $productPayload, $setting, LazadaClient $client): array
    {
        $creds = LazadaSetting::activeCredentials($setting);

        $images = data_get($productPayload, 'Request.Product.Images.Image', []);
        if (!is_array($images) || empty($images)) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'images' => 'At least 1 product image is required. (Product.Images.Image is empty.)'
            ]);
        }

        $images = array_values(array_filter($images, fn($v) => is_string($v) && trim($v) !== ''));
        if (empty($images)) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'images' => 'At least 1 product image is required. (No valid image URL found.)'
            ]);
        }

        // Lazada migration APIs support up to 8 images per call; keep it predictable.
        $images = array_slice($images, 0, 8);

        // Reject localhost/private URLs: Lazada cannot fetch them.
        foreach ($images as $u) {
            $host = strtolower((string) parse_url($u, PHP_URL_HOST));
            if ($host === '' || in_array($host, ['localhost', '127.0.0.1', '0.0.0.0'], true)) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'images' => 'Your image URLs are using a local host (e.g., localhost). Lazada cannot access these. Please set APP_URL to your public domain and ensure the images are publicly reachable.'
                ]);
            }
        }

        $region = (string) $setting->region;
        $inlinks = $this->migrateImagesToLazadaInlinks(
            $region,
            $images,
            (string) $creds['app_key'],
            (string) $creds['app_secret'],
            (string) $creds['access_token'],
            $client
        );

        // Build an original->inlink map so we can sanitize SKU-level images reliably.
        $imageMap = [];
        foreach ($images as $i => $orig) {
            $orig = is_string($orig) ? trim($orig) : '';
            $in = $inlinks[$i] ?? null;
            if ($orig !== '' && is_string($in) && trim($in) !== '') {
                $imageMap[$orig] = $in;
            }
        }

        data_set($productPayload, 'Request.Product.Images.Image', $inlinks);

        // Sanitize SKU-level images:
        // Lazada rejects *any* non-Lazada URLs at SKU Images. Option images are optional,
        // so if we can't safely migrate them, we drop them.
        $skuRows = data_get($productPayload, 'Request.Product.Skus.Sku', []);
        if (is_array($skuRows)) {
            foreach ($skuRows as $idx => $row) {
                if (!is_array($row)) {
                    continue;
                }

                $skuImgs = data_get($row, 'Images.Image');
                if (!is_array($skuImgs)) {
                    $skuImgs = [];
                }

                $skuImgs = array_values(array_filter(array_map(fn($u) => is_string($u) ? trim($u) : '', $skuImgs), fn($u) => $u !== ''));

                if (!empty($skuImgs)) {
                    $clean = [];
                    foreach ($skuImgs as $u) {
                        // Already a Lazada inlink -> keep
                        if ($this->isLazadaInlinkUrl($u)) {
                            $clean[] = $u;
                            continue;
                        }

                        // If it matches a product image we already migrated, reuse its inlink.
                        if (isset($imageMap[$u]) && $this->isLazadaInlinkUrl($imageMap[$u])) {
                            $clean[] = $imageMap[$u];
                            continue;
                        }

                        // Best-effort: migrate this SKU image. If migration fails, drop it.
                        $newUrl = $this->migrateSingleImageXml(
                            $region,
                            $u,
                            (string) $creds['app_key'],
                            (string) $creds['app_secret'],
                            (string) $creds['access_token'],
                            $client
                        );
                        if (is_string($newUrl) && $newUrl !== '' && $this->isLazadaInlinkUrl($newUrl)) {
                            $clean[] = $newUrl;
                        }
                    }

                    $clean = array_values(array_unique($clean));
                    if (!empty($clean)) {
                        $row['Images'] = ['Image' => array_slice($clean, 0, 8)];
                    } else {
                        unset($row['Images']);
                    }
                } else {
                    // If empty/invalid, remove Images key to avoid sending blanks.
                    if (isset($row['Images'])) {
                        unset($row['Images']);
                    }
                }

                $skuRows[$idx] = $row;
            }

            data_set($productPayload, 'Request.Product.Skus.Sku', $skuRows);
        }

        // SKU-level main image: force first SKU image to the first inlink.
        $first = $inlinks[0] ?? null;
        if ($first && $this->isLazadaInlinkUrl((string)$first)) {
            $sku0 = data_get($productPayload, 'Request.Product.Skus.Sku.0');
            if (is_array($sku0)) {
                $sku0['Images'] = ['Image' => [(string)$first]];
                data_set($productPayload, 'Request.Product.Skus.Sku.0', $sku0);
            }
        }

        return $productPayload;
    }

    /**
     * Determine whether a URL is a Lazada inlink (i.e., Lazada-hosted image URL).
     *
     * Lazada rejects non-Lazada URLs in SKU Images (BIZ_CHECK_EXIST_OUTER_SKU_IMAGE).
     */
    private function isLazadaInlinkUrl(string $url): bool
    {
        $url = trim($url);
        if ($url === '') return false;
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        if ($host === '') return false;

        // Common Lazada/CDN hosts across regions and sandbox.
        if (str_ends_with($host, 'slatic.net')) return true;
        if (str_ends_with($host, 'lazcdn.com')) return true;
        if (str_contains($host, 'lazada')) return true; // defensive

        return false;
    }

    /**
     * Convert external/public image URLs to Lazada inlinks.
     *
     * IMPORTANT: We use /image/migrate (single-image) only.
     * Lazada's product APIs do not allow non-Lazada URLs in Images, and /image/migrate
     * returns the inlink immediately (data.image.url). This avoids the batch polling
     * complexity (/images/migrate + /image/response/get) that caused repeated format/
     * method errors during testing.
     */
    public function migrateImagesToLazadaInlinks(string $region, array $imageUrls, string $appKey, string $appSecret, string $accessToken, LazadaClient $client): array
    {
    $imageUrls = array_values(array_filter(array_map(fn($u) => trim((string)$u), $imageUrls), fn($u) => $u !== ''));
    $imageUrls = array_slice($imageUrls, 0, 8);

    // Keep Lazada inlinks as-is (no need to re-migrate).
    // This also prevents us from accidentally sending an outer URL to /image/migrate.
    $already = [];
    $needsInput = [];
    foreach ($imageUrls as $u) {
        if ($this->isLazadaInlinkUrl($u)) {
            $already[$u] = $u;
        } else {
            $needsInput[] = $u;
        }
    }

    // Serve from cache first.
    $out = [];
    $needs = [];
    foreach ($needsInput as $url) {
        $hash = hash('sha256', $url);
        $cached = LazadaImageLink::query()
            ->where('region', $region)
            ->where('original_hash', $hash)
            ->value('lazada_url');
        if ($cached) {
            $out[$url] = $cached;
        } else {
            $needs[] = $url;
        }
    }

    if (!empty($needs)) {
        // Prefer batch migration first (faster for multiple images). If it fails or partially succeeds,
        // fall back to the proven single-image migrate call.
        $batchMap = $this->tryMigrateBatchImagesXml($region, $needs, $appKey, $appSecret, $accessToken, $client);

        foreach ($needs as $originalUrl) {
            $newUrl = $batchMap[$originalUrl] ?? null;
            if (!is_string($newUrl) || trim($newUrl) === '') {
                $newUrl = $this->migrateSingleImageXml($region, $originalUrl, $appKey, $appSecret, $accessToken, $client);
            }

            if (!$newUrl) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'images' => 'Failed to migrate image to Lazada inlink. Please ensure the image URL is public and reachable.'
                ]);
            }

            $hash = hash('sha256', $originalUrl);
            LazadaImageLink::query()->updateOrCreate(
                ['region' => $region, 'original_hash' => $hash],
                ['original_url' => $originalUrl, 'lazada_url' => $newUrl]
            );
            $out[$originalUrl] = $newUrl;
        }
    }

    // Return in the same order as input.
    $ordered = [];
    foreach ($imageUrls as $u) {
        if (isset($already[$u])) {
            $ordered[] = $already[$u];
            continue;
        }
        if (isset($out[$u])) $ordered[] = $out[$u];
    }

    if (empty($ordered)) {
        throw \Illuminate\Validation\ValidationException::withMessages([
            'images' => 'Unable to prepare Lazada image links. No images were migrated.'
        ]);
    }

    return $ordered;
    }

    /**
     * Migrate a single image using /image/migrate with the proven XML payload.
     * Returns Lazada inlink (data.image.url) or null on failure.
     */
    private function migrateSingleImageXml(string $region, string $url, string $appKey, string $appSecret, string $accessToken, LazadaClient $client): ?string
    {
        $apiPath = '/image/migrate';

        // Lazada API Explorer + SDK example format
        $xml = '<?xml version="1.0" encoding="UTF-8" ?>'
            . '<Request>'
            . '<Image><Url>' . htmlspecialchars($url, ENT_XML1) . '</Url></Image>'
            . '</Request>';

        $timestamp = (string) round(microtime(true) * 1000);
        $params = [
            'app_key' => $appKey,
            'sign_method' => 'sha256',
            'timestamp' => $timestamp,
            'access_token' => $accessToken,
            'payload' => $xml,
        ];

        $params['sign'] = $client->sign($apiPath, $params, $appSecret);
        $resp = $client->post($region, $apiPath, $params);
        $body = $resp['body'] ?? [];

        $inlink = data_get($body, 'data.image.url') ?? data_get($body, 'data.image.Url');
        $ok = is_string($inlink) && $inlink !== '';

        LazadaApiLog::safeCreate([
            'pack'            => 'lazada.image.migrate.single',
            'method'          => 'POST',
            'api_path'        => $apiPath,
            'auth_required'   => true,
            'request_params'  => $params,
            'response_status' => (int) ($resp['status'] ?? 0),
            'ok'              => $ok,
            'response_body'   => $body,
            'user_id'         => auth()->id(),
        ]);

        return $ok ? $inlink : null;
    }

private function tryMigrateBatchImagesXml(string $region, array $urls, string $appKey, string $appSecret, string $accessToken, LazadaClient $client): array
{
    $urls = array_values(array_slice(array_filter(array_map(fn($u) => trim((string)$u), $urls), fn($u) => $u !== ''), 0, 8));
    if (empty($urls)) return [];

    $apiPath = '/images/migrate';

    // Prepare candidate request formats.
    $candidates = [];

    // A) XML payload under "payload"
    $xml = '<?xml version="1.0" encoding="UTF-8" ?>'
        . '<Request><Images>'
        . implode('', array_map(fn($u) => '<Url>' . htmlspecialchars($u, ENT_XML1) . '</Url>', $urls))
        . '</Images></Request>';
    $candidates[] = ['payload' => $xml];

    // B) JSON payload under "payload"
    $json = json_encode(['Request' => ['Images' => ['Url' => $urls]]], JSON_UNESCAPED_SLASHES);
    if (is_string($json)) {
        $candidates[] = ['payload' => $json];
    }

    // C) Direct param list (some gateways)
    $candidates[] = ['urls' => json_encode($urls, JSON_UNESCAPED_SLASHES)];

    foreach ($candidates as $extra) {
        $timestamp = (string) round(microtime(true) * 1000);
        $params = array_merge([
            'app_key' => $appKey,
            'sign_method' => 'sha256',
            'timestamp' => $timestamp,
            'access_token' => $accessToken,
        ], $extra);
        $params['sign'] = $client->sign($apiPath, $params, $appSecret);

        $resp = $client->post($region, $apiPath, $params);
        $body = $resp['body'] ?? [];

        $batchId = data_get($body, 'batch_id')
            ?? data_get($body, 'data.batch_id')
            ?? data_get($body, 'data.batchId')
            ?? data_get($body, 'request_id')
            ?? data_get($body, 'data.request_id')
            ?? data_get($body, 'data.requestId');

        LazadaApiLog::safeCreate([
            'pack'            => 'lazada.images.migrate.batch',
            'method'          => 'POST',
            'api_path'        => $apiPath,
            'auth_required'   => true,
            'request_params'  => $params,
            'response_status' => (int) ($resp['status'] ?? 0),
            'ok'              => (bool) $batchId,
            'response_body'   => $body,
            'user_id'         => auth()->id(),
        ]);

        if (!$batchId) {
            continue;
        }

        usleep(650000);

        $apiPath2 = '/image/response/get';

        // Lazada docs often show GET, but in production some gateways are strict.
        // Try POST (form) first, then GET as fallback.
        foreach (['batch_id', 'request_id'] as $idKey) {
            $timestamp2 = (string) round(microtime(true) * 1000);
            $params2 = [
                'app_key' => $appKey,
                'sign_method' => 'sha256',
                'timestamp' => $timestamp2,
                'access_token' => $accessToken,
                $idKey => (string) $batchId,
            ];
            $params2['sign'] = $client->sign($apiPath2, $params2, $appSecret);

            $resp2 = $client->post($region, $apiPath2, $params2);
            $body2 = $resp2['body'] ?? [];

            $code2 = is_array($body2) ? (string)($body2['code'] ?? '') : '';
            if (!($resp2['ok'] ?? false) || ($code2 !== '' && $code2 !== '0')) {
                $params2['sign'] = $client->sign($apiPath2, $params2, $appSecret);
                $resp2 = $client->get($region, $apiPath2, $params2);
                $body2 = $resp2['body'] ?? [];
            }

            $items = data_get($body2, 'data.images')
                ?? data_get($body2, 'data.image')
                ?? data_get($body2, 'data')
                ?? data_get($body2, 'result')
                ?? [];

            if (isset($items['images']) && is_array($items['images'])) $items = $items['images'];
            if (isset($items['Images']) && is_array($items['Images'])) $items = $items['Images'];
            if (isset($items['image']) && is_array($items['image'])) $items = $items['image'];
            if (isset($items['Image']) && is_array($items['Image'])) $items = $items['Image'];

            $migrated = [];
            if (is_array($items)) {
                foreach ($items as $it) {
                    if (is_string($it)) {
                        $migrated[] = $it;
                        continue;
                    }
                    if (!is_array($it)) continue;

                    $migrated[] = $it['url']
                        ?? $it['Url']
                        ?? ($it['image']['url'] ?? null)
                        ?? ($it['image']['Url'] ?? null)
                        ?? $it['image']
                        ?? $it['image_url']
                        ?? null;
                }
            }

            $migrated = array_values(array_filter($migrated, fn($v) => is_string($v) && $v !== ''));

            if (count($migrated) === count($urls)) {
                $map = [];
                foreach ($urls as $i => $orig) {
                    $map[$orig] = $migrated[$i] ?? null;
                }
                return $map;
            }
        }
    }

    return array_fill_keys($urls, null);
}
}
