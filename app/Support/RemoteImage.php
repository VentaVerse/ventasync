<?php

namespace App\Support;

use Illuminate\Support\Facades\Http;

class RemoteImage
{
    private const MAX_HOPS = 3;

    public static function fetch(string $url, int $timeout = 20): ?array
    {
        for ($hop = 0; $hop <= self::MAX_HOPS; $hop++) {
            $url = trim($url);
            if (! self::hostIsPublic($url)) {
                return null;
            }
            try {
                $answer = Http::timeout($timeout)
                    ->withOptions(['allow_redirects' => false])
                    ->get($url);
            } catch (\Throwable) {
                return null;
            }
            if ($answer->redirect()) {
                $next = (string) $answer->header('Location');
                if ($next === '') {
                    return null;
                }
                if (! preg_match('#^https?://#i', $next)) {
                    $base = parse_url($url);
                    $next = (string) ($base['scheme'] ?? 'https') . '://' . (string) ($base['host'] ?? '')
                        . (str_starts_with($next, '/') ? $next : rtrim(dirname((string) ($base['path'] ?? '/')), '/') . '/' . $next);
                }
                $url = $next;
                continue;
            }
            if (! $answer->successful()) {
                return null;
            }
            $body = $answer->body();
            $ext = self::extensionOf($body, (string) $answer->header('Content-Type'));
            if ($ext === null) {
                return null;
            }

            return ['body' => $body, 'ext' => $ext];
        }

        return null;
    }

    private static function hostIsPublic(string $url): bool
    {
        if (! preg_match('#^https://#i', $url)) {
            return false;
        }
        $host = parse_url($url, PHP_URL_HOST);
        if (! is_string($host) || $host === '') {
            return false;
        }
        $ips = @gethostbynamel($host) ?: (filter_var($host, FILTER_VALIDATE_IP) ? [$host] : []);
        if ($ips === []) {
            return false;
        }
        foreach ($ips as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                return false;
            }
        }

        return true;
    }

    private static function extensionOf(string $body, string $contentType): ?string
    {
        $byBytes = match (true) {
            str_starts_with($body, "\xFF\xD8\xFF") => 'jpg',
            str_starts_with($body, "\x89PNG") => 'png',
            str_starts_with($body, 'GIF8') => 'gif',
            str_starts_with($body, 'RIFF') && substr($body, 8, 4) === 'WEBP' => 'webp',
            default => null,
        };
        if ($byBytes !== null) {
            return $byBytes;
        }
        $type = strtolower(trim(explode(';', $contentType)[0]));

        return match ($type) {
            'image/jpeg', 'image/jpg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
            default => null,
        };
    }
}
