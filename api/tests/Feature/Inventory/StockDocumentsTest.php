<?php

use App\Models\Menu;
use App\Models\StockDocument;
use App\Models\StockLevel;
use App\Models\StockMovement;
use App\Models\Supplier;
use Database\Seeders\StockDocumentsModuleSeeder;
use Spatie\Permission\Models\Permission;

const SD_MODULES = ['damages', 'supplier-returns', 'adjustments', 'stock-counts'];

/** @return list<string> */
function sdPermissions(): array
{
    $names = [];
    foreach (SD_MODULES as $module) {
        foreach (['view', 'create', 'update', 'delete', 'post'] as $action) {
            $names[] = "{$module}.{$action}";
        }
    }

    return $names;
}

function sdVariant(string $sku, ?int $cost = 500): int
{
    return catalogProduct([], [['sku' => $sku, 'price' => 99000, 'cost_price' => $cost]])->variants()->firstOrFail()->id;
}

/** Put stock on a variant through a posted adjustment. */
function sdStock($test, int $variantId, int $quantity): void
{
    $id = $test->postJson('/api/v1/adjustments', ['reason' => 'opening', 'lines' => [['variant_id' => $variantId, 'quantity' => $quantity]]])
        ->assertCreated()->json('data.id');
    $test->postJson("/api/v1/adjustments/{$id}/post")->assertOk();
}

function onHand(int $variantId): int
{
    return StockLevel::query()->find($variantId)?->on_hand ?? 0;
}

beforeEach(function () {
    $this->seed(StockDocumentsModuleSeeder::class);
});

it('requires authentication and the permission of the document type', function () {
    $this->getJson('/api/v1/damages')->assertUnauthorized();
    $this->postJson('/api/v1/adjustments/1/post')->assertUnauthorized();

    actingAsRole('viewer', ['damages.view', 'stock-counts.view']);
    $this->getJson('/api/v1/damages')->assertOk();
    $this->getJson('/api/v1/stock-counts')->assertOk();
    $this->getJson('/api/v1/adjustments')->assertForbidden();
    $this->getJson('/api/v1/supplier-returns')->assertForbidden();
    $this->postJson('/api/v1/damages', ['reason' => 'x'])->assertForbidden();
});

it('cannot reach a document of another type through a permitted route', function () {
    actingAsRole('clerk', sdPermissions());
    $id = $this->postJson('/api/v1/adjustments', ['reason' => 'x'])->assertCreated()->json('data.id');

    $this->getJson("/api/v1/damages/{$id}")->assertNotFound();
    $this->patchJson("/api/v1/stock-counts/{$id}", ['notes' => 'x'])->assertNotFound();
    $this->postJson("/api/v1/supplier-returns/{$id}/post")->assertNotFound();
    $this->getJson("/api/v1/adjustments/{$id}")->assertOk();

    // Permission follows the document's real type, not the URL.
    actingAsRole('damage-only', ['damages.view', 'damages.post']);
    $this->getJson("/api/v1/adjustments/{$id}")->assertForbidden();
    $this->postJson("/api/v1/damages/{$id}/post")->assertNotFound();
});

it('writes off damaged stock on post and keeps drafts out of stock', function () {
    $user = actingAsRole('clerk', sdPermissions());
    $v = sdVariant('D1');
    sdStock($this, $v, 10);

    $response = $this->postJson('/api/v1/damages', ['reason' => 'Water damage', 'lines' => [['variant_id' => $v, 'quantity' => 3]]])->assertCreated();
    $id = $response->json('data.id');

    expect($response->json('data.number'))->toMatch('/^DM-\d{6}$/')
        ->and($response->json('data.status'))->toBe('draft')
        ->and($response->json('data.lines.0.unit_cost'))->toBe(['amount' => 500, 'currency' => 'BDT'])
        ->and(onHand($v))->toBe(10);

    $this->postJson("/api/v1/damages/{$id}/post")->assertOk()->assertJsonPath('data.status', 'posted');

    $movement = StockMovement::query()->where('type', 'damage')->sole();
    expect($movement->quantity)->toBe(-3)
        ->and($movement->balance_after)->toBe(7)
        ->and($movement->note)->toBe('Water damage')
        ->and($movement->reference_type)->toBe('stock_documents')
        ->and($movement->reference_id)->toBe($id)
        ->and($movement->created_by)->toBe($user->id)
        ->and(onHand($v))->toBe(7);

    // Posted documents are immutable.
    $this->patchJson("/api/v1/damages/{$id}", ['reason' => 'late'])->assertInvalidFields(['status']);
    $this->deleteJson("/api/v1/damages/{$id}")->assertInvalidFields(['status']);
    $this->postJson("/api/v1/damages/{$id}/post")->assertInvalidFields(['status']);
});

