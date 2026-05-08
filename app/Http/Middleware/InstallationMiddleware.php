<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gates access to the installer wizard.
 *
 * Behavior:
 * - When marker file does NOT exist (fresh install state):
 *     - /install/* routes → allowed
 *     - /* (all other routes) → redirected to /install
 * - When marker file EXISTS (system installed):
 *     - /install/* routes → redirected to /
 *     - /* routes → allowed (normal app)
 *
 * The marker file at storage/agila/installed.lock is touched on
 * successful completion of the install wizard. WiseCP additionally
 * `unlink()`s the install.php file itself; we keep the controller
 * (so customers can re-run if needed) but the middleware enforces
 * one-time access via the marker.
 */
class InstallationMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $isInstallRoute = str_starts_with($request->path(), 'install');
        $isInstalled = self::isInstalled();

        if ($isInstallRoute && $isInstalled) {
            return redirect('/');
        }

        if (!$isInstallRoute && !$isInstalled) {
            return redirect('/install');
        }

        return $next($request);
    }

    /**
     * Check whether the install has completed. Read directly from the
     * filesystem so we don't depend on cache, config, or DB.
     */
    public static function isInstalled(): bool
    {
        return is_file(self::markerPath());
    }

    public static function markerPath(): string
    {
        return storage_path('agila/installed.lock');
    }

    public static function markInstalled(): void
    {
        $dir = dirname(self::markerPath());
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        file_put_contents(self::markerPath(), now()->toIso8601String() . PHP_EOL);
    }
}
