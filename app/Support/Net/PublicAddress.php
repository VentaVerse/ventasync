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
        '100.64.0.0/10',
        '198.18.0.0/15',
        '192.0.0.0/24',
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
        $address = self::unwrapIpv4(trim($address, '[]'));
        if ($address === null) {
            return true;
        }

        foreach (self::BLOCKED_IP_RANGES as $cidr) {
            if (self::addressInCidr($address, $cidr)) {
                return true;
            }
        }

        return filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
    }

    // An IPv6 address can carry an IPv4 one (::ffff:a.b.c.d, ::a.b.c.d, 64:ff9b::a.b.c.d, in hex too); check the IPv4 inside.
    private static function unwrapIpv4(string $address): ?string
    {
        $bin = @inet_pton($address);
        if ($bin === false) {
            return null;
        }
        if (strlen($bin) !== 16) {
            return $address;
        }

        $prefix = substr($bin, 0, 12);
        $carriers = [str_repeat("\0", 10) . "\xff\xff", str_repeat("\0", 12), "\x00\x64\xff\x9b" . str_repeat("\0", 8)];
        if (in_array($prefix, $carriers, true) && substr($bin, 12) !== "\0\0\0\0" && substr($bin, 12) !== "\0\0\0\1") {
            return (string) inet_ntop(substr($bin, 12));
        }

        return (string) inet_ntop($bin);
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
