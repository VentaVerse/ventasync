<?php

namespace App\Integrations;

class AddStoreAction
{
    public function __construct(
        public string $route,
        public string $permission,
        public string $label = 'Add store',
        public array $fields = [],
    ) {
    }
}
