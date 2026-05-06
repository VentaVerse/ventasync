<?php

namespace App\Integrations;

class IntegrationCard
{
    /**
     * @param MenuItem[] $menu Module-level menu items (Settings, Reviews, etc.)
     * @param StoreLink[] $stores Optional list of configured stores; only multi-store
     *                            modules populate this. Rendered inside the card as
     *                            a separate "Stores" section so the page stays
     *                            "one card per module" visually.
     */
    public function __construct(
        public string $id,
        public string $name,
        public string $tagline,
        public string $icon,
        public string $accent,
        public string $permission,
        public array $menu = [],
        public array $stores = [],
        public ?AddStoreAction $addStore = null,
    ) {
    }
}
