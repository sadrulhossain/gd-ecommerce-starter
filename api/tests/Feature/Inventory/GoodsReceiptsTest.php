<?php

use App\Models\GoodsReceipt;
use App\Models\Menu;
use App\Models\PurchaseOrder;
use App\Models\StockLevel;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Services\StockService;
use Database\Seeders\PurchasingModuleSeeder;
use Database\Seeders\StockModuleSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;

const GR_ALL = ['goods-receipts.view', 'goods-receipts.create', 'goods-receipts.update', 'goods-receipts.delete', 'goods-receipts.post', 'stock.view'];

beforeEach(function () {
    $this->seed([PurchasingModuleSeeder::class, StockModuleSeeder::class]);
    $this->supplier = Supplier::create(['name' => 'Acme', 'code' => 'ACME']);
});

/** An ordered purchase order: A x10 at 1000 and B x5 at 2000. Returns [order, lineA, lineB]. */
function orderedOrder($test, string $suffix = ''): array
{
    $order = new PurchaseOrder(['supplier_id' => $test->supplier->id]);
    $order->number = PurchaseOrder::nextNumber();
    $order->save();

    $lines = [];
    foreach ([['A', 10, 1000], ['B', 5, 2000]] as [$sku, $qty, $cost]) {
        $variant = catalogProduct([], [['sku' => $sku.$suffix, 'price' => 99000]])->variants()->firstOrFail();
        $lines[] = $order->lines()->create([
            'variant_id' => $variant->id, 'sku' => $sku, 'name' => $sku, 'quantity_ordered' => $qty,
            'unit_cost' => $cost, 'line_total' => $qty * $cost,
        ]);
    }

    $order->forceFill(['status' => PurchaseOrder::ORDERED])->save();

    return [$order, ...$lines];
}

it('requires authentication and the module permissions', function () {
    [$order, $a] = orderedOrder($this);
    $receipt = new GoodsReceipt(['notes' => 'x']);
    $receipt->number = GoodsReceipt::nextNumber();
    $receipt->purchase_order_id = $order->id;
    $receipt->save();

    $this->getJson('/api/v1/goods-receipts')->assertUnauthorized();
    $this->postJson("/api/v1/goods-receipts/{$receipt->id}/post")->assertUnauthorized();
    $this->getJson('/api/v1/stock/levels')->assertUnauthorized();
    $this->getJson('/api/v1/stock/movements')->assertUnauthorized();

    actingAsRole('viewer', ['goods-receipts.view']);
    $this->getJson('/api/v1/goods-receipts')->assertOk();
    $this->getJson("/api/v1/goods-receipts/{$receipt->id}")->assertOk();
    $this->postJson('/api/v1/goods-receipts', ['purchase_order_id' => $order->id])->assertForbidden();
    $this->patchJson("/api/v1/goods-receipts/{$receipt->id}", ['notes' => 'y'])->assertForbidden();
    $this->deleteJson("/api/v1/goods-receipts/{$receipt->id}")->assertForbidden();
    $this->postJson("/api/v1/goods-receipts/{$receipt->id}/post")->assertForbidden();
    $this->getJson('/api/v1/stock/levels')->assertForbidden();
    $this->getJson('/api/v1/stock/movements')->assertForbidden();
});

it('creates a draft that does not touch stock and defaults the cost from the order line', function () {
    actingAsRole('clerk', GR_ALL);
    [$order, $a] = orderedOrder($this);

    $response = $this->postJson('/api/v1/goods-receipts', [
        'purchase_order_id' => $order->id,
        'delivery_note' => 'DN-77',
        'lines' => [['purchase_order_line_id' => $a->id, 'quantity' => 4]],
    ])->assertCreated();

    expect($response->json('data.number'))->toMatch('/^GR-\d{6}$/')
        ->and($response->json('data.status'))->toBe('draft')
        ->and($response->json('data.purchase_order_number'))->toBe($order->number)
        ->and($response->json('data.lines.0.unit_cost'))->toBe(['amount' => 1000, 'currency' => 'BDT'])
        ->and(StockLevel::query()->count())->toBe(0)
        ->and(StockMovement::query()->count())->toBe(0);
});

