<?php

namespace App\Integrations;

interface IntegrationProvider
{
    /**
     * Stable id matching extension.json id (or a core slug for non-extension marketplaces).
     * For multi-store marketplaces, this is the parent extension id; the per-store
     * cards and tabs use compound ids like "venta:1", "venta:2" inside the OrderTab and
     * IntegrationCard `id` fields themselves.
     */
    public function integrationId(): string;

    /**
     * Returns one card per discoverable surface. Single-store marketplaces
     * return a one-element array; multi-store marketplaces return one card
     * per store. Empty array means no card to show (e.g. headless).
     *
     * @return IntegrationCard[]
     */
    public function integrationCards(): array;

    /**
     * Returns one tab per fulfillable surface. Single-store marketplaces
     * return a one-element array; multi-store marketplaces return one tab
     * per store. Empty array means no orders surface.
     *
     * @return OrderTab[]
     */
    public function orderTabs(): array;
}
