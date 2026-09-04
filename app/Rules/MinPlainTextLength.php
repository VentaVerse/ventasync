<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A minimum length counted on the TEXT, not the markup (#198 follow-up,
 * the owner 2026-09-04). The description field stores HTML from the rich
 * editor, and "<p><strong>Nice</strong></p>" is 4 characters of product
 * description wearing 26 characters of tags - a tag-inclusive minimum
 * would wave through exactly the listings the marketplaces refuse.
 */
class MinPlainTextLength implements ValidationRule
{
    public function __construct(private int $min) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $text = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags((string) $value), ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? '');
        $length = mb_strlen($text);

        if ($length < $this->min) {
            $fail("The {$attribute} needs at least {$this->min} characters of actual text - TikTok refuses shorter listings and Shopee wants 25 or more. It has {$length} so far.");
        }
    }
}