it('rejects a post that would take stock below zero and writes nothing', function () {
    actingAsRole('clerk', sdPermissions());
    $a = sdVariant('N1');
    $b = sdVariant('N2');
    sdStock($this, $a, 5);
    sdStock($this, $b, 1);
    $before = StockMovement::query()->count();

    $id = $this->postJson('/api/v1/damages', ['reason' => 'x', 'lines' => [
        ['variant_id' => $a, 'quantity' => 5],
        ['variant_id' => $b, 'quantity' => 2],
    ]])->json('data.id');

    $this->postJson("/api/v1/damages/{$id}/post")->assertInvalidFields(['lines']);

    expect(onHand($a))->toBe(5)
        ->and(onHand($b))->toBe(1)
        ->and(StockMovement::query()->count())->toBe($before)
        ->and(StockDocument::query()->find($id)->status)->toBe('draft');
});

it('returns goods to a supplier', function () {
    actingAsRole('clerk', sdPermissions());
    $supplier = Supplier::create(['name' => 'Acme', 'code' => 'ACME']);
    $v = sdVariant('R1');
    sdStock($this, $v, 8);

    $this->postJson('/api/v1/supplier-returns', ['lines' => []])->assertInvalidFields(['supplier_id']);

    $id = $this->postJson('/api/v1/supplier-returns', ['supplier_id' => $supplier->id, 'lines' => [['variant_id' => $v, 'quantity' => 2, 'unit_cost' => 450]]])
        ->assertCreated()->assertJsonPath('data.supplier.name', 'Acme')->assertJsonPath('data.number', fn ($n) => str_starts_with($n, 'SR-'))
        ->json('data.id');
    $this->postJson("/api/v1/supplier-returns/{$id}/post")->assertOk();

    $movement = StockMovement::query()->where('type', 'return_to_supplier')->sole();
    expect($movement->quantity)->toBe(-2)->and($movement->unit_cost)->toBe(450)->and(onHand($v))->toBe(6);

    $inactive = Supplier::create(['name' => 'Old', 'code' => 'OLD', 'is_active' => false]);
    $this->postJson('/api/v1/supplier-returns', ['supplier_id' => $inactive->id])->assertInvalidFields(['supplier_id']);
});

it('applies signed adjustments and rejects zero quantity and a missing reason', function () {
    actingAsRole('clerk', sdPermissions());
    $v = sdVariant('A1');
    sdStock($this, $v, 10);

    $this->postJson('/api/v1/adjustments', ['lines' => [['variant_id' => $v, 'quantity' => 0]]])->assertInvalidFields(['reason', 'lines.0.quantity']);
    $this->postJson('/api/v1/adjustments', ['reason' => 'x', 'supplier_id' => 1])->assertInvalidFields(['supplier_id']);

    $id = $this->postJson('/api/v1/adjustments', ['reason' => 'Found in aisle 4', 'lines' => [['variant_id' => $v, 'quantity' => -4]]])->json('data.id');
    $this->postJson("/api/v1/adjustments/{$id}/post")->assertOk();

    expect(onHand($v))->toBe(6)
        ->and(StockMovement::query()->where('type', 'adjustment')->orderByDesc('id')->first()->quantity)->toBe(-4);
});

