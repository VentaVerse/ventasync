<?php

namespace Extensions\lazada\Services\Lazada;

use Extensions\lazada\Models\LazadaApiLog;
use Extensions\lazada\Models\LazadaImageLink;
use Extensions\lazada\Models\LazadaSetting;
use Illuminate\Support\Str;

class LazadaImages
{
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

        if (Str::startsWith($path, ['storage/', 'image/'])) {
            return '/' . $this->encodeUrlPath($path);
        }

        $path = \App\Services\Media\ImageCache::path($path) ?? $path;

        try {
            if (file_exists(public_path('storage/' . $path))) {
                return '/' . $this->encodeUrlPath('storage/' . $path);
            }
        } catch (\Throwable $e) {
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

        if (str_contains($imagePath, '..')) {
            return null;
        }

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

        if (Str::startsWith($path, ['storage/', 'image/'])) {
            return $base . '/' . $this->encodeUrlPath($path);
        }

        $storageCandidate = 'storage/' . $path;
        try {
            if (file_exists(public_path($storageCandidate))) {
                return $base . '/' . $this->encodeUrlPath($storageCandidate);
            }
        } catch (\Throwable $e) {
        }

        $prefix = trim((string) config('catalog.image_prefix', 'image'), '/');
        if ($prefix === '') {
            $prefix = 'image';
        }
        $prefixed = $prefix . '/' . $path;

        return $base . '/' . $this->encodeUrlPath($prefixed);
    }

    private function encodeUrlPath(string $path): string
    {
        $path = ltrim($path, '/');
        $parts = array_map('rawurlencode', array_filter(explode('/', $path), fn($p) => $p !== ''));
        return implode('/', $parts);
    }

    private function imageBaseUrl(): string
    {
        $base = rtrim((string) config('catalog.public_url', config('app.url')), '/');
        $host = strtolower((string) (parse_url($base, PHP_URL_HOST) ?? ''));

        if ($base === '' || $host === '' || in_array($host, ['localhost', '127.0.0.1', '0.0.0.0'], true)) {
            try {
                $base = rtrim(request()->getSchemeAndHttpHost(), '/');
            } catch (\Throwable $e) {
            }
        }

        return $base;
    }

    public function normalizeImageUrl(string $urlOrPath): ?string
    {
        $urlOrPath = trim($urlOrPath);
        if ($urlOrPath === '') return null;

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

        return $this->toPublicImageUrl($urlOrPath);
    }

    public function getProductImageUrls(int $productId, ?object $productRow, ?array $own = null, ?object $listing = null): array
    {
        $paths = \App\Services\Media\ListingWatermark::paths(
            \App\Integrations\Listings\ListingImages::sending(\App\Support\Catalog\ProductImages::paths($productId, $own), $listing),
            $listing,
            \App\Services\Media\ListingWatermark::groupTemplateId('lazada_product_groups', 'lazada_product_group_products', 'lazada_product_group_id', $productId, 'lazada_setting_id', (int) ($listing?->lazada_setting_id ?? 0) ?: null),
            'lazada',
            (int) ($listing?->lazada_setting_id ?? 0)
        );
        $paths = \App\Services\Media\ImageCache::pushPaths($paths);
        $images = [];
        foreach ($paths as $path) {
            $url = $this->normalizeImageUrl($path);
            if ($url && ! in_array($url, $images, true)) {
                $images[] = $url;
            }
        }

        return $images;
    }

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

        $images = array_slice($images, 0, 8);

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

        $imageMap = [];
        foreach ($images as $i => $orig) {
            $orig = is_string($orig) ? trim($orig) : '';
            $in = $inlinks[$i] ?? null;
            if ($orig !== '' && is_string($in) && trim($in) !== '') {
                $imageMap[$orig] = $in;
            }
        }

        data_set($productPayload, 'Request.Product.Images.Image', $inlinks);

        // Lazada rejects any non-Lazada URL on SKU images, so unmigrated ones are dropped.
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
                        if ($this->isLazadaInlinkUrl($u)) {
                            $clean[] = $u;
                            continue;
                        }

