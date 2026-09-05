<?php

namespace App\Support;

/**
 * Whether a marketplace call succeeded, by the marketplace's own verdict.
 *
 * Shopee, Lazada and TikTok answer HTTP 200 whether or not they did what
 * was asked; the refusal rides the body. Reading only the HTTP status
 * painted refused calls green in the API log and told the operator
 * nothing was wrong (the owner, 2026-09-06: a Lazada create refused with
 * code 4108 and a Shopee add_item refused for price both logged ok=1).
 * Every API log row and every outcome line reads the verdict from here.
 */
final class MarketplaceVerdict
{
    /**
     * @param string $channel  shopee | lazada | tiktok | anything else (HTTP only)
     * @param bool   $httpOk   the HTTP status was 2xx
     * @param mixed  $body     the decoded answer, when there is one
     */
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
