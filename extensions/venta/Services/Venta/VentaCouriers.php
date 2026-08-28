<?php

namespace Extensions\venta\Services\Venta;

/**
 * The names a packer reads for the storefront's courier keys.
 *
 * The storefront identifies a courier by the key its module registered
 * (quadx, spx, lbc) and that key is what its API sends back on a booking.
 * The ERP has no courier modules of its own, so it keeps the handful of
 * names here and falls back to the key in capitals for one it has not
 * heard of, which reads wrong in review rather than blank in production.
 */
final class VentaCouriers
{
    private const NAMES = [
        'spx'   => 'SPX Express',
        'quadx' => 'QuadX',
        'lbc'   => 'LBC',
    ];

    public static function name(?string $key): string
    {
        $key = strtolower(trim((string) $key));

        if ($key === '') {
            return '';
        }

        return self::NAMES[$key] ?? strtoupper($key);
    }
}
