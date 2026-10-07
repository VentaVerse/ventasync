<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $descriptions = [
            'manage_venta'        => 'Venta store settings, product groups, and product sync',
            'manage_venta_orders' => 'View and sync orders from Venta stores',
        ];

        foreach ($descriptions as $key => $desc) {
            DB::table('permissions')->where('key', $key)->update([
                'description' => $desc,
                'label'       => match ($key) {
                    'manage_venta'        => 'Venta',
                    'manage_venta_orders' => 'Venta Orders',
                },
            ]);
        }
    }

    public function down(): void
    {
        DB::table('permissions')
            ->whereIn('key', ['manage_venta', 'manage_venta_orders'])
            ->update(['description' => null]);
    }
};
