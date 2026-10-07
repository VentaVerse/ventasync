<?php

namespace App\Support;

use Illuminate\Support\Str;

final class MarketplaceAnswer
{
    public static function error(string $channel, array $result): ?string
    {
        $body = is_array($result['body'] ?? null) ? $result['body'] : [];
        $ok = (bool) ($result['ok'] ?? false);

        $message = match (true) {
            str_starts_with(strtolower($channel), 'shopee') => self::shopee($ok, $body),
            str_starts_with(strtolower($channel), 'lazada') => self::lazada($ok, $body),
            str_starts_with(strtolower($channel), 'tiktok') => self::tiktok($ok, $body),
            default => $ok ? null : self::firstOf($body, ['message', 'error', 'error_msg']),
        };

        if ($message === null && ! $ok) {
            $status = (int) ($result['status'] ?? 0);
            $message = $status > 0
                ? $channel . ' answered HTTP ' . $status . ' with no error message.'
                : $channel . ' gave no answer.';
        }

        return $message !== null ? Str::limit(trim($message), 300, '…') : null;
    }

    public static function plain(string $channel, array $result): string
    {
        $message = self::errorText($channel, $result);

        return MarketplaceWords::headline($channel, $message) ?? $message;
    }

    public static function errorText(string $channel, array $result): string
    {
        return self::error($channel, $result)
            ?? ($channel . ' reported no error message.');
    }

    private static function shopee(bool $ok, array $body): ?string
    {
        $code = trim((string) ($body['error'] ?? ''));
        if ($ok && $code === '') {
            return null;
        }

        $message = trim((string) ($body['message'] ?? ''));
        $detail = trim((string) (data_get($body, 'response.failure_list.0.fail_message')
            ?? data_get($body, 'response.failure_list.0.fail_error')
            ?? ''));

        $parts = array_filter([$message !== '' ? $message : null, $detail !== '' && $detail !== $message ? $detail : null]);
        if ($parts !== []) {
            return self::joined($parts) . ($code !== '' && $message === '' ? " ({$code})" : '');
        }

        return $code !== '' ? $code : null;
    }

    private static function lazada(bool $ok, array $body): ?string
    {
        $code = trim((string) ($body['code'] ?? '0'));
        if (($code === '0' || $code === '') && $ok) {
            return null;
        }

        $details = [];
        foreach ((array) ($body['detail'] ?? []) as $d) {
            if (is_array($d) && trim((string) ($d['message'] ?? '')) !== '') {
                $field = trim((string) ($d['field'] ?? ''));
                $details[] = ($field !== '' ? $field . ': ' : '') . trim((string) $d['message']);
            }
        }
        $message = $details !== [] ? implode('; ', $details) : self::firstOf($body, ['message', 'error_msg']);
        if ($code === '0' || $code === '') {
            return $message;
        }

        return $message !== null ? $message . " ({$code})" : "Lazada refused with code {$code}.";
    }

    private static function tiktok(bool $ok, array $body): ?string
    {
        $code = (int) ($body['code'] ?? ($ok ? 0 : -1));
        $skuError = trim((string) data_get($body, 'data.errors.0.message', ''));

        if ($ok && $code === 0 && $skuError === '') {
            return null;
        }

        $message = trim((string) ($body['message'] ?? ''));
        $parts = array_filter([
            $message !== '' && strtolower($message) !== 'success' ? $message : null,
            $skuError !== '' && $skuError !== $message ? $skuError : null,
        ]);

        return $parts !== [] ? self::joined($parts) : null;
    }

    private static function joined(array $parts): string
    {
        $parts = array_values($parts);
        $last = array_pop($parts);
        $lead = array_map(fn ($p) => rtrim($p, " .:;,"), $parts);

        return implode(': ', [...$lead, $last]);
    }

    private static function firstOf(array $body, array $keys): ?string
    {
        foreach ($keys as $key) {
            $v = trim((string) ($body[$key] ?? ''));
            if ($v !== '') {
                return $v;
            }
        }

        return null;
    }
}
