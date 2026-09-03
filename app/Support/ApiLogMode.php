<?php

namespace App\Support;

/**
 * The API log's three-way switch (#200): record everything, record
 * errors only, or record nothing. One vocabulary for every channel so
 * the writers, the settings forms and the validation cannot drift.
 *
 * "Errors only" is the setting the owner actually wanted all along: the
 * every-minute order fetch stops eating the database, and the rows
 * that explain a broken push are still there when he needs them.
 */
class ApiLogMode
{
    public const ALL = 'all';
    public const ERRORS = 'errors';
    public const OFF = 'off';

    public const MODES = [self::ALL, self::ERRORS, self::OFF];

    /** Should this call land in the log under the given mode? */
    public static function shouldLog(?string $mode, bool $ok): bool
    {
        return match ($mode) {
            self::OFF => false,
            self::ERRORS => ! $ok,
            // Unknown or unset reads as "everything" - a misspelled mode
            // must never silently stop the log.
            default => true,
        };
    }

    /** The words the settings pages use for each mode. */
    public static function labels(): array
    {
        return [
            self::ALL => 'Everything',
            self::ERRORS => 'Errors only',
            self::OFF => 'Off',
        ];
    }
}
