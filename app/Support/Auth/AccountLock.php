<?php

namespace App\Support\Auth;

use App\Models\User;
use App\Services\ActivityLogger;

final class AccountLock
{
    public const TRIES = 5;

    public const MINUTES = 15;

    public static function isLocked(User $user): bool
    {
        return $user->locked_until !== null && $user->locked_until->isFuture();
    }

    public static function message(User $user): string
    {
        $minutes = max(1, (int) ceil(now()->diffInSeconds($user->locked_until, true) / 60));

        return 'This account is locked after too many wrong passwords. Try again in '
            . $minutes . ' ' . ($minutes === 1 ? 'minute' : 'minutes') . ', or ask an admin to unlock it.';
    }

    public static function failed(User $user): void
    {
        if (self::isLocked($user)) {
            return;
        }

        $tries = (int) $user->failed_logins + 1;
        if ($tries < self::TRIES) {
            $user->forceFill(['failed_logins' => $tries])->saveQuietly();

            return;
        }

        $user->forceFill(['failed_logins' => 0, 'locked_until' => now()->addMinutes(self::MINUTES)])->saveQuietly();
        ActivityLogger::log('account_locked', 'User', (int) $user->id, $user->username,
            ['wrong_passwords' => self::TRIES, 'minutes' => self::MINUTES], 'system');
    }

    public static function clear(User $user): void
    {
        if ((int) $user->failed_logins === 0 && $user->locked_until === null) {
            return;
        }

        $user->forceFill(['failed_logins' => 0, 'locked_until' => null])->saveQuietly();
    }

    public static function unlock(User $user): void
    {
        self::clear($user);
        ActivityLogger::log('account_unlocked', 'User', (int) $user->id, $user->username);
    }
}
