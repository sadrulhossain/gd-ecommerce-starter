<?php

use App\Models\Brand;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductVariant;
use Database\Seeders\CatalogModuleSeeder;
use Database\Seeders\Site\SiteCatalogSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('uploads');
    $this->seed(CatalogModuleSeeder::class);
    $this->category = ProductCategory::create(['name' => 'Shirts', 'slug' => 'shirts']);
});

function productPayload(int $categoryId, array $overrides = []): array
{
    return $overrides + [
        'category_id' => $categoryId,
        'name' => 'Basic Tee',
        'has_variants' => false,
        'variants' => [['sku' => 'TEE-1', 'price' => 150000, 'cost_price' => 90000]],
    ];
}

it('requires authentication and the module permission', function () {
    $this->getJson('/api/v1/products')->assertUnauthorized();
    $this->postJson('/api/v1/products', [])->assertUnauthorized();

    actingAsRole('nobody');
    $this->getJson('/api/v1/products')->assertForbidden();
    $this->postJson('/api/v1/products', productPayload($this->category->id))->assertForbidden();
    $this->postJson('/api/v1/products/'.catalogProduct()->id.'/publish')->assertForbidden();
});

it('creates a draft product with its default variant and money in minor units', function () {
    actingAsRole('editor', ['products.create', 'products.view']);

    $this->postJson('/api/v1/products', productPayload($this->category->id, ['description' => '<p>Hi</p><script>x()</script>']))
        ->assertCreated()
        ->assertJsonPath('data.slug', 'basic-tee')
        ->assertJsonPath('data.status', 'draft')
        ->assertJsonPath('data.description', '<p>Hi</p>')
        ->assertJsonCount(1, 'data.variants')
        ->assertJsonPath('data.variants.0.price', ['amount' => 150000, 'currency' => 'BDT'])
        ->assertJsonPath('data.variants.0.compare_at_price', null);

    expect(Product::count())->toBe(1);
});

it('validates the product and its variants', function () {
    actingAsRole('editor', ['products.create']);

    $this->postJson('/api/v1/products', [])
        ->assertInvalidFields(['category_id', 'name', 'has_variants', 'variants']);

    $this->postJson('/api/v1/products', productPayload($this->category->id, ['variants' => [['sku' => 'A', 'price' => 12.5, 'compare_at_price' => 100]]]))
        ->assertInvalidFields(['variants.0.price']);

    $this->postJson('/api/v1/products', productPayload($this->category->id, ['variants' => [['sku' => 'A', 'price' => 500, 'compare_at_price' => 400]]]))
        ->assertInvalidFields(['variants.0.compare_at_price']);

    // A simple product takes exactly one option-less variant.
    $this->postJson('/api/v1/products', productPayload($this->category->id, ['variants' => [
        ['sku' => 'A', 'price' => 1], ['sku' => 'B', 'price' => 1],
    ]]))->assertInvalidFields(['variants']);
    $this->postJson('/api/v1/products', productPayload($this->category->id, ['variants' => [['sku' => 'A', 'price' => 1, 'options' => ['Color' => 'Red']]]]))
        ->assertInvalidFields(['variants']);

    // Duplicate SKU inside the request and against existing rows.
    $this->postJson('/api/v1/products', productPayload($this->category->id, ['has_variants' => true, 'variants' => [
        ['sku' => 'A', 'price' => 1, 'options' => ['Size' => 'S']], ['sku' => 'A', 'price' => 1, 'options' => ['Size' => 'M']],
    ]]))->assertInvalidFields(['variants.0.sku', 'variants.1.sku']);

    catalogProduct([], [['sku' => 'TAKEN', 'price' => 1]]);
    $this->postJson('/api/v1/products', productPayload($this->category->id, ['variants' => [['sku' => 'TAKEN', 'price' => 1]]]))
        ->assertInvalidFields(['variants.0.sku']);
});

