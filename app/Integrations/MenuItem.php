<?php

namespace App\Integrations;

class MenuItem
{
    /**
     * @param string|null $group Workspace sidebar group: 'Sell', 'Catalog'
     *                           or 'Channel'. Null means the item predates
     *                           channel workspaces and falls under 'Channel'.
     *                           Added last with a default so no existing call
     *                           site needs to change.
     * @param array $children Sections INSIDE the destination page, for the
     *                        channel menubar's dropdown: each entry is
     *                        ['label' => ..., 'section' => <the section's
     *                        tab-panel id>]. The link lands on the page with
     *                        #<section> in the URL and the page's own tab
     *                        script opens that tab, so these never invent a
     *                        destination the page does not already have.
     */
    public function __construct(
        public string $label,
        public string $routeName,
        public ?string $permission = null,
        public ?string $icon = null,
        public array $routeParams = [],
        public ?string $group = null,
        public array $children = [],
    ) {
    }
}
