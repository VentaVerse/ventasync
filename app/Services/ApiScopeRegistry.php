<?php

namespace App\Services;

use App\Extensions\ExtensionManager;

/**
 * Aggregates the external-API scope catalog from core (config/api_scopes.php)
 * plus every enabled extension's manifest ("api_scopes" key in extension.json).
 *
 * Mirrors how PermissionSync merges core permissions with per-extension
 * manifests: core owns the API kernel; each module contributes its own
 * scopes. Disabling an extension removes its scopes here automatically, so
 * the Settings → API matrix and scope validation stay in lock-step with what
 * endpoints actually exist.
 *
 * Scope entry shape (same as config/api_scopes.php):
 *   'reports' => ['label' => ..., 'actions' => ['read'(, 'write')], 'description' => ...]
 */
class ApiScopeRegistry
{
    public function __construct(private readonly ExtensionManager $extensions)
    {
    }

    /**
     * @return array<string, array{label:string, actions:array<int,string>, description:string, owner?:string}>
     */
    public function all(): array
    {
        // Core-owned resources (catalog: products, orders, inventory).
        $scopes = config('api_scopes', []);
        foreach ($scopes as $key => $meta) {
            $scopes[$key]['owner'] = 'core';
        }

        // Extension-contributed scopes.
        foreach ($this->extensions->getEnabledIds() as $id) {
            $manifest = $this->extensions->getManifest($id);
            foreach (($manifest['api_scopes'] ?? []) as $key => $meta) {
                if (! is_array($meta)) {
                    continue;
                }
                $meta['owner'] = $id;
                $scopes[$key] = $meta;
            }
        }

        return $scopes;
    }

    /**
     * Flat list of every valid "resource:action" ability across all scopes.
     *
     * @return array<int,string>
     */
    public function abilities(): array
    {
        $abilities = [];
        foreach ($this->all() as $resource => $meta) {
            foreach (($meta['actions'] ?? []) as $action) {
                $abilities[] = "{$resource}:{$action}";
            }
        }

        return $abilities;
    }
}