                        if (isset($imageMap[$u]) && $this->isLazadaInlinkUrl($imageMap[$u])) {
                            $clean[] = $imageMap[$u];
                            continue;
                        }

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
                    if (isset($row['Images'])) {
                        unset($row['Images']);
                    }
                }

                $skuRows[$idx] = $row;
            }

            data_set($productPayload, 'Request.Product.Skus.Sku', $skuRows);
        }

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

    private const HTML_ATTRIBUTES = ['description', 'short_description'];

    private const LOOPBACK = ['localhost', '127.0.0.1', '0.0.0.0'];

    public function ensureLazadaDescriptionImages(array $productPayload, $setting, LazadaClient $client): array
    {
        $attributes = data_get($productPayload, 'Request.Product.Attributes');
        if (!is_array($attributes)) {
            return [$productPayload, 0];
        }

        $creds = LazadaSetting::activeCredentials($setting);
        $region = (string) ($setting->region ?? '');
        $links = [];
        $leftOut = [];

        foreach (self::HTML_ATTRIBUTES as $key) {
            $html = $attributes[$key] ?? null;
            if (!is_string($html) || stripos($html, '<img') === false) {
                continue;
            }

            $attributes[$key] = preg_replace_callback('/<img\b[^>]*>/i', function (array $tag) use (&$links, &$leftOut, $region, $creds, $client) {
                if (!preg_match('/\ssrc\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'>]+))/i', $tag[0], $m)) {
                    return '';
                }
                $src = trim(html_entity_decode(($m[1] ?? '') . ($m[2] ?? '') . ($m[3] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                if ($src === '') {
                    return '';
                }

                if (!array_key_exists($src, $links)) {
                    $links[$src] = $this->descriptionImageLink($src, $region, $creds, $client);
                }
                $link = $links[$src];
                if ($link === null) {
                    $leftOut[$src] = true;

                    return '';
                }

                $img = (string) preg_replace('/\ssrcset\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s"\'>]+)/i', '', $tag[0]);

                return (string) preg_replace_callback(
                    '/(\ssrc\s*=\s*)(?:"[^"]*"|\'[^\']*\'|[^\s"\'>]+)/i',
                    fn (array $s) => $s[1] . '"' . htmlspecialchars($link, ENT_QUOTES, 'UTF-8') . '"',
                    $img,
                    1
                );
            }, $html) ?? $html;
        }

        data_set($productPayload, 'Request.Product.Attributes', $attributes);

        return [$productPayload, count($leftOut)];
    }

    public static function descriptionNote(int $leftOut): ?string
    {
        if ($leftOut < 1) {
            return null;
        }

        return $leftOut === 1
            ? '1 picture was left out of the description.'
            : $leftOut . ' pictures were left out of the description.';
    }

    public static function ofItem(array $item): array
    {
        $own = array_filter((array) ($item['images'] ?? []), fn ($u) => is_string($u) && trim($u) !== '');
        if ($own === []) {
            $own = [];
            foreach ((array) ($item['skus'] ?? []) as $sku) {
                foreach ((array) (is_array($sku) ? ($sku['Images'] ?? []) : []) as $u) {
                    if (is_string($u) && trim($u) !== '') {
                        $own[] = $u;
                    }
                }
            }
        }

        return array_values(array_unique(array_map([self::class, 'secure'], $own)));
    }

    public static function secure(string $url): string
    {
        $url = trim($url);

        return preg_match('#^http://#i', $url) ? 'https://' . substr($url, 7) : $url;
    }

    private function descriptionImageLink(string $src, string $region, array $creds, LazadaClient $client): ?string
    {
        if ($this->isLazadaInlinkUrl($src)) {
            return $src;
        }

        $url = $this->descriptionImageSource($src);
        if ($url === null || $region === '') {
            return null;
        }

        $hash = hash('sha256', $url);
        $cached = LazadaImageLink::query()
            ->where('region', $region)
            ->where('original_hash', $hash)
            ->value('lazada_url');
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        try {
            $link = $this->migrateSingleImageXml(
                $region,
                $url,
                (string) $creds['app_key'],
                (string) $creds['app_secret'],
                (string) $creds['access_token'],
                $client
            );
        } catch (\Throwable) {
            return null;
        }
        if (!is_string($link) || !$this->isLazadaInlinkUrl($link)) {
            return null;
        }

        LazadaImageLink::query()->updateOrCreate(
            ['region' => $region, 'original_hash' => $hash],
            ['original_url' => $url, 'lazada_url' => $link]
        );

        return $link;
    }

    private function descriptionImageSource(string $src): ?string
    {
        if (str_starts_with($src, '//')) {
            $src = 'https:' . $src;
        }
        $absolute = (bool) preg_match('#^https?://#i', $src);
        if (!$absolute && preg_match('#^[a-z][a-z0-9+.\-]*:#i', $src)) {
            return null;
        }

        $url = $src;
        if (!$absolute || $this->isOwnHost(strtolower((string) parse_url($src, PHP_URL_HOST)))) {
            $path = rawurldecode(ltrim((string) parse_url($src, PHP_URL_PATH), '/'));
            if ($path === '' || str_contains($path, '..') || str_contains($path, "\0")) {
                return null;
            }

            $onDisk = Str::startsWith($path, 'storage/') ? substr($path, strlen('storage/')) : $path;
            try {
                $fitted = \App\Services\Media\StoreReadyImages::fit($onDisk, \App\Services\Media\StoreReadyImages::LAZADA);
            } catch (\Throwable) {
                $fitted = null;
            }

            $url = $fitted !== null
                ? $this->toPublicImageUrl('storage/' . $fitted)
                : $this->toPublicImageUrl($path);
        }

        $host = strtolower((string) parse_url((string) $url, PHP_URL_HOST));

        return ($url !== null && $host !== '' && !in_array($host, self::LOOPBACK, true)) ? $url : null;
    }

    private function isOwnHost(string $host): bool
    {
        if ($host === '') {
            return false;
        }
        if (in_array($host, self::LOOPBACK, true)) {
            return true;
        }

        $own = [
            parse_url((string) config('app.url'), PHP_URL_HOST),
            parse_url((string) config('catalog.public_url'), PHP_URL_HOST),
        ];
        try {
            $own[] = request()->getHost();
        } catch (\Throwable) {
        }

        foreach ($own as $candidate) {
            if (is_string($candidate) && $candidate !== '' && strtolower($candidate) === $host) {
                return true;
            }
        }

        return false;
    }

    private function isLazadaInlinkUrl(string $url): bool
    {
        $url = trim($url);
        if ($url === '') return false;
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        if ($host === '') return false;

        if (str_ends_with($host, 'slatic.net')) return true;
        if (str_ends_with($host, 'lazcdn.com')) return true;
        if (str_contains($host, 'lazada')) return true;

        return false;
    }

    public function migrateImagesToLazadaInlinks(string $region, array $imageUrls, string $appKey, string $appSecret, string $accessToken, LazadaClient $client): array
    {
    $imageUrls = array_values(array_filter(array_map(fn($u) => trim((string)$u), $imageUrls), fn($u) => $u !== ''));
    $imageUrls = array_slice($imageUrls, 0, 8);

    $already = [];
    $needsInput = [];
    foreach ($imageUrls as $u) {
        if ($this->isLazadaInlinkUrl($u)) {
            $already[$u] = $u;
        } else {
            $needsInput[] = $u;
        }
    }

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
        $batchMap = $this->tryMigrateBatchImagesXml($region, $needs, $appKey, $appSecret, $accessToken, $client);

        foreach ($needs as $originalUrl) {
            $newUrl = $batchMap[$originalUrl] ?? null;
            if (!is_string($newUrl) || trim($newUrl) === '') {
                $newUrl = $this->migrateSingleImageXml($region, $originalUrl, $appKey, $appSecret, $accessToken, $client);
            }

            if (!$newUrl) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'images' => 'Lazada would not take the picture "' . basename((string) parse_url($originalUrl, PHP_URL_PATH)) . '".'
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

    private function migrateSingleImageXml(string $region, string $url, string $appKey, string $appSecret, string $accessToken, LazadaClient $client): ?string
    {
        $apiPath = '/image/migrate';

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

    $candidates = [];

    $xml = '<?xml version="1.0" encoding="UTF-8" ?>'
        . '<Request><Images>'
        . implode('', array_map(fn($u) => '<Url>' . htmlspecialchars($u, ENT_XML1) . '</Url>', $urls))
        . '</Images></Request>';
    $candidates[] = ['payload' => $xml];

    $json = json_encode(['Request' => ['Images' => ['Url' => $urls]]], JSON_UNESCAPED_SLASHES);
    if (is_string($json)) {
        $candidates[] = ['payload' => $json];
    }

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
