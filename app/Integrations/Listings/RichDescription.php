<?php

namespace App\Integrations\Listings;

use App\Support\Catalog\DescriptionHtml;

/**
 * A DESCRIPTION SHAPED FOR ONE MARKETPLACE, KEEPING WHAT IT ACCEPTS.
 *
 * TikTok Shop documents a rich description: paragraphs, headings, lists,
 * and up to thirty images. The push ran strip_tags() over the whole thing
 * and sent the words alone, so a description an operator had laid out -
 * headings, bullets, three photographs - arrived as a wall of text and
 * nobody was told (VENT2-129).
 *
 * The rule here is drop only what the marketplace forbids. Stripping
 * everything is easy and safe and throws away the operator's work; this
 * keeps the structure and removes the rest.
 *
 * A PICTURE INSIDE A DESCRIPTION IS A URL THE MARKETPLACE FETCHES, not
 * bytes we upload - so it has to be reachable from outside. That is the
 * same requirement Venta and WooCommerce have for the gallery, and the
 * reason a pasted base64 picture is turned into a real file at save time
 * (App\Support\Catalog\DescriptionHtml). A data: src that survives to here
 * is dropped rather than sent: TikTok will not read it, and leaving it in
 * makes a payload megabytes wide for nothing.
 */
final class RichDescription
{
    /**
     * What TikTok Shop documents. Deliberately narrower than the catalog's
     * own allowance: no links, no tables, no blockquote. When TikTok's
     * documentation is confirmed to take more, this list is where it goes.
     */
    private const TIKTOK_TAGS = ['p', 'br', 'b', 'strong', 'i', 'em', 'u', 'ul', 'ol', 'li', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'img'];

    /** TikTok's own ceiling on pictures inside a description. */
    public const TIKTOK_MAX_IMAGES = 30;

    /**
     * The description TikTok is sent: its own tags kept, everything else
     * unwrapped to its text, pictures capped and unfetchable ones dropped.
     */
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

    /** How many pictures a description carries. */
    public static function imageCount(string $html): int
    {
        return count(DescriptionHtml::imageUrls($html));
    }

    /**
     * Everything not in the list loses its TAGS, never its words: a <div>
     * around three paragraphs must not take the paragraphs with it.
     *
     * @param  list<string>  $tags
     */
    private static function keepOnly(string $html, array $tags): string
    {
        $allow = '<' . implode('><', $tags) . '>';

        // strip_tags keeps the text of everything it removes, which is
        // exactly the behaviour wanted here.
        return strip_tags($html, $allow);
    }

    /** A src the marketplace cannot fetch is not a picture to it. */
    private static function dropUnreachableImages(string $html): string
    {
        return (string) preg_replace('#<img\b[^>]*\bsrc="(?!https?://)[^"]*"[^>]*>#i', '', $html);
    }

    /** Past the ceiling, the img tags go and the words stay. */
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
