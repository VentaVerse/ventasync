<?php

namespace App\Channels\Listings;

use Illuminate\Support\Facades\DB;

final class ListingGroup
{
    public static function current(array $shape, int $productId): ?int
    {
        $id = DB::table($shape['pivot'] . ' as pv')
            ->join($shape['groups'] . ' as g', 'g.id', '=', 'pv.' . $shape['fk'])
            ->where('pv.product_id', $productId)
            ->when($shape['storeFk'] ?? null, fn ($q) => $q->where('g.' . $shape['storeFk'], $shape['storeId']))
            ->orderBy('pv.id')
            ->value('g.id');

        return $id !== null ? (int) $id : null;
    }

    public static function band(array $shape, int $productId, string $groupRoute, array $routeParams = [], string $key = 'id'): array
    {
        return [
            'current' => self::current($shape, $productId),
            'groups' => DB::table($shape['groups'])
                ->when($shape['storeFk'] ?? null, fn ($q) => $q->where($shape['storeFk'], $shape['storeId']))
                ->orderBy('name')->get(['id', 'name'])
                ->map(fn ($g) => ['id' => (int) $g->id, 'name' => (string) $g->name, 'url' => route($groupRoute, $routeParams + [$key => $g->id])])
                ->all(),
            'values' => [],
        ];
    }
}
