<?php

namespace App\Support;

use Illuminate\Http\Client\ConnectionException;

/**
 * The sentence a page may print when a marketplace call threw.
 *
 * Two different things throw. A TRANSPORT failure (timeout, refused,
 * DNS) carries the full signed request URL in its message: app key,
 * access token, signature. Echoing it on screen leaks credentials to
 * anyone who can read the page, so it becomes a fixed sentence naming
 * only the kind of failure. A marketplace REFUSAL wrapped in a
 * RuntimeException carries the marketplace's own words, which the
 * operator needs; those are kept, with any URL scrubbed as a second
 * line of defence. The raw message belongs in the channel's API log.
 */
final class TransportError
{
    public static function plain(\Throwable $e, string $channel): string
    {
        $message = $e->getMessage();
        $m = strtolower($message);

        $isTransport = $e instanceof ConnectionException
            || $e instanceof \GuzzleHttp\Exception\TransferException
            || str_contains($m, 'curl error');

        if (!$isTransport) {
            return $channel . ' did not answer: ' . self::scrub($message);
        }

        $why = match (true) {
            str_contains($m, 'timed out') || str_contains($m, 'timeout') => 'the request timed out',
            str_contains($m, 'resolve host') || str_contains($m, 'could not resolve') || str_contains($m, 'name resolution') => 'its address could not be resolved',
            str_contains($m, 'ssl') || str_contains($m, 'certificate') => 'the secure connection failed',
            str_contains($m, 'refused') => 'the connection was refused',
            default => 'the connection failed',
        };

        return $channel . ' could not be reached; ' . $why . '. The details are in the API log.';
    }

    /** Any URL in a message is replaced whole: its query string is where credentials ride. */
    public static function scrub(string $message): string
    {
        return (string) preg_replace('~https?://\S+~i', '[url]', $message);
    }
}
