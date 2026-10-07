<?php

namespace App\Integrations\Listings;

use App\Support\Catalog\DescriptionHtml;

final class RichDescription
{
    private const TIKTOK_TAGS = ['p', 'br', 'b', 'strong', 'i', 'em', 'u', 'ul', 'ol', 'li', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'img'];

    public const TIKTOK_MAX_IMAGES = 30;

    public static function forTikTok(string $html): string
    {
        $html = trim($html);
        if ($html === '') {
            return '';
        }

        $kept = self::keepOnly($html, self::TIKTOK_TAGS);
        $kept = self::dropUnreachableImages($kept);

        return trim(self::capImages($kept, self::TIKTOK_MAX_IMAGES));
    }

    public static function imageCount(string $html): int
    {
        return count(DescriptionHtml::imageUrls($html));
    }

    private static function keepOnly(string $html, array $tags): string
    {
        $allow = '<' . implode('><', $tags) . '>';

        return strip_tags($html, $allow);
    }

    private static function dropUnreachableImages(string $html): string
    {
        return (string) preg_replace('#<img\b[^>]*\bsrc="(?!https?://)[^"]*"[^>]*>#i', '', $html);
    }

    private static function capImages(string $html, int $max): string
    {
        $seen = 0;

        return (string) preg_replace_callback(
            '#<img\b[^>]*>#i',
            function (array $m) use (&$seen, $max): string {
                $seen++;

                return $seen <= $max ? $m[0] : '';
            },
            $html
        );
    }
}