it('rejects receipts for orders that are not open, foreign lines and over-receiving', function () {
    actingAsRole('clerk', GR_ALL);
    [$order, $a] = orderedOrder($this);
    [, $foreign] = orderedOrder($this, '-X');

    $draft = new PurchaseOrder(['supplier_id' => $this->supplier->id]);
    $draft->number = PurchaseOrder::nextNumber();
    $draft->save();

    $this->postJson('/api/v1/goods-receipts', ['purchase_order_id' => $draft->id])->assertInvalidFields(['purchase_order_id']);
    $this->postJson('/api/v1/goods-receipts', [])->assertInvalidFields(['purchase_order_id']);
    $this->postJson('/api/v1/goods-receipts', ['purchase_order_id' => $order->id, 'lines' => [['purchase_order_line_id' => $a->id, 'quantity' => 0]]])
        ->assertInvalidFields(['lines.0.quantity']);
    $this->postJson('/api/v1/goods-receipts', ['purchase_order_id' => $order->id, 'lines' => [['purchase_order_line_id' => $foreign->id, 'quantity' => 1]]])
        ->assertInvalidFields(['lines.0.purchase_order_line_id']);
    $this->postJson('/api/v1/goods-receipts', ['purchase_order_id' => $order->id, 'lines' => [['purchase_order_line_id' => $a->id, 'quantity' => 11]]])
        ->assertInvalidFields(['lines.0.quantity']);

    expect(GoodsReceipt::query()->count())->toBe(0);
});

it('posts a partial receipt: ledger row, level, order quantities and status', function () {
    $user = actingAsRole('clerk', GR_ALL);
    [$order, $a, $b] = orderedOrder($this);

    $id = $this->postJson('/api/v1/goods-receipts', [
        'purchase_order_id' => $order->id,
        'lines' => [['purchase_order_line_id' => $a->id, 'quantity' => 4, 'unit_cost' => 1100]],
    ])->assertCreated()->json('data.id');

    $this->postJson("/api/v1/goods-receipts/{$id}/post")->assertOk()
        ->assertJsonPath('data.status', 'posted')->assertJsonPath('data.lines.0.unit_cost.amount', 1100);

    $movement = StockMovement::query()->sole();
    expect($movement->type)->toBe('receipt')
        ->and($movement->quantity)->toBe(4)
        ->and($movement->balance_after)->toBe(4)
        ->and($movement->unit_cost)->toBe(1100)
        ->and($movement->reference_type)->toBe('goods_receipts')
        ->and($movement->reference_id)->toBe($id)
        ->and($movement->created_by)->toBe($user->id)
        ->and(StockLevel::query()->find($a->variant_id)->on_hand)->toBe(4)
        ->and($a->refresh()->quantity_received)->toBe(4)
        ->and($order->refresh()->status)->toBe('partially_received');

    // A posted receipt is immutable.
    $this->patchJson("/api/v1/goods-receipts/{$id}", ['notes' => 'late'])->assertInvalidFields(['status']);
    $this->deleteJson("/api/v1/goods-receipts/{$id}")->assertInvalidFields(['status']);
    $this->postJson("/api/v1/goods-receipts/{$id}/post")->assertInvalidFields(['status']);
});

it('completes the order with a second receipt and refuses more than outstanding', function () {
    actingAsRole('clerk', GR_ALL);
    [$order, $a, $b] = orderedOrder($this);

    $first = $this->postJson('/api/v1/goods-receipts', ['purchase_order_id' => $order->id, 'lines' => [['purchase_order_line_id' => $a->id, 'quantity' => 6]]])->json('data.id');
    // A second draft created before the first posts would exceed the order once both post.
    $second = $this->postJson('/api/v1/goods-receipts', ['purchase_order_id' => $order->id, 'lines' => [
        ['purchase_order_line_id' => $a->id, 'quantity' => 6],
        ['purchase_order_line_id' => $b->id, 'quantity' => 5],
    ]])->json('data.id');

    $this->postJson("/api/v1/goods-receipts/{$first}/post")->assertOk();
    $this->postJson("/api/v1/goods-receipts/{$second}/post")->assertInvalidFields(['lines.0.quantity']);

    expect(StockMovement::query()->count())->toBe(1)
        ->and($b->refresh()->quantity_received)->toBe(0);

    $this->patchJson("/api/v1/goods-receipts/{$second}", ['lines' => [
        ['purchase_order_line_id' => $a->id, 'quantity' => 4],
        ['purchase_order_line_id' => $b->id, 'quantity' => 5],
    ]])->assertOk()->assertJsonCount(2, 'data.lines');
    $this->postJson("/api/v1/goods-receipts/{$second}/post")->assertOk();

    expect($order->refresh()->status)->toBe('received')
        ->and(StockLevel::query()->find($a->variant_id)->on_hand)->toBe(10)
        ->and(StockLevel::query()->find($b->variant_id)->on_hand)->toBe(5);

    // A received order cannot take another receipt, and cannot be cancelled.
    $this->postJson('/api/v1/goods-receipts', ['purchase_order_id' => $order->id])->assertInvalidFields(['purchase_order_id']);
});

