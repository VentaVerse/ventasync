<?php

namespace App\Support;

use Illuminate\Support\Facades\Crypt;

/**
 * How a stored credential is shaped, in one place.
 *
 * Seven channels store these columns three different ways: Shopee, Lazada and
 * TikTok encrypt through the model and expose a decrypted() copy of the row;
 * Venta, OpenCart, Shopify and Pedallion encrypt in their controllers at write
 * time, some with encrypt() and some with encryptString(); and a value that
 * predates any of that is simply itself.
 *
 * Anything that needs to read one of these - the reveal endpoint, or a
 * settings form that only wants to know how long it is - goes through here, so
 * a channel's storage choice is not something every caller has to remember.
 */
class Credentials
{
    /**
     * The value as the operator would type it, or NOTHING.
     *
     * Never ciphertext. Handing that back would be worse than refusing: it
     * looks like a credential, so it gets pasted into a marketplace dashboard
     * and fails there instead of here, hours later and somewhere else.
     *
     * A value that IS encrypted and still will not open means the row was
     * written under a different APP_KEY, which is what a database copied
     * between environments looks like. It is unreadable here and retrying
     * changes nothing, so it is refused.
     */
    public static function plaintext(?string $value): ?string
    {
        $value = (string) $value;

        if ($value === '') {
            return null;
        }

        try {
            $plain = Crypt::decryptString($value);

            /*
             * decryptString does NOT throw on a value written with encrypt():
             * it decrypts with unserialize disabled and hands back the
             * serialized string itself - s:12:"secret-value"; - which is a
             * non-empty string and would sail straight through a naive
             * is_string() check into the operator's field.
             *
             * Both writers exist in this codebase, so the shape is detected
             * rather than assumed.
             */
            if (preg_match('/^s:\d+:".*";$/s', $plain)) {
                $plain = Crypt::decrypt($value);
            }

            return is_string($plain) && $plain !== '' ? $plain : null;
        } catch (\Throwable $e) {
            // Not ours to read; fall through.
        }

        // Laravel's own envelope, present but unopenable: wrong key.
        if (str_starts_with($value, 'eyJpdiI6')) {
            return null;
        }

        return $value;
    }

    /**
     * How many characters the operator would see, for sizing the mask.
     *
     * The plaintext is computed and discarded; it is never returned to a view.
     * A settings page that printed strlen() of the stored column would size
     * its mask to the CIPHERTEXT - 228 dots for a nine character token, and a
     * "228 stored characters" caption that is simply false.
     *
     * An unreadable value returns 0, so the field renders as empty rather than
     * inviting the operator to reveal something that cannot be shown.
     */
    public static function length(?string $stored): int
    {
        return mb_strlen((string) self::plaintext($stored));
    }
}
