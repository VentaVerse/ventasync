<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * One product, one group (cleanup point 2, the owner 2026-09-03).
 *
 * A product in several groups was the ambiguity that made group status
 * unreadable: which group's settings priced it, which page's status was
 * true. Membership becomes unique - per store where groups are
 * store-scoped (Shopee, Venta, OpenCart), globally where they are not
 * (Lazada, TikTok, Pedallion) - and the legacy category/manufacturer
 * FILTER membership dies with it (its resolution code is already gone:
 * an empty filter matched every product, the #96 catch-all trap).
 *
 * Existing multi-memberships are resolved deterministically - the OLDEST
 * membership (lowest pivot id) wins - and every removal is written to the
 * log so nothing is silently reassigned. The owner asked for a report, not a
 * guess; the log lines are the report.
 */
return new class extends Migration
{
    /** pivot table => [group fk column, group table, store fk column|null] */
    private const PIVOTS = [
        'shopee_product_group_products' => ['shopee_product_group_id', 'shopee_product_groups', 'shopee_setting_id'],
        'venta_product_group_products' => ['venta_product_group_id', 'venta_product_groups', 'venta_setting_id'],
        'opencart_product_group_products' => ['opencart_product_group_id', 'opencart_product_groups', 'opencart_setting_id'],
        'lazada_product_group_products' => ['lazada_product_group_id', 'lazada_product_groups', null],
        'tiktok_product_group_products' => ['tiktok_product_group_id', 'tiktok_product_groups', null],
        'pedallion_product_group_products' => ['pedallion_product_group_id', 'pedallion_product_groups', null],
    ];

    private const FILTER_TABLES = [
        'shopee_product_groups', 'venta_product_groups', 'opencart_product_groups',
        'lazada_product_groups', 'tiktok_product_groups', 'pedallion_product_groups',
    ];

    public function up(): void
    {
        foreach (self::PIVOTS as $pivot => [$groupFk, $groupTable, $storeFk]) {
            if (! Schema::hasTable($pivot)) {
                continue;
            }

            $rows = DB::table($pivot . ' as p')
                ->join($groupTable . ' as g', 'g.id', '=', 'p.' . $groupFk)
                ->orderBy('p.id')
                ->get(array_filter([
                    'p.id', 'p.product_id', 'p.' . $groupFk . ' as group_id', 'g.name',
                    $storeFk ? 'g.' . $storeFk . ' as store_id' : null,
                ]));

            $keep = [];
            $drop = [];
            foreach ($rows as $r) {
                $scope = ($storeFk ? ($r->store_id ?? 0) . ':' : '') . $r->product_id;
                if (! isset($keep[$scope])) {
                    $keep[$scope] = $r;
                    continue;
                }
                $drop[] = $r;
            }

            foreach ($drop as $r) {
                Log::warning('one-group-per-product: removed duplicate membership', [
                    'pivot' => $pivot,
                    'product_id' => (int) $r->product_id,
                    'removed_from_group' => (string) $r->name,
                    'removed_group_id' => (int) $r->group_id,
                    'kept_group' => (string) $keep[($storeFk ? ($r->store_id ?? 0) . ':' : '') . $r->product_id]->name,
                ]);
            }

            if ($drop !== []) {
                DB::table($pivot)->whereIn('id', array_map(fn ($r) => (int) $r->id, $drop))->delete();
            }

            /* The database holds the rule where it can express it: a global
             * unique on product_id where groups are not store-scoped. The
             * store-scoped pivots (the store lives on the GROUP row, not
             * the pivot) are enforced in the controllers instead. */
            if ($storeFk === null) {
                Schema::table($pivot, function (Blueprint $table) use ($pivot) {
                    $table->unique('product_id', $pivot . '_one_group');
                });
            }
        }

        foreach (self::FILTER_TABLES as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            Schema::table($table, function (Blueprint $t) use ($table) {
                foreach (['catalog_category_ids', 'manufacturer_ids'] as $col) {
                    if (Schema::hasColumn($table, $col)) {
                        $t->dropColumn($col);
                    }
                }
            });
        }
    }

    public function down(): void
    {
        foreach (self::PIVOTS as $pivot => [, , $storeFk]) {
            if ($storeFk === null && Schema::hasTable($pivot)) {
                Schema::table($pivot, function (Blueprint $table) use ($pivot) {
                    $table->dropUnique($pivot . '_one_group');
                });
            }
        }

        foreach (self::FILTER_TABLES as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            Schema::table($table, function (Blueprint $t) use ($table) {
                foreach (['catalog_category_ids', 'manufacturer_ids'] as $col) {
                    if (! Schema::hasColumn($table, $col)) {
                        $t->json($col)->nullable();
                    }
                }
            });
        }
    }
};
