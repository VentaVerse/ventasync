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
 * gated only by permission. Multi-store integrations may use compound ids
 * like "venta:1" — the gate check extracts the base extension id ("venta")
 * before consulting the extensions table.
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
            if (!$this->passesGates($id, $user)) {
                continue;
            }

            foreach ($provider->integrationCards() as $card) {
                if (!$card instanceof IntegrationCard) {
                    continue;
                }
                if ($card->permission && (!$user || !$user->hasPermission($card->permission))) {
                    continue;
                }
                $cards[] = $card;
            }
        }

        return $cards;
    }

    /**
     * Providers that implement SkuResolver, regardless of user — used by core
     * services (OrderStockService, etc.) that need to translate a marketplace
     * SKU into a catalog product. Filters by enabled+licensed only; permission
     * checks don't apply because this is system-level reconciliation, not UI.
     *
     * @return \App\Integrations\Contracts\SkuResolver[]
     */
    public function skuResolvers(): array
    {
        $resolvers = [];

        foreach ($this->providers as $id => $provider) {
            if (!$provider instanceof \App\Integrations\Contracts\SkuResolver) {
                continue;
            }
            if (!$this->passesGates($id, null)) {
                continue;
            }
            $resolvers[] = $provider;
        }

        return $resolvers;
    }

    /**
     * Order tabs visible to the given user, flattened across providers.
     * Multi-store providers contribute one tab per store; single-store
     * providers contribute one tab.
     *
     * @return OrderTab[]
     */
    public function visibleOrderTabs(?User $user): array
    {
        $tabs = [];

        foreach ($this->providers as $id => $provider) {
            if (!$this->passesGates($id, $user)) {
                continue;
            }

            foreach ($provider->orderTabs() as $tab) {
                if (!$tab instanceof OrderTab) {
                    continue;
                }
                if ($tab->permission && (!$user || !$user->hasPermission($tab->permission))) {
                    continue;
                }
                $tabs[] = $tab;
            }
        }

        return $tabs;
    }

    /**
     * Enabled + license gates. Permission is checked per surface (card vs tab)
     * by the visible* methods above so different permissions can apply to each.
     */
    protected function passesGates(string $id, ?User $user): bool
    {
        // Core integrations bypass extension/license gates; only permission applies.
        if (str_starts_with($id, 'core:')) {
            return true;
        }

        // Compound ids (e.g. "venta:1" for store 1) are scoped per-instance but
        // still gated against the underlying extension id ("venta").
        $baseId = str_contains($id, ':') ? strstr($id, ':', true) : $id;

        $extMgr = app(ExtensionManager::class);

        if (!$extMgr->isEnabled($baseId)) {
            return false;
        }

        $manifest = $extMgr->getManifest($baseId);
        $licenseRequired = !empty($manifest['license_required']);

        if ($licenseRequired) {
            $license = app(LicenseService::class);
            if (!$license->isModuleLicensed($baseId)) {
                return false;
            }
        }

        return true;
    }
}
