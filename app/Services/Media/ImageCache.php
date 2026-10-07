<?php

namespace App\Services\Media;

use App\Models\Setting;
use Illuminate\Support\Facades\Schema;
use App\Models\WatermarkTemplate;
use Illuminate\Support\Facades\Storage;

final class ImageCache
{
    public const DIR = 'cache';

    public const THUMB = 'thumb';
    public const PREVIEW = 'preview';
    public const PUSH = 'push';

    public const DEFAULTS = [self::THUMB => 200, self::PREVIEW => 500, self::PUSH => 1000];

    public const LIMITS = [self::THUMB => [50, 1000], self::PREVIEW => [100, 2000], self::PUSH => [300, 4000]];

    // Lazada refuses pictures over 1 MB, the lowest limit of the three stores.
    public const PUSH_MAX_BYTES = 1000000;

    private const QUALITIES = [88, 82, 76, 70, 64, 58];

    private static ?array $sides = null;

    public static function side(string $kind): int
    {
        if (self::$sides === null) {
            $setting = Schema::hasTable('settings') && Schema::hasColumn('settings', 'image_thumb_size')
                ? Setting::query()->first()
                : null;
            self::$sides = [];
            foreach (self::DEFAULTS as $k => $default) {
                [$min, $max] = self::LIMITS[$k];
                $value = (int) ($setting?->{'image_' . $k . '_size'} ?? $default);
                self::$sides[$k] = $value >= $min && $value <= $max ? $value : $default;
            }
        }

        return self::$sides[$kind] ?? self::DEFAULTS[self::THUMB];
    }

    public static function forget(): void
    {
        self::$sides = null;
    }

    public static function url(?string $path, string $kind = self::THUMB): ?string
    {
        $path = trim((string) $path);
        if ($path === '') {
            return null;
        }

        return self::publicUrl(self::path($path, $kind) ?? ltrim($path, '/'));
    }

    public static function appUrl(?string $path, string $kind = self::THUMB): ?string
    {
        $url = rescue(fn () => self::url($path, $kind), null, false);
        if ($url !== null && str_starts_with($url, 'http://') && str_starts_with((string) config('app.url'), 'https://')) {
            $url = 'https://' . substr($url, 7);
        }

        return $url !== null && str_starts_with($url, 'https://') ? $url : null;
    }

    public static function path(string $path, string $kind = self::THUMB): ?string
    {
        $source = self::safe($path);
        if ($source === null) {
            return null;
        }
        if (str_starts_with($source, self::DIR . '/') || str_starts_with($source, StampedImages::DIR . '/')) {
            return $source;
        }

        $disk = Storage::disk('public');
        if (! $disk->exists($source)) {
            return null;
        }

        $side = self::side($kind);
        $target = self::target($source, $side);
        if (self::fresh($target, $source)) {
            return $target;
        }

        $bytes = self::squareJpeg($disk->path($source), $side, self::PUSH_MAX_BYTES);
        if ($bytes === null) {
            return null;
        }
        $disk->put($target, $bytes);

        return $target;
    }

    public static function existing(string $path, string $kind = self::THUMB): ?string
    {
        $source = self::safe($path);
        if ($source === null) {
            return null;
        }
        $target = self::target($source, self::side($kind));

        return self::fresh($target, $source) ? self::publicUrl($target) : null;
    }

    public static function pushPaths(array $paths): array
    {
        return array_map(fn ($p) => self::path((string) $p, self::PUSH) ?? (string) $p, array_values($paths));
    }

    public static function pushLocalFile(string $absolute): string
    {
        $disk = Storage::disk('public');
        foreach ([rtrim($disk->path(''), '/'), rtrim(public_path('storage'), '/')] as $root) {
            if ($root !== '' && str_starts_with($absolute, $root . '/')) {
                $copy = self::path(substr($absolute, strlen($root) + 1), self::PUSH);

                return $copy !== null ? $disk->path($copy) : $absolute;
            }
        }

        return $absolute;
    }

    public static function target(string $source, int $side, string $mark = ''): string
    {
        $info = pathinfo($source);
        $dir = ($info['dirname'] ?? '.') === '.' ? '' : $info['dirname'] . '/';
        $stamp = $mark === '' ? '' : '-w' . $mark;

        return self::DIR . '/' . $dir . $info['filename'] . '-' . $side . 'x' . $side . $stamp . '.jpg';
    }

