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

        $this->syncPermissions($id);
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

        $this->syncPermissions($id);
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

        $this->removePermissions($id);
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
     * Pull permission keys out of a manifest's "permissions" array,
     * accepting both legacy string form and the new object form
     * ({key, parent?, subgroup?, label?}).
     *
     * @return string[]
     */
    private function permissionKeys(array $manifest): array
    {
        $keys = [];
        foreach ($manifest['permissions'] ?? [] as $perm) {
            if (is_string($perm)) {
                $keys[] = $perm;
            } elseif (is_array($perm) && !empty($perm['key']) && is_string($perm['key'])) {
                $keys[] = $perm['key'];
            }
        }
        return $keys;
    }

    /**
     * Insert permission rows declared in the extension manifest.
     * Grants them to the Administrator group automatically.
     */
    public function syncPermissions(string $id): void
    {
        if (!Schema::hasTable('permissions')) {
            return;
        }

        $manifest = $this->getManifest($id);
        $keys = $this->permissionKeys($manifest);

        if (empty($keys)) {
            return;
        }

        $adminGroupId = DB::table('user_groups')->where('name', 'Administrator')->value('id');

        foreach ($keys as $key) {
            $existing = DB::table('permissions')->where('key', $key)->first();

            if (!$existing) {
                $permId = DB::table('permissions')->insertGetId([
                    'key' => $key,
                ]);
            } else {
                $permId = $existing->id;
            }

            // Grant to Administrator group
            if ($adminGroupId) {
                DB::table('user_group_permissions')->updateOrInsert([
                    'user_group_id' => $adminGroupId,
                    'permission_id' => $permId,
                ]);
            }
        }

        // Permission hierarchy depends on manifest contents; bust the
        // cached tree so the next request rebuilds with the new keys.
        app(\App\Services\PermissionHierarchy::class)->invalidate();
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

        $manifest = $this->getManifest($id);
        $keys = $this->permissionKeys($manifest);

        if (empty($keys)) {
            return;
        }

        $permIds = DB::table('permissions')->whereIn('key', $keys)->pluck('id')->all();

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
