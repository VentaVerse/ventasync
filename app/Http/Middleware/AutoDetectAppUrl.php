<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

// Only rewrite APP_URL while it is a placeholder; overwriting a real domain would allow Host header spoofing.
class AutoDetectAppUrl
{
    private const PLACEHOLDER_HOSTS = [
        '',
        'your-domain.com',
        'example.com',
        'example.org',
        'localhost',
        '127.0.0.1',
        '0.0.0.0',
    ];

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
