<?php

namespace App\Services;

use App\Extensions\ExtensionManager;

class ApiScopeRegistry
{
    public function __construct(private readonly ExtensionManager $extensions)
    {
    }

    public function all(): array
    {
        $scopes = config('api_scopes', []);
        foreach ($scopes as $key => $meta) {
            $scopes[$key]['owner'] = 'core';
        }

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
