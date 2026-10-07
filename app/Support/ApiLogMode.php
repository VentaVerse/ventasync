<?php

namespace App\Support;

class ApiLogMode
{
    public const ALL = 'all';
    public const ERRORS = 'errors';
    public const OFF = 'off';

    public const MODES = [self::ALL, self::ERRORS, self::OFF];

    public static function shouldLog(?string $mode, bool $ok): bool
    {
        return match ($mode) {
            self::OFF => false,
            self::ERRORS => ! $ok,
            default => true,
        };
    }

    public static function labels(): array
    {
        return [
            self::ALL => 'Everything',
            self::ERRORS => 'Errors only',
            self::OFF => 'Off',
        ];
    }
}
