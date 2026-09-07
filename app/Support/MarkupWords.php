<?php

namespace App\Support;

/**
 * A price rule in words: "5% + 20.00", "5%", "+ 20.00", or "none". One
 * formatter for every Product group card and every Last push line.
 */
class MarkupWords
{
    public static function of(?float $percent, ?float $fixed): string
    {
        $parts = [];
        if ($percent !== null && (float) $percent != 0.0) {
            $parts[] = rtrim(rtrim(number_format((float) $percent, 2, '.', ''), '0'), '.') . '%';
        }
        if ($fixed !== null && (float) $fixed != 0.0) {
            $parts[] = ($parts === [] ? '+ ' : '') . number_format((float) $fixed, 2, '.', '');
        }

        return $parts === [] ? 'none' : implode(' + ', $parts);
    }
}
