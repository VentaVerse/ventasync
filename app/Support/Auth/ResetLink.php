<?php

namespace App\Support\Auth;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;

final class ResetLink
{
    public const MINUTES = 30;

    public static function for(User $user): string
    {
        $path = URL::temporarySignedRoute('login.reset', now()->addMinutes(self::MINUTES),
            ['user' => $user->id, 'v' => self::version($user)], false);

        return rtrim((string) config('app.url'), '/') . $path;
    }

    public static function valid(Request $request, User $user): bool
    {
        return $request->hasValidSignature(false)
            && hash_equals(self::version($user), (string) $request->query('v'));
    }

    // The link dies with the password it was made for, so it works once.
    private static function version(User $user): string
    {
        return substr(hash('sha256', (string) $user->password), 0, 16);
    }
}
