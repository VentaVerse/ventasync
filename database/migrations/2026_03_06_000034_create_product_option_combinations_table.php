<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_option_combinations', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('product_id')->index();
            $table->string('sku', 64)->default('');
            $table->integer('quantity')->default(0);
            $table->decimal('absolute_price', 15, 4)->default(0);
            $table->decimal('absolute_cost', 15, 4)->default(0);
            $table->tinyInteger('subtract')->default(1);
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->index('sku');
        });

        Schema::create('product_option_combination_values', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('combination_id')->index();
            $table->unsignedInteger('product_option_value_id')->index();

            $table->unique(['combination_id', 'product_option_value_id'], 'pocv_combo_pov_unique');

            $table->foreign('combination_id')
                  ->references('id')
                  ->on('product_option_combinations')
                  ->cascadeOnDelete();
        });

        // Backfill: create 1 combination + 1 pivot row per existing POV
        $pfx = config('catalog.prefix');

        $povRows = DB::table($pfx . 'product_option_value as pov')
            ->join($pfx . 'product as p', 'pov.product_id', '=', 'p.product_id')
            ->get([
                'pov.product_option_value_id',
                'pov.product_id',
                'pov.sku',
                'pov.quantity',
                'pov.subtract',
                'pov.absolute_price',
                'pov.absolute_cost',
                'pov.price',
                'pov.price_prefix',
                'p.price as base_price',
            ]);

        $now = now();

        foreach ($povRows as $pov) {
            // Use absolute_price if set, otherwise compute from base + delta
            $absPrice = $pov->absolute_price;
            if ($absPrice === null) {
                $delta = (float) $pov->price;
                $absPrice = (float) $pov->base_price + ($pov->price_prefix === '+' ? $delta : -$delta);
            }

            $comboId = DB::table('product_option_combinations')->insertGetId([
                'product_id'     => (int) $pov->product_id,
                'sku'            => $pov->sku ?? '',
                'quantity'       => (int) $pov->quantity,
                'absolute_price' => (float) $absPrice,
                'absolute_cost'  => (float) ($pov->absolute_cost ?? 0),
                'subtract'       => (int) ($pov->subtract ?? 1),
                'sort_order'     => 0,
                'created_at'     => $now,
                'updated_at'     => $now,
            ]);

            DB::table('product_option_combination_values')->insert([
                'combination_id'          => $comboId,
                'product_option_value_id' => (int) $pov->product_option_value_id,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('product_option_combination_values');
        Schema::dropIfExists('product_option_combinations');
    }
};
