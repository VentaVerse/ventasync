<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

final class FirstAccount
{
    private const MADE = 'first-account.made';

    public static function needed(): bool
    {
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
