<?php

namespace App\Services;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/**
 * Every permissionable area in the application, derived from the routes.
 *
 * THE POINT IS THAT NOBODY MAINTAINS THIS. OpenCart builds its permission
 * list by walking admin/controller/**\/*.php and turning each file into a
 * key; add a controller and it appears on the user-group screen by itself.
 * That property is why the model is worth copying: our previous list came
 * from hand-written extension.json manifests plus config/permissions.php,
 * and it had drifted - four keys for a removed messages feature that no sync
 * could reach, because syncing only ever inserted.
 *
 * Here the route table is the filesystem walk. One entry per CONTROLLER, as
 * in OpenCart, because a controller is one screen's worth of behaviour: list,
 * create, edit and delete belong together and are separated by TIER, not by
 * key.
 *
 *   App\Http\Controllers\Catalog\ProductController        -> catalog/product
 *   Extensions\lazada\Controllers\LazadaProductController -> lazada/product
 *
 * Two tiers, matching OpenCart's access/modify and this application's
 * existing view_/manage_ convention, so User::hasPermission and its
 * manage-implies-view expansion keep working untouched:
 *
 *   view_catalog/product      GET routes
 *   manage_catalog/product    everything else
 *
 * A controller with no write route publishes no manage tier at all, so the
 * editor never offers a level that cannot exist.
 */
class PermissionCatalogue
{
    /**
     * Controllers that are never permissioned.
     *
     * OpenCart carries the same idea and for the same reason: a login page
     * you cannot reach until you have permission to log in is a locked door
     * with the key inside. Everything here is either authentication itself,
     * or a surface every signed-in person must be able to reach.
     */
    private const NEVER_GATED = [
        'App\Http\Controllers\Auth\\',
        'Laravel\Sanctum\\',
        'App\Http\Controllers\DashboardController',
        'App\Http\Controllers\HomeController',
        // Search and the command palette return only what the caller may
        // already see - they filter per result rather than at the door.
        'App\Http\Controllers\SearchController',
        'App\Http\Controllers\PaletteController',
        /* GATED PER ITEM, SO NOT GATED AT THE DOOR.
         *
         * Each of these already decides for itself, and more finely than a
         * single key could. The channels hub renders only the cards the
         * viewer's own permissions allow. ChannelWorkspaceController::show
         * re-asks the card question. AutomationsController re-checks
         * manage_{integration} per row inside a batch, specifically so no
         * authorised row smuggles an unauthorised one past.
         * OrderPrintController::resolve requires view_{channel}_orders for
         * the channel actually being printed.
         *
         * Gating the door as well would deny the whole page to someone
         * entitled to part of it - a Shopee packer refused the fulfilment hub
         * because the hub is not "a Shopee thing" - which is worse than what
         * they do now and no more secure.
         *
         * It also breaks their own 404s: OrderPrintController aborts 404 on
         * an unknown channel, and a door check fires before that, turning
         * "no such channel" into "you may not". Twelve tests said so.
         *
         * ANY CONTROLLER ADDED HERE MUST DO ITS OWN CHECK. That is the whole
         * bargain, and it is the one thing this list cannot verify for you.
         */
        'App\\Http\\Controllers\\Integrations\\',
        'App\\Http\\Controllers\\AutomationsController',
        'App\\Http\\Controllers\\Fulfilment\\',
        /* THE MOBILE AND PARTNER API IS NOT PART OF THIS MODEL.
         *
         * Those routes live in the `api` middleware group, authenticate with
         * a Sanctum token rather than a session, and carry their own checks -
         * MarketplaceOrderController builds `manage_{platform}_orders` per
         * request. They belong to a token's scope, not to a person's user
         * group, and listing them on the group editor would put twelve rows
         * in front of an operator that no operator can act on. */
        'App\\Http\\Controllers\\Api\\',
    ];

    /** Extension API controllers, same reasoning as the core Api namespace. */
    private const NEVER_GATED_CONTAINS = [
        '\\Controllers\\Api\\',
    ];

    /** @var array<string, array>|null */
    private ?array $entries = null;

    /** @var array<string, array>|null */
    private ?array $legacy = null;

