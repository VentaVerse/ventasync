<?php

namespace App\Services\Media;

use App\Models\Setting;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * SIZED COPIES OF A PICTURE, MADE ONCE AND KEPT, THE WAY OPENCART'S
 * image/cache WORKS.
 *
 * One folder, cache/, beside catalog/ on the public disk, so the media
 * library never lists it and a store can fetch a copy by URL. A copy keeps
 * the picture's own path with its size in the name:
 *
 *   catalog/Wampler/pedal.png  ->  cache/catalog/Wampler/pedal-200x200.jpg
 *
 * so it is found again without a lookup, and it is drawn again when the
 * picture changed after the copy was made.
 *
 * Every copy is a square JPEG on white, the picture fitted inside it and
 * never cropped, at one of three sizes set under Settings:
 *
 *   thumb    every small box: lists, order lines, variation rows
 *   preview  the larger boxes: image cards, zoom
 *   push     what every store is sent, and under 1 MB, which is Lazada's
 *            limit and the strictest of the three marketplaces
 *
 * A watermark is never kept here: it is stamped when a push needs it
 * (StampedImages).
 *
 * GD, because that is what the server has.
 */
final class ImageCache
{
    public const DIR = 'cache';

    public const THUMB = 'thumb';
    public const PREVIEW = 'preview';
    public const PUSH = 'push';

    /** The size each copy takes on a new install. */
    public const DEFAULTS = [self::THUMB => 200, self::PREVIEW => 500, self::PUSH => 1000];

    /**
     * The sizes Settings accepts. A push copy stays within what every store
     * takes: TikTok Shop wants a main picture of 300 to 4000 px.
     */
    public const LIMITS = [self::THUMB => [50, 1000], self::PREVIEW => [100, 2000], self::PUSH => [300, 4000]];

    /** Lazada refuses a picture over 1 MB, the lowest limit of the three stores. */
    public const PUSH_MAX_BYTES = 1000000;

    private const QUALITIES = [88, 82, 76, 70, 64, 58];

    /** @var array<string, int>|null */
    private static ?array $sides = null;

    /** The side, in pixels, of one kind of copy. */
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

    /** Read the sizes again, after Settings changed them. */
    public static function forget(): void
    {
        self::$sides = null;
    }

    /**
     * The copy's public URL, or the picture's own URL when no copy can be
     * made, or null when there is no picture.
     */
    public static function url(?string $path, string $kind = self::THUMB): ?string
    {
        $path = trim((string) $path);
        if ($path === '') {
            return null;
        }

        return self::publicUrl(self::path($path, $kind) ?? ltrim($path, '/'));
    }

    /**
     * The copy's path on the public disk, made now when it does not exist or
     * is older than the picture. Null when the picture is missing or cannot
     * be read.
     */
    public static function path(string $path, string $kind = self::THUMB): ?string
    {
        $source = self::safe($path);
        if ($source === null) {
            return null;
        }
        // A copy, or a stamped picture made for one push, is already what it should be.
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

        // Every copy stays under the push limit, so two sizes that match share one file.
        $bytes = self::squareJpeg($disk->path($source), $side, self::PUSH_MAX_BYTES);
        if ($bytes === null) {
            return null;
        }
        $disk->put($target, $bytes);

        return $target;
    }

    /**
     * The copy's URL only when it has already been made, so a page can offer
     * a picture without drawing thousands of copies in one request.
     */
    public static function existing(string $path, string $kind = self::THUMB): ?string
    {
        $source = self::safe($path);
        if ($source === null) {
            return null;
        }
        $target = self::target($source, self::side($kind));

        return self::fresh($target, $source) ? self::publicUrl($target) : null;
    }

    /**
     * The pictures a store is sent, in the same order, each as its push
     * copy. A picture no copy can be made of goes as it is, so the store
     * names the problem.
     *
     * @param  list<string>  $paths
     * @return list<string>
     */
    public static function pushPaths(array $paths): array
    {
        return array_map(fn ($p) => self::path((string) $p, self::PUSH) ?? (string) $p, array_values($paths));
    }

    /**
     * The push copy of a picture given by its full path, for an upload that
     * reads a file. A file off the public disk is returned as it is.
     */
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

    /** Where the copy of this picture at this size lives. */
    public static function target(string $source, int $side): string
    {
        $info = pathinfo($source);
        $dir = ($info['dirname'] ?? '.') === '.' ? '' : $info['dirname'] . '/';

        return self::DIR . '/' . $dir . $info['filename'] . '-' . $side . 'x' . $side . '.jpg';
    }

    /** A copy exists and was made after the picture it copies last changed. */
    public static function fresh(string $target, string $source): bool
    {
        $disk = Storage::disk('public');

        return $disk->exists($target) && $disk->lastModified($target) >= $disk->lastModified($source);
    }

    /**
     * The picture as a square JPEG on white, fitted inside the square without
     * cropping. With $maxBytes, compressed until it is under that size.
     */
    public static function squareJpeg(string $absolute, int $side, ?int $maxBytes = null): ?string
    {
        $source = self::open($absolute);
        if ($source === null) {
            return null;
        }

        $width = imagesx($source);
        $height = imagesy($source);
        // A picture no compression brings under the limit is drawn smaller, never below the stores' 300 px floor.
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

    /** JPEG bytes that decode to a picture, recompressed under $maxBytes. */
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

    /** The public URL of a path on the public disk. */
    public static function publicUrl(string $path): string
    {
        return asset('storage/' . implode('/', array_map('rawurlencode', explode('/', ltrim($path, '/')))));
    }

    /** A relative path on the public disk that climbs nowhere, or null. */
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
