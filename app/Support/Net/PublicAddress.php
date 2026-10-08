<?php

namespace App\Support\Net;

final class PublicAddress
{
    private const BLOCKED_IP_RANGES = [
        '127.0.0.0/8',
        '10.0.0.0/8',
        '172.16.0.0/12',
        '192.168.0.0/16',
        '169.254.0.0/16',
        '0.0.0.0/8',
        '224.0.0.0/4',
        '240.0.0.0/4',
        '::1/128',
        '::/128',
        'fe80::/10',
        'ff00::/8',
        'fc00::/7',
    ];

    private static $resolver = null;

    public static function resolveUsing(?callable $resolver): void
    {
        self::$resolver = $resolver;
    }

    public static function pin(string $url): ?array
    {
        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = (string) ($parts['host'] ?? '');

        if (! in_array($scheme, ['http', 'https'], true) || $host === '') {
            return null;
        }

        $host = trim($host, '[]');
        $addresses = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : self::resolveHostAddresses($host);
        if ($addresses === []) {
            return null;
        }

        foreach ($addresses as $address) {
            if (self::isBlockedAddress($address)) {
                return null;
            }
        }

        return [
            'host' => $host,
            'port' => (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80)),
            'ip' => trim((string) $addresses[0], '[]'),
        ];
    }

    private static function resolveHostAddresses(string $host): array
    {
        if (self::$resolver) {
            return array_values((array) (self::$resolver)($host));
        }

        $records = @dns_get_record($host, DNS_A + DNS_AAAA);
        if (! is_array($records)) {
            return [];
        }

        $addresses = [];
        foreach ($records as $record) {
            if (! empty($record['ip'])) {
                $addresses[] = $record['ip'];
            }
            if (! empty($record['ipv6'])) {
                $addresses[] = $record['ipv6'];
            }
        }

        return $addresses;
    }
    private static function isBlockedAddress(string $address): bool
    {
        $address = trim($address, '[]');

        if (str_starts_with(strtolower($address), '::ffff:')
            && filter_var(substr($address, 7), FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $address = substr($address, 7);
        }

        if (! filter_var($address, FILTER_VALIDATE_IP)) {
            return true;
        }

        foreach (self::BLOCKED_IP_RANGES as $cidr) {
            if (self::addressInCidr($address, $cidr)) {
                return true;
            }
        }

        return false;
    }
    private static function addressInCidr(string $address, string $cidr): bool
    {
        [$subnet, $maskBits] = explode('/', $cidr, 2);
        $maskBits = (int) $maskBits;

        $addressBin = @inet_pton($address);
        $subnetBin = @inet_pton($subnet);
        if ($addressBin === false || $subnetBin === false || strlen($addressBin) !== strlen($subnetBin)) {
            return false;
        }

        $totalBits = strlen($addressBin) * 8;
        if ($maskBits < 0 || $maskBits > $totalBits) {
            return false;
        }

        $fullBytes = intdiv($maskBits, 8);
        $remainderBits = $maskBits % 8;

        if ($fullBytes > 0 && substr($addressBin, 0, $fullBytes) !== substr($subnetBin, 0, $fullBytes)) {
            return false;
        }
        if ($remainderBits === 0) {
            return true;
        }

        $mask = (0xFF << (8 - $remainderBits)) & 0xFF;

        return (ord($addressBin[$fullBytes]) & $mask) === (ord($subnetBin[$fullBytes]) & $mask);
    }
}
