<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // These tables mirror OpenCart's option structure.
        // They are created only if missing to keep installs stable.
        if (!Schema::hasTable('option')) {
            Schema::create('option', function (Blueprint $table) {
                $table->engine = 'MyISAM';
                $table->increments('option_id');
                $table->string('type', 32);
                $table->integer('sort_order');
            });
        }

        if (!Schema::hasTable('option_description')) {
            Schema::create('option_description', function (Blueprint $table) {
                $table->engine = 'MyISAM';
                $table->integer('option_id');
                $table->integer('language_id');
                $table->string('name', 128);
                $table->primary(['option_id', 'language_id'], 'pk_option_description');
            });
        }

        if (!Schema::hasTable('option_value')) {
            Schema::create('option_value', function (Blueprint $table) {
                $table->engine = 'MyISAM';
                $table->increments('option_value_id');
                $table->integer('option_id')->index('idx_option_value_option_id');
                $table->string('image', 255);
                $table->integer('sort_order');
            });
        }

        if (!Schema::hasTable('option_value_description')) {
            Schema::create('option_value_description', function (Blueprint $table) {
                $table->engine = 'MyISAM';
                $table->integer('option_value_id');
                $table->integer('language_id');
                $table->integer('option_id')->index('idx_ovd_option_id');
                $table->string('name', 128);
                $table->primary(['option_value_id', 'language_id'], 'pk_option_value_description');
            });
        }

        if (!Schema::hasTable('product_option')) {
            Schema::create('product_option', function (Blueprint $table) {
                $table->engine = 'MyISAM';
                $table->increments('product_option_id');
                $table->integer('product_id')->index('idx_product_option_product_id');
                $table->integer('option_id')->index('idx_product_option_option_id');
                $table->text('value');
                $table->boolean('required');
            });
        }

        if (!Schema::hasTable('product_option_value')) {
            Schema::create('product_option_value', function (Blueprint $table) {
                $table->engine = 'MyISAM';
                $table->increments('product_option_value_id');
                $table->integer('product_option_id')->index('idx_pov_product_option_id');
                $table->integer('product_id')->index('idx_pov_product_id');
                $table->integer('option_id')->index('idx_pov_option_id');
                $table->integer('option_value_id')->index('idx_pov_option_value_id');
                $table->string('sku', 64)->default('');
                $table->integer('quantity');
                $table->boolean('subtract');
                $table->decimal('price', 15, 4);
                $table->decimal('cost', 15, 4)->default(0.0000);
                $table->integer('costing_method')->default(0);
                $table->decimal('cost_amount', 15, 4)->default(0.0000);
                $table->string('cost_prefix', 1)->default('+');
                $table->string('price_prefix', 1);
                $table->integer('points');
                $table->string('points_prefix', 1);
                $table->decimal('weight', 15, 8);
                $table->string('weight_prefix', 1);
            });
        }
    }

    public function down(): void
    {
        // No-op for safety: dropping these tables can break existing installs.
    }
};
