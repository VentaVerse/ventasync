<?php

namespace App\Support;

/**
 * The way back to the list you came from (#189): an edit page carries
 * the list's own URL - search, filters, page and all - and the save
 * returns there instead of the bare index. Only a local path is ever
 * followed; anything else falls back to the named route.
 */
final class BackTo
{
    public static function capture(): string
    {
        return (string) preg_replace('#^https?://[^/]+#', '', url()->previous());
    }

    public static function follow(?string $candidate, string $fallbackRoute)
    {
        return is_string($candidate)
            && $candidate !== ''
            && $candidate[0] === '/'
            && ! str_starts_with($candidate, '//')
            && ! str_starts_with($candidate, '/\\')
                ? redirect($candidate)
                : redirect()->route($fallbackRoute);
    }
}