it('requires named, consistent, unique options on a product with variants', function () {
    actingAsRole('editor', ['products.create']);
    $payload = fn (array $variants) => productPayload($this->category->id, ['has_variants' => true, 'variants' => $variants]);

    $this->postJson('/api/v1/products', $payload([['sku' => 'A', 'price' => 1]]))
        ->assertInvalidFields(['variants.0.options']);
    $this->postJson('/api/v1/products', $payload([
        ['sku' => 'A', 'price' => 1, 'options' => ['Size' => 'S']],
        ['sku' => 'B', 'price' => 1, 'options' => ['Colour' => 'Red']],
    ]))->assertInvalidFields(['variants.1.options']);
    $this->postJson('/api/v1/products', $payload([
        ['sku' => 'A', 'price' => 1, 'options' => ['Size' => 'S', 'Color' => 'Red']],
        ['sku' => 'B', 'price' => 1, 'options' => ['Color' => 'Red', 'Size' => 'S']],
    ]))->assertInvalidFields(['variants.1.options']);

    $this->postJson('/api/v1/products', $payload([
        ['sku' => 'A', 'price' => 1000, 'options' => ['Size' => 'S']],
        ['sku' => 'B', 'price' => 1200, 'options' => ['Size' => 'M']],
    ]))->assertCreated()->assertJsonCount(2, 'data.variants')->assertJsonPath('data.variants.1.options.Size', 'M');
});

it('updates a product but not its status or variants', function () {
    actingAsRole('editor', ['products.update']);
    $product = catalogProduct();

    $this->patchJson("/api/v1/products/{$product->id}", ['name' => 'Renamed', 'status' => 'active', 'variants' => []])
        ->assertOk()->assertJsonPath('data.name', 'Renamed')->assertJsonPath('data.status', 'draft');
    $this->patchJson("/api/v1/products/{$product->id}", ['category_id' => 999999])
        ->assertInvalidFields(['category_id']);
});

it('publishes only a complete product, then archives and restores it', function () {
    actingAsRole('editor', ['products.publish', 'products.update']);
    $product = catalogProduct();

    $this->postJson("/api/v1/products/{$product->id}/publish")
        ->assertInvalidFields(['status']);

    $this->post("/api/v1/products/{$product->id}/images", ['image' => UploadedFile::fake()->image('a.jpg', 800, 800)], ['Accept' => 'application/json'])->assertCreated();

    $this->postJson("/api/v1/products/{$product->id}/publish")->assertOk()->assertJsonPath('data.status', 'active');
    $this->postJson("/api/v1/products/{$product->id}/archive")->assertOk()->assertJsonPath('data.status', 'archived');
    $this->postJson("/api/v1/products/{$product->id}/publish")->assertOk()->assertJsonPath('data.status', 'active');
    $this->postJson("/api/v1/products/{$product->id}/unpublish")->assertOk()->assertJsonPath('data.status', 'draft');
});

it('soft-deletes a product and keeps its slug reserved', function () {
    actingAsRole('editor', ['products.delete', 'products.create']);
    $product = catalogProduct(['slug' => 'gone']);

    $this->deleteJson("/api/v1/products/{$product->id}")->assertNoContent();
    expect(Product::withTrashed()->find($product->id)->trashed())->toBeTrue();

    $this->postJson('/api/v1/products', productPayload($this->category->id, ['slug' => 'gone']))
        ->assertInvalidFields(['slug']);
});

it('lists products for admins with filters', function () {
    actingAsRole('editor', ['products.view']);
    catalogProduct(['name' => 'Red Shoe', 'slug' => 'red-shoe', 'category_id' => $this->category->id], [['sku' => 'SHOE-9', 'price' => 5]]);
    catalogProduct(['name' => 'Blue Hat', 'slug' => 'blue-hat', 'status' => 'active']);

    $this->getJson('/api/v1/products')->assertOk()->assertJsonCount(2, 'data');
    $this->getJson('/api/v1/products?status=active')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.slug', 'blue-hat');
    $this->getJson('/api/v1/products?q=shoe-9')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.slug', 'red-shoe');
    $this->getJson("/api/v1/products?category_id={$this->category->id}")->assertOk()->assertJsonCount(1, 'data');
    $this->getJson('/api/v1/products')->assertJsonPath('data.0.variants.0.cost_price', null);
});

