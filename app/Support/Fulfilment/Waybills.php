<?php

namespace App\Support\Fulfilment;

/**
 * Where a channel keeps the waybills it has fetched:
 * storage/app/{channel}-awb/{store id}/{reference}.pdf.
 *
 * The store folder is what lets two stores hold the same order reference
 * (a storefront numbers its own orders) and lets a store's waybills be
 * found and removed as one.
 */
final class Waybills
{
    public static function path(string $channel, int $storeId, string $reference): string
    {
        return storage_path('app/' . self::relative($channel, $storeId, $reference));
    }

    /** The same path relative to the local disk's root. */
    public static function relative(string $channel, int $storeId, string $reference): string
    {
        $safe = preg_replace('/[^A-Za-z0-9_-]/', '', $reference);

        return $channel . '-awb/' . $storeId . '/' . ($safe !== '' ? $safe : 'awb') . '.pdf';
    }

    public static function save(string $path, string $bytes): void
    {
        $dir = dirname($path);
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents($path, $bytes);
    }
}
