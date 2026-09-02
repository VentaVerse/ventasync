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

        // The hardcoded map grows here (the owner, 2026-09-03): each entry
        // is a refusal met in real use, translated into what to DO.
        if (str_contains($m, 'rate limit') || str_contains($m, 'too many request') || str_contains($m, 'request too frequent')) {
            return $channel . ' is rate-limiting this app right now. Wait a minute and try the same action again.';
        }
        if (str_contains($m, 'wrong sign') || str_contains($m, 'sign is invalid') || str_contains($m, 'incompletesignature') || str_contains($m, 'signature')) {
            return 'The app credentials look wrong - ' . $channel . ' refused the request signature. Check the Partner ID and Partner Key on the Settings page.';
        }
        if (str_contains($m, 'shop_id') && (str_contains($m, 'not found') || str_contains($m, 'invalid'))) {
            return $channel . " does not recognise this Shop ID. Re-run the authorisation from Settings so the shop names itself.";
        }
        if (str_contains($m, 'error_param')) {
            $detail = trim((string) preg_replace('/^.*?error_param\.?/i', '', $raw)) ?: null;
            return $channel . ' refused a field in the request' . ($detail ? ': ' . $detail : '') . '. Fix that field on the listing and push again.';
        }

        return null;
    }
}
