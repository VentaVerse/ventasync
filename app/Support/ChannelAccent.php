<?php

namespace App\Support;

class ChannelAccent
{
    private const FLOOR = 4.5;

    private const NEUTRAL = '#0b1228';

    private const DARK_SHEET = [17, 24, 40];

    private const ALERT = [203, 51, 39];

    private const ALERT_CLEARANCE = 60.0;

    private const LIGHT_INK_ON_ACCENT = [238, 242, 248];

    public static function raw(?string $hex): string
    {
        return self::normalise($hex) ?? self::NEUTRAL;
    }

    public static function deep(?string $hex): string
    {
        $rgb = self::rgb(self::normalise($hex) ?? self::NEUTRAL);

        for ($step = 100; $step >= 0; $step--) {
            $factor = $step / 100;
            $candidate = [
                (int) round($rgb[0] * $factor),
                (int) round($rgb[1] * $factor),
                (int) round($rgb[2] * $factor),
            ];

            if (self::contrastWithPaper($candidate) >= self::FLOOR) {
                return sprintf('#%02x%02x%02x', ...$candidate);
            }
        }

        return self::NEUTRAL;
    }

    public static function cta(?string $hex): string
    {
        $deep = self::deep($hex);

        return self::distance(self::rgb($deep), self::ALERT) < self::ALERT_CLEARANCE
            ? self::NEUTRAL
            : $deep;
    }

    private static function distance(array $a, array $b): float
    {
        return sqrt(
            (($a[0] - $b[0]) ** 2)
            + (($a[1] - $b[1]) ** 2)
            + (($a[2] - $b[2]) ** 2)
        );
    }

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

    private static function normalise(?string $hex): ?string
    {
        $value = strtolower(trim((string) $hex));
        $value = ltrim($value, '#');

        if (preg_match('/^[0-9a-f]{3}$/', $value)) {
            $value = $value[0].$value[0].$value[1].$value[1].$value[2].$value[2];
        }

        return preg_match('/^[0-9a-f]{6}$/', $value) ? '#'.$value : null;
    }

    private static function rgb(string $hex): array
    {
        $hex = ltrim($hex, '#');

        return [
            (int) hexdec(substr($hex, 0, 2)),
            (int) hexdec(substr($hex, 2, 2)),
            (int) hexdec(substr($hex, 4, 2)),
        ];
    }

    private static function contrastWithPaper(array $rgb): float
    {
        return self::contrast($rgb, self::LIGHT_INK_ON_ACCENT);
    }

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
