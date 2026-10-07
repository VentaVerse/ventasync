<?php

namespace App\Support;

final class BackTo
{
    public static function capture(): string
    {
        return (string) preg_replace('#^https?://[^/]+#', '', url()->previous());
    }

    public static function safe(?string $candidate, string $fallbackUrl): string
    {
        return is_string($candidate)
            && $candidate !== ''
            && $candidate[0] === '/'
            && ! str_starts_with($candidate, '//')
            && ! str_starts_with($candidate, '/\\')
                ? $candidate
                : $fallbackUrl;
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
