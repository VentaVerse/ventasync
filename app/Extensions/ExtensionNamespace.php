<?php

namespace App\Extensions;

class ExtensionNamespace
{
    private const KEPT_LOWERCASE = ['pedallion'];

    private static array $resolved = [];

    public static function for(string $id): string
    {
        if (isset(self::$resolved[$id])) {
            return self::$resolved[$id];
        }

        $namespace = $id;
        if (! in_array($id, self::KEPT_LOWERCASE, true)) {
            $provider = glob(base_path('extensions/' . $id . '/*Extension.php'))[0] ?? null;
            $prefix = $provider ? substr(basename($provider, '.php'), 0, -strlen('Extension')) : '';
            if ($prefix !== '' && strtolower($prefix) === $id) {
                $namespace = $prefix;
            }
        }

        return self::$resolved[$id] = 'Extensions\\' . $namespace;
    }
}
