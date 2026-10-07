<?php

namespace App\Support\Catalog;

final class DescriptionText
{
    public static function of(?string $html): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", (string) $html);
        $text = DescriptionHtml::decoded($text);
        if (! self::looksLikeHtml($text)) {
            return trim($text);
        }

        $text = preg_replace('#<br\s*/?>#i', "\n", $text);
        $text = preg_replace('#<li\b[^>]*>#i', "\n• ", $text);
        $text = preg_replace('#</tr\s*>#i', "\n", $text);
        $text = preg_replace('#</(?:p|div|h[1-6]|ul|ol|blockquote|pre|table)\s*>#i', "\n\n", $text);
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = str_replace("\u{00A0}", ' ', $text);

        $lines = array_map(fn (string $line) => trim(preg_replace('/[ \t]+/', ' ', $line)), explode("\n", $text));

        return trim(preg_replace("/\n{3,}/", "\n\n", implode("\n", $lines)));
    }

    public static function looksLikeHtml(string $text): bool
    {
        return preg_match('#<(?:[a-z][a-z0-9]*\b[^>]*|/[a-z][a-z0-9]*\s*)>#i', $text) === 1
            || preg_match('/&(?:[a-z]+|#\d+|#x[0-9a-f]+);/i', $text) === 1;
    }

    public static function isBlank(?string $html): bool
    {
        $html = (string) $html;

        return self::of($html) === '' && stripos($html, '<img') === false;
    }
}
