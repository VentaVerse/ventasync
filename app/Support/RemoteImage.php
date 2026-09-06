<?php

namespace App\Support;

use Illuminate\Support\Facades\Http;

/**
 * The one way an importer downloads a marketplace image (2026-09-04,
 * from the security review of the channel importers).
 *
 * The URL comes from a channel's API answer - data, not something this
 * server chose - so fetching it blindly is server-side request forgery:
 * a hostile or compromised channel could hand back links to the cloud
 * metadata service or anything else on the private network. This
 * fetcher refuses everything but https, resolves the host FIRST and
 * refuses private, loopback, link-local and reserved addresses, and
 * follows a redirect only by running the same check on every hop (a
 * CDN's 30x to a sibling host was why TikTok pictures never arrived,
 * 2026-09-07). The body is judged by its bytes, not the header alone:
 * a CDN that says octet-stream for a JPEG is still handing over a JPEG.
 *
 * @return array{body: string, ext: string}|null null = not fetched, for any reason
 */
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
                // A relative Location resolves against the URL that answered.
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

    /** https only, and a host that resolves to public addresses. */
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

    /** The file extension from the bytes first, the header second; null when it is not an image. */
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
