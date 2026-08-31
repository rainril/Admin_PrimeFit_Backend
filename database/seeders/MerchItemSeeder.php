<?php

namespace Database\Seeders;

use App\Models\MerchItem;
use Illuminate\Database\Seeder;

// Run with: php artisan db:seed --class=MerchItemSeeder
// Pre-fills the 3 items already visible in your UI, using the name/price
// you confirmed are already accurate. Stock is left as a rough placeholder
// (40) — since you mentioned that number isn't accurate, log into
// phpMyAdmin after seeding and correct `stock` for each row to the real
// count, or use the Restock feature once it's wired up.
class MerchItemSeeder extends Seeder
{
    public function run(): void
    {
        $items = [
            ['sku' => 'MC-001-TSH', 'name' => 'PrimeFit T-Shirt (All Sizes)', 'price' => 290, 'stock' => 40],
            ['sku' => 'MC-002-SSL', 'name' => 'PrimeFit Sando Sleeveless (All Sizes)', 'price' => 260, 'stock' => 40],
            ['sku' => 'MC-003-SMT', 'name' => 'PrimeFit Sando Muscle Tee (All Sizes)', 'price' => 260, 'stock' => 40],
        ];

        foreach ($items as $item) {
            MerchItem::updateOrCreate(['sku' => $item['sku']], $item);
        }
    }
}
