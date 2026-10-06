<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * A new install has no users: nothing is seeded, so whoever opens it first
 * creates the administrator on the first account page.
 */
final class FirstAccount
{
    private const MADE = 'first-account.made';

    /** True while the install has no user at all. */
    public static function needed(): bool
    {
        // Only "made" is remembered: it can never become untrue, while a
        // remembered "needed" would outlive the account that answers it.
        if (Cache::get(self::MADE) === true) {
            return false;
        }
        if (DB::table('users')->exists()) {
            Cache::forever(self::MADE, true);

            return false;
        }

        return true;
    }
}
