<?php

use App\Models\Product;
use App\Models\ProductImage;
use Database\Seeders\CatalogModuleSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('uploads');
    $this->seed(CatalogModuleSeeder::class);
});

function variantsProduct(): Product
{
    return catalogProduct(['has_variants' => true], [
        ['sku' => 'V-S', 'price' => 1000, 'options' => ['Size' => 'S']],
        ['sku' => 'V-M', 'price' => 1000, 'options' => ['Size' => 'M']],
    ]);
}

function uploadProductImage($test, Product $product, array $extra = [])
{
    return $test->post("/api/v1/products/{$product->id}/images", $extra + ['image' => UploadedFile::fake()->image('p.jpg', 800, 800)], ['Accept' => 'application/json']);
}

it('requires authentication and permissions on variant and image routes', function () {
    $product = variantsProduct();
    $variant = $product->variants()->first();

    $this->getJson("/api/v1/products/{$product->id}/variants")->assertUnauthorized();
    $this->deleteJson("/api/v1/variants/{$variant->id}")->assertUnauthorized();

    actingAsRole('viewer', ['products.view']);
    $this->getJson("/api/v1/products/{$product->id}/variants")->assertOk()->assertJsonCount(2, 'data');
    $this->getJson("/api/v1/variants/{$variant->id}")->assertOk();
    $this->postJson("/api/v1/products/{$product->id}/variants", [])->assertForbidden();
    $this->patchJson("/api/v1/variants/{$variant->id}", ['price' => 5])->assertForbidden();
    $this->deleteJson("/api/v1/variants/{$variant->id}")->assertForbidden();
    uploadProductImage($this, $product)->assertForbidden();
});

it('adds, updates and deletes variants', function () {
    actingAsRole('editor', ['products.view', 'products.update']);
    $product = variantsProduct();

    $id = $this->postJson("/api/v1/products/{$product->id}/variants", ['sku' => 'V-L', 'price' => 1500, 'compare_at_price' => 2000, 'options' => ['Size' => 'L']])
        ->assertCreated()->assertJsonPath('data.price.amount', 1500)->assertJsonPath('data.compare_at_price.amount', 2000)->json('data.id');

    $this->patchJson("/api/v1/variants/{$id}", ['price' => 1800, 'is_active' => false])
        ->assertOk()->assertJsonPath('data.price.amount', 1800)->assertJsonPath('data.is_active', false);
    $this->patchJson("/api/v1/variants/{$id}", ['price' => 2500])
        ->assertInvalidFields(['compare_at_price']);
    $this->patchJson("/api/v1/variants/{$id}", ['sku' => 'V-S'])->assertInvalidFields(['sku']);
    $this->patchJson("/api/v1/variants/{$id}", ['sku' => 'V-L'])->assertOk();

    $this->deleteJson("/api/v1/variants/{$id}")->assertNoContent();
    $this->getJson("/api/v1/variants/{$id}")->assertNotFound();
});

it('rejects duplicate or inconsistent options and a variant on a simple product', function () {
    actingAsRole('editor', ['products.update']);
    $product = variantsProduct();

    $this->postJson("/api/v1/products/{$product->id}/variants", ['sku' => 'X1', 'price' => 1, 'options' => ['Size' => 'S']])
        ->assertInvalidFields(['options']);
    $this->postJson("/api/v1/products/{$product->id}/variants", ['sku' => 'X2', 'price' => 1, 'options' => ['Colour' => 'Red']])
        ->assertInvalidFields(['options']);
    $this->postJson("/api/v1/products/{$product->id}/variants", ['sku' => 'X3', 'price' => 1])
        ->assertInvalidFields(['options']);

    $v = $product->variants()->where('sku', 'V-M')->first();
    $this->patchJson("/api/v1/variants/{$v->id}", ['options' => ['Size' => 'S']])->assertInvalidFields(['options']);

    $simple = catalogProduct();
    $this->postJson("/api/v1/products/{$simple->id}/variants", ['sku' => 'X4', 'price' => 1])
        ->assertInvalidFields(['options']);
});

