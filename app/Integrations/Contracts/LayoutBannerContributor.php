<?php

namespace App\Integrations\Contracts;

/**
 * Optional sub-contract for integrations that surface persistent banners in
 * the app shell (header, sidebar, or topbar). Token-expiry warnings and
 * "credentials not set" prompts are the canonical use cases.
 *
 * Each contributor returns 0..N banner descriptors. The layout iterates
 * every contributor on every page render — keep the implementations cheap
 * (single primary-key lookup against the settings table is the usual shape).
 *
 * Banner shape:
 *   [
 *     'label'    => string,            // human text shown to the operator
 *     'severity' => 'warning' | 'error' | 'info',
 *     'href'     => string|null,       // optional link the banner points at
 *   ]
 *
 * @return array<int, array{label: string, severity: string, href: ?string}>
 */
interface LayoutBannerContributor
{
    public function layoutBanners(): array;
}
