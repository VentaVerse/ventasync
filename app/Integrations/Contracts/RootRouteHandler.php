<?php

namespace App\Integrations\Contracts;

use Illuminate\Http\Request;

/**
 * Optional sub-contract for integrations that need to claim the root URL
 * ("/") under specific request conditions — typically OAuth callbacks
 * where the marketplace provider only permits a domain-level redirect
 * URI with no path (e.g. Shopee requires the redirect URI to be the bare
 * APP_URL).
 *
 * Each handler inspects the request and returns a Response/RedirectResponse/
 * View/Responsable when it claims it, or null to defer. Core iterates the
 * registered handlers; the first non-null result wins. If every handler
 * defers, core falls back to the canonical home-page response (redirect
 * to /dashboard).
 *
 * Implementations MUST be cheap to call — they run on every hit to /
 * regardless of whether the request actually carries OAuth params.
 * Inspect the request first and return null fast when the signal isn't
 * present.
 */
interface RootRouteHandler
{
    /**
     * @return mixed Response/RedirectResponse/View/Responsable when claiming the request, null to defer.
     */
    public function handleRootRoute(Request $request): mixed;
}
