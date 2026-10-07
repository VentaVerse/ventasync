<?php

namespace App\Integrations;

class StoreLink
{
    public function __construct(
        public string $id,
        public string $label,
        public array $menu = [],
        public string $status = 'active',
    ) {
    }
}
