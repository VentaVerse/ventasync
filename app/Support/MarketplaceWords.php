<?php

namespace App\Support;

final class MarketplaceWords
{
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
            return null;
        }

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
