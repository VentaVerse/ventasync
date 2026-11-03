<?php

namespace App\Extensions;

use Illuminate\Support\Facades\File;

final class ExtensionAssets
{
    public static function publicDir(string $id): string
    {
        return public_path('extension-assets/' . $id);
    }

    public static function publish(string $id): void
    {
        if (self::validId($id)) {
            ExtensionImages::mirror(base_path('extensions/' . $id . '/assets'), self::publicDir($id));
        }
    }

    public static function remove(string $id): void
    {
        if (self::validId($id) && File::isDirectory(self::publicDir($id))) {
            File::deleteDirectory(self::publicDir($id));
        }
    }

    public static function styles(): array
    {
        return self::urls('css');
    }

    public static function scripts(): array
    {
        return self::urls('js');
    }

    private static function urls(string $type): array
    {
        $urls = [];
        foreach (app(ExtensionManager::class)->getEnabledIds() as $id) {
            if (! self::validId($id)) {
                continue;
            }
            $file = self::publicDir($id) . '/' . $id . '.' . $type;
            if (is_file($file)) {
                $urls[] = asset('extension-assets/' . $id . '/' . $id . '.' . $type) . '?v=' . filemtime($file);
            }
        }

        return $urls;
    }

    private static function validId(string $id): bool
    {
        return (bool) preg_match('/^[a-z0-9][a-z0-9_-]*$/', $id);
    }
}
