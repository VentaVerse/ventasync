<?php

namespace App\Extensions;

use App\Models\Extension;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

class ExtensionManager
{
    /** Base path for extension directories */
    protected string $extensionsPath;

    /** Cached manifests keyed by extension id */
    protected ?array $manifests = null;

    /** Cached array of enabled extension ids */
    protected ?array $enabledCache = null;

    public function __construct()
    {
        $this->extensionsPath = base_path('extensions');
    }

    /**
     * Scan extension directories for extension.json, parse JSON, cache.
     * Returns id => manifest map.
     */
    public function getManifests(): array
    {
        if ($this->manifests !== null) {
            return $this->manifests;
        }

        $this->manifests = [];

        if (!File::isDirectory($this->extensionsPath)) {
            return $this->manifests;
        }

        $directories = File::directories($this->extensionsPath);

        foreach ($directories as $dir) {
            $manifestFile = $dir . '/extension.json';

            if (!File::exists($manifestFile)) {
                continue;
            }

            $json = File::get($manifestFile);
            $manifest = json_decode($json, true);

            if (!is_array($manifest) || empty($manifest['id'])) {
                continue;
            }

            $this->manifests[$manifest['id']] = $manifest;
        }

        return $this->manifests;
    }

    /**
     * Get a single manifest by extension id.
     */
    public function getManifest(string $id): ?array
    {
        $manifests = $this->getManifests();

        return $manifests[$id] ?? null;
    }

    /**
     * Query Extension model where enabled=true, pluck ids.
     * Uses Schema::hasTable check for safety. Caches result.
     */
    public function getEnabledIds(): array
    {
        if ($this->enabledCache !== null) {
            return $this->enabledCache;
        }

        // Tolerate DB-unreachable state (fresh install before migrations run).
        // Schema::hasTable() itself requires a working DB connection — if
        // .env credentials are wrong or the database doesn't exist yet, it
        // throws. In that case, treat as "no extensions enabled" so the
        // login or error page can still render.
        try {
            if (!Schema::hasTable('extensions')) {
                $this->enabledCache = [];
                return $this->enabledCache;
            }

            $this->enabledCache = Extension::where('enabled', true)
                ->pluck('id')
                ->all();
        } catch (\Throwable $e) {
            $this->enabledCache = [];
        }

        return $this->enabledCache;
    }

    /**
     * Check if an extension id is in the enabled list.
     */
    public function isEnabled(string $id): bool
    {
        return in_array($id, $this->getEnabledIds(), true);
    }

    /**
     * Merge disk manifests with DB records.
     * Returns array of arrays with keys: id, name, version, description,
     * author, license_required, enabled, installed, license_key.
     */
    public function all(): array
    {
        $manifests = $this->getManifests();

        // Load all DB records keyed by id. Tolerate DB-unreachable state
        // (same reason as getEnabledIds — Schema::hasTable requires a working
        // connection).
        $dbRecords = [];
        try {
            if (Schema::hasTable('extensions')) {
                $dbRecords = Extension::all()->keyBy('id');
            }
        } catch (\Throwable $e) {
            $dbRecords = [];
        }

        $result = [];

        foreach ($manifests as $id => $manifest) {
            $dbRecord = $dbRecords->get($id);

            $result[] = [
                'id'               => $id,
                'name'             => $manifest['name'] ?? $id,
                'version'          => $manifest['version'] ?? '1.0.0',
                'description'      => $manifest['description'] ?? '',
                'author'           => $manifest['author'] ?? '',
                'license_required' => !empty($manifest['license_required']),
                'enabled'          => $dbRecord ? (bool) $dbRecord->enabled : false,
                'installed'        => $dbRecord !== null,
                'license_key'      => $dbRecord->license_key ?? null,
            ];
        }

        return $result;
    }

    /**
     * Install an extension: read manifest, create/update DB record with enabled=true.
     */
    public function install(string $id): bool
    {
        $manifest = $this->getManifest($id);

        if ($manifest === null) {
            return false;
        }

        // Preserve the existing enabled state on re-install; only default-disable
        // for brand-new rows. This way an admin reinstalling doesn't lose their
        // prior enable/disable preference.
        $existing = Extension::find($id);
        $enabled = $existing ? $existing->enabled : false;

        Extension::updateOrCreate(
            ['id' => $id],
            [
                'name'        => $manifest['name'] ?? $id,
                'version'     => $manifest['version'] ?? '1.0.0',
                'description' => $manifest['description'] ?? null,
                'author'      => $manifest['author'] ?? null,
                'enabled'     => $enabled,
                'manifest'    => $manifest,
            ]
        );

        $this->registerProviderNow($id);
        $this->syncPermissions();
        ExtensionImages::publish($id);
        $this->clearCache();

        return true;
    }

