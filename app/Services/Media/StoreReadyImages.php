<?php

namespace App\Services\Media;

use Illuminate\Support\Facades\Storage;

/**
 * A PICTURE FITTED TO WHAT ONE STORE ACCEPTS, ON THE WAY OUT.
 *
 * Stores refuse pictures the catalog keeps without complaint: Lazada takes
 * JPG or PNG under 1 MB, Shopee JPG or PNG under 10 MB, and a phone photo or
 * a WebP breaks either. The original stays exactly as it is. A store that
 * cannot take it gets a copy converted to JPEG, scaled down so its longest
 * side is within the store's limit, and compressed until it is under the
 * store's file size. A picture the store already accepts goes up untouched.
 *
 * A copy is written once and kept, named by the picture and the rules, so
 * the next push reuses it and a changed original draws a new one: the same
 * shape as the watermark's marked copies, which come first. A picture that
 * cannot be read or fitted goes up as it is, so the store names the
 * problem.
 *
 * GD, because that is what the server has.
 */
final class StoreReadyImages
{
    public const DIR = 'store-ready';

    /** Lazada's image migrate: JPG or PNG, 1 MB, under 5000 px. */
    public const LAZADA = ['formats' => [IMAGETYPE_JPEG, IMAGETYPE_PNG], 'max_side' => 2000, 'max_bytes' => 1000000];

    /** Shopee's media upload: JPG or PNG, 10 MB. */
    public const SHOPEE = ['formats' => [IMAGETYPE_JPEG, IMAGETYPE_PNG], 'max_side' => 4000, 'max_bytes' => 10000000];

    /**
     * TikTok Shop, whose upload limits the local documentation does not
     * state: a cap that keeps the squared JPEG its push builds a modest file.
     */
    public const TIKTOK = ['formats' => [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], 'max_side' => 3000, 'max_bytes' => 5000000];

    /** Smallest longest side a copy is shrunk to before giving up. */
    private const MIN_SIDE = 500;

    /**
     * The pictures to send, in the same order, each fitted to the rules.
     *
     * @param  list<string>  $paths  pictures on the public disk
     * @param  array{formats: list<int>, max_side: int, max_bytes: int}  $rules
     * @return list<string>
     */
    public static function paths(array $paths, array $rules): array
    {
        return array_map(fn ($path) => self::fit((string) $path, $rules) ?? (string) $path, array_values($paths));
    }

    /**
     * One picture fitted to the rules: the picture itself when it already
     * fits, a store-ready copy when it does not, or null when it cannot be
     * read or brought within the rules.
     *
     * @param  array{formats: list<int>, max_side: int, max_bytes: int}  $rules
     */
    public static function fit(string $path, array $rules): ?string
    {
        $disk = Storage::disk('public');
        $path = ltrim($path, '/');
        if ($path === '' || ! $disk->exists($path)) {
            return null;
        }

        $absolute = $disk->path($path);
        $info = @getimagesize($absolute);
        if ($info === false || (int) $info[0] < 1 || (int) $info[1] < 1) {
            return null;
        }
        [$width, $height, $type] = [(int) $info[0], (int) $info[1], (int) $info[2]];
        $bytes = (int) $disk->size($path);

        if (in_array($type, $rules['formats'], true) && max($width, $height) <= $rules['max_side'] && $bytes <= $rules['max_bytes']) {
            return $path;
        }

        $target = self::DIR . '/' . hash('sha256', implode('|', [
            $path, $bytes, $disk->lastModified($path), json_encode($rules),
        ])) . '.jpg';
        if ($disk->exists($target)) {
            return $target;
        }

        $jpeg = self::encode($absolute, $type, $width, $height, $rules);
        if ($jpeg === null) {
            return null;
        }
        $disk->put($target, $jpeg);

        return $target;
    }

    /**
     * The fitted file for a picture given by its full path, for the uploads
     * that read a file rather than a disk path. A file outside the public
     * disk, or one that already fits, is returned as it is.
     *
     * @param  array{formats: list<int>, max_side: int, max_bytes: int}  $rules
     */
    public static function fitLocalFile(string $absolute, array $rules): string
    {
        $disk = Storage::disk('public');
        foreach ([rtrim($disk->path(''), '/'), rtrim(public_path('storage'), '/')] as $root) {
            if ($root !== '' && str_starts_with($absolute, $root . '/')) {
                $fitted = self::fit(substr($absolute, strlen($root) + 1), $rules);

                return $fitted !== null ? $disk->path($fitted) : $absolute;
            }
        }

        return $absolute;
    }

    /** The picture as a JPEG within the rules, shrinking it further only when compression alone is not enough. */
    private static function encode(string $absolute, int $type, int $width, int $height, array $rules): ?string
    {
        $source = match ($type) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($absolute),
            IMAGETYPE_PNG => @imagecreatefrompng($absolute),
            IMAGETYPE_GIF => @imagecreatefromgif($absolute),
            IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($absolute) : false,
            default => false,
        };
        if (! $source) {
            return null;
        }

        try {
            $side = min((int) $rules['max_side'], max($width, $height));
            while ($side >= self::MIN_SIDE) {
                $scale = $side / max($width, $height);
                $w = max(1, (int) round($width * $scale));
                $h = max(1, (int) round($height * $scale));

                $canvas = imagecreatetruecolor($w, $h);
                // A transparent picture lands on white: JPEG has no transparency.
                imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255));
                imagecopyresampled($canvas, $source, 0, 0, 0, 0, $w, $h, $width, $height);

                foreach ([88, 80, 72, 64] as $quality) {
                    ob_start();
                    imagejpeg($canvas, null, $quality);
                    $out = (string) ob_get_clean();
                    if (strlen($out) <= (int) $rules['max_bytes']) {
                        imagedestroy($canvas);

                        return $out;
                    }
                }
                imagedestroy($canvas);
                $side = (int) floor($side * 0.8);
            }
        } finally {
            imagedestroy($source);
        }

        return null;
    }
}