it('shows only storefront-visible products publicly and never leaks cost price', function () {
    $off = ProductCategory::create(['name' => 'Off', 'slug' => 'off', 'is_active' => false]);
    catalogProduct(['slug' => 'live', 'status' => 'active'], [['sku' => 'L1', 'price' => 900, 'cost_price' => 400]]);
    catalogProduct(['slug' => 'draft']);
    catalogProduct(['slug' => 'archived', 'status' => 'archived']);
    catalogProduct(['slug' => 'in-hidden-category', 'status' => 'active', 'category_id' => $off->id]);
    catalogProduct(['slug' => 'no-active-variant', 'status' => 'active'], [['sku' => 'N1', 'price' => 1, 'is_active' => false]]);

    $this->getJson('/api/v1/public/products')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.slug', 'live');

    $response = $this->getJson('/api/v1/public/products/live')->assertOk()
        ->assertJsonPath('data.price_from', ['amount' => 900, 'currency' => 'BDT']);
    expect($response->getContent())->not->toContain('cost_price')->not->toContain('"status"');

    foreach (['draft', 'archived', 'in-hidden-category', 'no-active-variant'] as $slug) {
        $this->getJson("/api/v1/public/products/{$slug}")->assertNotFound();
    }
});

it('filters, searches and sorts the public product list', function () {
    $parent = ProductCategory::create(['name' => 'Wear', 'slug' => 'wear']);
    $child = ProductCategory::create(['name' => 'Tops', 'slug' => 'tops', 'parent_id' => $parent->id]);
    $acme = Brand::create(['name' => 'Acme', 'slug' => 'acme']);

    catalogProduct(['name' => 'Cheap Top', 'slug' => 'cheap-top', 'status' => 'active', 'category_id' => $child->id, 'brand_id' => $acme->id], [['sku' => 'TOP-1', 'price' => 500]]);
    catalogProduct(['name' => 'Fancy Coat', 'slug' => 'fancy-coat', 'status' => 'active', 'category_id' => $parent->id], [
        ['sku' => 'COAT-1', 'price' => 9000, 'options' => ['Size' => 'M']], ['sku' => 'COAT-2', 'price' => 12000, 'options' => ['Size' => 'L']],
    ]);
    catalogProduct(['name' => 'Mug 100% pure', 'slug' => 'mug', 'status' => 'active'], [['sku' => 'MUG-1', 'price' => 2500]]);

    $slugs = fn (string $query) => collect($this->getJson("/api/v1/public/products?{$query}")->assertOk()->json('data'))->pluck('slug')->all();

    expect($slugs('category=wear'))->toEqualCanonicalizing(['cheap-top', 'fancy-coat'])
        ->and($slugs('category=tops'))->toBe(['cheap-top'])
        ->and($slugs('category=nope'))->toBe([])
        ->and($slugs('brand=acme'))->toBe(['cheap-top'])
        ->and($slugs('q=coat'))->toBe(['fancy-coat'])
        ->and($slugs('q=mug-1'))->toBe(['mug'])
        ->and($slugs('q=100%25'))->toBe(['mug'])
        ->and($slugs('q=%25'))->toBe(['mug'])
        ->and($slugs('min_price=1000&max_price=10000'))->toBe(['mug', 'fancy-coat'])
        ->and($slugs('sort=price_asc'))->toBe(['cheap-top', 'mug', 'fancy-coat'])
        ->and($slugs('sort=price_desc'))->toBe(['fancy-coat', 'mug', 'cheap-top'])
        ->and($slugs('sort=name'))->toBe(['cheap-top', 'fancy-coat', 'mug'])
        ->and($slugs('sort=newest'))->toBe(['mug', 'fancy-coat', 'cheap-top']);

    $this->getJson('/api/v1/public/products?sort=bogus')->assertInvalidFields(['sort']);
    $this->getJson('/api/v1/public/products?per_page=1')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.total', 3);
    $this->getJson('/api/v1/public/products?q=coat')->assertJsonPath('data.0.price_from.amount', 9000)->assertJsonCount(2, 'data.0.variants');
});

it('seeds the demo catalog idempotently', function () {
    $this->seed(SiteCatalogSeeder::class);
    $this->seed(SiteCatalogSeeder::class);

    expect(Product::count())->toBe(2)
        ->and(ProductVariant::count())->toBe(5);
    $this->getJson('/api/v1/public/products')->assertOk()->assertJsonCount(2, 'data');
});
