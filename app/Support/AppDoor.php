<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\Gate;

final class AppDoor
{
    public const ATTRIBUTE = 'door';

    public const VALUE = 'app';

    public const SIGN_IN = 'app.sign-in';

    public const APP_OFF = 'The mobile app is not turned on for this business.';

    public const SIGN_IN_REFUSED = 'The app is turned off for this account.';

    public static function refusal(User $user): ?string
    {
        if (! Gate::has(self::SIGN_IN)) {
            return self::APP_OFF;
        }

        return Gate::forUser($user)->allows(self::SIGN_IN) ? null : self::SIGN_IN_REFUSED;
    }

    public static function isApp(): bool
    {
        $request = request();

        return $request !== null && $request->attributes->get(self::ATTRIBUTE) === self::VALUE;
    }

    public static function pagePath(string $path): string
    {
        return url((self::isApp() ? '/api/' : '/api/v1/') . ltrim($path, '/'));
    }

    public static function source(string $source): string
    {
        if (in_array($source, ['api', 'user'], true) && self::isApp() && ! Actor::current()->isApplication()) {
            return 'mobile';
        }

        return $source;
    }
}
