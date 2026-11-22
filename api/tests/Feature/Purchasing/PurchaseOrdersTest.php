<?php

use App\Models\ProductVariant;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use Database\Seeders\PurchasingModuleSeeder;

const PO_ALL = [
    'purchase-orders.view', 'purchase-orders.create', 'purchase-orders.update', 'purchase-orders.delete',
    'purchase-orders.order', 'purchase-orders.cancel',
];

beforeEach(function () {
    $this->seed(PurchasingModuleSeeder::class);
    $this->supplier = Supplier::create(['name' => 'Acme', 'code' => 'ACME']);
});

function poVariant(string $sku, ?int $cost = 1000): ProductVariant
{
    return catalogProduct([], [['sku' => $sku, 'price' => 99000, 'cost_price' => $cost]])->variants()->firstOrFail();
}

function draftOrder($test, array $lines = []): int
{
    return $test->postJson('/api/v1/purchase-orders', ['supplier_id' => $test->supplier->id, 'lines' => $lines])
        ->assertCreated()->json('data.id');
}

it('requires authentication and the module permission', function () {
    $order = new PurchaseOrder(['supplier_id' => $this->supplier->id]);
    $order->number = PurchaseOrder::nextNumber();
    $order->save();
    $line = $order->lines()->create(['variant_id' => poVariant('A')->id, 'sku' => 'A', 'name' => 'A', 'quantity_ordered' => 1, 'unit_cost' => 1, 'line_total' => 1]);

    $this->getJson('/api/v1/purchase-orders')->assertUnauthorized();
    $this->postJson("/api/v1/purchase-orders/{$order->id}/order")->assertUnauthorized();
    $this->patchJson("/api/v1/purchase-order-lines/{$line->id}", ['quantity_ordered' => 2])->assertUnauthorized();

    actingAsRole('viewer', ['purchase-orders.view']);
    $this->getJson('/api/v1/purchase-orders')->assertOk();
    $this->getJson("/api/v1/purchase-orders/{$order->id}")->assertOk()->assertJsonCount(1, 'data.lines');
    $this->postJson('/api/v1/purchase-orders', ['supplier_id' => $this->supplier->id])->assertForbidden();
    $this->patchJson("/api/v1/purchase-orders/{$order->id}", ['notes' => 'x'])->assertForbidden();
    $this->deleteJson("/api/v1/purchase-orders/{$order->id}")->assertForbidden();
    $this->postJson("/api/v1/purchase-orders/{$order->id}/lines", ['variant_id' => poVariant('B')->id, 'quantity_ordered' => 1])->assertForbidden();
    $this->patchJson("/api/v1/purchase-order-lines/{$line->id}", ['quantity_ordered' => 2])->assertForbidden();
    $this->deleteJson("/api/v1/purchase-order-lines/{$line->id}")->assertForbidden();
    $this->postJson("/api/v1/purchase-orders/{$order->id}/order")->assertForbidden();
    $this->postJson("/api/v1/purchase-orders/{$order->id}/cancel")->assertForbidden();
});

it('creates a draft with numbered sequence and server-computed totals in minor units', function () {
    actingAsRole('buyer', PO_ALL);
    $a = poVariant('A');
    $b = poVariant('B', 2501);

    $response = $this->postJson('/api/v1/purchase-orders', [
        'supplier_id' => $this->supplier->id,
        'expected_at' => '2026-12-01',
        'subtotal' => 1, 'grand_total' => 1, // ignored: never accepted from a client
        'lines' => [
            ['variant_id' => $a->id, 'quantity_ordered' => 3, 'unit_cost' => 1000, 'tax_rate_bp' => 500],
            ['variant_id' => $b->id, 'quantity_ordered' => 2, 'tax_rate_bp' => 750], // unit cost falls back to the variant's cost price
        ],
    ])->assertCreated()
        ->assertJsonPath('data.status', 'draft')
        ->assertJsonPath('data.expected_at', '2026-12-01')
        ->assertJsonPath('data.lines.0.line_total', ['amount' => 3000, 'currency' => 'BDT'])
        ->assertJsonPath('data.lines.0.tax_amount.amount', 150)
        ->assertJsonPath('data.lines.1.unit_cost.amount', 2501)
        ->assertJsonPath('data.lines.1.line_total.amount', 5002)
        ->assertJsonPath('data.lines.1.tax_amount.amount', 375)
        ->assertJsonPath('data.subtotal.amount', 8002)
        ->assertJsonPath('data.tax_total.amount', 525)
        ->assertJsonPath('data.grand_total', ['amount' => 8527, 'currency' => 'BDT'])
        ->assertJsonPath('data.supplier.code', 'ACME');

    expect($response->json('data.number'))->toMatch('/^PO-\d{6}$/')
        ->and($response->json('data.lines.0.sku'))->toBe('A');

    $second = $this->postJson('/api/v1/purchase-orders', ['supplier_id' => $this->supplier->id])->assertCreated();
    expect($second->json('data.number'))->toBeGreaterThan($response->json('data.number'))
        ->and($second->json('data.grand_total.amount'))->toBe(0);
});