    /**
     * @return array<string, array{key:string,controller:string,area:string,name:string,
     *                             routes:int,writes:int,tiers:array<int,string>}>
     */
    public function all(): array
    {
        if ($this->entries !== null) {
            return $this->entries;
        }

        $out = [];

        foreach (Route::getRoutes() as $route) {
            $action = $route->getActionName();

            if (! str_contains($action, '@')) {
                continue; // closure route: nothing to key on
            }

            [$class] = explode('@', $action);

            if ($this->neverGated($class)) {
                continue;
            }

            $key = $this->keyFor($class);
            $isWrite = (bool) array_diff($route->methods(), ['GET', 'HEAD']);

            $entry = $out[$key] ?? [
                'key' => $key,
                'controller' => $class,
                'area' => Str::before($key, '/'),
                'name' => Str::after($key, '/'),
                'routes' => 0,
                'writes' => 0,
            ];

            $entry['routes']++;
            $entry['writes'] += $isWrite ? 1 : 0;
            $out[$key] = $entry;
        }

        foreach ($out as $key => $entry) {
            // A read-only area offers no manage tier. The editor reads this
            // rather than assuming both tiers exist for everything.
            $out[$key]['tiers'] = $entry['writes'] > 0 ? ['view', 'manage'] : ['view'];
        }

        ksort($out);

        return $this->entries = $out;
    }

    /**
     * The OLD keys that used to gate a given controller, per tier.
     *
     * A TRANSITION SHIM, AND IT HAS TO EXIST. The migration translates the
     * grants that were in the database when it ran; it cannot reach a group
     * created afterwards, or one built in a test, or one restored from a
     * backup taken before the changeover. Without this, every such group is
     * silently locked out of everything - 586 tests said so on the first run,
     * which is exactly the shape a deployment would have taken.
     *
     * Derived from the same route table as everything else: for this
     * controller, which `perm:` keys did its routes accept? A hand-written
     * compatibility table would be one more thing to drift.
     *
     * REMOVING IT is a deliberate later step: once no group holds an
     * old-scheme key, this returns nothing for every controller and can go.
     * PermissionsSyncCatalogue --prune-legacy is what makes that true.
     *
     * @return array{view: array<int,string>, manage: array<int,string>}
     */
    public function legacyKeysFor(string $controller): array
    {
        if ($this->legacy === null) {
            $this->legacy = $this->buildLegacyMap();
        }

        return $this->legacy[$controller] ?? ['view' => [], 'manage' => []];
    }

    /** @return array<string, array{view: array<int,string>, manage: array<int,string>}> */
    private function buildLegacyMap(): array
    {
        $map = [];

        foreach (Route::getRoutes() as $route) {
            $action = $route->getActionName();

            if (! str_contains($action, '@')) {
                continue;
            }

            [$class] = explode('@', $action);

            foreach ($route->gatherMiddleware() as $middleware) {
                if (! is_string($middleware) || ! str_starts_with($middleware, 'perm:')) {
                    continue;
                }

                foreach (preg_split('/[,|]/', substr($middleware, 5)) as $old) {
                    $old = trim($old);

                    if ($old === '') {
                        continue;
                    }

                    /* A manage_ key satisfies both tiers - it always did,
                     * through hasPermission's manage-implies-view expansion.
                     * A view_ key satisfies only view, so the shim cannot
                     * widen anyone's access on the way through. */
                    $map[$class]['view'][$old] = true;

                    if (str_starts_with($old, 'manage_')) {
                        $map[$class]['manage'][$old] = true;
                    }
                }
            }
        }

        foreach ($map as $class => $tiers) {
            $map[$class] = [
                'view' => array_keys($tiers['view'] ?? []),
                'manage' => array_keys($tiers['manage'] ?? []),
            ];
        }

        return $map;
    }

    /**
     * How an area group is titled: "Lazada", "Catalogue", "Settings".
     *
     * An extension is asked for its own name, so core never holds a list of
     * marketplaces - the same bargain Permission::label already makes. Core
     * areas fall back to a lang entry and then to the key itself, humanised.
     * NOTHING IS EVER HIDDEN FOR WANT OF A LABEL: an area with no wording
     * anywhere still renders, wearing its key. Coverage does not wait on
     * somebody writing prose.
     */
    public function areaLabel(string $area): string
    {
        $manifest = app(\App\Extensions\ExtensionManager::class)->getManifest($area);

        if ($manifest && ! empty($manifest['name'])) {
            return (string) $manifest['name'];
        }

        $key = 'permissions.areas.' . $area;
        $translated = __($key);

        if ($translated !== $key) {
            return (string) $translated;
        }

        return Str::headline($area);
    }

