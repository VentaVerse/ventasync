<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A watermark template belongs to a store, the way a description template
 * does: named by (integration, store_id), the pair the rest of the core
 * uses for a store.
 *
 * The two are the same object. A named block authored once, chosen by a
 * listing or lent by its product group, applied at the moment the listing
 * is sent. A mark carries a shop's words as much as its logo, COD accepted
 * or an authorised distributor line, so it is no more portable between
 * stores than a returns policy is. Neither may ever cross, and a list with
 * no store to belong to cannot enforce that.
 *
 * The picture itself is unaffected: image_path still points into the shared
 * image library, so a logo is uploaded once however many stores mark with
 * it.
 *
 * Templates already stored belong to no store, and a template belonging to
 * no store can never be chosen again. Rather than drop them, each is given
 * to every store that exists, so nothing a seller built is lost and the
 * copies they do not want can be deleted from the store that holds them.
 */
return new class extends Migration
{
    /** Every channel that has stores, and the table its stores live in. */
    private const STORE_TABLES = [
        'shopee' => 'shopee_settings',
        'lazada' => 'lazada_settings',
        'tiktok' => 'tiktok_settings',
        'venta' => 'venta_settings',
        'woocommerce' => 'woocommerce_settings',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('watermark_templates')) {
            return;
        }

        $existing = DB::table('watermark_templates')->get();

        Schema::table('watermark_templates', function (Blueprint $table) {
            if (! Schema::hasColumn('watermark_templates', 'integration')) {
                $table->string('integration', 40)->default('')->after('id');
            }
            if (! Schema::hasColumn('watermark_templates', 'store_id')) {
                $table->unsignedBigInteger('store_id')->default(0)->after('integration');
            }
        });

        /* The old rule was one name across the whole install. The new one
           is one name within a store, so two stores may each keep a mark
           called "Shop logo" without knowing about each other. */
        $this->dropNameUnique();

        Schema::table('watermark_templates', function (Blueprint $table) {
            $table->unique(['integration', 'store_id', 'name'], 'watermark_templates_store_name_unique');
        });

        if ($existing->isNotEmpty()) {
            $this->handDownToEveryStore($existing);
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('watermark_templates')) {
            return;
        }

        Schema::table('watermark_templates', function (Blueprint $table) {
            $table->dropUnique('watermark_templates_store_name_unique');
        });

        /* One name per install again, so everything past the first of each
           name has to go before the index can exist. */
        $keep = DB::table('watermark_templates')->orderBy('id')->get()->unique('name')->pluck('id')->all();
        DB::table('watermark_templates')->whereNotIn('id', $keep ?: [0])->delete();

        Schema::table('watermark_templates', function (Blueprint $table) {
            $table->unique('name');
            $table->dropColumn(['integration', 'store_id']);
        });
    }

    /** Give every storeless template to each store, then remove the original. */
    private function handDownToEveryStore(\Illuminate\Support\Collection $existing): void
    {
        $stores = [];
        foreach (self::STORE_TABLES as $integration => $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            foreach (DB::table($table)->pluck('id') as $id) {
                $stores[] = [$integration, (int) $id];
            }
        }

        foreach ($existing as $row) {
            $columns = array_diff_key((array) $row, array_flip(['id', 'integration', 'store_id']));
            foreach ($stores as [$integration, $storeId]) {
                DB::table('watermark_templates')->insert($columns + [
                    'integration' => $integration,
                    'store_id' => $storeId,
                ]);
            }
            DB::table('watermark_templates')->where('id', $row->id)->delete();
        }
    }

    /**
     * Drop the unique on name, whatever it is called. It was declared
     * without a name, so it carries the one the database chose.
     */
    private function dropNameUnique(): void
    {
        try {
            Schema::table('watermark_templates', function (Blueprint $table) {
                $table->dropUnique('watermark_templates_name_unique');
            });
        } catch (\Throwable) {
            // Already gone, or never named that way on this tier.
        }
    }
};
