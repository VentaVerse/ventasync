<?php

namespace App\Integrations;

use App\Extensions\ExtensionManager;
use App\Models\User;

/**
 * Central registry every marketplace integration registers itself with at boot.
 *
 * The Integrations page and the unified Orders page query this registry instead
 * of hardcoding marketplace knowledge. New integrations appear automatically by
 * implementing IntegrationProvider and calling IntegrationRegistry::register()
 * from their service provider's boot() method.
 *
 * Visibility filters apply in this order: enabled (DB) -> permission (per-user).
 * "Core" integrations (id starts with "core:") skip the enable gate and are
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
     * Layout banner contributors — token expiry warnings, credentials
     * prompts, etc. Layout iterates these on every page render.
     *
     * @return \App\Integrations\Contracts\LayoutBannerContributor[]
     */
    public function layoutBannerContributors(): array
    {
        return $this->providersImplementing(\App\Integrations\Contracts\LayoutBannerContributor::class);
    }

    /**
     * Product inventory detail contributors — used by the product edit
     * form to show supplemental inventory breakdowns (warehousing, etc.).
     *
     * @return \App\Integrations\Contracts\ProductInventoryDetailContributor[]
     */
    public function productInventoryDetailContributors(): array
    {
        return $this->providersImplementing(\App\Integrations\Contracts\ProductInventoryDetailContributor::class);
    }

    /**
     * Walk the root-route handlers and return the first non-null response
     * from any handler that claims the request, or null when no handler
     * wants it. Core's HomeController uses this to delegate the bare "/"
     * URL to integrations that need a domain-level OAuth callback
     * (e.g. Shopee).
     *
     * Returns mixed because handlers may legitimately return a Response,
     * a RedirectResponse, a View, or any Responsable — Laravel's router
     * converts whichever it gets to a final HTTP response.
     */
    public function resolveRootRouteResponse(\Illuminate\Http\Request $request): mixed
    {
        foreach ($this->providersImplementing(\App\Integrations\Contracts\RootRouteHandler::class) as $handler) {
            $response = $handler->handleRootRoute($request);
            if ($response !== null) {
                return $response;
            }
        }
        return null;
    }

    /**
     * Aggregate all available marketplace-source options for the unified
     * orders filter dropdown. Single-account marketplaces contribute one
     * option; multi-store integrations contribute one option per enabled
     * store. Order reflects extension registration order.
     *
     * @return array<int, array{value: string, label: string, badge_class?: string}>
     */
    public function availableMarketplaceSourceOptions(): array
    {
        $options = [];
        foreach ($this->providersImplementing(\App\Integrations\Contracts\MarketplaceSourceOptionsProvider::class) as $provider) {
            foreach ($provider->availableSourceOptions() as $opt) {
                if (is_array($opt) && isset($opt['value'], $opt['label'])) {
                    $options[] = $opt;
                }
            }
        }
        return $options;
    }

    /**
     * Aggregate "related action" buttons that extensions want to contribute
     * to the core product edit page (e.g. Purchasing's "Manage Vendors").
     * Order reflects extension registration order. Per-action permission
     * gating is handled by the view, not here — this method returns the
     * raw list and the caller filters by current user.
     *
     * @return array<int, array{label:string, url:string, icon?:string, permission?:string}>
     */
    public function productActions(int $productId): array
    {
        $actions = [];
        foreach ($this->providersImplementing(\App\Integrations\Contracts\ProductActionContributor::class) as $provider) {
            foreach ($provider->productActions($productId) as $action) {
                if (is_array($action) && isset($action['label'], $action['url'])) {
                    $actions[] = $action;
                }
            }
        }
        return $actions;
    }

    /**
     * Walk the marketplace-source resolvers and return the first non-null
     * label for the given source string, or null if no integration claims
     * it.
     */
    public function resolveMarketplaceSourceLabel(string $source): ?string
    {
        foreach ($this->providersImplementing(\App\Integrations\Contracts\MarketplaceSourceLabelResolver::class) as $resolver) {
            $label = $resolver->resolveSourceLabel($source);
            if ($label !== null) {
                return $label;
            }
        }
        return null;
    }

    /**
     * Walk the order-ref renderers and return the first non-null render
     * spec for the given (marketplace_source, marketplace_order_id). The
     * render spec is `['display' => '#1001', 'url' => '...']` so views can
     * render a friendly label with a deep link back to the marketplace.
     *
     * @return array{display:string,url:?string}|null
     */
    public function resolveOrderRef(string $marketplaceSource, string $marketplaceOrderId): ?array
    {
        if ($marketplaceSource === '' || $marketplaceOrderId === '') {
            return null;
        }
        foreach ($this->providersImplementing(\App\Integrations\Contracts\MarketplaceOrderRefRenderer::class) as $renderer) {
            $ref = $renderer->renderOrderRef($marketplaceSource, $marketplaceOrderId);
            if ($ref !== null) {
                return $ref;
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
     * Enabled gate. Permission is checked per surface (card vs tab) by the
     * visible* methods above so different permissions can apply to each.
     */
    protected function passesGates(string $id, ?User $user): bool
    {
        // Core integrations bypass extension gates; only permission applies.
        if (str_starts_with($id, 'core:')) {
            return true;
        }

        // Compound ids (e.g. "venta:1" for store 1) are scoped per-instance but
        // still gated against the underlying extension id ("venta").
        $baseId = str_contains($id, ':') ? strstr($id, ':', true) : $id;

        return app(ExtensionManager::class)->isEnabled($baseId);
    }
}
