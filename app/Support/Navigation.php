<?php

namespace App\Support;

use Illuminate\Support\Facades\Route;

/**
 * Single source of truth for the global navigation.
 *
 * Permissions are NOT hand-maintained here. They are read from each route's
 * own `perm:` middleware at runtime, so this file cannot drift out of step
 * with routes/web.php. A previous hand-maintained list in SettingsHubController
 * had to carry a comment warning about exactly that drift.
 */
class Navigation
{
    /**
     * The raw tree, before route-existence and permission filtering.
     * Extension routes (`ext.` prefix) exist only while the extension is
     * enabled, which is a real state, not a hypothetical.
     */
    public static function definition(): array
    {
        return [
            ['label' => 'Dashboard', 'icon' => 'monitor', 'route' => 'dashboard',
             'keywords' => ['home', 'overview', 'summary']],

            ['label' => 'Sales', 'icon' => 'receipt', 'items' => [
                ['label' => 'Orders', 'route' => 'orders.index',
                 'keywords' => ['sale', 'customer', 'invoice', 'marketplace']],
                ['label' => 'Order Payments', 'route' => 'orders.payments_report',
                 'keywords' => ['paid', 'settlement', 'remittance']],
                ['label' => 'Order Statuses', 'route' => 'order_statuses.index',
                 'keywords' => ['workflow', 'pending', 'shipped']],
            ]],

            ['label' => 'Catalogue', 'icon' => 'package', 'items' => [
                ['label' => 'Products', 'route' => 'products.index',
                 'keywords' => ['sku', 'variation', 'stock', 'price']],
                ['label' => 'Categories', 'route' => 'categories.index',
                 'keywords' => ['taxonomy', 'group']],
                ['label' => 'Manufacturers', 'route' => 'manufacturers.index',
                 'keywords' => ['brand', 'supplier']],
                ['label' => 'Reviews', 'route' => 'ext.opencart.reviews.index',
                 'keywords' => ['rating', 'feedback', 'comment']],
            ]],

            ['label' => 'Purchasing', 'icon' => 'truck', 'items' => [
                ['label' => 'Purchase Orders', 'route' => 'ext.purchasing.purchase_orders.index',
                 'keywords' => ['po', 'buy', 'restock', 'inbound']],
                ['label' => 'Vendors', 'route' => 'ext.purchasing.vendors.index',
                 'keywords' => ['supplier', 'seller', 'source']],
            ]],

            ['label' => 'Warehousing', 'icon' => 'archive', 'items' => [
                ['label' => 'Transfers', 'route' => 'ext.warehousing.transfers.index',
                 'keywords' => ['move', 'stock transfer', 'location']],
                ['label' => 'Inventory', 'route' => 'ext.warehousing.inventory.index',
                 'keywords' => ['stock', 'quantity', 'count', 'adjust']],
                ['label' => 'Locations', 'route' => 'ext.warehousing.locations.index',
                 'keywords' => ['warehouse', 'branch', 'store']],
            ]],

            ['label' => 'Reports', 'icon' => 'coins', 'items' => [
                ['label' => 'Orders', 'route' => 'ext.reports.orders',
                 'keywords' => ['revenue', 'sales report']],
                ['label' => 'Products', 'route' => 'ext.reports.products',
                 'keywords' => ['profit', 'margin', 'markup', 'cogs']],
                ['label' => 'Categories', 'route' => 'ext.reports.categories',
                 'keywords' => ['category profit']],
                ['label' => 'Fees', 'route' => 'ext.reports.fees',
                 'keywords' => ['commission', 'charge', 'marketplace fee']],
                ['label' => 'Payouts', 'route' => 'ext.reports.payouts',
                 'keywords' => ['settlement', 'remittance', 'disbursement']],
                ['label' => 'Inventory', 'route' => 'ext.reports.inventory',
                 'keywords' => ['stock value', 'on hand']],
            ]],

            ['label' => 'Petty Cash', 'icon' => 'wallet', 'route' => 'ext.pettycash.index',
             'keywords' => ['expense', 'cash', 'reimbursement', 'float']],

            ['label' => 'Integrations', 'icon' => 'plug', 'route' => 'integrations.index',
             'keywords' => ['shopee', 'lazada', 'tiktok', 'venta', 'opencart', 'channel', 'sync', 'api']],

            ['label' => 'Settings', 'icon' => 'shield', 'route' => 'settings.hub',
             'keywords' => ['config', 'currency', 'user', 'permission', 'extension']],
        ];
    }

    /**
     * Read the pipe-delimited permission from the route's own `perm:`
     * middleware. Returns null for an auth-only route or an unknown one.
     */
    public static function permissionFor(string $routeName): ?string
    {
        $route = Route::getRoutes()->getByName($routeName);

        if (!$route) {
            return null;
        }

        foreach ($route->gatherMiddleware() as $middleware) {
            if (is_string($middleware) && str_starts_with($middleware, 'perm:')) {
                return substr($middleware, 5);
            }
        }

        return null;
    }

    /**
     * Same any-match parsing EnsurePermission uses. Never can(): this app
     * registers no Gates or Policies, so can() is always false.
     */
    public static function allows(string $routeName): bool
    {
        if (!Route::has($routeName)) {
            return false;
        }

        // A nav entry links to a fixed URL with no route-model context, so a
        // route that needs a URI parameter (e.g. products/{id}/vendors)
        // cannot be a valid destination here. route() would throw
        // UrlGenerationException at render time otherwise, taking down every
        // page that includes the sidebar rather than just hiding one link.
        $route = Route::getRoutes()->getByName($routeName);
        if ($route && $route->parameterNames() !== []) {
            return false;
        }

        $permission = self::permissionFor($routeName);

        if ($permission === null) {
            return true;
        }

        $user = auth()->user();

        if (!$user) {
            return false;
        }

        foreach (explode('|', $permission) as $key) {
            if ($user->hasPermission(trim($key))) {
                return true;
            }
        }

        return false;
    }

    /**
     * The tree a view should render: existing routes only, permitted only,
     * and groups with no surviving children removed entirely.
     */
    public static function groups(): array
    {
        $out = [];

        foreach (self::definition() as $entry) {
            if (isset($entry['route'])) {
                if (self::allows($entry['route'])) {
                    $out[] = $entry;
                }
                continue;
            }

            $items = array_values(array_filter(
                $entry['items'],
                fn (array $i) => self::allows($i['route'])
            ));

            if ($items !== []) {
                $out[] = ['label' => $entry['label'], 'icon' => $entry['icon'], 'items' => $items];
            }
        }

        return $out;
    }
}