it('rounds line tax half-up', function () {
    actingAsRole('buyer', PO_ALL);

    // 1 x 1 at 50% = 0.5 -> 1;  1 x 1 at 49.99% = 0.4999 -> 0
    $this->postJson('/api/v1/purchase-orders', ['supplier_id' => $this->supplier->id, 'lines' => [
        ['variant_id' => poVariant('R1')->id, 'quantity_ordered' => 1, 'unit_cost' => 1, 'tax_rate_bp' => 5000],
        ['variant_id' => poVariant('R2')->id, 'quantity_ordered' => 1, 'unit_cost' => 1, 'tax_rate_bp' => 4999],
    ]])->assertCreated()->assertJsonPath('data.lines.0.tax_amount.amount', 1)->assertJsonPath('data.lines.1.tax_amount.amount', 0);
});

it('validates the order and its lines', function () {
    actingAsRole('buyer', PO_ALL);
    $v = poVariant('V1');
    $noCost = poVariant('V2', null);
    $off = Supplier::create(['name' => 'Off', 'code' => 'OFF', 'is_active' => false]);

    $this->postJson('/api/v1/purchase-orders', [])->assertInvalidFields(['supplier_id']);
    $this->postJson('/api/v1/purchase-orders', ['supplier_id' => $off->id])->assertInvalidFields(['supplier_id']);
    $this->postJson('/api/v1/purchase-orders', ['supplier_id' => 999999, 'expected_at' => 'tomorrow'])->assertInvalidFields(['supplier_id', 'expected_at']);

    $this->postJson('/api/v1/purchase-orders', ['supplier_id' => $this->supplier->id, 'lines' => [
        ['variant_id' => 999999, 'quantity_ordered' => 0, 'unit_cost' => 1.5, 'tax_rate_bp' => 10001],
    ]])->assertInvalidFields(['lines.0.variant_id', 'lines.0.quantity_ordered', 'lines.0.unit_cost', 'lines.0.tax_rate_bp']);

    $this->postJson('/api/v1/purchase-orders', ['supplier_id' => $this->supplier->id, 'lines' => [
        ['variant_id' => $v->id, 'quantity_ordered' => 1], ['variant_id' => $v->id, 'quantity_ordered' => 1],
    ]])->assertInvalidFields(['lines.0.variant_id', 'lines.1.variant_id']);

    $this->postJson('/api/v1/purchase-orders', ['supplier_id' => $this->supplier->id, 'lines' => [
        ['variant_id' => $noCost->id, 'quantity_ordered' => 1],
    ]])->assertInvalidFields(['lines.0.unit_cost']);

    expect(PurchaseOrder::count())->toBe(0); // the failed create rolled back
});

it('edits the header and lines of a draft and keeps the totals right', function () {
    actingAsRole('buyer', PO_ALL);
    $a = poVariant('E1');
    $id = draftOrder($this, [['variant_id' => $a->id, 'quantity_ordered' => 2, 'unit_cost' => 500]]);
    $lineId = PurchaseOrder::find($id)->lines()->first()->id;

    $this->patchJson("/api/v1/purchase-orders/{$id}", ['notes' => 'Rush', 'expected_at' => null])->assertOk()->assertJsonPath('data.notes', 'Rush');

    $b = poVariant('E2');
    $this->postJson("/api/v1/purchase-orders/{$id}/lines", ['variant_id' => $b->id, 'quantity_ordered' => 4, 'unit_cost' => 250, 'tax_rate_bp' => 1000])
        ->assertOk()->assertJsonCount(2, 'data.lines')->assertJsonPath('data.subtotal.amount', 2000)->assertJsonPath('data.tax_total.amount', 100);

    $this->postJson("/api/v1/purchase-orders/{$id}/lines", ['variant_id' => $b->id, 'quantity_ordered' => 1])->assertInvalidFields(['variant_id']);

    $this->patchJson("/api/v1/purchase-order-lines/{$lineId}", ['quantity_ordered' => 10])
        ->assertOk()->assertJsonPath('data.subtotal.amount', 6000)->assertJsonPath('data.grand_total.amount', 6100);
    $this->patchJson("/api/v1/purchase-order-lines/{$lineId}", ['quantity_ordered' => 0])->assertInvalidFields(['quantity_ordered']);
    $this->patchJson("/api/v1/purchase-order-lines/{$lineId}", ['variant_id' => $b->id, 'unit_cost' => 600])
        ->assertOk()->assertJsonPath('data.lines.0.variant_id', $a->id)->assertJsonPath('data.grand_total.amount', 7100);

    $this->deleteJson("/api/v1/purchase-order-lines/{$lineId}")->assertOk()->assertJsonCount(1, 'data.lines')->assertJsonPath('data.grand_total.amount', 1100);
});

