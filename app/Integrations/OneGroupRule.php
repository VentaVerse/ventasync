<?php

namespace App\Integrations;

use Illuminate\Support\Facades\DB;

class OneGroupRule
{
    public static function claim(
        string $pivotTable,
        string $groupFk,
        string $groupTable,
        ?string $storeFk,
        int $groupId,
        array $productIds,
        array $moveIds = [],
    ): array {
        $productIds = array_values(array_unique(array_map('intval', $productIds)));
        $moveIds = array_map('intval', $moveIds);

        if ($productIds === []) {
            return ['free' => [], 'held' => [], 'moved' => []];
        }

        $owners = self::owners($pivotTable, $groupFk, $groupTable, $storeFk, $groupId, $productIds);

        $free = [];
        $held = [];
        $moved = [];

        foreach ($productIds as $pid) {
            if (! isset($owners[$pid])) {
                $free[] = $pid;
                continue;
            }
            if (in_array($pid, $moveIds, true)) {
                DB::table($pivotTable)
                    ->where('product_id', $pid)
                    ->where($groupFk, $owners[$pid]['group_id'])
                    ->delete();
                $moved[$pid] = $owners[$pid]['name'];
                continue;
            }
            $held[$pid] = $owners[$pid]['name'];
        }

        return ['free' => $free, 'held' => $held, 'moved' => $moved];
    }

    public static function owners(
        string $pivotTable,
        string $groupFk,
        string $groupTable,
        ?string $storeFk,
        int $groupId,
        array $productIds,
    ): array {
        if ($productIds === []) {
            return [];
        }

        $q = DB::table($pivotTable . ' as p')
            ->join($groupTable . ' as g', 'g.id', '=', 'p.' . $groupFk)
            ->whereIn('p.product_id', array_map('intval', $productIds))
            ->where('p.' . $groupFk, '!=', $groupId);

        if ($storeFk !== null) {
            $storeId = DB::table($groupTable)->where('id', $groupId)->value($storeFk);
            if ($storeId !== null) {
                $q->where('g.' . $storeFk, $storeId);
            }
        }

        $out = [];
        foreach ($q->get(['p.product_id', 'p.' . $groupFk . ' as group_id', 'g.name']) as $r) {
            $out[(int) $r->product_id] = ['group_id' => (int) $r->group_id, 'name' => (string) $r->name];
        }

        return $out;
    }

    public static function heldClause(array $held): string
    {
        if ($held === []) {
            return '';
        }

        $parts = [];
        foreach (array_slice($held, 0, 3, true) as $pid => $name) {
            $parts[] = "#{$pid} is in '{$name}'";
        }
        $more = count($held) - min(3, count($held));

        return ' ' . count($held) . ' not added - a product lives in one group: '
            . implode('; ', $parts) . ($more > 0 ? "; and {$more} more" : '')
            . '. Use Move here to transfer one.';
    }

    public static function place(string $pivotTable, string $groupFk, string $groupTable, string $storeFk, int $storeId, int $groupId, int $productId): bool
    {
        $belongs = DB::table($groupTable)->where('id', $groupId)
            ->when($storeId > 0, fn ($q) => $q->where($storeFk, $storeId))
            ->exists();
        if (! $belongs) {
            return false;
        }

        self::claim($pivotTable, $groupFk, $groupTable, $storeFk, $groupId, [$productId], [$productId]);

        if (! DB::table($pivotTable)->where($groupFk, $groupId)->where('product_id', $productId)->exists()) {
            $row = [$groupFk => $groupId, 'product_id' => $productId];
            foreach (['created_at', 'updated_at'] as $stamp) {
                if (\Illuminate\Support\Facades\Schema::hasColumn($pivotTable, $stamp)) {
                    $row[$stamp] = now();
                }
            }
            DB::table($pivotTable)->insert($row);
        }

        return true;
    }
}
