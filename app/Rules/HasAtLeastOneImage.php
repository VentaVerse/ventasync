<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * The image manager posts its whole state as images_json - a JSON array
 * of paths (or {path} objects). "At least one image" therefore means
 * that array parses and holds one non-empty path (the owner, 2026-09-04:
 * Shopee, Lazada and TikTok all refuse a listing without a photo, so a
 * product without one cannot save half-made and fail at push time).
 */
class HasAtLeastOneImage implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $decoded = json_decode(trim((string) $value), true);
        if (is_array($decoded)) {
            foreach ($decoded as $item) {
                $path = is_array($item) ? (string) ($item['path'] ?? '') : (string) $item;
                if (trim($path) !== '') {
                    return;
                }
            }
        }

        $fail('At least one product photo is needed.');
    }
}
