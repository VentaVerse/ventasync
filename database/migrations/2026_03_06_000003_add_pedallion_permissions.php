<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        foreach (['manage_pedallion' => 'Pedallion', 'manage_pedallion_orders' => 'Pedallion Orders'] as $key => $label) {
            if (!DB::table('permissions')->where('key', $key)->exists()) {
                DB::table('permissions')->insert([
                    'key'   => $key,
                    'label' => $label,
                ]);
            }
        }
    }

    public function down(): void
    {
        DB::table('permissions')->whereIn('key', ['manage_pedallion', 'manage_pedallion_orders'])->delete();
    }
};
