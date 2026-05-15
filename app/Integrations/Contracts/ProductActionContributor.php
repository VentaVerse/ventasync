<?php

namespace App\Integrations\Contracts;

/**
 * Optional sub-contract for integrations that want to contribute "related
 * action" buttons to the core product edit page.
 *
 * Core's products/edit view exposes a contributor slot near the page header.
 * Each registered contributor returns zero or more action descriptors; core
 * iterates the registry and renders each as a small action link/button.
 *
 * This is how features that *belong* to a specific extension (e.g. vendor
 * management inside Purchasing, marketplace listing inside Lazada, etc.) can
 * deep-link off the product edit page without core needing to know which
 * extensions are installed.
 *
 * Return shape:
 *   [
 *     [
 *       'label' => string,           // user-visible button text
 *       'url' => string,             // fully-qualified URL (use route() in caller)
 *       'icon' => ?string,           // optional icon slug (e.g. 'truck'); view falls back to no icon
 *       'permission' => ?string,     // optional permission gate; null = visible to anyone who can edit the product
 *     ],
 *     ...
 *   ]
 *
 * Implementations MUST be cheap to call — they run on every product edit
 * render. Do not hit external APIs; static config + a possible DB read
 * scoped to the product is the budget.
 */
interface ProductActionContributor
{
    /**
     * @return array<int, array{label:string, url:string, icon?:string, permission?:string}>
     */
    public function productActions(int $productId): array;
}
