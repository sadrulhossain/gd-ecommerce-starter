<?php

use App\Models\Brand;
use App\Models\Menu;
use App\Models\ProductCategory;
use Database\Seeders\CatalogModuleSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    Storage::fake('uploads');
    $this->seed(CatalogModuleSeeder::class);
});

it('requires authentication and the module permission', function () {
    foreach (['product-categories', 'brands'] as $path) {
        $this->getJson("/api/v1/{$path}")->assertUnauthorized();
    }

    actingAsRole('nobody');
    foreach (['product-categories', 'brands'] as $path) {
        $this->getJson("/api/v1/{$path}")->assertForbidden();
        $this->postJson("/api/v1/{$path}", ['name' => 'X'])->assertForbidden();
    }
});

it('creates a category with a generated slug and keeps it unique', function () {
    actingAsRole('editor', ['product-categories.create']);

    $this->postJson('/api/v1/product-categories', ['name' => 'Home & Living'])
        ->assertCreated()->assertJsonPath('data.slug', 'home-living')->assertJsonPath('data.is_active', true);
    $this->postJson('/api/v1/product-categories', ['name' => 'Home & Living'])
        ->assertCreated()->assertJsonPath('data.slug', 'home-living-2');
    $this->postJson('/api/v1/product-categories', ['name' => 'Other', 'slug' => 'home-living'])
        ->assertInvalidFields(['slug']);
    $this->postJson('/api/v1/product-categories', [])->assertInvalidFields(['name']);
});

it('limits the category tree to three levels and refuses cycles', function () {
    actingAsRole('editor', ['product-categories.create', 'product-categories.update']);

    $a = ProductCategory::create(['name' => 'A', 'slug' => 'a']);
    $b = ProductCategory::create(['name' => 'B', 'slug' => 'b', 'parent_id' => $a->id]);
    $c = ProductCategory::create(['name' => 'C', 'slug' => 'c', 'parent_id' => $b->id]);

    $this->postJson('/api/v1/product-categories', ['name' => 'D', 'parent_id' => $c->id])
        ->assertInvalidFields(['parent_id']);
    $this->patchJson("/api/v1/product-categories/{$a->id}", ['parent_id' => $c->id])
        ->assertInvalidFields(['parent_id']);
    $this->patchJson("/api/v1/product-categories/{$a->id}", ['parent_id' => $a->id])
        ->assertInvalidFields(['parent_id']);

    // Moving the B subtree (2 levels) under a new root is fine; under C (level 3) it is not.
    $root = ProductCategory::create(['name' => 'R', 'slug' => 'r']);
    $this->patchJson("/api/v1/product-categories/{$b->id}", ['parent_id' => $root->id])->assertOk();
    $this->patchJson("/api/v1/product-categories/{$root->id}", ['parent_id' => $c->id])
        ->assertInvalidFields(['parent_id']);
});

it('refuses to delete a category with children or products', function () {
    actingAsRole('editor', ['product-categories.delete']);

    $parent = ProductCategory::create(['name' => 'P', 'slug' => 'p']);
    $child = ProductCategory::create(['name' => 'Ch', 'slug' => 'ch', 'parent_id' => $parent->id]);

    $this->deleteJson("/api/v1/product-categories/{$parent->id}")->assertInvalidFields(['category']);

    catalogProduct(['category_id' => $child->id]);
    $this->deleteJson("/api/v1/product-categories/{$child->id}")->assertInvalidFields(['category']);

    $empty = ProductCategory::create(['name' => 'E', 'slug' => 'e']);
    $this->deleteJson("/api/v1/product-categories/{$empty->id}")->assertNoContent();
});

it('reorders categories', function () {
    actingAsRole('editor', ['product-categories.update', 'product-categories.view']);
    $a = ProductCategory::create(['name' => 'A', 'slug' => 'a']);
    $b = ProductCategory::create(['name' => 'B', 'slug' => 'b']);

    $this->putJson('/api/v1/product-categories/reorder', ['ids' => [$b->id, $a->id]])->assertOk();
    expect($b->refresh()->sort_order)->toBeLessThan($a->refresh()->sort_order);
    $this->putJson('/api/v1/product-categories/reorder', ['ids' => [999999]])->assertInvalidFields(['ids.0']);
});

it('serves the active category tree publicly', function () {
    $root = ProductCategory::create(['name' => 'Root', 'slug' => 'root', 'sort_order' => 1]);
    ProductCategory::create(['name' => 'Kid', 'slug' => 'kid', 'parent_id' => $root->id]);
    ProductCategory::create(['name' => 'Hidden kid', 'slug' => 'hidden-kid', 'parent_id' => $root->id, 'is_active' => false]);
    $off = ProductCategory::create(['name' => 'Off', 'slug' => 'off', 'is_active' => false]);
    ProductCategory::create(['name' => 'Under off', 'slug' => 'under-off', 'parent_id' => $off->id]);

    $this->getJson('/api/v1/public/product-categories')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonCount(1, 'data.0.children')
        ->assertJsonPath('data.0.children.0.slug', 'kid');
});

it('manages brands, uploads a logo and keeps products when a brand is deleted', function () {
    actingAsRole('editor', ['brands.view', 'brands.create', 'brands.update', 'brands.delete']);

    $id = $this->postJson('/api/v1/brands', ['name' => 'Acme Co'])->assertCreated()->assertJsonPath('data.slug', 'acme-co')->json('data.id');
    $this->postJson('/api/v1/brands', ['name' => 'Dup', 'slug' => 'acme-co'])->assertInvalidFields(['slug']);
    $this->patchJson("/api/v1/brands/{$id}", ['is_active' => false])->assertOk()->assertJsonPath('data.is_active', false);

    $this->post("/api/v1/brands/{$id}/image", ['image' => UploadedFile::fake()->image('logo.png', 300, 300)], ['Accept' => 'application/json'])
        ->assertOk();
    expect(Brand::find($id)->image)->toStartWith('brands/');
    $this->post("/api/v1/brands/{$id}/image", ['image' => UploadedFile::fake()->create('x.pdf', 10, 'application/pdf')], ['Accept' => 'application/json'])
        ->assertInvalidFields(['image']);

    $product = catalogProduct(['brand_id' => $id]);
    $this->deleteJson("/api/v1/brands/{$id}")->assertNoContent();
    expect($product->refresh()->brand_id)->toBeNull();
});

it('lists only active brands publicly', function () {
    Brand::create(['name' => 'On', 'slug' => 'on']);
    Brand::create(['name' => 'Off', 'slug' => 'off', 'is_active' => false]);

    $this->getJson('/api/v1/public/brands')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.slug', 'on');
});

it('seeds permissions, menus and admin grants idempotently', function () {
    $this->seed(CatalogModuleSeeder::class);

    expect(Menu::whereIn('permission_name', ['products.view', 'product-categories.view', 'brands.view'])->count())->toBe(3)
        ->and(Role::findByName('admin', 'api')->hasAllPermissions(['products.publish', 'brands.delete', 'product-categories.update']))->toBeTrue();
});
