<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $renames = [
            'lazada_profiles'           => 'lazada_product_groups',
            'lazada_profile_attributes' => 'lazada_product_group_attributes',
            'lazada_product_profile'    => 'lazada_product_group_products',
            'shopee_profiles'           => 'shopee_product_groups',
            'shopee_profile_attributes' => 'shopee_product_group_attributes',
            'opencart_profiles'         => 'opencart_product_groups',
            'opencart_profile_products' => 'opencart_product_group_products',
            'pedallion_profiles'        => 'pedallion_product_groups',
            'pedallion_profile_products'=> 'pedallion_product_group_products',
        ];

        foreach ($renames as $old => $new) {
            if (Schema::hasTable($old) && !Schema::hasTable($new)) {
                Schema::rename($old, $new);
            }
        }
    }

    public function down(): void
    {
        $renames = [
            'lazada_product_groups'           => 'lazada_profiles',
            'lazada_product_group_attributes' => 'lazada_profile_attributes',
            'lazada_product_group_products'   => 'lazada_product_profile',
            'shopee_product_groups'           => 'shopee_profiles',
            'shopee_product_group_attributes' => 'shopee_profile_attributes',
            'opencart_product_groups'         => 'opencart_profiles',
            'opencart_product_group_products' => 'opencart_profile_products',
            'pedallion_product_groups'        => 'pedallion_profiles',
            'pedallion_product_group_products'=> 'pedallion_profile_products',
        ];

        foreach ($renames as $old => $new) {
            if (Schema::hasTable($old) && !Schema::hasTable($new)) {
                Schema::rename($old, $new);
            }
        }
    }
};
