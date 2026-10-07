<?php

namespace App\Support;

use Illuminate\Support\Facades\Crypt;

class Credentials
{
    public static function plaintext(?string $value): ?string
    {
        $value = (string) $value;

        if ($value === '') {
            return null;
        }

        try {
            $plain = Crypt::decryptString($value);

            if (preg_match('/^s:\d+:".*";$/s', $plain)) {
                $plain = Crypt::decrypt($value);
            }

            return is_string($plain) && $plain !== '' ? $plain : null;
        } catch (\Throwable $e) {
        }

        if (str_starts_with($value, 'eyJpdiI6')) {
            return null;
        }

        return $value;
    }

    public static function length(?string $stored): int
    {
        return mb_strlen((string) self::plaintext($stored));
    }
}
