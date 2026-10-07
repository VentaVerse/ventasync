<?php

namespace App\Services\Media;

use Illuminate\Support\Facades\Storage;

final class BrandImage
{
    public const NAV = 'nav';
    public const LOGIN = 'login';

    public const DEFAULTS = [
        self::NAV => 44,
        self::LOGIN => 96,
    ];

    public const WIDTHS = [
        self::NAV => 184,
        self::LOGIN => 420,
    ];

    public const LIMITS = ['h' => [16, 400]];

    private static ?array $boxes = null;

    public static function forget(): void
    {
        self::$boxes = null;
    }

    public static function box(string $place): array
    {
        if (self::$boxes === null) {
            self::$boxes = [];
            foreach (self::DEFAULTS as $key => $height) {
                self::$boxes[$key] = [
                    self::WIDTHS[$key],
                    self::clamp((int) self::setting('logo_' . $key . '_h', $height), self::LIMITS['h']),
                ];
            }
        }

        return self::$boxes[$place] ?? [self::WIDTHS[self::NAV], self::DEFAULTS[self::NAV]];
    }

    public static function height(string $place): int
    {
        return self::box($place)[1];
    }

    public static function url(?string $uploaded, string $place): string
    {
        return self::show($uploaded, $place)['url'];
    }

    public static function show(?string $uploaded, string $place): array
    {
        $uploaded = trim((string) $uploaded);

        if ($uploaded === '') {
            $shipped = self::shipped($place);

            return $shipped !== null
                ? self::measured(asset('storage/' . $shipped), Storage::disk('public')->path($shipped), $place)
                : self::measured(asset('images/brand/ventasync.png'), public_path('images/brand/ventasync.png'), $place);
        }

        $fitted = self::fitted($uploaded, $place);
        $path = $fitted ?? ltrim($uploaded, '/');

        return self::measured(asset('storage/' . $path), Storage::disk('public')->path($path), $place);
    }

    private static function measured(string $url, string $absolute, string $place): array
    {
        $size = is_file($absolute) ? @getimagesize($absolute) : false;
        if ($size === false || empty($size[0]) || empty($size[1])) {
            [$w, $h] = self::box($place);

            return ['url' => $url, 'width' => $w, 'height' => $h];
        }

        return ['url' => $url, 'width' => (int) $size[0], 'height' => (int) $size[1]];
    }

    public static function shipped(string $place): ?string
    {
        $source = public_path('images/brand/ventasync.png');
        if (! is_file($source)) {
            return null;
        }

        [$w, $h] = self::box($place);
        $target = ImageCache::DIR . '/brand/ventasync-' . $w . 'x' . $h . 'fit.png';

        $disk = Storage::disk('public');
        if ($disk->exists($target) && $disk->lastModified($target) >= filemtime($source)) {
            return $target;
        }

        $bytes = self::fitPng($source, $w, $h);
        if ($bytes === null) {
            return null;
        }
        $disk->put($target, $bytes);

        return $target;
    }

    public static function fitted(string $path, string $place): ?string
    {
        $path = ltrim(trim($path), '/');
        if ($path === '' || str_contains($path, '..') || str_starts_with($path, ImageCache::DIR . '/')) {
            return null;
        }

        $disk = Storage::disk('public');
        if (! $disk->exists($path)) {
            return null;
        }

        [$w, $h] = self::box($place);
        $info = pathinfo($path);
        $dir = ($info['dirname'] ?? '.') === '.' ? '' : $info['dirname'] . '/';
        $target = ImageCache::DIR . '/' . $dir . $info['filename'] . '-' . $w . 'x' . $h . 'fit.png';

        if (ImageCache::fresh($target, $path)) {
            return $target;
        }

        $bytes = self::fitPng($disk->path($path), $w, $h);
        if ($bytes === null) {
            return null;
        }
        $disk->put($target, $bytes);

        return $target;
    }

    private static function fitPng(string $absolute, int $boxW, int $boxH): ?string
    {
        $bytes = @file_get_contents($absolute);
        if ($bytes === false) {
            return null;
        }
        $source = @imagecreatefromstring($bytes);
        if (! $source) {
            return null;
        }

        $width = imagesx($source);
        $height = imagesy($source);
        $scale = min($boxW / $width, $boxH / $height, 1);
        $w = max(1, (int) round($width * $scale));
        $h = max(1, (int) round($height * $scale));

        $canvas = imagecreatetruecolor($w, $h);
        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);
        imagefill($canvas, 0, 0, imagecolorallocatealpha($canvas, 0, 0, 0, 127));
        imagecopyresampled($canvas, $source, 0, 0, 0, 0, $w, $h, $width, $height);

        ob_start();
        $ok = imagepng($canvas, null, 8);

        return $ok ? (string) ob_get_clean() : (ob_end_clean() ? null : null);
    }

    private static function clamp(int $value, array $range): int
    {
        return max($range[0], min($range[1], $value));
    }

    private static function setting(string $column, int $fallback): int
    {
        try {
            $value = \App\Models\Setting::query()->value($column);

            return $value === null ? $fallback : (int) $value;
        } catch (\Throwable) {
            return $fallback;
        }
    }
}