    /**
     * Uninstall an extension: delete DB record. Optionally delete files.
     */
    public function uninstall(string $id, bool $deleteFiles = false): bool
    {
        $this->removePermissions($id);

        // Clear data from tables declared in manifest
        $manifest = $this->getManifest($id);
        $tables = $manifest['tables'] ?? [];
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        foreach ($tables as $table) {
            if (Schema::hasTable($table)) {
                DB::table($table)->truncate();
            }
        }
        DB::statement('SET FOREIGN_KEY_CHECKS=1');

        $extension = Extension::find($id);

        if ($extension) {
            $extension->delete();
        }

        ExtensionImages::remove($id);

        if ($deleteFiles) {
            $dir = $this->extensionsPath . '/' . $id;
            if (File::isDirectory($dir)) {
                File::deleteDirectory($dir);
            }
        }

        $this->clearCache();

        return true;
    }

    /**
     * Enable an extension.
     */
    public function enable(string $id): bool
    {
        $extension = Extension::find($id);

        if (!$extension) {
            return false;
        }

        $extension->update(['enabled' => true]);

        /* REGISTER THE PROVIDER NOW, NOT NEXT REQUEST.
         *
         * Permission rows derive from the route table, and an extension
         * enabled mid-request has not loaded its routes yet - the old
         * manifest-based sync did not care, the derived one does. Loading
         * the provider here also means the extension is usable the moment
         * the admin clicks Enable, instead of one full request later. */
        $this->registerProviderNow($id);
        $this->syncPermissions();
        ExtensionImages::publish($id);
        $this->clearCache();

        return true;
    }

    /**
     * Disable an extension.
     */
    public function disable(string $id): bool
    {
        $extension = Extension::find($id);

        if (!$extension) {
            return false;
        }

        $extension->update(['enabled' => false]);

        // The permissions stay, with every group's grants: a disabled
        // extension is coming back. Uninstall is what removes them.
        app(\App\Services\PermissionHierarchy::class)->invalidate();
        $this->clearCache();

        return true;
    }

    /**
     * Get all nav groups from enabled extensions' manifests.
     * Each nav group gets _extension_id added. Sort by priority (lower first).
     */
    public function getNavItems(): array
    {
        $enabledIds = $this->getEnabledIds();
        $manifests = $this->getManifests();
        $navItems = [];

        foreach ($enabledIds as $id) {
            $manifest = $manifests[$id] ?? null;

            if ($manifest === null || empty($manifest['nav'])) {
                continue;
            }

            foreach ($manifest['nav'] as $navGroup) {
                $navGroup['_extension_id'] = $id;
                $navItems[] = $navGroup;
            }
        }

        usort($navItems, function ($a, $b) {
            $priorityA = $a['priority'] ?? 100;
            $priorityB = $b['priority'] ?? 100;
            return $priorityA <=> $priorityB;
        });

        return $navItems;
    }

    /**
     * Look for *Extension.php in extensions/{id}/ root.
     * Return FQCN like Extensions\{Ucfirst_id}\{ClassName}.
     */
    public function getProviderClass(string $id): ?string
    {
        $dir = $this->extensionsPath . '/' . $id;

        if (!File::isDirectory($dir)) {
            return null;
        }

        $files = File::glob($dir . '/*Extension.php');

        if (empty($files)) {
            return null;
        }

        $filename = basename($files[0], '.php');

        return 'Extensions\\' . $id . '\\' . $filename;
    }

    /**
     * Every permission key anything on disk still claims.
     *
     * The route-derived catalog plus config/permissions.php plus the
     * label file. Manifests no longer declare permissions - an extension
     * claims keys by having routes. A DISABLED extension's routes are
     * absent, so its exact keys cannot be derived here; every extension
     * present on disk instead claims its whole area by prefix
     * ({tier}_{id}/...) in orphanedPermissionKeys(), because a disabled
     * extension is coming back and its groups' grants have to survive the
     * trip. Only an extension whose folder is GONE stops claiming.
     *
     * @return array<int, string>
     */
    public function claimedPermissionKeys(): array
    {
        /* ONE SOURCE: the route-derived catalog. The config file, the
         * manifest blocks and the label-file merge that used to sit beside
         * it each carried a piece of the retired scheme, and each is gone
         * with it (Phase D). An extension claims keys by having routes;
         * a disabled extension claims its area by prefix in
         * orphanedPermissionKeys(). */
        return app(\App\Services\PermissionCatalogue::class)->keys();
    }

