<?php

namespace App\Support;

use Illuminate\Http\Client\ConnectionException;

// A transport error message holds the signed URL with tokens; never print it, show a fixed sentence.
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

    public static function scrub(string $message): string
    {
        return (string) preg_replace('~https?://\S+~i', '[url]', $message);
    }
}
