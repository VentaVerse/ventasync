<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['lazada_products', 'lazada_product_groups', 'lazada_product_group_products'] as $table) {
            if (! Schema::hasTable($table)) {
                return;
            }
        }

        $pairs = DB::table('lazada_product_group_products as gp')
            ->join('lazada_product_groups as g', 'g.id', '=', 'gp.lazada_product_group_id')
            ->join('lazada_products as l', 'l.id', '=', 'gp.lazada_product_id')
            ->whereColumn('l.lazada_setting_id', 'g.lazada_setting_id')
            ->get([
                'l.id as listing_id', 'l.primary_category_id', 'l.brand_id', 'l.brand_name_override', 'l.markup_fixed', 'l.markup_percent',
                'g.id as group_id', 'g.lazada_category_id', 'g.brand_id as g_brand_id', 'g.brand_name_override as g_brand_name', 'g.markup_fixed as g_fixed', 'g.markup_percent as g_pct',
            ]);

        $same = fn ($a, $b) => $a !== null && $b !== null && (float) $a === (float) $b;
        $sameText = fn ($a, $b) => strtolower(trim((string) $a)) === strtolower(trim((string) $b));

        foreach ($pairs as $r) {
            $clear = [];
            if ((int) $r->primary_category_id > 0 && (int) $r->primary_category_id === (int) $r->lazada_category_id) {
                $clear['primary_category_id'] = null;
            }
            if ($same($r->markup_fixed ?? 0, $r->g_fixed ?? 0) && $same($r->markup_percent ?? 0, $r->g_pct ?? 0)
                && ($r->markup_fixed !== null || $r->markup_percent !== null)) {
                $clear['markup_fixed'] = null;
                $clear['markup_percent'] = null;
            }
            if ((int) ($r->brand_id ?? 0) === (int) ($r->g_brand_id ?? 0) && $sameText($r->brand_name_override, $r->g_brand_name)
                && ((int) ($r->brand_id ?? 0) > 0 || trim((string) $r->brand_name_override) !== '')) {
                $clear['brand_id'] = null;
                $clear['brand_name_override'] = null;
            }
            if ($clear !== []) {
                DB::table('lazada_products')->where('id', $r->listing_id)->update($clear);
            }

            if (Schema::hasTable('lazada_product_attributes') && Schema::hasTable('lazada_product_group_attributes')) {
                $groupAnswers = DB::table('lazada_product_group_attributes')->where('lazada_product_group_id', $r->group_id)->pluck('value', 'attribute_key');
                foreach (DB::table('lazada_product_attributes')->where('lazada_product_id', $r->listing_id)->get(['id', 'attribute_key', 'value']) as $a) {
                    if ($groupAnswers->has($a->attribute_key) && $sameText($a->value, $groupAnswers->get($a->attribute_key))) {
                        DB::table('lazada_product_attributes')->where('id', $a->id)->delete();
                    }
                }
            }
        }
    }

    public function down(): void
    {
    }
};
