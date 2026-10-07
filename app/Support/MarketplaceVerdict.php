<?php

namespace App\Support;

// Shopee, Lazada and TikTok answer HTTP 200 even on refusal; read success from the response body.
final class MarketplaceVerdict
{
    public static function ok(string $channel, bool $httpOk, mixed $body): bool
    {
        if (! $httpOk) {
            return false;
        }
        if (! is_array($body)) {
            return true;
        }

        return match (strtolower($channel)) {
            'shopee' => trim((string) ($body['error'] ?? '')) === '',
            'lazada' => ! array_key_exists('code', $body) || in_array(trim((string) $body['code']), ['', '0'], true),
            'tiktok', 'tiktok shop' => ! array_key_exists('code', $body) || (int) $body['code'] === 0,
            default => true,
        };
    }
}
