<?php

namespace App\Support;

/**
 * A channel's brand colour, in the two forms an interface needs.
 *
 * Every integration declares one accent on its IntegrationCard and its
 * OrderTab - Shopee's orange, Lazada's navy, TikTok's black. That single hex
 * is the channel's identity and it is the only place a colour is written
 * down; nothing here invents a second list, so adding a channel needs no
 * colour work beyond the manifest it already ships.
 *
 * The two forms exist because a brand colour has two jobs with different
 * rules:
 *
 *   RAW  is for marks that carry no text: a ring, an underline, a border.
 *        Nothing is read against it, so nothing constrains it and the
 *        channel gets its exact colour.
 *
 *   DEEP is for a fill that carries white text: a card's colour cap, the
 *        page's primary button. White at 12.5px needs 4.5:1, and most
 *        marketplace brand colours are nowhere near it - Shopee's orange
 *        measures 3.66, TikTok's pink 3.68, Shopify's green 2.14. Using the
 *        raw hex there ships a label a person cannot read.
 *
 * DESIGN.md already settled how this project answers that: "Every accent is
 * chosen against white text, not for its own sake... Adding an accent means
 * computing it, not eyeballing it." So deep() computes rather than storing a
 * hand-picked second hex per channel, which would drift the first time
 * somebody updated one and not the other.
 *
 * The computation is a straight walk down the value axis, which keeps the
 * hue and only spends lightness. A colour already clearing the floor comes
 * back untouched, so Lazada, TikTok and Venta pay nothing for this.
 */
class ChannelAccent
{
    /** The contrast a 12.5px label needs against its own background. */
    private const FLOOR = 4.5;

    /** Fallback when an extension ships a malformed accent. */
    private const NEUTRAL = '#0b1228';

    /** The dark theme's sheet, which onDark() has to clear. */
    private const DARK_SHEET = [17, 24, 40];

    /**
     * The channel's own colour, for marks that carry no text.
     */
    public static function raw(?string $hex): string
    {
        return self::normalise($hex) ?? self::NEUTRAL;
    }

    /**
     * The darkest step of the channel's colour that still holds white text.
     *
     * Returns the colour unchanged when it already clears the floor.
     */
    public static function deep(?string $hex): string
    {
        $rgb = self::rgb(self::normalise($hex) ?? self::NEUTRAL);

        // 100 steps is finer than the eye resolves and cheap enough to run
        // per request; the first step that clears the floor wins, so the
        // result is the lightest compliant version rather than an
        // arbitrarily dark one.
        for ($step = 100; $step >= 0; $step--) {
            $factor = $step / 100;
            $candidate = [
                (int) round($rgb[0] * $factor),
                (int) round($rgb[1] * $factor),
                (int) round($rgb[2] * $factor),
            ];

            if (self::contrastWithWhite($candidate) >= self::FLOOR) {
                return sprintf('#%02x%02x%02x', ...$candidate);
            }
        }

        return self::NEUTRAL;
    }

    /**
     * The lightest-touch step of the channel's colour that stays legible on
     * the DARK theme's sheet.
     *
     * deep() solves the light theme by walking down; it cannot solve the
     * dark one, because a colour dark enough to hold white text disappears
     * against a near-black surface. Lazada's navy is the clearest case: it
     * clears white at 15.66:1 and sits at barely 1.3:1 on the dark sheet, so
     * the order references on a Lazada page vanished the moment the theme
     * switched. This walks the other way, toward white, until the colour
     * clears the same floor against that sheet.
     *
     * Used for text on a dark surface, and as the FILL of the primary button
     * in dark mode - where the button inverts to a light fill with dark
     * text, the way --bl-ink already does.
     */
    public static function onDark(?string $hex): string
    {
        $rgb = self::rgb(self::normalise($hex) ?? self::NEUTRAL);

        for ($step = 0; $step <= 100; $step++) {
            $factor = $step / 100;
            $candidate = [
                (int) round($rgb[0] + (255 - $rgb[0]) * $factor),
                (int) round($rgb[1] + (255 - $rgb[1]) * $factor),
                (int) round($rgb[2] + (255 - $rgb[2]) * $factor),
            ];

            if (self::contrast($candidate, self::DARK_SHEET) >= self::FLOOR) {
                return sprintf('#%02x%02x%02x', ...$candidate);
            }
        }

        return '#ffffff';
    }

    /**
     * Accept #abc, #aabbcc and the same without the hash; reject anything
     * else rather than guessing, so a typo in a manifest reads as the
     * neutral rather than as a colour nobody chose.
     */
    private static function normalise(?string $hex): ?string
    {
        $value = strtolower(trim((string) $hex));
        $value = ltrim($value, '#');

        if (preg_match('/^[0-9a-f]{3}$/', $value)) {
            $value = $value[0].$value[0].$value[1].$value[1].$value[2].$value[2];
        }

        return preg_match('/^[0-9a-f]{6}$/', $value) ? '#'.$value : null;
    }

    /** @return array{0:int,1:int,2:int} */
    private static function rgb(string $hex): array
    {
        $hex = ltrim($hex, '#');

        return [
            (int) hexdec(substr($hex, 0, 2)),
            (int) hexdec(substr($hex, 2, 2)),
            (int) hexdec(substr($hex, 4, 2)),
        ];
    }

    /**
     * @param  array{0:int,1:int,2:int}  $rgb
     */
    private static function contrastWithWhite(array $rgb): float
    {
        return self::contrast($rgb, [255, 255, 255]);
    }

    /**
     * WCAG 2.1 contrast ratio between two colours.
     *
     * @param  array{0:int,1:int,2:int}  $a
     * @param  array{0:int,1:int,2:int}  $b
     */
    private static function contrast(array $a, array $b): float
    {
        $luminance = static function (array $rgb): float {
            $channel = static function (int $value): float {
                $v = $value / 255;

                return $v <= 0.03928 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4;
            };

            return 0.2126 * $channel($rgb[0])
                + 0.7152 * $channel($rgb[1])
                + 0.0722 * $channel($rgb[2]);
        };

        $la = $luminance($a);
        $lb = $luminance($b);

        return (max($la, $lb) + 0.05) / (min($la, $lb) + 0.05);
    }
}
