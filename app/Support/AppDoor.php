<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Gate;

final class AppDoor
{
    public const ATTRIBUTE = 'door';

    public const VALUE = 'app';

    public const SIGN_IN = 'app.sign-in';

    public const PREFIX = 'api/app/v1';

    public const FORMER_PREFIX = 'api';

    private const FORMER_CHANNEL_NAMES = ['ventacart' => 'venta'];

    private static bool $mountingFormer = false;

    public const SIGN_IN_REFUSED = 'The app is turned off for this account.';

    public static function refusal(User $user): ?string
    {
        if (! Gate::has(self::SIGN_IN)) {
            return null;
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
        $request = request();
        $prefix = match (true) {
            $request !== null && $request->is(self::PREFIX . '/*') => '/' . self::PREFIX . '/',
            self::isApp() => '/' . self::FORMER_PREFIX . '/',
            default => '/api/v1/',
        };

        return url($prefix . ltrim($path, '/'));
    }

    // The same route file serves /api/app/v1 and, for phones on older apps, /api with the former names.
    public static function mount(Router $router, array $middleware, string $file): void
    {
        foreach ([false, true] as $former) {
            self::$mountingFormer = $former;
            $router->prefix($former ? self::FORMER_PREFIX : self::PREFIX)
                ->middleware($former ? [...$middleware, 'app.former-names'] : $middleware)
                ->group($file);
        }
        self::$mountingFormer = false;
    }

    public static function channelNames(string $channel): array
    {
        $former = self::FORMER_CHANNEL_NAMES[$channel] ?? null;

        return self::$mountingFormer && $former !== null ? [$channel, $former] : [$channel];
    }

    public static function source(string $source): string
    {
        if (in_array($source, ['api', 'user'], true) && self::isApp() && ! Actor::current()->isApplication()) {
            return 'mobile';
        }

        return $source;
    }
}
