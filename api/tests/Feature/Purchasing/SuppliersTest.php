<?php

use App\Models\Menu;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use Database\Seeders\PurchasingModuleSeeder;
use Spatie\Permission\Models\Role;

beforeEach(fn () => $this->seed(PurchasingModuleSeeder::class));

it('requires authentication and the module permission', function () {
    $supplier = Supplier::create(['name' => 'Acme', 'code' => 'ACME']);

    $this->getJson('/api/v1/suppliers')->assertUnauthorized();
    $this->postJson('/api/v1/suppliers', [])->assertUnauthorized();

    actingAsRole('nobody');
    $this->getJson('/api/v1/suppliers')->assertForbidden();
    $this->getJson("/api/v1/suppliers/{$supplier->id}")->assertForbidden();
    $this->postJson('/api/v1/suppliers', ['name' => 'X', 'code' => 'X'])->assertForbidden();
    $this->patchJson("/api/v1/suppliers/{$supplier->id}", ['name' => 'Y'])->assertForbidden();
    $this->deleteJson("/api/v1/suppliers/{$supplier->id}")->assertForbidden();
});

it('creates, shows and updates a supplier', function () {
    actingAsRole('buyer', ['suppliers.view', 'suppliers.create', 'suppliers.update']);

    $id = $this->postJson('/api/v1/suppliers', ['name' => 'Acme Trading', 'code' => 'ACME-01', 'email' => 'buy@acme.test', 'payment_terms' => 'Net 30'])
        ->assertCreated()->assertJsonPath('data.is_active', true)->assertJsonPath('data.payment_terms', 'Net 30')->json('data.id');

    $this->getJson("/api/v1/suppliers/{$id}")->assertOk()->assertJsonPath('data.code', 'ACME-01');
    $this->patchJson("/api/v1/suppliers/{$id}", ['phone' => '0123', 'is_active' => false])
        ->assertOk()->assertJsonPath('data.phone', '0123')->assertJsonPath('data.is_active', false);
    $this->patchJson("/api/v1/suppliers/{$id}", ['code' => 'ACME-01'])->assertOk();
});

it('validates suppliers', function () {
    actingAsRole('buyer', ['suppliers.create']);
    Supplier::create(['name' => 'Taken', 'code' => 'TAKEN']);

    $this->postJson('/api/v1/suppliers', [])->assertInvalidFields(['name', 'code']);
    $this->postJson('/api/v1/suppliers', ['name' => 'X', 'code' => 'TAKEN'])->assertInvalidFields(['code']);
    $this->postJson('/api/v1/suppliers', ['name' => 'X', 'code' => 'bad code!', 'email' => 'nope'])->assertInvalidFields(['code', 'email']);
});

it('lists and searches suppliers', function () {
    actingAsRole('buyer', ['suppliers.view']);
    Supplier::create(['name' => 'Zed Wholesale', 'code' => 'ZED', 'email' => 'sales@zed.test']);
    Supplier::create(['name' => 'Alpha Imports', 'code' => 'ALP', 'is_active' => false]);

    $this->getJson('/api/v1/suppliers')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('data.0.code', 'ALP');
    $this->getJson('/api/v1/suppliers?q=zed')->assertOk()->assertJsonCount(1, 'data');
    $this->getJson('/api/v1/suppliers?q=sales@')->assertOk()->assertJsonCount(1, 'data');
    $this->getJson('/api/v1/suppliers?q=%25')->assertOk()->assertJsonCount(0, 'data');
    $this->getJson('/api/v1/suppliers?is_active=0')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.code', 'ALP');
});

it('deletes a supplier without orders but not one with orders', function () {
    actingAsRole('buyer', ['suppliers.delete']);
    $free = Supplier::create(['name' => 'Free', 'code' => 'FREE']);
    $used = Supplier::create(['name' => 'Used', 'code' => 'USED']);
    $order = new PurchaseOrder(['supplier_id' => $used->id]);
    $order->number = PurchaseOrder::nextNumber();
    $order->save();

    $this->deleteJson("/api/v1/suppliers/{$used->id}")->assertInvalidFields(['supplier']);
    $this->deleteJson("/api/v1/suppliers/{$free->id}")->assertNoContent();
    expect(Supplier::withTrashed()->find($free->id)->trashed())->toBeTrue();
});

it('seeds permissions, menus and admin grants idempotently', function () {
    $this->seed(PurchasingModuleSeeder::class);

    expect(Menu::whereIn('permission_name', ['purchase-orders.view', 'suppliers.view'])->count())->toBe(2)
        ->and(Role::findByName('admin', 'api')->hasAllPermissions(['suppliers.delete', 'purchase-orders.order', 'purchase-orders.cancel']))->toBeTrue();
});