it('posts a stock count as variance movements and records expected quantities', function () {
    actingAsRole('clerk', sdPermissions());
    $a = sdVariant('C1');
    $b = sdVariant('C2');
    $c = sdVariant('C3');
    sdStock($this, $a, 10);
    sdStock($this, $b, 4);
    $movements = StockMovement::query()->count();

    $this->postJson('/api/v1/stock-counts', ['reason' => 'x'])->assertInvalidFields(['reason']);
    $this->postJson('/api/v1/stock-counts', ['lines' => [['variant_id' => $a, 'quantity' => -1]]])->assertInvalidFields(['lines.0.quantity']);

    $id = $this->postJson('/api/v1/stock-counts', ['notes' => 'Q4 count', 'lines' => [
        ['variant_id' => $a, 'quantity' => 7],  // 3 short
        ['variant_id' => $b, 'quantity' => 4],  // matches: no movement
        ['variant_id' => $c, 'quantity' => 2],  // never stocked: +2
    ]])->assertCreated()->assertJsonPath('data.lines.0.variance', null)->json('data.id');

    expect(onHand($a))->toBe(10);

    $response = $this->postJson("/api/v1/stock-counts/{$id}/post")->assertOk();
    expect(collect($response->json('data.lines'))->pluck('variance', 'sku')->all())->toBe(['C1' => -3, 'C2' => 0, 'C3' => 2])
        ->and(collect($response->json('data.lines'))->pluck('expected_quantity', 'sku')->all())->toBe(['C1' => 10, 'C2' => 4, 'C3' => 0])
        ->and(onHand($a))->toBe(7)
        ->and(onHand($b))->toBe(4)
        ->and(onHand($c))->toBe(2)
        ->and(StockMovement::query()->count())->toBe($movements + 2)
        ->and(StockMovement::query()->where('type', 'count')->pluck('quantity')->sort()->values()->all())->toBe([-3, 2]);
});

it('replaces lines on update, validates lines and deletes drafts', function () {
    actingAsRole('clerk', sdPermissions());
    $a = sdVariant('U1');
    $b = sdVariant('U2');

    $id = $this->postJson('/api/v1/damages', ['reason' => 'x', 'lines' => [['variant_id' => $a, 'quantity' => 1]]])->json('data.id');

    $this->patchJson("/api/v1/damages/{$id}", ['lines' => [['variant_id' => $b, 'quantity' => 2]]])
        ->assertOk()->assertJsonCount(1, 'data.lines')->assertJsonPath('data.lines.0.sku', 'U2')->assertJsonPath('data.reason', 'x');
    $this->patchJson("/api/v1/damages/{$id}", ['reason' => ''])->assertInvalidFields(['reason']);
    $this->postJson('/api/v1/damages', ['reason' => 'x', 'lines' => [['variant_id' => $a, 'quantity' => 0]]])->assertInvalidFields(['lines.0.quantity']);
    $this->postJson('/api/v1/damages', ['reason' => 'x', 'lines' => [['variant_id' => 99999, 'quantity' => 1]]])->assertInvalidFields(['lines.0.variant_id']);
    $this->postJson('/api/v1/damages', ['reason' => 'x', 'lines' => [['variant_id' => $a, 'quantity' => 1], ['variant_id' => $a, 'quantity' => 1]]])
        ->assertInvalidFields(['lines.0.variant_id', 'lines.1.variant_id']);

    $this->getJson('/api/v1/damages?status=posted')->assertJsonCount(0, 'data');
    $this->getJson('/api/v1/damages')->assertJsonCount(1, 'data');

    $this->deleteJson("/api/v1/damages/{$id}")->assertNoContent();
    expect(StockDocument::query()->count())->toBe(0);
});

it('refuses to post an empty document', function () {
    actingAsRole('clerk', sdPermissions());
    $id = $this->postJson('/api/v1/adjustments', ['reason' => 'x'])->json('data.id');

    $this->postJson("/api/v1/adjustments/{$id}/post")->assertInvalidFields(['lines']);
});

it('seeds the stock document permissions and menus idempotently', function () {
    $this->seed(StockDocumentsModuleSeeder::class);

    expect(Permission::query()->where('name', 'like', 'stock-counts.%')->count())->toBe(5)
        ->and(Menu::query()->where('path', '/supplier-returns')->count())->toBe(1);
});