it('never deletes the last variant of a product', function () {
    actingAsRole('editor', ['products.update']);
    $simple = catalogProduct();
    $multi = catalogProduct(['has_variants' => true], [['sku' => 'ONLY', 'price' => 1, 'options' => ['Size' => 'S']]]);

    $this->deleteJson('/api/v1/variants/'.$simple->variants()->first()->id)->assertInvalidFields(['variant']);
    $this->deleteJson('/api/v1/variants/'.$multi->variants()->first()->id)->assertInvalidFields(['variant']);
});

it('makes the first image primary and lets another take over', function () {
    actingAsRole('editor', ['products.update']);
    $product = catalogProduct();
    $variant = $product->variants()->first();

    $first = uploadProductImage($this, $product, ['alt' => 'Front', 'variant_id' => $variant->id])->assertCreated()
        ->assertJsonPath('data.is_primary', true)->assertJsonPath('data.alt', 'Front')->json('data.id');
    $second = uploadProductImage($this, $product)->assertCreated()->assertJsonPath('data.is_primary', false)->json('data.id');

    expect(ProductImage::find($first)->path)->toMatch('#^products/[a-z0-9]{32}/original\.jpg$#');

    $this->patchJson("/api/v1/product-images/{$second}", ['is_primary' => true])->assertOk()->assertJsonPath('data.is_primary', true);
    expect(ProductImage::find($first)->is_primary)->toBeFalse()
        ->and(ProductImage::where('product_id', $product->id)->where('is_primary', true)->count())->toBe(1);

    $this->patchJson("/api/v1/product-images/{$second}", ['is_primary' => false])->assertInvalidFields(['is_primary']);

    // A variant of another product is refused.
    $other = catalogProduct();
    $this->patchJson("/api/v1/product-images/{$second}", ['variant_id' => $other->variants()->first()->id])
        ->assertInvalidFields(['variant_id']);
});

it('validates image uploads', function () {
    actingAsRole('editor', ['products.update']);
    $product = catalogProduct();

    uploadProductImage($this, $product, ['image' => UploadedFile::fake()->create('x.pdf', 10, 'application/pdf')])
        ->assertInvalidFields(['image']);
    $this->post("/api/v1/products/{$product->id}/images", [], ['Accept' => 'application/json'])->assertInvalidFields(['image']);
});

it('promotes the next image when the primary one is deleted and keeps one on an active product', function () {
    actingAsRole('editor', ['products.update']);
    $product = catalogProduct();

    $first = uploadProductImage($this, $product)->json('data.id');
    $second = uploadProductImage($this, $product)->json('data.id');
    $path = ProductImage::find($first)->path;

    $this->deleteJson("/api/v1/product-images/{$first}")->assertNoContent();
    Storage::disk('uploads')->assertMissing($path);
    expect(ProductImage::find($second)->is_primary)->toBeTrue();

    $product->forceFill(['status' => Product::ACTIVE])->save();
    $this->deleteJson("/api/v1/product-images/{$second}")->assertInvalidFields(['image']);

    $product->forceFill(['status' => Product::DRAFT])->save();
    $this->deleteJson("/api/v1/product-images/{$second}")->assertNoContent();
});

it('reorders images of one product only', function () {
    actingAsRole('editor', ['products.update']);
    $product = catalogProduct();
    $a = uploadProductImage($this, $product)->json('data.id');
    $b = uploadProductImage($this, $product)->json('data.id');
    $foreign = uploadProductImage($this, catalogProduct())->json('data.id');

    $this->putJson("/api/v1/products/{$product->id}/images/reorder", ['ids' => [$b, $a]])->assertOk();
    expect(ProductImage::find($b)->sort_order)->toBeLessThan(ProductImage::find($a)->sort_order);

    $this->putJson("/api/v1/products/{$product->id}/images/reorder", ['ids' => [$b, $foreign]])
        ->assertInvalidFields(['ids']);
});
