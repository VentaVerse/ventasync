<?php

namespace App\Support\Api;

use Illuminate\Http\Request;

final class ClientLimits
{
    public static function rules(): array
    {
        return [
            'allowed_ips' => ['nullable', 'string', 'max:2000', function (string $attribute, mixed $value, \Closure $fail) {
                foreach (self::splitIps((string) $value) as $ip) {
                    if (! self::looksLikeIpOrRange($ip)) {
                        $fail("\"{$ip}\" is not an IP address or a CIDR range.");
                    }
                }
            }],
            'calls_per_minute' => ['nullable', 'integer', 'min:1', 'max:600'],
        ];
    }

    public static function fields(Request $request): array
    {
        $ips = self::splitIps((string) $request->input('allowed_ips', ''));

        return [
            'allowed_ips' => $ips === [] ? null : $ips,
            'calls_per_minute' => $request->filled('calls_per_minute') ? (int) $request->integer('calls_per_minute') : null,
        ];
    }

    public static function splitIps(string $raw): array
    {
        $parts = preg_split('/[\s,]+/', $raw) ?: [];

        return array_values(array_unique(array_filter(array_map('trim', $parts), fn ($p) => $p !== '')));
    }

    private static function looksLikeIpOrRange(string $ip): bool
    {
        [$addr, $bits] = array_pad(explode('/', $ip, 2), 2, null);
        if (filter_var($addr, FILTER_VALIDATE_IP) === false) {
            return false;
        }
        if ($bits === null) {
            return true;
        }
        $max = str_contains($addr, ':') ? 128 : 32;

        return ctype_digit($bits) && (int) $bits >= 0 && (int) $bits <= $max;
    }
}
