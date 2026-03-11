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

        if (!Schema::hasTable('extensions')) {
            $this->enabledCache = [];
            return $this->enabledCache;
        }

        $this->enabledCache = Extension::where('enabled', true)
            ->pluck('id')
            ->all();

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

        // Load all DB records keyed by id
        $dbRecords = [];
        if (Schema::hasTable('extensions')) {
            $dbRecords = Extension::all()->keyBy('id');
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

        Extension::updateOrCreate(
            ['id' => $id],
            [
                'name'        => $manifest['name'] ?? $id,
                'version'     => $manifest['version'] ?? '1.0.0',
                'description' => $manifest['description'] ?? null,
                'author'      => $manifest['author'] ?? null,
                'enabled'     => true,
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
     * Insert permission rows declared in the extension manifest.
     * Grants them to the Administrator group automatically.
     */
    protected function syncPermissions(string $id): void
    {
        if (!Schema::hasTable('permissions')) {
            return;
        }

        $manifest = $this->getManifest($id);
        $keys = $manifest['permissions'] ?? [];

        if (empty($keys)) {
            return;
        }

        $adminGroupId = DB::table('user_groups')->where('name', 'Administrator')->value('id');

        foreach ($keys as $key) {
            $label = ucwords(str_replace('_', ' ', $key));

            $permId = DB::table('permissions')->where('key', $key)->value('id');

            if (!$permId) {
                $permId = DB::table('permissions')->insertGetId([
                    'key'   => $key,
                    'label' => $label,
                ]);
            }

            // Grant to Administrator group
            if ($adminGroupId) {
                DB::table('user_group_permissions')->updateOrInsert([
                    'user_group_id' => $adminGroupId,
                    'permission_id' => $permId,
                ]);
            }
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

        $manifest = $this->getManifest($id);
        $keys = $manifest['permissions'] ?? [];

        if (empty($keys)) {
            return;
        }

        $permIds = DB::table('permissions')->whereIn('key', $keys)->pluck('id')->all();

        if (!empty($permIds)) {
            DB::table('user_group_permissions')->whereIn('permission_id', $permIds)->delete();
            DB::table('permissions')->whereIn('id', $permIds)->delete();
        }
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