    public static function marked(string $path, WatermarkTemplate $template, string $kind = self::THUMB): ?string
    {
        $source = self::safe($path);
        if ($source === null || str_starts_with($source, self::DIR . '/')) {
            return null;
        }

        $disk = Storage::disk('public');
        if (! $disk->exists($source)) {
            return null;
        }

        $side = self::side($kind);
        $target = self::target($source, $side, $template->fingerprint());
        if (self::fresh($target, $source)) {
            return $target;
        }

        $plain = self::path($source, $kind);
        if ($plain === null) {
            return null;
        }

        $bytes = $template->stamp($disk->path($plain));
        if ($bytes === null) {
            return null;
        }
        $bytes = self::compressed($bytes, self::PUSH_MAX_BYTES);
        if ($bytes === null) {
            return null;
        }
        $disk->put($target, $bytes);

        return $target;
    }

    public static function markedUrl(?string $path, ?WatermarkTemplate $template, string $kind = self::THUMB): ?string
    {
        $path = trim((string) $path);
        if ($path === '') {
            return null;
        }
        if ($template !== null && ($marked = self::marked($path, $template, $kind)) !== null) {
            return self::publicUrl($marked);
        }

        return self::url($path, $kind);
    }

    public static function fresh(string $target, string $source): bool
    {
        $disk = Storage::disk('public');

        return $disk->exists($target) && $disk->lastModified($target) >= $disk->lastModified($source);
    }

    public static function squareJpeg(string $absolute, int $side, ?int $maxBytes = null): ?string
    {
        $source = self::open($absolute);
        if ($source === null) {
            return null;
        }

        $width = imagesx($source);
        $height = imagesy($source);
        for ($s = $side; $s >= min($side, self::LIMITS[self::PUSH][0]); $s = (int) floor($s * 0.85)) {
            $scale = $s / max($width, $height);
            $w = max(1, (int) round($width * $scale));
            $h = max(1, (int) round($height * $scale));

            $canvas = imagecreatetruecolor($s, $s);
            imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255));
            imagecopyresampled($canvas, $source, intdiv($s - $w, 2), intdiv($s - $h, 2), 0, 0, $w, $h, $width, $height);

            $out = self::encode($canvas, $maxBytes);
            if ($out !== null || $maxBytes === null) {
                return $out;
            }
        }

        return null;
    }

    public static function compressed(string $bytes, int $maxBytes): ?string
    {
        if (strlen($bytes) <= $maxBytes) {
            return $bytes;
        }
        $image = @imagecreatefromstring($bytes);
        if (! $image) {
            return null;
        }

        return self::encode($image, $maxBytes);
    }

    public static function publicUrl(string $path): string
    {
        return asset('storage/' . implode('/', array_map('rawurlencode', explode('/', ltrim($path, '/')))));
    }

    private static function safe(string $path): ?string
    {
        $path = trim(str_replace('\\', '/', $path), '/');
        if ($path === '' || str_contains($path, "\0")) {
            return null;
        }
        foreach (explode('/', $path) as $segment) {
            if ($segment === '..' || $segment === '.') {
                return null;
            }
        }

        return $path;
    }

    private static function encode(\GdImage $image, ?int $maxBytes): ?string
    {
        imageinterlace($image, true);
        foreach (self::QUALITIES as $quality) {
            ob_start();
            imagejpeg($image, null, $quality);
            $out = (string) ob_get_clean();
            if ($maxBytes === null || strlen($out) <= $maxBytes) {
                return $out !== '' ? $out : null;
            }
        }

        return null;
    }

    private static function open(string $absolute): ?\GdImage
    {
        $info = @getimagesize($absolute);
        if ($info === false || (int) $info[0] < 1 || (int) $info[1] < 1) {
            return null;
        }
        $image = match ((int) $info[2]) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($absolute),
            IMAGETYPE_PNG => @imagecreatefrompng($absolute),
            IMAGETYPE_GIF => @imagecreatefromgif($absolute),
            IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($absolute) : false,
            IMAGETYPE_BMP => function_exists('imagecreatefrombmp') ? @imagecreatefrombmp($absolute) : false,
            default => false,
        };

        return $image instanceof \GdImage ? $image : null;
    }
}
