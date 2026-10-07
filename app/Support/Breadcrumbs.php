<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;

class Breadcrumbs
{
    public const CHANNEL_ROOT = 'Channels';

    public static function for(Request $request): array
    {
        $path = Navigation::normalisePath($request->path());

        $trail = static::channelTrail($request) ?? static::navTrail($path);

        foreach (static::settingsCardTrail($path) as $crumb) {
            $trail[] = $crumb;
        }

        $leaf = static::leaf();

        if ($leaf !== null) {
            $trail[] = ['label' => $leaf, 'url' => null];
        }

        foreach ($trail as $i => $crumb) {
            if ($crumb['url'] !== null && Navigation::normalisePath($crumb['url']) === $path) {
                $trail[$i]['url'] = null;
            }
        }

        return $trail;
    }

    protected static function channelTrail(Request $request): ?array
    {
        $payload = $request->attributes->get('channel-workspace.compose');

        if (! is_array($payload) || ! isset($payload['channelCard'])) {
            return null;
        }

        $trail = [
            ['label' => static::CHANNEL_ROOT, 'url' => null],
            ['label' => (string) $payload['channelCard']->name, 'url' => null],
        ];

        $store = $payload['channelStoreLabel'] ?? null;

        if (is_string($store) && $store !== '') {
            $trail[] = ['label' => $store, 'url' => null];
        }

        return $trail;
    }

    protected static function navTrail(string $path): array
    {
        $match = Navigation::matchPath($path);

        if ($match !== null) {
            $trail = [];

            if ($match['group'] !== null) {
                $trail[] = [
                    'label' => $match['group']['label'],
                    'url' => Navigation::urlFor($match['group']),
                ];
            }

            $trail[] = [
                'label' => $match['item']['label'],
                'url' => Navigation::urlFor($match['item']),
            ];

            return $trail;
        }

        $section = static::firstSegment($path);
        $group = $section === null ? null : Navigation::groupForSection($section);

        if ($group === null) {
            return [];
        }

        return [['label' => $group['label'], 'url' => Navigation::urlFor($group)]];
    }

    protected static function settingsCardTrail(string $path): array
    {
        if (static::firstSegment($path) !== 'settings') {
            return [];
        }

        $best = null;

        foreach (\App\Http\Controllers\Settings\SettingsHubController::destinations() as $cards) {
            foreach ($cards as $card) {
                $cardPath = Navigation::pathFor(['route' => $card['route']]);

                if ($cardPath === null || $cardPath === $path || ! str_starts_with($path, rtrim($cardPath, '/').'/')) {
                    continue;
                }

                if ($best === null || strlen($cardPath) > strlen($best['path'])) {
                    $best = ['path' => $cardPath, 'label' => $card['label'], 'url' => Navigation::urlFor(['route' => $card['route']])];
                }
            }
        }

        return $best === null ? [] : [['label' => $best['label'], 'url' => $best['url']]];
    }

    protected static function firstSegment(string $path): ?string
    {
        $segments = array_values(array_filter(explode('/', $path), fn ($s) => $s !== ''));

        return $segments[0] ?? null;
    }

    // The section is already HTML-escaped once; decode it so the layout's {{ }} does not double-encode.
    protected static function leaf(): ?string
    {
        if (! View::getFacadeRoot()) {
            return null;
        }

        $leaf = trim(View::yieldContent('breadcrumb'));
        $leaf = html_entity_decode($leaf, ENT_QUOTES, 'UTF-8');

        return $leaf === '' ? null : $leaf;
    }
}
