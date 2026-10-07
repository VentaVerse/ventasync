<?php

namespace App\Services\Media;

use Illuminate\Support\Facades\Storage;

final class StoreReadyImages
{

    public const LAZADA = ['formats' => [IMAGETYPE_JPEG, IMAGETYPE_PNG], 'max_side' => 2000, 'max_bytes' => 1000000];

    public const SHOPEE = ['formats' => [IMAGETYPE_JPEG, IMAGETYPE_PNG], 'max_side' => 4000, 'max_bytes' => 10000000];

    public const TIKTOK = ['formats' => [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], 'max_side' => 3000, 'max_bytes' => 5000000];

    private const MIN_SIDE = 500;

    public static function paths(array $paths, array $rules): array
    {
        return array_map(fn ($path) => self::fit((string) $path, $rules) ?? (string) $path, array_values($paths));
    }

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

        $info = pathinfo($path);
        $dir = ($info['dirname'] ?? '.') === '.' ? '' : $info['dirname'] . '/';
        $target = ImageCache::DIR . '/' . $dir . $info['filename'] . '-fit' . (int) $rules['max_side'] . '.jpg';
        if (ImageCache::fresh($target, $path)) {
            return $target;
        }

        $jpeg = self::encode($absolute, $type, $width, $height, $rules);
        if ($jpeg === null) {
            return null;
        }
        $disk->put($target, $jpeg);

        return $target;
    }

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
