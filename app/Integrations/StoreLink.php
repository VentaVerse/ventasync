<?php

namespace App\Integrations;

/**
 * One store entry on a multi-store module's Integration card. Rendered as a
 * sub-row inside the parent card (not as its own card) so the visual model
 * stays "one card per module" — stores are child entities, not peers.
 */
class StoreLink
{
    /**
     * @param MenuItem[] $menu Per-store action links (e.g. Product Groups, Orders).
     * @param string $status 'active' (configured + enabled) or 'setup_pending'
     *                       (created but not yet enabled). The UI shows a
     *                       badge so freshly-created stores stay visible
     *                       until the operator finishes configuring them.
     */
    public function __construct(
        public string $id,
        public string $label,
        public array $menu = [],
        public string $status = 'active',
    ) {
    }
}
