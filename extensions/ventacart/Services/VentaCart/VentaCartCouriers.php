<?php

namespace Extensions\ventacart\Services\VentaCart;

final class VentaCartCouriers
{
    private const NAMES = [
        'spx'    => 'SPX Express',
        'quadx'  => 'QuadX',
        'lbc'    => 'LBC',
        'manual' => 'Another courier',
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
