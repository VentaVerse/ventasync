<?php

namespace App\Integrations;

class MenuItem
{
    public function __construct(
        public string $label,
        public string $routeName,
        public ?string $permission = null,
        public ?string $icon = null,
        public array $routeParams = [],
    ) {
    }
}
