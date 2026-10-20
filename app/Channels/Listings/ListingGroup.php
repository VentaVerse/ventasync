<?php

namespace App\Channels\Listings;

use App\Channels\OneGroupRule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ListingGroup
{
    public static function rules(): array
    {
        return [
            'product_group_listed' => 'nullable|boolean',
            'product_group_id' => 'nullable|integer|min:1',
        ];
    }

    public static function submitted(Request $request): array
    {
        return [
            'present' => $request->boolean('product_group_listed'),
            'id' => ((int) $request->input('product_group_id', 0)) ?: null,
        ];
    }

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

    public static function band(array $shape, int $productId, string $productsRoute, array $routeParams = []): array
    {
        return [
            'current' => self::current($shape, $productId),
            'groups' => DB::table($shape['groups'])
                ->when($shape['storeFk'] ?? null, fn ($q) => $q->where($shape['storeFk'], $shape['storeId']))
                ->orderBy('name')->get(['id', 'name'])
                ->map(fn ($g) => ['id' => (int) $g->id, 'name' => (string) $g->name, 'url' => route($productsRoute, $routeParams + ['group' => $g->id])])
                ->all(),
            'values' => [],
        ];
    }

    public static function assign(array $shape, int $productId, ?int $groupId, array $extra = []): void
    {
        if (self::current($shape, $productId) === $groupId) {
            return;
        }

        $storeGroups = DB::table($shape['groups'])
            ->when($shape['storeFk'] ?? null, fn ($q) => $q->where($shape['storeFk'], $shape['storeId']))
            ->pluck('id')->map(fn ($v) => (int) $v)->all();

        if ($groupId === null) {
            DB::table($shape['pivot'])->where('product_id', $productId)->whereIn($shape['fk'], $storeGroups ?: [0])->delete();

            return;
        }
        if (! in_array($groupId, $storeGroups, true)) {
            throw ValidationException::withMessages(['product_group_id' => 'Pick one of this store\'s product groups.']);
        }

        DB::transaction(function () use ($shape, $productId, $groupId, $extra) {
            OneGroupRule::claim($shape['pivot'], $shape['fk'], $shape['groups'], $shape['storeFk'] ?? null, $groupId, [$productId], [$productId]);
            DB::table($shape['pivot'])->insert([$shape['fk'] => $groupId, 'product_id' => $productId, 'sync_status' => 'pending'] + $extra);
        });
    }
}
