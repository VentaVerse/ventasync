<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('warehouses')->count() === 0) {
            DB::table('warehouses')->insert([
                'name' => 'Main Store',
                'code' => 'STORE',
                'is_default' => true,
                'sort_order' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        if (DB::table('warehouse_inventory')->count() === 0) {
            $defaultId = DB::table('warehouses')->where('is_default', true)->value('id');
            if ($defaultId) {
                $pfx = config('catalog.prefix');

                $povProductIds = DB::table($pfx . 'product_option_value')
                    ->distinct()
                    ->pluck('product_id')
                    ->toArray();

                if (!empty($povProductIds)) {
                    $optionValues = DB::table($pfx . 'product_option_value')
                        ->whereIn('product_id', $povProductIds)
                        ->get(['product_id', 'product_option_value_id', 'quantity']);

                    foreach ($optionValues as $ov) {
                        DB::table('warehouse_inventory')->updateOrInsert(
                            ['warehouse_id' => $defaultId, 'product_id' => $ov->product_id, 'product_option_value_id' => $ov->product_option_value_id],
                            ['quantity' => $ov->quantity, 'created_at' => now(), 'updated_at' => now()]
                        );
                    }
                }

                $noOptProducts = DB::table($pfx . 'product')
                    ->whereNotIn('product_id', $povProductIds)
                    ->get(['product_id', 'quantity']);

                foreach ($noOptProducts as $p) {
                    DB::table('warehouse_inventory')->updateOrInsert(
                        ['warehouse_id' => $defaultId, 'product_id' => $p->product_id, 'product_option_value_id' => 0],
                        ['quantity' => $p->quantity, 'created_at' => now(), 'updated_at' => now()]
                    );
                }
            }
        }
    }

    public function down(): void
    {
    }
};
