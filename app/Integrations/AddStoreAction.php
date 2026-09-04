<?php

namespace App\Integrations;

/**
 * "Add store" action attached to a multi-store IntegrationCard. It renders
 * in the Add-store modal on the Integrations board: pick this channel,
 * answer the fewest questions, and a store row is created - every other
 * connection field (API token, credentials) is filled on the per-store
 * settings page after creation.
 *
 * A Store Name is always asked (it is how the operator, and the MCP, names
 * this connection). `fields` lists the EXTRA questions this channel needs
 * before a row can exist at all - a base URL, a shop domain - as
 * ['name', 'label', 'type', 'placeholder', 'hint']. Everything else waits
 * for settings.
 */
class AddStoreAction
{
    /**
     * @param list<array{name: string, label: string, type?: string, placeholder?: string, hint?: string}> $fields
     */
    public function __construct(
        public string $route,
        public string $permission,
        public string $label = 'Add store',
        public array $fields = [],
    ) {
    }
}
