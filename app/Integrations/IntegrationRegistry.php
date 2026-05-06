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
        return $this->providersImplementing(\App\Integrations\Contracts\SkuResolver::class);
    }

    /**
     * Providers that implement SkuSyncContributor — used by SkuSyncService
     * when a catalog product's SKU changes and needs to be pushed to every
     * connected marketplace.
     *
     * @return \App\Integrations\Contracts\SkuSyncContributor[]
     */
    public function skuSyncContributors(): array
    {
        return $this->providersImplementing(\App\Integrations\Contracts\SkuSyncContributor::class);
    }

    /**
     * Providers that implement DashboardContributor — used by core's
     * DashboardController to gather per-marketplace stats, sync status,
     * and other widgets without core knowing which marketplaces exist.
     *
     * @return \App\Integrations\Contracts\DashboardContributor[]
     */
    public function dashboardContributors(): array
    {
        return $this->providersImplementing(\App\Integrations\Contracts\DashboardContributor::class);
    }

    /**
     * Providers that implement OrderImagesContributor — used by core's
     * OrderController to resolve marketplace-supplied product images.
     *
     * @return \App\Integrations\Contracts\OrderImagesContributor[]
     */
    public function orderImagesContributors(): array
    {
        return $this->providersImplementing(\App\Integrations\Contracts\OrderImagesContributor::class);
    }

    /**
     * Providers that implement OrderFeesContributor — used by core's
     * OrderController to resolve marketplace-side fees (commission,
     * shipping, transaction tax, etc.) for a catalog order.
     *
     * @return \App\Integrations\Contracts\OrderFeesContributor[]
     */
    public function orderFeesContributors(): array
    {
        return $this->providersImplementing(\App\Integrations\Contracts\OrderFeesContributor::class);
    }

    /**
     * Find the MobileMarketplaceProvider whose platform slug matches the
     * given identifier (e.g. 'lazada', 'shopee'). Returns null if no
     * matching provider is enabled+licensed.
     */
    public function mobileProviderFor(string $platform): ?\App\Integrations\Contracts\MobileMarketplaceProvider
    {
        foreach ($this->providersImplementing(\App\Integrations\Contracts\MobileMarketplaceProvider::class) as $provider) {
            if ($provider->mobilePlatformSlug() === $platform) {
                return $provider;
            }
        }
        return null;
    }

    /**
     * Generic helper: return providers that implement the given interface
     * AND pass enabled+licensed gates. No permission filter — these are
     * system-level contracts, not UI surfaces.
     *
     * @template T
     * @param class-string<T> $contract
     * @return T[]
     */
    protected function providersImplementing(string $contract): array
    {
        $matches = [];

        foreach ($this->providers as $id => $provider) {
            if (!$provider instanceof $contract) {
                continue;
            }
            if (!$this->passesGates($id, null)) {
                continue;
            }
            $matches[] = $provider;
        }

        return $matches;
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
