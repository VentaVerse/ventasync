<?php

namespace App\Integrations;

use App\Extensions\ExtensionManager;
use App\Models\User;
use App\Services\LicenseService;

/**
 * Central registry every marketplace integration registers itself with at boot.
 *
 * The Integrations page and the unified Orders page query this registry instead
 * of hardcoding marketplace knowledge. New integrations appear automatically by
 * implementing IntegrationProvider and calling IntegrationRegistry::register()
 * from their service provider's boot() method.
 *
 * Visibility filters apply in this order: enabled (DB) -> licensed (when
 * license_required) -> permission (per-user). Extension-based integrations
 * are gated against the extensions table and the license server; "core"
 * integrations (id starts with "core:") skip enable/license gating and are
 * gated only by permission.
 */
class IntegrationRegistry
{
    /** @var array<string, IntegrationProvider> */
    protected array $providers = [];

    public function register(IntegrationProvider $provider): void
    {
        $this->providers[$provider->integrationId()] = $provider;
    }

    /** @return array<string, IntegrationProvider> */
    public function all(): array
    {
        return $this->providers;
    }

    /**
     * Cards visible to the given user, after enabled + license + permission gates.
     *
     * @return IntegrationCard[]
     */
    public function visibleCards(?User $user): array
    {
        $cards = [];

        foreach ($this->providers as $id => $provider) {
            if (!$this->passesGates($id, $provider, $user)) {
                continue;
            }

            $card = $provider->integrationCard();
            if ($card === null) {
                continue;
            }

            if ($card->permission && (!$user || !$user->hasPermission($card->permission))) {
                continue;
            }

            $cards[] = $card;
        }

        return $cards;
    }

    /**
     * Providers that implement SummaryProvider, are visible to the user,
     * and pass enabled+licensed gates. Used by the Orders summary page
     * to gather per-marketplace daily counts and feeds.
     *
     * Returned as [integrationId => provider] so callers can pair the
     * summary data with the matching IntegrationCard for display.
     *
     * @return array<string, SummaryProvider>
     */
    public function visibleSummaryProviders(?User $user): array
    {
        $providers = [];

        foreach ($this->providers as $id => $provider) {
            if (!$provider instanceof SummaryProvider) {
                continue;
            }

            if (!$this->passesGates($id, $provider, $user)) {
                continue;
            }

            $card = $provider->integrationCard();
            if ($card === null) {
                continue;
            }

            if ($card->permission && (!$user || !$user->hasPermission($card->permission))) {
                continue;
            }

            $providers[$id] = $provider;
        }

        return $providers;
    }

    /**
     * Order tabs visible to the given user, after the same gates.
     *
     * @return OrderTab[]
     */
    public function visibleOrderTabs(?User $user): array
    {
        $tabs = [];

        foreach ($this->providers as $id => $provider) {
            if (!$this->passesGates($id, $provider, $user)) {
                continue;
            }

            $tab = $provider->orderTab();
            if ($tab === null) {
                continue;
            }

            if ($tab->permission && (!$user || !$user->hasPermission($tab->permission))) {
                continue;
            }

            $tabs[] = $tab;
        }

        return $tabs;
    }

    /**
     * Enabled + license gates. Permission is checked per surface (card vs tab)
     * by the visible* methods above so different permissions can apply to each.
     */
    protected function passesGates(string $id, IntegrationProvider $provider, ?User $user): bool
    {
        // Core integrations bypass extension/license gates; only permission applies.
        if (str_starts_with($id, 'core:')) {
            return true;
        }

        $extMgr = app(ExtensionManager::class);

        if (!$extMgr->isEnabled($id)) {
            return false;
        }

        $manifest = $extMgr->getManifest($id);
        $licenseRequired = !empty($manifest['license_required']);

        if ($licenseRequired) {
            $license = app(LicenseService::class);
            if (!$license->isModuleLicensed($id)) {
                return false;
            }
        }

        return true;
    }
}
