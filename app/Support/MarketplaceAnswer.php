<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * The marketplace's own error message, and nothing else (the owner,
 * 2026-09-03: "Instead of going to the API logs, I want the reason why
 * the API had error. It should not be the whole return message. Only
 * the error message.").
 *
 * Every channel client answers ['ok' => bool, 'status' => int, 'body'
 * => array]; every marketplace hides its reason in its own corner of
 * the body. This is the ONE place that knows those corners, so a flash
 * or result panel can print the reason without either dumping the
 * payload or shrugging "Unknown error" while the reason sat one field
 * over. MarketplaceWords::headline() stays the layer above: it turns a
 * KNOWN reason into plain seller words; this extracts the reason.
 */
final class MarketplaceAnswer
{
    /**
     * The error message alone, or null when the answer was a success.
     * Clipped to one flash-sized line; the full body belongs behind a
     * details fold or in the API log, never in the message.
     */
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

    /**
     * The idiot-proof variant for flashes (the owner, 2026-09-03): a KNOWN
     * reason comes back translated into plain seller words by the
     * hardcoded map in MarketplaceWords; an unknown one keeps the
     * marketplace's own sentence, because a made-up translation would
     * be worse. Surfaces that render partials.channel-answer keep using
     * errorText() - the partial translates itself and keeps the raw
     * sentence one click away, which a flash has no room for. The raw
     * answer always survives in the API log either way.
     */
    public static function plain(string $channel, array $result): string
    {
        $message = self::errorText($channel, $result);

        return MarketplaceWords::headline($channel, $message) ?? $message;
    }

    /** Never-null variant for failure branches that already know they failed. */
    public static function errorText(string $channel, array $result): string
    {
        return self::error($channel, $result)
            ?? ($channel . ' reported no error message.');
    }

    /**
     * Shopee replies HTTP 200 with an `error` code beside a often-empty
     * `message`; per-item refusals ride response.failure_list with the
     * real sentence in fail_message.
     */
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
            return implode(' — ', $parts) . ($code !== '' && $message === '' ? " ({$code})" : '');
        }

        return $code !== '' ? $code : null;
    }

    /**
     * Lazada: code '0' is success; the reason lives in message. A
     * transport failure carries a message with no code at all, and must
     * not read as success for lacking one.
     */
    private static function lazada(bool $ok, array $body): ?string
    {
        $code = trim((string) ($body['code'] ?? '0'));
        if (($code === '0' || $code === '') && $ok) {
            return null;
        }

        $message = self::firstOf($body, ['message', 'error_msg', 'detail']);
        if ($code === '0' || $code === '') {
            return $message;
        }

        return $message !== null ? $message . " ({$code})" : "Lazada refused with code {$code}.";
    }

    /**
     * TikTok: body.code 0 is success; per-SKU refusals inside an
     * accepted call ride data.errors[].message.
     */
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

        return $parts !== [] ? implode(' — ', $parts) : null;
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
