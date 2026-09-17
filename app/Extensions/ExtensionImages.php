<?php

namespace App\Extensions;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;

/**
 * An extension's own pictures, carried in its folder and put where pages
 * can show them.
 *
 * An extension ships its pictures under extensions/{id}/images/, beside its
 * views and routes, so an extension installed on its own brings them with
 * it. Pages load them from public/images/extensions/{id}/, the published
 * copy: written on install and on enable, refreshed on boot when a pulled
 * picture is newer, and removed on uninstall. Nothing outside the extension
 * holds its pictures, so removing it leaves nothing behind.
 */
final class ExtensionImages
{
    /** The folder under public/ that pages load an extension's pictures from. */
    public static function publicDir(string $id): string
    {
        return public_path('images/extensions/' . $id);
    }

    /** The URL of one of an extension's pictures, such as logo.png. */
    public static function url(string $id, string $file): string
    {
        return asset('images/extensions/' . rawurlencode($id) . '/' . implode('/', array_map('rawurlencode', explode('/', ltrim($file, '/')))));
    }

    /** Whether the extension ships this picture, published or in its own folder. */
    public static function has(string $id, string $file): bool
    {
        $file = ltrim(str_replace('\\', '/', $file), '/');
        if (! self::validId($id) || $file === '' || str_contains($file, '..')) {
            return false;
        }

        return is_file(self::publicDir($id) . '/' . $file) || is_file(base_path('extensions/' . $id . '/images/' . $file));
    }

    /**
     * Whether a picture sits on nothing rather than on its own background.
     *
     * A lockup drawn on black or navy becomes a block on a white bar, so the
     * bar shows one only when it is clear, and falls back to the square mark
     * with the channel's name. Read once per file and remembered until the
     * file changes.
     */
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

    /** Copy the extension's pictures that are missing or out of date. */
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
            /* Written beside it and moved into place: a copy taken while the
               picture is still being written would otherwise be served
               half-drawn, and a half-drawn PNG renders as its top rows. */
            $temp = $to . '.' . bin2hex(random_bytes(4)) . '.part';
            File::copy($file->getPathname(), $temp);
            @rename($temp, $to);
            @touch($to, $file->getMTime());
        }
    }

    /** Remove the extension's published pictures. */
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
