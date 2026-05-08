<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gates access to the installer wizard.
 *
 * Behavior:
 * - When NOT installed (fresh state):
 *     - /install/* routes → allowed
 *     - /* (all other routes) → redirected to /install
 * - When INSTALLED:
 *     - /install/* routes → redirected to /
 *     - /* routes → allowed (normal app)
 *
 * "Installed" detection is robust to multiple scenarios:
 *   1. Marker file storage/agila/installed.lock exists → installed (fast path)
 *   2. No marker, but .env has DB credentials AND `users` table exists AND
 *      it has at least one user → installed (existing customer upgrading
 *      to a version that added the installer; auto-creates marker to
 *      cache the verdict)
 *   3. No marker, no DB / no users → fresh install, route to /install
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
     * Robust installed-state detection. Order:
     *   1. Marker file (fast path; no DB query)
     *   2. DB-state probe (legacy detection for upgrades)
     *
     * If detection succeeds via path 2, the marker is auto-created so
     * subsequent requests take the fast path.
     */
    public static function isInstalled(): bool
    {
        if (is_file(self::markerPath())) {
            return true;
        }

        if (self::detectExistingInstall()) {
            // Cache the verdict — touch the marker so we don't probe the DB
            // on every request from now on.
            self::markInstalled('detected from existing DB state');
            return true;
        }

        return false;
    }

    /**
     * Probe whether the site looks like an existing, configured install.
     * Used as a fallback when the marker file is missing — primarily for
     * customers who upgraded from a pre-installer Community version.
     */
    protected static function detectExistingInstall(): bool
    {
        try {
            // .env must be present and configured
            if (!file_exists(base_path('.env'))) {
                return false;
            }
            $dbName = (string) env('DB_DATABASE', '');
            if ($dbName === '') {
                return false;
            }

            // Must be able to connect AND have a `users` table with rows
            if (!\Illuminate\Support\Facades\Schema::hasTable('users')) {
                return false;
            }
            $count = \Illuminate\Support\Facades\DB::table('users')->count();
            return $count > 0;
        } catch (\Throwable $e) {
            // Any failure (no DB, no tables, bad credentials) → not installed
            return false;
        }
    }

    public static function markerPath(): string
    {
        return storage_path('agila/installed.lock');
    }

    public static function markInstalled(string $note = ''): void
    {
        $dir = dirname(self::markerPath());
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $contents = now()->toIso8601String();
        if ($note !== '') {
            $contents .= ' — ' . $note;
        }
        file_put_contents(self::markerPath(), $contents . PHP_EOL);
    }
}
