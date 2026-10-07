<?php

namespace App\Integrations\Listings;

final class ListingTrouble
{
    public const ATTENTION = 'attention';
    public const DRIFT = 'drift';

    public const BUCKETS = [self::ATTENTION, self::DRIFT];

    public static function words(string $bucket, int $count): string
    {
        return $bucket === self::DRIFT
            ? $count . ' catalog change' . ($count === 1 ? '' : 's')
            : $count . ' listing' . ($count === 1 ? ' needs' : 's need') . ' attention';
    }

    public static function showing(string $bucket, int $count): string
    {
        return $bucket === self::DRIFT
            ? 'Showing the ' . $count . ' ' . ($count === 1 ? 'listing' : 'listings') . ' with a catalog change.'
            : 'Showing the ' . $count . ' ' . ($count === 1 ? 'listing that needs' : 'listings that need') . ' attention.';
    }

    public static function figures(array $summary, callable $urlFor): array
    {
        $out = [];
        foreach (self::BUCKETS as $bucket) {
            $count = (int) ($summary[$bucket] ?? 0);
            if ($count < 1) {
                continue;
            }
            $out[] = [
                'text' => self::words($bucket, $count),
                'href' => $urlFor($bucket),
                'tone' => $bucket === self::ATTENTION ? 'bad' : 'warn',
            ];
        }

        return $out;
    }

    public static function asked(?string $value): ?string
    {
        $value = strtolower(trim((string) $value));

        return in_array($value, self::BUCKETS, true) ? $value : null;
    }
}
