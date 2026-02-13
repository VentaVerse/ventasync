<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Product;

class ProductSeeder extends Seeder
{
    public function run()
    {
        Product::create([
            'name' => 'Demo Guitar Strings',
            'sku' => 'QABLE-001',
            'price' => 499.00,
            'quantity' => 50
        ]);

        Product::create([
            'name' => 'Demo Guitar Pedal',
            'sku' => 'QABLE-002',
            'price' => 2999.00,
            'quantity' => 20
        ]);
    }
}
