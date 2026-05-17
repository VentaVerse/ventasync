<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Auto-detect APP_URL on the first web request that arrives while the .env
 * still holds a placeholder host (your-domain.com, example.com, localhost, …).
 *
 * Rationale: a fresh install ships with APP_URL=https://your-domain.com. Until
 * that is corrected, route() falls back to the placeholder in non-HTTP contexts
 * (queues, scheduled commands, mail) and helpers that read config('app.url')
 * directly produce unreachable URLs (Lazada/Shopee image migration, etc).
 *
 * Forcing the operator to edit .env on day one is unfriendly. Instead, this
 * middleware detects the real hostname from the incoming request, writes
 * APP_URL into .env once, and clears the bootstrap config cache so subsequent
 * processes pick up the new value.
 *
 * Security notes:
 *   - Only rewrites while APP_URL host is in the placeholder list. Once a real
 *     domain is set, this middleware is a no-op — no host-header-injection risk.
 *   - Domain moves require a manual .env edit on purpose; auto-overwriting a
 *     real domain on every visit would let an attacker repoint the install by
 *     visiting with a spoofed Host header.
 */
class AutoDetectAppUrl
{
    /**
     * Hostnames that mean "not configured yet". Match is case-insensitive.
     */
    private const PLACEHOLDER_HOSTS = [
        '',
        'your-domain.com',
        'example.com',
        'example.org',
        'localhost',
        '127.0.0.1',
        '0.0.0.0',
    ];

    /**
     * Per-worker idempotency flag. Avoid re-checking on every request.
     */
    private static bool $checked = false;

    public function handle(Request $request, Closure $next): Response
    {
        if (!self::$checked) {
            self::$checked = true;
            $this->maybeRewriteEnv($request);
        }

        return $next($request);
    }

    private function maybeRewriteEnv(Request $request): void
    {
        $currentUrl = (string) config('app.url', '');
        $currentHost = strtolower((string) parse_url($currentUrl, PHP_URL_HOST));

        if (!in_array($currentHost, self::PLACEHOLDER_HOSTS, true)) {
            return;
        }

        $detected = rtrim($request->getSchemeAndHttpHost(), '/');
        $detectedHost = strtolower((string) parse_url($detected, PHP_URL_HOST));
        if ($detected === '' || in_array($detectedHost, self::PLACEHOLDER_HOSTS, true)) {
            return;
        }

        $envPath = base_path('.env');
        if (!is_file($envPath) || !is_writable($envPath)) {
            Log::warning('AutoDetectAppUrl: .env is missing or not writable; skipping auto-detect', [
                'env_path' => $envPath,
                'detected' => $detected,
            ]);
            return;
        }

        try {
            $env = file_get_contents($envPath);
            if ($env === false) {
                return;
            }

            $pattern = '/^APP_URL=.*$/m';
            $replacement = 'APP_URL=' . $detected;

            $env = preg_match($pattern, $env)
                ? preg_replace($pattern, $replacement, $env)
                : rtrim($env, "\n") . "\n" . $replacement . "\n";

            file_put_contents($envPath, $env, LOCK_EX);

            config(['app.url' => $detected]);

            $configCache = base_path('bootstrap/cache/config.php');
            if (file_exists($configCache)) {
                @unlink($configCache);
            }

            Log::info('AutoDetectAppUrl: APP_URL set to ' . $detected);
        } catch (\Throwable $e) {
            Log::warning('AutoDetectAppUrl: failed to update .env', [
                'error' => $e->getMessage(),
                'detected' => $detected,
            ]);
        }
    }
}