    /**
     * Permission rows nothing on disk declares any more.
     *
     * THE LIST WAS APPEND-ONLY UNTIL THIS EXISTED. syncPermissions() inserts
     * the keys a manifest names and never removes one that has left it, and
     * the uninstall path deletes by the manifest's CURRENT keys - so a
     * permission dropped from a manifest is unreachable by both. It survives
     * every sync and it survives uninstall.
     *
     * Found four that way: view_lazada_messages, manage_lazada_messages and
     * the Shopee pair, left behind by a messages feature that no longer has a
     * route, a nav entry or a manifest entry. They were four rows on the user
     * group screen that granted nothing and could never be removed.
     *
     * Reporting is separate from removing on purpose. Deleting a permission
     * revokes it from every group that holds it, so this answers the question
     * and lets a person decide.
     *
     * @return array<int, string> orphaned keys, sorted
     */
    public function orphanedPermissionKeys(): array
    {
        if (!Schema::hasTable('permissions')) {
            return [];
        }

        $claimed = array_flip($this->claimedPermissionKeys());

        /* An extension on disk claims its whole area, enabled or not. A
         * disabled extension has no routes to derive exact keys from, but
         * deleting its rows would take its groups' grants with them - and
         * it is coming back. The area prefix is exactly the shape
         * removePermissions() deletes on UNINSTALL, when the claim really
         * does end. */
        $areaPrefixes = array_map(
            fn ($id) => [\Illuminate\Support\Str::snake($id) . '/'],
            array_keys($this->getManifests())
        );
        $areaPrefixes = array_merge(...($areaPrefixes ?: [[]]));

        $claimedByArea = function (string $key) use ($areaPrefixes): bool {
            foreach (['view_', 'manage_'] as $tier) {
                foreach ($areaPrefixes as $prefix) {
                    if (str_starts_with($key, $tier . $prefix)) {
                        return true;
                    }
                }
            }

            return false;
        };

        $orphans = DB::table('permissions')
            ->pluck('key')
            ->filter(fn ($key) => !isset($claimed[$key]) && !$claimedByArea($key))
            ->values()
            ->all();

        sort($orphans);

        return $orphans;
    }

    /**
     * Delete the rows nothing declares, and the grants that pointed at them.
     *
     * @return int the number of permission rows removed
     */
    public function pruneOrphanedPermissions(): int
    {
        $orphans = $this->orphanedPermissionKeys();

        if ($orphans === []) {
            return 0;
        }

        $ids = DB::table('permissions')->whereIn('key', $orphans)->pluck('id')->all();

        DB::transaction(function () use ($ids) {
            DB::table('user_group_permissions')->whereIn('permission_id', $ids)->delete();
            DB::table('permissions')->whereIn('id', $ids)->delete();
        });

        return count($ids);
    }



    /**
     * Insert permission rows declared in the extension manifest.
     * Grants them to the Administrator group automatically.
     */
    /**
     * Bring the permissions table up to date with the route table.
     *
     * AN EXTENSION CONTRIBUTES PERMISSIONS BY HAVING ROUTES - NOTHING ELSE.
     * This used to ingest each manifest's "permissions" array, which is how
     * the application ended up speaking two permission vocabularies at once:
     * 55 declared keys gating cards, tabs and dashboards that the
     * route-derived editor could not grant. The manifest blocks are gone;
     * every key now derives from a controller that answers a real route,
     * and a key cannot exist for a screen that is gone.
     *
     * New keys are granted to the Administrator group, which by definition
     * holds everything. Never removes anything - pruning is a deliberate,
     * reported act (permissions:reconcile), not a boot side effect.
     */
    public function syncPermissions(): void
    {
        if (!Schema::hasTable('permissions')) {
            return;
        }

        $wanted = app(\App\Services\PermissionCatalogue::class)->keys();
        $existing = DB::table('permissions')->whereIn('key', $wanted)->pluck('key')->all();
        $missing = array_diff($wanted, $existing);

        if ($missing === []) {
            return;
        }

        $adminGroupId = DB::table('user_groups')->where('name', 'Administrator')->value('id');

        foreach ($missing as $key) {
            $permId = DB::table('permissions')->insertGetId(['key' => $key]);

            if ($adminGroupId) {
                DB::table('user_group_permissions')->updateOrInsert([
                    'user_group_id' => $adminGroupId,
                    'permission_id' => $permId,
                ]);
            }
        }

        // Cached hierarchy must pick up the new keys.
        app(\App\Services\PermissionHierarchy::class)->invalidate();
    }

    /**
     * Register an extension's provider into the running application, so its
     * routes exist before anything derives from them. Safe to call twice:
     * the container ignores a provider it already holds.
     */
    protected function registerProviderNow(string $id): void
    {
        $providerClass = $this->getProviderClass($id);

        if ($providerClass && class_exists($providerClass)) {
            app()->register($providerClass);
        }
    }

    /**
     * Remove permission rows declared in the extension manifest
     * and clean up any user_group_permissions pivot entries.
     */
    protected function removePermissions(string $id): void
    {
        if (!Schema::hasTable('permissions')) {
            return;
        }

        // The extension's keys are the ones its area owns: {tier}_{id}/...
        // Derived rather than read from the manifest, because the manifest
        // no longer declares permissions - routes do, and this extension's
        // routes are going away with it.
        $permIds = DB::table('permissions')
            ->where('key', 'like', 'view\_' . $id . '/%')
            ->orWhere('key', 'like', 'manage\_' . $id . '/%')
            ->pluck('id')
            ->all();

        if (!empty($permIds)) {
            DB::table('user_group_permissions')->whereIn('permission_id', $permIds)->delete();
            DB::table('permissions')->whereIn('id', $permIds)->delete();
        }

        // Cached hierarchy must drop the removed keys.
        app(\App\Services\PermissionHierarchy::class)->invalidate();
    }

    /**
     * Clear internal caches.
     */
    protected function clearCache(): void
    {
        $this->manifests = null;
        $this->enabledCache = null;
    }
}
