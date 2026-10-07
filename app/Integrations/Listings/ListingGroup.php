<?php

namespace App\Integrations\Listings;

use App\Integrations\OneGroupRule;
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

    public static function follow(\Illuminate\Database\Eloquent\Model $row, array $groupValues, array $pairs = []): void
    {
        $paired = $pairs === [] ? [] : array_merge(...$pairs);
        foreach ($groupValues as $col => $groupValue) {
            if (in_array($col, $paired, true)) {
                continue;
            }
            $mine = $row->getAttribute($col);
            if (self::isBlank($mine)) {
                continue;
            }
            $equal = is_array($mine) || is_array($groupValue)
                ? ! self::isBlank($groupValue) && self::sameList((array) $mine, (array) $groupValue)
                : self::same($mine, $groupValue);
            if ($equal) {
                $row->setAttribute($col, null);
            }
        }
        foreach ($pairs as $cols) {
            $anyOwn = collect($cols)->contains(fn ($c) => ! self::isBlank($row->getAttribute($c)));
            $allSame = collect($cols)->every(fn ($c) => self::same($row->getAttribute($c), $groupValues[$c] ?? null));
            if ($anyOwn && $allSame) {
                foreach ($cols as $c) {
                    $row->setAttribute($c, null);
                }
            }
        }
        if ($row->isDirty()) {
            $row->save();
        }
    }

    private static function isBlank(mixed $v): bool
    {
        return $v === null || (is_string($v) && trim($v) === '') || (is_array($v) && $v === []);
    }

    public static function same(mixed $mine, mixed $group): bool
    {
        $blank = fn ($v) => $v === null || (is_string($v) && trim($v) === '');
        if ($blank($mine) || $blank($group)) {
            return $blank($mine) && $blank($group);
        }
        if (is_numeric($mine) && is_numeric($group)) {
            return (float) $mine === (float) $group;
        }

        return strtolower(trim((string) $mine)) === strtolower(trim((string) $group));
    }

    public static function sameList(?array $mine, ?array $group): bool
    {
        $norm = fn ($l) => collect((array) $l)->map(fn ($v) => (int) $v)->filter()->unique()->sort()->values()->all();

        return $norm($mine) === $norm($group);
    }

    public static function ownAnswers(array $mine, array $group): array
    {
        return array_filter($mine, fn ($v, $k) => ! (array_key_exists($k, $group) && self::same($v, $group[$k])), ARRAY_FILTER_USE_BOTH);
    }
}