it('refuses to post an empty receipt and deletes a draft', function () {
    actingAsRole('clerk', GR_ALL);
    [$order] = orderedOrder($this);

    $id = $this->postJson('/api/v1/goods-receipts', ['purchase_order_id' => $order->id])->json('data.id');

    $this->postJson("/api/v1/goods-receipts/{$id}/post")->assertInvalidFields(['lines']);
    $this->deleteJson("/api/v1/goods-receipts/{$id}")->assertNoContent();
    expect(GoodsReceipt::query()->count())->toBe(0);
});

it('lists stock levels and the ledger with filters', function () {
    actingAsRole('clerk', GR_ALL);
    [$order, $a, $b] = orderedOrder($this);

    $id = $this->postJson('/api/v1/goods-receipts', ['purchase_order_id' => $order->id, 'lines' => [
        ['purchase_order_line_id' => $a->id, 'quantity' => 10],
        ['purchase_order_line_id' => $b->id, 'quantity' => 2],
    ]])->json('data.id');
    $this->postJson("/api/v1/goods-receipts/{$id}/post")->assertOk();

    $this->getJson('/api/v1/stock/levels')->assertOk()->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.sku', 'B')->assertJsonPath('data.0.on_hand', 2);
    $this->getJson('/api/v1/stock/levels?max=5')->assertJsonCount(1, 'data');
    $this->getJson('/api/v1/stock/levels?q=A')->assertJsonCount(1, 'data')->assertJsonPath('data.0.sku', 'A');

    $this->getJson('/api/v1/stock/movements')->assertOk()->assertJsonCount(2, 'data');
    $this->getJson("/api/v1/stock/movements?variant_id={$a->variant_id}&type=receipt")->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.sku', 'A')->assertJsonPath('data.0.quantity', 10);
    $this->getJson('/api/v1/stock/movements?type=damage')->assertJsonCount(0, 'data');
});

it('keeps the ledger append-only and the level non-negative', function () {
    actingAsRole('clerk', GR_ALL);
    [$order, $a] = orderedOrder($this);
    $receipt = new GoodsReceipt;
    $receipt->number = GoodsReceipt::nextNumber();
    $receipt->purchase_order_id = $order->id;
    $receipt->save();

    $stock = app(StockService::class);
    $stock->apply($a->variant_id, 'receipt', 3, $receipt);

    expect(fn () => $stock->apply($a->variant_id, 'damage', -4, $receipt))->toThrow(ValidationException::class)
        ->and(StockLevel::query()->find($a->variant_id)->on_hand)->toBe(3)
        ->and(StockMovement::query()->count())->toBe(1);

    $stock->apply($a->variant_id, 'damage', -3, $receipt);
    expect(StockLevel::query()->find($a->variant_id)->on_hand)->toBe(0)
        ->and(StockMovement::query()->orderByDesc('id')->first()->quantity)->toBe(-3);

    // The database itself rejects edits to the ledger (savepoint keeps the test transaction usable).
    foreach (['UPDATE stock_movements SET quantity = 9', 'DELETE FROM stock_movements'] as $sql) {
        DB::beginTransaction();
        expect(fn () => DB::statement($sql))->toThrow(QueryException::class);
        DB::rollBack();
    }
    expect(StockMovement::query()->count())->toBe(2);
});

it('seeds the inventory permissions and menu idempotently', function () {
    $this->seed(StockModuleSeeder::class);
    $this->seed(StockModuleSeeder::class);

    expect(Permission::query()->where('name', 'like', 'goods-receipts.%')->count())->toBe(5)
        ->and(Menu::query()->where('path', '/goods-receipts')->count())->toBe(1);
});
