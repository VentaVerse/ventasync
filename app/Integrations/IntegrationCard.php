<?php

namespace App\Integrations;

class IntegrationCard
{
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
