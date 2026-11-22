<?php

namespace Database\Seeders\Site;

use App\Models\Brand;
use App\Models\Product;
use App\Models\ProductCategory;
use Illuminate\Database\Seeder;

/**
 * Sample catalog (no images): categories, brands, a simple product and a product with size/colour variants. Run by hand:
 * `php artisan db:seed --class="Database\Seeders\Site\SiteCatalogSeeder"`. Not part of DatabaseSeeder; re-running
 * updates the same rows and never touches variants you added. Prices are BDT minor units (poisha).
 */
class SiteCatalogSeeder extends Seeder
{
    public function run(): void
    {
        $clothing = ProductCategory::query()->updateOrCreate(['slug' => 'clothing'], ['name' => 'Clothing', 'sort_order' => 10]);
        $tshirts = ProductCategory::query()->updateOrCreate(['slug' => 't-shirts'], ['name' => 'T-shirts', 'parent_id' => $clothing->id, 'sort_order' => 10]);
        $home = ProductCategory::query()->updateOrCreate(['slug' => 'home'], ['name' => 'Home', 'sort_order' => 20]);

        $acme = Brand::query()->updateOrCreate(['slug' => 'acme'], ['name' => 'Acme']);
        $northwind = Brand::query()->updateOrCreate(['slug' => 'northwind'], ['name' => 'Northwind']);

        $mug = Product::query()->firstOrCreate(['slug' => 'ceramic-mug'], [
            'category_id' => $home->id, 'brand_id' => $northwind->id, 'name' => 'Ceramic mug',
            'short_description' => 'A 350 ml ceramic mug.', 'description' => '<p>Dishwasher safe, holds 350 ml.</p>',
            'has_variants' => false,
        ]);
        $mug->forceFill(['status' => Product::ACTIVE])->save();
        $mug->variants()->firstOrCreate(['sku' => 'MUG-001'], ['price' => 45000, 'cost_price' => 28000, 'weight_grams' => 400]);

        $shirt = Product::query()->firstOrCreate(['slug' => 'classic-t-shirt'], [
            'category_id' => $tshirts->id, 'brand_id' => $acme->id, 'name' => 'Classic T-shirt',
            'short_description' => 'Soft cotton tee.', 'description' => '<p>100% cotton, regular fit.</p>',
            'has_variants' => true,
        ]);
        $shirt->forceFill(['status' => Product::ACTIVE])->save();

        foreach (['Red', 'Blue'] as $c => $color) {
            foreach (['M', 'L'] as $s => $size) {
                $shirt->variants()->firstOrCreate(['sku' => 'TEE-'.strtoupper($color[0]).'-'.$size], [
                    'name' => "{$color} / {$size}", 'options' => ['Color' => $color, 'Size' => $size],
                    'price' => 120000, 'compare_at_price' => $c === 0 ? 150000 : null, 'cost_price' => 70000,
                    'sort_order' => ($c * 2 + $s + 1) * 10,
                ]);
            }
        }
    }
}
