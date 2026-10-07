<?php

namespace Extensions\tiktok\Services\TikTok;

use App\Integrations\Listings\RichDescription;
use App\Services\Media\StoreReadyImages;
use Extensions\tiktok\Models\TikTokApiLog;
use Extensions\tiktok\Models\TikTokDescriptionImage;
use Illuminate\Support\Facades\Storage;

class TikTokDescriptionImages
{
    private const TIKTOK_HOSTS = ['ibyteimg.com', 'tiktokcdn.com', 'tiktokcdn-us.com', 'byteimg.com'];

    public function host(string $html, array $c, TikTokClient $client, int $storeId): array
    {
        if (stripos($html, '<img') === false) {
            return ['html' => $html, 'left_out' => 0];
        }

        $leftOut = 0;
        $sent = 0;
        $memo = [];

        $out = (string) preg_replace_callback('#<img\b[^>]*>#i', function (array $m) use (&$leftOut, &$sent, &$memo, $c, $client, $storeId): string {
            $tag = $sent < RichDescription::TIKTOK_MAX_IMAGES ? $this->hosted($m[0], $c, $client, $storeId, $memo) : null;
            if ($tag === null) {
                $leftOut++;

                return '';
            }
            $sent++;

            return $tag;
        }, $html);

        return ['html' => $out, 'left_out' => $leftOut];
    }

    private function hosted(string $tag, array $c, TikTokClient $client, int $storeId, array &$memo): ?string
    {
        $src = self::src($tag);
        if ($src === null) {
            return null;
        }
        if (self::onTikTok($src)) {
            return $tag;
        }

        $path = self::ownPath($src);
        $fitted = $path !== null ? StoreReadyImages::fit($path, StoreReadyImages::TIKTOK) : null;
        if ($fitted === null) {
            return null;
        }
        $disk = Storage::disk('public');
        try {
            $bytes = (string) $disk->get($fitted);
        } catch (\Throwable) {
            return null;
        }
        if ($bytes === '') {
            return null;
        }
        $hash = hash('sha256', $bytes);

        $held = $memo[$hash] ??= $this->remembered($storeId, $hash) ?? $this->upload($bytes, $fitted, $hash, $c, $client, $storeId);
        if ($held === false) {
            return null;
        }

        return '<img src="' . e($held['url']) . '" width="' . $held['width'] . '" height="' . $held['height'] . '">';
    }

    private function remembered(int $storeId, string $hash): ?array
    {
        try {
            $row = TikTokDescriptionImage::query()->where('tiktok_setting_id', $storeId)->where('content_hash', $hash)->first();
        } catch (\Throwable) {
            return null;
        }

        return $row ? ['url' => (string) $row->url, 'width' => (int) $row->width, 'height' => (int) $row->height] : null;
    }

    private function upload(string $bytes, string $path, string $hash, array $c, TikTokClient $client, int $storeId): array|false
    {
        try {
            $result = $client->uploadDescriptionImage($c['app_key'], $c['app_secret'], $c['token'], $bytes, ($c['shop_cipher'] ?? '') ?: null);
        } catch (\Throwable $e) {
            $result = ['ok' => false, 'status' => 0, 'body' => ['message' => \App\Support\TransportError::plain($e, 'TikTok Shop')]];
        }
        TikTokApiLog::safeCreate([
            'pack' => 'tiktok.products.push', 'method' => 'POST',
            'api_path' => '/product/202309/images/upload', 'auth_required' => true,
            'request_params' => ['image' => $path, 'use_case' => 'DESCRIPTION_IMAGE'],
            'response_status' => $result['status'] ?? 0,
            'ok' => (bool) ($result['ok'] ?? false),
            'response_body' => is_array($result['body'] ?? null) ? $result['body'] : [],
            'user_id' => auth()->id(),
        ]);

        $data = is_array($result['body'] ?? null) ? ($result['body']['data'] ?? []) : [];
        $url = trim((string) ($data['url'] ?? ''));
        if (! ($result['ok'] ?? false) || (int) ($result['body']['code'] ?? -1) !== 0
            || ! preg_match('#^https://[^\s"\'<>]+$#', $url) || strlen($url) > 1024) {
            return false;
        }

        $width = (int) ($data['width'] ?? 0);
        $height = (int) ($data['height'] ?? 0);
        if ($width < 1 || $height < 1) {
            $size = @getimagesizefromstring($bytes);
            [$width, $height] = $size ? [(int) $size[0], (int) $size[1]] : [0, 0];
        }
        if ($width < 1 || $height < 1) {
            return false;
        }

        $held = ['url' => $url, 'width' => $width, 'height' => $height];
        try {
            TikTokDescriptionImage::query()->upsert([[
                'tiktok_setting_id' => $storeId, 'content_hash' => $hash,
                'url' => $url, 'uri' => mb_substr((string) ($data['uri'] ?? ''), 0, 512) ?: null,
                'width' => $width, 'height' => $height,
                'created_at' => now(), 'updated_at' => now(),
            ]], ['tiktok_setting_id', 'content_hash'], ['url', 'uri', 'width', 'height', 'updated_at']);
        } catch (\Throwable) {
        }

        return $held;
    }

    private static function src(string $tag): ?string
    {
        if (! preg_match('#\bsrc\s*=\s*(?:"([^"]*)"|\'([^\']*)\')#i', $tag, $m)) {
            return null;
        }
        $src = trim(html_entity_decode(($m[1] ?? '') !== '' ? $m[1] : ($m[2] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        return $src !== '' ? $src : null;
    }

    private static function onTikTok(string $src): bool
    {
        if (! str_starts_with(strtolower($src), 'https://')) {
            return false;
        }
        $host = strtolower((string) parse_url($src, PHP_URL_HOST));
        foreach (self::TIKTOK_HOSTS as $known) {
            if ($host === $known || str_ends_with($host, '.' . $known)) {
                return true;
            }
        }

        return false;
    }

    public static function ownPath(string $src): ?string
    {
        $bare = preg_replace('#^https?:#i', '', $src);
        $rest = null;
        foreach ([asset('storage'), rtrim((string) config('app.url'), '/') . '/storage'] as $base) {
            $base = rtrim((string) preg_replace('#^https?:#i', '', $base), '/') . '/';
            if ($base !== '/' && str_starts_with(strtolower($bare), strtolower($base))) {
                $rest = substr($bare, strlen($base));
                break;
            }
        }
        if ($rest === null && str_starts_with($src, '/storage/')) {
            $rest = substr($src, strlen('/storage/'));
        }
        if ($rest === null) {
            return null;
        }

        $rest = (string) preg_replace('/[?#].*$/s', '', $rest);
        $segments = array_map('rawurldecode', explode('/', $rest));
        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..' || preg_match('/[\x00-\x1F\x7F\\\\]/', $segment)) {
                return null;
            }
        }

        return implode('/', $segments);
    }
}
