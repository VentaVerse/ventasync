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
         * viewer's own permissions allow. (The channel dashboards used to
         * shelter here too, behind core's /{channel} wildcard - which is
         * exactly how they fell off the permission editor. Each now derives
         * its own {channel}/dashboard key from its extension's route.) AutomationsController re-checks
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
    ];

    /**
     * Areas nobody reaches through the sidebar.
     *
     * The mobile app and API tokens sign in against these; a person never
     * clicks one. They are real permissions and they stay grantable - hiding
     * them would be a security change dressed up as tidying - but they are
     * answering a different question from the rest of the page, so they sort
     * last and open closed.
     */
    private const MACHINE_AREAS = ['api', 'api_v1'];

    /**
     * The handful of keys that hand over more than their own screen.
     *
     * The route table can count a controller's actions. It cannot know that
     * one of settings/user_group's six is "grant yourself anything", while
     * all eight of settings/currency's are "rename the peso". That is a
     * judgement, so it is written down rather than derived, and it is kept
     * deliberately short: a warning on everything warns about nothing.
     *
     * Every 'api_explorer' key joins them by rule, not by hand - the
     * explorer sends requests to the marketplace AS THE SHOP, outside the
     * ERP's own record keeping.
     *
     * NO SPECIAL VOCABULARY REACHES THE SCREEN. An earlier version tagged
     * these rows "Elevated", and the owner's reaction was the correct review:
     * he has run dozens of platforms and never met the word. The row's own
     * description carries one plain sentence instead, and the bulk-action
     * confirmations name the rows it covers.
     */
    private const WARNED_KEYS = [
        'settings/user_group',
        'settings/user',
        'settings/api_client',
        'settings/extension',
    ];


    /** @var array<string, array>|null */
    private ?array $entries = null;

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
     * How an area group is titled: "Lazada", "Catalog", "Settings".
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
     * The leaf of the key, humanised, unless a lang entry says better.
     */
    public function rowLabel(string $key): string
    {
        $lang = 'permissions.rows.' . $key;
        $translated = __($lang);

        if ($translated !== $lang) {
            return (string) $translated;
        }

        return Str::headline(Str::after($key, '/'));
    }

    /**
     * The areas, grouped and labelled, ready to render.
     *
     * @return array<int, array{area:string,label:string,rows:array<int,array>}>
     */
    /** Machine-facing areas answer to a token, not to a person. */
    public function isMachineArea(string $area): bool
    {
        return in_array($area, self::MACHINE_AREAS, true);
    }

    /**
     * One plain sentence for a row that hands over more than its screen,
     * or the empty string for the ordinary ones.
     *
     * A lang entry (permissions.warnings.{key}) says it best; the
     * api_explorer rule falls back to its own wording so a new channel's
     * explorer is never silently unexplained.
     */
    public function warningFor(string $key): string
    {
        $warned = in_array($key, self::WARNED_KEYS, true)
            || Str::endsWith($key, '/api_explorer');

        if (! $warned) {
            return '';
        }

        $lang = 'permissions.warnings.' . $key;
        $translated = __($lang);

        if ($translated !== $lang) {
            return (string) $translated;
        }

        return Str::endsWith($key, '/api_explorer')
            ? 'sends requests to the marketplace as your shop'
            : 'can widen what others may do';
    }

    /**
     * The areas, grouped and labelled, ready to render.
     *
     * @return array<int, array{area:string,label:string,machine:bool,note:?string,rows:array<int,array>}>
     */
    public function grouped(): array
    {
        $areas = [];

        foreach ($this->all() as $entry) {
            $areas[$entry['area']][] = $entry + [
                'label' => $this->rowLabel($entry['key']),
                'warning' => $this->warningFor($entry['key']),
            ];
        }

        $out = [];

        foreach ($areas as $area => $rows) {
            usort($rows, fn ($a, $b) => strcmp($a['label'], $b['label']));

            $note = __('permissions.notes.' . $area);

            $out[] = [
                'area' => $area,
                'label' => $this->areaLabel($area),
                'machine' => $this->isMachineArea($area),
                'note' => $note === 'permissions.notes.' . $area ? null : (string) $note,
                'rows' => $rows,
            ];
        }

        // Machine areas last, then alphabetical. A person scanning for their
        // own work should not have to read past the mobile app to find it.
        usort($out, fn ($a, $b) => [$a['machine'], $a['label']] <=> [$b['machine'], $b['label']]);

        return $out;
    }

    /** Every permission key this catalog implies, both tiers. */
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
