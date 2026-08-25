<?php

namespace App\Support;

/**
 * A marketplace's refusal, in the seller's words where the case is
 * known. The headline names the situation and the way out; the
 * marketplace's own sentence stays one click away (see the
 * partials.channel-answer view), never hidden and never the headline.
 * Unknown cases keep the marketplace's words as the headline: a made-up
 * translation would be worse than a strange one.
 */
final class MarketplaceWords
{
    /** @return ?string the plain headline, or null when the case is not known */
    public static function headline(string $channel, string $raw): ?string
    {
        $m = strtolower($raw);

        if (str_contains($m, 'access_token') || str_contains($m, 'access token')
            || str_contains($m, 'x-tts-access-token') || str_contains($m, 'illegalaccesstoken')
            || str_contains($m, 'token expired') || str_contains($m, 'token is expired')
            || str_contains($m, 'invalid token') || str_contains($m, 'unauthorized')) {
            return $channel . "'s sign-in has lapsed.";
        }
        if (str_contains($m, 'insufficientpermission') || str_contains($m, 'does not have permission')) {
            return $channel . ' has not granted this app that part of its API. It needs enabling in the ' . $channel . ' developer console.';
        }
        if (str_contains($m, 'could not be reached')) {
            return null; // already plain, from TransportError
        }

        return null;
    }
}
