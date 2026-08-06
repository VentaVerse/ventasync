<?php

namespace App\Integrations;

class MenuItem
{
    /**
     * @param string|null $group Workspace sidebar group: 'Sell', 'Catalogue'
     *                           or 'Channel'. Null means the item predates
     *                           channel workspaces and falls under 'Channel'.
     *                           Added last with a default so no existing call
     *                           site needs to change.
     */
    public function __construct(
        public string $label,
        public string $routeName,
        public ?string $permission = null,
        public ?string $icon = null,
        public array $routeParams = [],
        public ?string $group = null,
    ) {
    }
}
