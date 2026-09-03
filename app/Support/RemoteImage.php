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
 * follows no redirects (a redirect could hop to an internal address
 * after the check).
 *
 * @return array{body: string, ext: string}|null null = not fetched, for any reason
 */
class RemoteImage
{
    public static function fetch(string $url, int $timeout = 20): ?array
    {
        $url = trim($url);
        if (! preg_match('#^https://#i', $url)) {
            return null;
        }

        $host = parse_url($url, PHP_URL_HOST);
        if (! is_string($host) || $host === '') {
            return null;
        }

        // Resolve before fetching; refuse anything that lands inside.
        $ips = @gethostbynamel($host) ?: (filter_var($host, FILTER_VALIDATE_IP) ? [$host] : []);
        if ($ips === []) {
            return null;
        }
        foreach ($ips as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                return null;
            }
        }

        try {
            $answer = Http::timeout($timeout)
                ->withOptions(['allow_redirects' => false])
                ->get($url);
        } catch (\Throwable) {
            return null;
        }

        if (! $answer->successful()) {
            return null;
        }

        $type = (string) $answer->header('Content-Type');
        if ($type !== '' && ! str_starts_with($type, 'image/')) {
            return null;
        }

        return [
            'body' => $answer->body(),
            'ext' => match ($type) {
                'image/png' => 'png',
                'image/webp' => 'webp',
                'image/gif' => 'gif',
                default => 'jpg',
            },
        ];
    }
}
