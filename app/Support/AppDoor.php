<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * The app door: the mobile app's own API routes, which a person reaches with
 * the token their login returned, under their own permissions. The shared
 * rules the partner API and the assistant run are also reached through it,
 * and the ledgers should say a change came from the app, not from an
 * outside application.
 */
final class AppDoor
{
    /** Set on the request by App\Http\Middleware\EnsureAppPerson. */
    public const ATTRIBUTE = 'door';

    public const VALUE = 'app';

    /**
     * The ability the Mobile app extension defines: who may use the app.
     * With the extension off nobody defines it, and the app stays shut.
     */
    public const SIGN_IN = 'app.sign-in';

    public const APP_OFF = 'The mobile app is not turned on for this business.';

    public const SIGN_IN_REFUSED = 'The app is turned off for this account.';

    /** Why this person may not use the app, or null when they may. */
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

    /**
     * The address a paged list links its pages to: the door that was asked,
     * so the app can follow its own links with its own token. $path is the
     * partner API's path without /api/v1, such as "inventory".
     */
    public static function pagePath(string $path): string
    {
        return url((self::isApp() ? '/api/' : '/api/v1/') . ltrim($path, '/'));
    }

    /**
     * The source a ledger records. A shared rule names its changes "api";
     * when a person made the change through the app, it is "mobile", the
     * word every other app change already uses.
     */
    public static function source(string $source): string
    {
        if (in_array($source, ['api', 'user'], true) && self::isApp() && ! Actor::current()->isApplication()) {
            return 'mobile';
        }

        return $source;
    }
}
