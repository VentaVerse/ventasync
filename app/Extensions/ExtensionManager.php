<?php

namespace App\Extensions;

use App\Models\Extension;
use App\Plans\Plan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

class ExtensionManager
{
    protected string $extensionsPath;

    protected ?array $manifests = null;

    protected ?array $enabledCache = null;

    public function __construct()
    {
        $this->extensionsPath = base_path('extensions');
    }

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

    public function getManifest(string $id): ?array
    {
        $manifests = $this->getManifests();

        return $manifests[$id] ?? null;
    }

    public function getEnabledIds(): array
    {
        if ($this->enabledCache !== null) {
            return $this->enabledCache;
        }

        try {
            if (!Schema::hasTable('extensions')) {
                $this->enabledCache = [];
                return $this->enabledCache;
            }

            $this->enabledCache = Extension::where('enabled', true)
                ->pluck('id')
                ->filter(fn ($id) => Plan::allowsExtension($id))
                ->values()
                ->all();
        } catch (\Throwable $e) {
            $this->enabledCache = [];
        }

        return $this->enabledCache;
    }

    public function isEnabled(string $id): bool
    {
        return in_array($id, $this->getEnabledIds(), true);
    }

    public function all(): array
    {
        $manifests = $this->getManifests();

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
                'enabled'          => $dbRecord ? (bool) $dbRecord->enabled && Plan::allowsExtension($id) : false,
                'installed'        => $dbRecord !== null,
                'locked'           => !Plan::allowsExtension($id),
                'license_key'      => $dbRecord->license_key ?? null,
            ];
        }

        return $result;
    }

    public function install(string $id): bool
    {
        $manifest = $this->getManifest($id);

        if ($manifest === null || !Plan::allowsExtension($id)) {
            return false;
        }

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

    public function uninstall(string $id, bool $deleteFiles = false): bool
    {
        $this->removePermissions($id);

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

    public function enable(string $id): bool
    {
        $extension = Extension::find($id);

        if (!$extension || !Plan::allowsExtension($id)) {
            return false;
        }

        $extension->update(['enabled' => true]);

        $this->registerProviderNow($id);
        $this->syncPermissions();
        ExtensionImages::publish($id);
        $this->clearCache();

        return true;
    }

    public function disable(string $id): bool
    {
        $extension = Extension::find($id);

        if (!$extension) {
            return false;
        }

        $extension->update(['enabled' => false]);

        app(\App\Services\PermissionHierarchy::class)->invalidate();
        $this->clearCache();

        return true;
    }

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

    public function claimedPermissionKeys(): array
    {
        return app(\App\Services\PermissionCatalogue::class)->keys();
    }

    public function orphanedPermissionKeys(): array
    {
        if (!Schema::hasTable('permissions')) {
            return [];
        }

        $claimed = array_flip($this->claimedPermissionKeys());

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

        app(\App\Services\PermissionHierarchy::class)->invalidate();
    }

    protected function registerProviderNow(string $id): void
    {
        $providerClass = $this->getProviderClass($id);

        if ($providerClass && class_exists($providerClass)) {
            app()->register($providerClass);
        }
    }

    protected function removePermissions(string $id): void
    {
        if (!Schema::hasTable('permissions')) {
            return;
        }

        $permIds = DB::table('permissions')
            ->where('key', 'like', 'view\_' . $id . '/%')
            ->orWhere('key', 'like', 'manage\_' . $id . '/%')
            ->pluck('id')
            ->all();

        if (!empty($permIds)) {
            DB::table('user_group_permissions')->whereIn('permission_id', $permIds)->delete();
            DB::table('permissions')->whereIn('id', $permIds)->delete();
        }

        app(\App\Services\PermissionHierarchy::class)->invalidate();
    }

    protected function clearCache(): void
    {
        $this->manifests = null;
        $this->enabledCache = null;
    }
}
