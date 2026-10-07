<?php

namespace App\Services;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

class PermissionCatalogue
{
    private const NEVER_GATED = [
        'App\Http\Controllers\Auth\\',
        'Laravel\Sanctum\\',
        'Laravel\Passport\\',
        'Laravel\Mcp\\',
        'App\Http\Controllers\DashboardController',
        'App\Http\Controllers\HomeController',
        'App\Http\Controllers\SearchController',
        'App\Http\Controllers\PaletteController',
        'App\\Http\\Controllers\\Integrations\\',
        'App\\Http\\Controllers\\AutomationsController',
        'App\\Http\\Controllers\\Fulfilment\\',
    ];

    private const MACHINE_AREAS = ['api', 'api_v1'];

    private const WARNED_KEYS = [
        'settings/user_group',
        'settings/user',
        'settings/api_client',
        'settings/extension',
    ];


    private ?array $entries = null;

    public function all(): array
    {
        if ($this->entries !== null) {
            return $this->entries;
        }

        $out = [];

        foreach (Route::getRoutes() as $route) {
            $action = $route->getActionName();

            if (! str_contains($action, '@')) {
                continue;
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
            $out[$key]['tiers'] = $entry['writes'] > 0 ? ['view', 'manage'] : ['view'];
        }

        ksort($out);

        return $this->entries = $out;
    }


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

    public function rowLabel(string $key): string
    {
        $lang = 'permissions.rows.' . $key;
        $translated = __($lang);

        if ($translated !== $lang) {
            return (string) $translated;
        }

        return Str::headline(Str::after($key, '/'));
    }

    public function isMachineArea(string $area): bool
    {
        return in_array($area, self::MACHINE_AREAS, true);
    }

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

        usort($out, fn ($a, $b) => [$a['machine'], $a['label']] <=> [$b['machine'], $b['label']]);

        return $out;
    }

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

    private function keyFor(string $class): string
    {
        if (preg_match('#^Extensions\\\\([A-Za-z0-9_]+)\\\\Controllers\\\\(.+)$#', $class, $m)) {
            $area = Str::snake($m[1]);
            $leaf = str_replace('\\', '', $m[2]);
            $leaf = preg_replace('/Controller$/', '', $leaf);
            $leaf = preg_replace('/^' . preg_quote($m[1], '/') . '/i', '', $leaf);

            return $area . '/' . (Str::snake($leaf) ?: 'overview');
        }

        $path = preg_replace('#^App\\\\Http\\\\Controllers\\\\#', '', $class);
        $parts = explode('\\', $path);
        $leaf = preg_replace('/Controller$/', '', array_pop($parts));

        $area = $parts === [] ? 'general' : Str::snake(implode('', $parts));

        return $area . '/' . (Str::snake($leaf) ?: 'overview');
    }
}
