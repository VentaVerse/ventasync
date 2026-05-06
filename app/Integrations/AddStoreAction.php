<?php

namespace App\Integrations;

/**
 * "Add New Store" action attached to a multi-store IntegrationCard. Renders
 * as a button on the module page that opens a modal asking for Store Name +
 * Base URL only — every other connection field (API token, etc.) is filled
 * in on the per-store settings page after creation.
 */
class AddStoreAction
{
    public function __construct(
        public string $route,
        public string $permission,
        public string $label = 'Add New Store',
    ) {
    }
}
