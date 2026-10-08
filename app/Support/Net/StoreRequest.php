<?php

namespace App\Support\Net;

use App\Plans\Plan;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

final class StoreRequest
{
    public const REFUSED = 'This store address is not allowed on a hosted server. Use the store\'s public https address.';

    public static function to(string $url): PendingRequest
    {
        if (! Plan::hosted()) {
            return Http::withOptions([]);
        }

        if ($refusal = self::refusal($url)) {
            throw new RuntimeException($refusal);
        }

        $pin = PublicAddress::pin($url);
        $ip = str_contains($pin['ip'], ':') ? '[' . $pin['ip'] . ']' : $pin['ip'];

        return Http::withOptions([
            'allow_redirects' => false,
            'curl' => [CURLOPT_RESOLVE => [$pin['host'] . ':' . $pin['port'] . ':' . $ip]],
        ]);
    }

    public static function refusal(string $url): ?string
    {
        if (! Plan::hosted()) {
            return null;
        }

        if (strtolower((string) parse_url($url, PHP_URL_SCHEME)) !== 'https' || PublicAddress::pin($url) === null) {
            return self::REFUSED;
        }

        return null;
    }
}