    /**
     * How one row is titled: "Products", "Product Groups", "Order Statuses".
     *
     * The leaf of the key, humanised, unless a lang entry says better. The
     * bare controller of an extension is its settings screen rather than an
     * overview - Lazada's holds the credentials, the sandbox switch and the
     * API explorer - so it is named for what it is.
     */
    public function rowLabel(string $key): string
    {
        $lang = 'permissions.rows.' . $key;
        $translated = __($lang);

        if ($translated !== $lang) {
            return (string) $translated;
        }

        $leaf = Str::after($key, '/');

        return $leaf === 'overview' ? 'Settings & API' : Str::headline($leaf);
    }

    /**
     * The areas, grouped and labelled, ready to render.
     *
     * @return array<int, array{area:string,label:string,rows:array<int,array>}>
     */
    public function grouped(): array
    {
        $areas = [];

        foreach ($this->all() as $entry) {
            $areas[$entry['area']][] = $entry + ['label' => $this->rowLabel($entry['key'])];
        }

        $out = [];

        foreach ($areas as $area => $rows) {
            usort($rows, fn ($a, $b) => strcmp($a['label'], $b['label']));

            $out[] = [
                'area' => $area,
                'label' => $this->areaLabel($area),
                'rows' => $rows,
            ];
        }

        usort($out, fn ($a, $b) => strcmp($a['label'], $b['label']));

        return $out;
    }

    /** Every permission key this catalogue implies, both tiers. */
    public function keys(): array
    {
        $keys = [];

        foreach ($this->all() as $entry) {
            foreach ($entry['tiers'] as $tier) {
                $keys[] = $tier . '_' . $entry['key'];
            }
        }

        return $keys;
    }

    /**
     * The key a controller class answers to, or null when it is never gated.
     *
     * Used by the middleware, so a route needs no per-route permission
     * argument: what it is gated on follows from what it is.
     */
    public function keyForController(string $class): ?string
    {
        return $this->neverGated($class) ? null : $this->keyFor($class);
    }

    public function neverGated(string $class): bool
    {
        foreach (self::NEVER_GATED as $prefix) {
            if ($class === $prefix || str_starts_with($class, $prefix)) {
                return true;
            }
        }

        foreach (self::NEVER_GATED_CONTAINS as $fragment) {
            if (str_contains($class, $fragment)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Turn a controller class into an OpenCart-shaped `area/name` key.
     *
     * Extensions are keyed on the extension id, so LazadaProductController
     * becomes lazada/product rather than repeating "lazada" twice. A
     * channel's bare controller becomes `<id>/overview`, which is what the
     * screen it serves actually is.
     */
    private function keyFor(string $class): string
    {
        // Extensions\lazada\Controllers\Foo  ->  lazada + Foo
        if (preg_match('#^Extensions\\\\([A-Za-z0-9_]+)\\\\Controllers\\\\(.+)$#', $class, $m)) {
            $area = Str::snake($m[1]);
            $leaf = str_replace('\\', '', $m[2]);
            $leaf = preg_replace('/Controller$/', '', $leaf);
            // Drop the extension's own name off the front of its controllers.
            $leaf = preg_replace('/^' . preg_quote($m[1], '/') . '/i', '', $leaf);

            return $area . '/' . (Str::snake($leaf) ?: 'overview');
        }

        // App\Http\Controllers\Catalog\ProductController -> catalog/product
        $path = preg_replace('#^App\\\\Http\\\\Controllers\\\\#', '', $class);
        $parts = explode('\\', $path);
        $leaf = preg_replace('/Controller$/', '', array_pop($parts));

        $area = $parts === [] ? 'general' : Str::snake(implode('', $parts));

        return $area . '/' . (Str::snake($leaf) ?: 'overview');
    }
}
