<?php

namespace App\Services\Media;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class CatalogImageImporter
{
    public const MAX_BYTES = 5 * 1024 * 1024;

    // Block loopback, private and reserved ranges so an image link cannot reach internal hosts.
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

    public function fromUrl(string $url, string $dir): string
    {
        $url = trim($url);
        if (! preg_match('#^https?://#i', $url) || ! $this->isSafeRemoteUrl($url)) {
            throw new RuntimeException('Invalid URL.');
        }

        $contents = $this->fetch($url);
        if ($contents === false) {
            throw new RuntimeException('Unable to download image from URL.');
        }
        if (strlen($contents) > self::MAX_BYTES) {
            throw new RuntimeException('The image is larger than 5 MB.');
        }

        $pathPart = parse_url($url, PHP_URL_PATH) ?: '';
        $name = basename($pathPart) ?: ('image_' . date('Ymd_His') . '.jpg');
        $name = preg_replace('/[^A-Za-z0-9._-]/', '_', $name);

        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (! in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)) {
            $info = @getimagesizefromstring($contents);
            $ext = match ((int) ($info[2] ?? 0)) {
                IMAGETYPE_PNG => 'png',
                IMAGETYPE_WEBP => 'webp',
                default => 'jpg',
            };
            $name = pathinfo($name, PATHINFO_FILENAME) . '.' . $ext;
        }

        return $this->place($contents, $name, $ext, $dir);
    }

    public const MAX_BASE64_LENGTH = 6990508;

    public const MAX_PIXELS = 50_000_000;

    public function fromBase64(string $data, string $filename, string $dir): string
    {
        $data = trim($data);
        if (preg_match('#^data:[^,]{0,100},#i', $data, $m)) {
            $data = substr($data, strlen($m[0]));
        }
        $data = (string) preg_replace('/\s+/', '', $data);
        if ($data === '') {
            throw new RuntimeException('It is empty.');
        }
        if (strlen($data) > self::MAX_BASE64_LENGTH) {
            throw new RuntimeException('The image is larger than 5 MB.');
        }

        $bytes = base64_decode($data, true);
        if ($bytes === false || $bytes === '') {
            throw new RuntimeException('It is not valid base64 data.');
        }
        if (strlen($bytes) > self::MAX_BYTES) {
            throw new RuntimeException('The image is larger than 5 MB.');
        }

        $info = @getimagesizefromstring($bytes);
        $ext = match ((int) ($info[2] ?? 0)) {
            IMAGETYPE_JPEG => 'jpg',
            IMAGETYPE_PNG => 'png',
            IMAGETYPE_WEBP => 'webp',
            default => null,
        };
        if ($info === false || $ext === null) {
            throw new RuntimeException('It is not a JPEG, PNG or WebP image.');
        }
        if ((int) $info[0] * (int) $info[1] > self::MAX_PIXELS) {
            throw new RuntimeException('The image is larger than 50 megapixels.');
        }

        $base = pathinfo(basename(str_replace('\\', '/', $filename)), PATHINFO_FILENAME);
        $base = trim((string) preg_replace('/[^A-Za-z0-9_-]/', '_', $base), '_');
        if ($base === '') {
            $base = 'image_' . date('Ymd_His');
        }

        return $this->place($bytes, substr($base, 0, 100) . '.' . $ext, $ext, $dir);
    }

    private function place(string $contents, string $name, string $ext, string $dir): string
    {
        $disk = Storage::disk('public');
        $tmpRel = 'tmp/product-images/' . Str::uuid()->toString() . '.' . $ext;
        $disk->put($tmpRel, $contents);
        try {
            $this->ensureAllowedImage($disk->path($tmpRel));
        } catch (RuntimeException $e) {
            $disk->delete($tmpRel);
            throw $e;
        }
        $tmpRel = $this->convertWebpToJpg($tmpRel);
        $name = preg_replace('/\.webp$/i', '.jpg', $name);

        $disk->makeDirectory($dir);
        $target = $this->uniqueTargetPath($dir, $name);
        $disk->move($tmpRel, $target);

        return $target;
    }

    public function isSafeRemoteUrl(string $url): bool
    {
        return $this->checkedAddress($url) !== null;
    }

    public function fetch(string $url): string|false
    {
        $pin = $this->checkedAddress($url);
        if ($pin === null || ! defined('CURLOPT_RESOLVE')) {
            return false;
        }
        $ip = str_contains($pin['ip'], ':') ? '[' . $pin['ip'] . ']' : $pin['ip'];

        try {
            $response = Http::withOptions([
                'allow_redirects' => false,
                'curl' => [CURLOPT_RESOLVE => [$pin['host'] . ':' . $pin['port'] . ':' . $ip]],
            ])->timeout(10)->get($url);
        } catch (\Throwable) {
            return false;
        }

        return $response->successful() ? $response->body() : false;
    }

    private function checkedAddress(string $url): ?array
    {
        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = (string) ($parts['host'] ?? '');

        if (! in_array($scheme, ['http', 'https'], true) || $host === '') {
            return null;
        }

        $host = trim($host, '[]');
        $addresses = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : $this->resolveHostAddresses($host);
        if ($addresses === []) {
            return null;
        }

        foreach ($addresses as $address) {
            if ($this->isBlockedAddress($address)) {
                return null;
            }
        }

        return [
            'host' => $host,
            'port' => (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80)),
            'ip' => trim((string) $addresses[0], '[]'),
        ];
    }

    public function ensureAllowedImage(string $absPath): void
    {
        $info = @getimagesize($absPath);
        if ($info === false || ! isset($info[2])) {
            throw new RuntimeException('Invalid image file.');
        }
        if (! in_array((int) $info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) {
            throw new RuntimeException('Only JPG, PNG, and WebP images are allowed.');
        }
    }

    public function convertWebpToJpg(string $relPath): string
    {
        $disk = Storage::disk('public');
        $absPath = $disk->path($relPath);

        $info = @getimagesize($absPath);
        if (! $info || (int) ($info[2] ?? 0) !== IMAGETYPE_WEBP) {
            return $relPath;
        }

        $im = @imagecreatefromwebp($absPath);
        if (! $im) {
            return $relPath;
        }

        $newRel = preg_replace('/\.webp$/i', '.jpg', $relPath);
        if ($newRel === $relPath) {
            $newRel = $relPath . '.jpg';
        }
        $newAbs = $disk->path($newRel);

        imagejpeg($im, $newAbs, 90);
        imagedestroy($im);

        if ($newAbs !== $absPath) {
            @unlink($absPath);
        }

        return $newRel;
    }

    public function uniqueTargetPath(string $dir, string $filename): string
    {
        $dir = trim($dir, '/');
        $filename = trim(basename($filename));
        $disk = Storage::disk('public');

        $base = pathinfo($filename, PATHINFO_FILENAME);
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        $candidate = $dir . '/' . $base . '.' . $ext;
        if (! $disk->exists($candidate)) {
            return $candidate;
        }

        for ($i = 1; $i <= 9999; $i++) {
            $candidate = $dir . '/' . $base . '_' . $i . '.' . $ext;
            if (! $disk->exists($candidate)) {
                return $candidate;
            }
        }

        throw new RuntimeException('Unable to generate unique image filename.');
    }

    private function resolveHostAddresses(string $host): array
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

    private function isBlockedAddress(string $address): bool
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
            if ($this->addressInCidr($address, $cidr)) {
                return true;
            }
        }

        return false;
    }

    private function addressInCidr(string $address, string $cidr): bool
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
