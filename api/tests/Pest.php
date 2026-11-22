<?php

use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Passport\Passport;
use PHPUnit\Framework\Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

pest()->extend(TestCase::class)->use(RefreshDatabase::class)->in('Feature');

/**
 * Create a user holding a role with the given permissions and authenticate as it on the api guard.
 *
 * @param  list<string>  $permissions
 */
function actingAsRole(string $role, array $permissions = []): User
{
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $role = Role::findOrCreate($role, 'api');

    foreach ($permissions as $name) {
        $role->givePermissionTo(Permission::findOrCreate($name, 'api'));
    }

    $user = User::factory()->create()->assignRole($role);

    Passport::actingAs($user, [], 'api');

    return $user;
}

/**
 * A product with one default variant (or the given variants), in a fresh category. Status defaults to draft.
 *
 * @param  array<string, mixed>  $attributes
 * @param  list<array<string, mixed>>  $variants
 */
function catalogProduct(array $attributes = [], array $variants = []): Product
{
    static $n = 0;
    $n++;

    $product = Product::query()->create($attributes + [
        'category_id' => $attributes['category_id'] ?? ProductCategory::query()->create(['name' => "Cat {$n}", 'slug' => "cat-{$n}"])->id,
        'name' => "Product {$n}",
        'slug' => "product-{$n}",
        'has_variants' => false,
    ]);

    foreach ($variants ?: [['sku' => "SKU-{$n}", 'price' => 10000]] as $variant) {
        $product->variants()->create($variant);
    }

    if (isset($attributes['status'])) {
        $product->forceFill(['status' => $attributes['status']])->save();
    }

    return $product;
}

/** 422 whose `error.details` (the API's error shape) contains every given field key, e.g. `variants.0.sku`. */
TestResponse::macro('assertInvalidFields', function (array $fields) {
    /** @var TestResponse $this */
    $this->assertUnprocessable();
    $details = $this->json('error.details') ?? [];

    foreach ($fields as $field) {
        Assert::assertArrayHasKey($field, $details, "No validation error for [{$field}]. Got: ".implode(', ', array_keys($details)));
    }

    return $this;
});