it('orders a draft only when it has lines and an active supplier, then freezes it', function () {
    actingAsRole('buyer', PO_ALL);
    $id = draftOrder($this);

    $this->postJson("/api/v1/purchase-orders/{$id}/order")->assertInvalidFields(['lines']);

    $this->postJson("/api/v1/purchase-orders/{$id}/lines", ['variant_id' => poVariant('O1')->id, 'quantity_ordered' => 2, 'unit_cost' => 100])->assertOk();
    $this->supplier->update(['is_active' => false]);
    $this->postJson("/api/v1/purchase-orders/{$id}/order")->assertInvalidFields(['supplier_id']);
    $this->supplier->update(['is_active' => true]);

    $this->postJson("/api/v1/purchase-orders/{$id}/order")->assertOk()->assertJsonPath('data.status', 'ordered');
    expect(PurchaseOrder::find($id)->ordered_at)->not->toBeNull();

    // Posted orders are immutable: every change is refused and nothing moves.
    $lineId = PurchaseOrder::find($id)->lines()->first()->id;
    $this->postJson("/api/v1/purchase-orders/{$id}/order")->assertInvalidFields(['status']);
    $this->patchJson("/api/v1/purchase-orders/{$id}", ['notes' => 'late'])->assertInvalidFields(['status']);
    $this->postJson("/api/v1/purchase-orders/{$id}/lines", ['variant_id' => poVariant('O2')->id, 'quantity_ordered' => 1, 'unit_cost' => 1])->assertInvalidFields(['status']);
    $this->patchJson("/api/v1/purchase-order-lines/{$lineId}", ['quantity_ordered' => 9])->assertInvalidFields(['status']);
    $this->deleteJson("/api/v1/purchase-order-lines/{$lineId}")->assertInvalidFields(['status']);
    $this->deleteJson("/api/v1/purchase-orders/{$id}")->assertInvalidFields(['status']);

    expect(PurchaseOrder::find($id)->grand_total)->toBe(200)->and(PurchaseOrder::find($id)->lines()->first()->quantity_ordered)->toBe(2);
});

it('deletes a draft', function () {
    actingAsRole('buyer', PO_ALL);
    $id = draftOrder($this, [['variant_id' => poVariant('D1')->id, 'quantity_ordered' => 1, 'unit_cost' => 1]]);

    $this->deleteJson("/api/v1/purchase-orders/{$id}")->assertNoContent();
    expect(PurchaseOrder::find($id))->toBeNull();
});

it('cancels an ordered order only while nothing was received', function () {
    actingAsRole('buyer', PO_ALL);
    $draft = draftOrder($this, [['variant_id' => poVariant('C1')->id, 'quantity_ordered' => 5, 'unit_cost' => 10]]);

    $this->postJson("/api/v1/purchase-orders/{$draft}/cancel")->assertInvalidFields(['status']);

    $this->postJson("/api/v1/purchase-orders/{$draft}/order")->assertOk();
    $this->postJson("/api/v1/purchase-orders/{$draft}/cancel")->assertOk()->assertJsonPath('data.status', 'cancelled');
    expect(PurchaseOrder::find($draft)->cancelled_at)->not->toBeNull();
    $this->postJson("/api/v1/purchase-orders/{$draft}/cancel")->assertInvalidFields(['status']);

    // Goods already received (written by E3's check-in): cannot be cancelled.
    $received = draftOrder($this, [['variant_id' => poVariant('C2')->id, 'quantity_ordered' => 5, 'unit_cost' => 10]]);
    $this->postJson("/api/v1/purchase-orders/{$received}/order")->assertOk();
    PurchaseOrder::find($received)->lines()->update(['quantity_received' => 2]);
    $this->postJson("/api/v1/purchase-orders/{$received}/cancel")->assertInvalidFields(['status']);

    PurchaseOrder::find($received)->forceFill(['status' => PurchaseOrder::PARTIALLY_RECEIVED])->save();
    $this->postJson("/api/v1/purchase-orders/{$received}/cancel")->assertInvalidFields(['status']);
});

it('lists orders with filters', function () {
    actingAsRole('buyer', PO_ALL);
    $other = Supplier::create(['name' => 'Beta Goods', 'code' => 'BETA']);

    $a = draftOrder($this);
    $b = $this->postJson('/api/v1/purchase-orders', ['supplier_id' => $other->id, 'lines' => [['variant_id' => poVariant('L1')->id, 'quantity_ordered' => 1, 'unit_cost' => 1]]])->json('data.id');
    $this->postJson("/api/v1/purchase-orders/{$b}/order")->assertOk();

    $ids = fn (string $query) => collect($this->getJson("/api/v1/purchase-orders?{$query}")->assertOk()->json('data'))->pluck('id')->all();

    expect($ids(''))->toBe([$b, $a])
        ->and($ids('status=ordered'))->toBe([$b])
        ->and($ids("supplier_id={$this->supplier->id}"))->toBe([$a])
        ->and($ids('q=beta'))->toBe([$b])
        ->and($ids('q='.PurchaseOrder::find($a)->number))->toBe([$a])
        ->and($ids('from='.now()->toDateString().'&to='.now()->toDateString()))->toBe([$b, $a])
        ->and($ids('from='.now()->addDay()->toDateString()))->toBe([]);

    $this->getJson('/api/v1/purchase-orders')->assertJsonMissingPath('data.0.lines');
});
