<?php

namespace App\Integrations;

interface IntegrationProvider
{
    /**
     * Stable id matching extension.json id (or a core slug for non-extension marketplaces).
     */
    public function integrationId(): string;

    /**
     * Returns null if this integration has no card to show (e.g. headless).
     */
    public function integrationCard(): ?IntegrationCard;

    /**
     * Returns null if this integration has no orders surface.
     */
    public function orderTab(): ?OrderTab;
}
