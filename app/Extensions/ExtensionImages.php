<?php

namespace App\Extensions;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;

final class ExtensionImages
{
    public static function publicDir(string $id): string
    {
        return public_path('images/extensions/' . $id);
    }

    public static function url(string $id, string $file): string
    {
        return asset('images/extensions/' . rawurlencode($id) . '/' . implode('/', array_map('rawurlencode', explode('/', ltrim($file, '/')))));
    }

    public static function has(string $id, string $file): bool
    {
        $file = ltrim(str_replace('\\', '/', $file), '/');
        if (! self::validId($id) || $file === '' || str_contains($file, '..')) {
            return false;
        }

        return is_file(self::publicDir($id) . '/' . $file) || is_file(base_path('extensions/' . $id . '/images/' . $file));
    }

    public static function isTransparent(string $id, string $file): bool
    {
        $path = self::publicDir($id) . '/' . ltrim($file, '/');
        if (! self::has($id, $file) || ! is_file($path)) {
            return false;
        }

        return (bool) Cache::remember('ext-image-clear:' . $path . ':' . @filemtime($path), now()->addDays(30), function () use ($path) {
            $info = @getimagesize($path);
            if (($info[2] ?? 0) !== IMAGETYPE_PNG) {
                return false;
            }
            $image = @imagecreatefrompng($path);
            if (! $image instanceof \GdImage) {
                return false;
            }
            $w = imagesx($image);
            $h = imagesy($image);
            foreach ([[1, 1], [$w - 2, 1], [1, $h - 2], [$w - 2, $h - 2]] as [$x, $y]) {
                $colour = imagecolorsforindex($image, imagecolorat($image, max(0, $x), max(0, $y)));
                if (($colour['alpha'] ?? 0) < 100) {
                    return false;
                }
            }

            return true;
        });
    }

    public static function publish(string $id): void
    {
        if (! self::validId($id)) {
            return;
        }
        $source = base_path('extensions/' . $id . '/images');
        if (! File::isDirectory($source)) {
            return;
        }
        $target = self::publicDir($id);
        foreach (File::allFiles($source) as $file) {
            $to = $target . '/' . $file->getRelativePathname();
            if (is_file($to) && filemtime($to) >= $file->getMTime()) {
                continue;
            }
            File::ensureDirectoryExists(dirname($to));
            $temp = $to . '.' . bin2hex(random_bytes(4)) . '.part';
            File::copy($file->getPathname(), $temp);
            @rename($temp, $to);
            @touch($to, $file->getMTime());
        }
    }

    public static function remove(string $id): void
    {
        if (self::validId($id) && File::isDirectory(self::publicDir($id))) {
            File::deleteDirectory(self::publicDir($id));
        }
    }

    private static function validId(string $id): bool
    {
        return (bool) preg_match('/^[a-z0-9][a-z0-9_-]*$/', $id);
    }
}
