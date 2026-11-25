<?php

use App\Models\CodRemittance;
use App\Models\Courier;
use App\Models\Delivery;
use App\Models\DeliveryZone;
use App\Models\Order;
use App\Models\Product;
use App\Models\StockLevel;
use Database\Seeders\CustomerClientSeeder;
use Database\Seeders\DeliveryModuleSeeder;
use Database\Seeders\OrdersModuleSeeder;
use Database\Seeders\PaymentMethodsSeeder;
use Spatie\Permission\Models\Permission;

const DLV_ALL = [
    'orders.view', 'orders.update', 'orders.cancel', 'delivery.view', 'delivery.manage',
    'cod-remittances.view', 'cod-remittances.create', 'cod-remittances.post', 'cod-remittances.delete', 'reports.view',
];

beforeEach(function () {
    $this->seed([PaymentMethodsSeeder::class, OrdersModuleSeeder::class, DeliveryModuleSeeder::class, CustomerClientSeeder::class]);
});

function dlvOrder($test, int $price = 10000, ?int $zoneId = null): int
{
    $product = catalogProduct(['status' => Product::ACTIVE], [['sku' => 'D-'.uniqid(), 'price' => $price]]);
    $product->category->update(['is_active' => true]);
    $variant = $product->variants()->firstOrFail();
    StockLevel::query()->insertOrIgnore(['variant_id' => $variant->id, 'on_hand' => 50]);

    $token = $test->postJson('/api/v1/public/carts')->json('data.token');
    $test->postJson("/api/v1/public/carts/{$token}/items", ['variant_id' => $variant->id, 'quantity' => 1]);

    return $test->postJson("/api/v1/public/carts/{$token}/checkout", [
        'contact_name' => 'Rafi', 'contact_phone' => '017', 'address_line' => 'House 1', 'city' => 'Dhaka',
        'payment_method' => 'cod', 'delivery_zone_id' => $zoneId,
    ])->assertCreated()->json('data.id');
}

/** Moves an order to delivered through a courier; returns the delivery. */
function dlvDeliver($test, int $orderId, Courier $courier): Delivery
{
    foreach (['advance', 'advance'] as $step) {
        $test->postJson("/api/v1/orders/{$orderId}/{$step}")->assertOk();
    }
    $test->postJson("/api/v1/orders/{$orderId}/delivery", ['courier_id' => $courier->id, 'tracking_number' => 'TRK1'])->assertOk();
    foreach (['advance', 'advance'] as $step) {
        $test->postJson("/api/v1/orders/{$orderId}/{$step}")->assertOk();
    }

    return Delivery::where('order_id', $orderId)->firstOrFail();
}

test('delivery admin routes need authentication and permissions', function () {
    $zone = DeliveryZone::create(['name' => 'Dhaka', 'shipping_fee' => 6000]);
    $courier = Courier::create(['name' => 'Pathao']);

    $this->getJson('/api/v1/delivery-zones')->assertUnauthorized();
    $this->getJson('/api/v1/reports/sales')->assertUnauthorized();

    actingAsRole('nobody');
    $this->getJson('/api/v1/delivery-zones')->assertForbidden();
    $this->postJson('/api/v1/delivery-zones', ['name' => 'X', 'shipping_fee' => 1])->assertForbidden();
    $this->patchJson("/api/v1/delivery-zones/{$zone->id}", ['name' => 'Y'])->assertForbidden();
    $this->deleteJson("/api/v1/delivery-zones/{$zone->id}")->assertForbidden();
    $this->getJson('/api/v1/couriers')->assertForbidden();
    $this->postJson('/api/v1/couriers', ['name' => 'X'])->assertForbidden();
    $this->deleteJson("/api/v1/couriers/{$courier->id}")->assertForbidden();
    $this->getJson('/api/v1/cod-remittances')->assertForbidden();
    $this->postJson('/api/v1/cod-remittances', [])->assertForbidden();
    $this->getJson('/api/v1/reports/sales')->assertForbidden();
    $this->getJson('/api/v1/reports/cod-reconciliation')->assertForbidden();
    $this->getJson('/api/v1/reports/stock-valuation')->assertForbidden();
});

test('zones and couriers: crud, validation, courier with deliveries is kept', function () {
    actingAsRole('ops', DLV_ALL);

    $id = $this->postJson('/api/v1/delivery-zones', ['name' => 'Dhaka', 'shipping_fee' => 6000])->assertCreated()
        ->assertJsonPath('data.shipping_fee.amount', 6000)->assertJsonPath('data.is_active', true)->json('data.id');
    $this->postJson('/api/v1/delivery-zones', ['shipping_fee' => -1])->assertUnprocessable()->assertInvalidFields(['name', 'shipping_fee']);
    $this->patchJson("/api/v1/delivery-zones/{$id}", ['shipping_fee' => 12000])->assertOk()->assertJsonPath('data.shipping_fee.amount', 12000);
    $this->getJson('/api/v1/delivery-zones')->assertOk()->assertJsonCount(1, 'data');
    $this->getJson('/api/v1/public/delivery-zones')->assertOk()->assertJsonCount(1, 'data');
    $this->patchJson("/api/v1/delivery-zones/{$id}", ['is_active' => false])->assertOk();
    $this->getJson('/api/v1/public/delivery-zones')->assertOk()->assertJsonCount(0, 'data');
    $this->deleteJson("/api/v1/delivery-zones/{$id}")->assertNoContent();

    $courier = $this->postJson('/api/v1/couriers', ['name' => 'Pathao'])->assertCreated()->json('data.id');
    $this->postJson('/api/v1/couriers', [])->assertUnprocessable()->assertInvalidFields(['name']);
    $this->patchJson("/api/v1/couriers/{$courier}", ['phone' => '017'])->assertOk()->assertJsonPath('data.phone', '017');

    $orderId = dlvOrder($this);
    dlvDeliver($this, $orderId, Courier::findOrFail($courier));
    $this->deleteJson("/api/v1/couriers/{$courier}")->assertUnprocessable()->assertInvalidFields(['courier']);
});

test('checkout shipping fee follows the delivery zone', function () {
    $zone = DeliveryZone::create(['name' => 'Outside', 'shipping_fee' => 15000]);
    $inactive = DeliveryZone::create(['name' => 'Old', 'shipping_fee' => 1, 'is_active' => false]);

    $id = dlvOrder($this, 10000, $zone->id);
    actingAsRole('ops', DLV_ALL);
    $this->getJson("/api/v1/orders/{$id}")->assertOk()
        ->assertJsonPath('data.shipping_total.amount', 15000)->assertJsonPath('data.grand_total.amount', 25000)
        ->assertJsonPath('data.delivery_zone_id', $zone->id);

    // Without a zone the flat default applies; an inactive zone is rejected.
    expect(Order::findOrFail(dlvOrder($this))->shipping_total)->toBe(6000);
    $token = $this->postJson('/api/v1/public/carts')->json('data.token');
    $this->postJson("/api/v1/public/carts/{$token}/checkout", ['delivery_zone_id' => $inactive->id])->assertUnprocessable()->assertInvalidFields(['delivery_zone_id']);
});

test('assigning a courier needs a packed order and an active courier', function () {
    $orderId = dlvOrder($this);
    $courier = Courier::create(['name' => 'Pathao']);
    actingAsRole('ops', DLV_ALL);

    $this->postJson("/api/v1/orders/{$orderId}/delivery", ['courier_id' => $courier->id])->assertUnprocessable()->assertInvalidFields(['status']);
    $this->postJson("/api/v1/orders/{$orderId}/advance");
    $this->postJson("/api/v1/orders/{$orderId}/advance");
    $this->postJson("/api/v1/orders/{$orderId}/delivery", [])->assertUnprocessable()->assertInvalidFields(['courier_id']);

    $courier->update(['is_active' => false]);
    $this->postJson("/api/v1/orders/{$orderId}/delivery", ['courier_id' => $courier->id])->assertUnprocessable()->assertInvalidFields(['courier_id']);

    $courier->update(['is_active' => true]);
    $this->postJson("/api/v1/orders/{$orderId}/delivery", ['courier_id' => $courier->id, 'tracking_number' => 'T-9'])->assertOk()
        ->assertJsonPath('data.delivery.cod_amount.amount', 16000)->assertJsonPath('data.delivery.tracking_number', 'T-9')
        ->assertJsonPath('data.delivery.collected_at', null);
});

test('delivering collects the cod cash and marks the order paid', function () {
    $orderId = dlvOrder($this);
    actingAsRole('ops', DLV_ALL);
    $delivery = dlvDeliver($this, $orderId, Courier::create(['name' => 'Pathao']));

    expect($delivery->collected_amount)->toBe(16000)->and($delivery->collected_at)->not->toBeNull()
        ->and(Order::findOrFail($orderId)->payment_status)->toBe('paid');
});

test('remittance: draft, post with difference, immutable afterwards', function () {
    actingAsRole('ops', DLV_ALL);
    $courier = Courier::create(['name' => 'Pathao']);
    $a = dlvDeliver($this, dlvOrder($this, 10000), $courier);
    $b = dlvDeliver($this, dlvOrder($this, 20000), $courier);

    $response = $this->postJson('/api/v1/cod-remittances', ['courier_id' => $courier->id, 'delivery_ids' => [$a->id, $b->id], 'reference' => 'DEP-1'])
        ->assertCreated()->assertJsonPath('data.status', 'draft')->assertJsonPath('data.expected_amount.amount', 16000 + 26000)
        ->assertJsonCount(2, 'data.deliveries');
    expect($response->json('data.number'))->toMatch('/^RM-\d{6}$/');
    $id = $response->json('data.id');

    // A delivery cannot be in two remittances.
    $this->postJson('/api/v1/cod-remittances', ['courier_id' => $courier->id, 'delivery_ids' => [$a->id]])->assertUnprocessable()->assertInvalidFields(['delivery_ids']);

    $this->postJson("/api/v1/cod-remittances/{$id}/post", [])->assertUnprocessable()->assertInvalidFields(['received_amount']);
    $this->postJson("/api/v1/cod-remittances/{$id}/post", ['received_amount' => 41000])->assertOk()
        ->assertJsonPath('data.status', 'posted')->assertJsonPath('data.difference.amount', -1000);

    $this->postJson("/api/v1/cod-remittances/{$id}/post", ['received_amount' => 42000])->assertUnprocessable()->assertInvalidFields(['status']);
    $this->deleteJson("/api/v1/cod-remittances/{$id}")->assertUnprocessable()->assertInvalidFields(['status']);
    expect(CodRemittance::findOrFail($id)->received_amount)->toBe(41000);
});

test('remittance rejects other couriers deliveries and uncollected ones; deleting a draft releases them', function () {
    actingAsRole('ops', DLV_ALL);
    $pathao = Courier::create(['name' => 'Pathao']);
    $other = Courier::create(['name' => 'Steadfast']);
    $delivery = dlvDeliver($this, dlvOrder($this), $pathao);

    $this->postJson('/api/v1/cod-remittances', ['courier_id' => $other->id, 'delivery_ids' => [$delivery->id]])->assertUnprocessable()->assertInvalidFields(['delivery_ids']);
    $this->postJson('/api/v1/cod-remittances', ['courier_id' => $pathao->id, 'delivery_ids' => []])->assertUnprocessable()->assertInvalidFields(['delivery_ids']);

    $uncollected = Delivery::query()->forceCreate(['order_id' => dlvOrder($this), 'courier_id' => $pathao->id, 'cod_amount' => 100]);
    $this->postJson('/api/v1/cod-remittances', ['courier_id' => $pathao->id, 'delivery_ids' => [$uncollected->id]])->assertUnprocessable()->assertInvalidFields(['delivery_ids']);

    $id = $this->postJson('/api/v1/cod-remittances', ['courier_id' => $pathao->id, 'delivery_ids' => [$delivery->id]])->assertCreated()->json('data.id');
    $this->deleteJson("/api/v1/cod-remittances/{$id}")->assertNoContent();
    expect($delivery->fresh()->remittance_id)->toBeNull();
    $this->getJson('/api/v1/cod-remittances')->assertOk()->assertJsonCount(0, 'data');
});

test('reports: sales, cod reconciliation and stock valuation', function () {
    actingAsRole('ops', DLV_ALL);
    $courier = Courier::create(['name' => 'Pathao']);
    $a = dlvDeliver($this, dlvOrder($this, 10000), $courier);
    dlvDeliver($this, dlvOrder($this, 20000), $courier);
    $cancelled = dlvOrder($this, 99999);
    $this->postJson("/api/v1/orders/{$cancelled}/cancel")->assertOk();

    $id = $this->postJson('/api/v1/cod-remittances', ['courier_id' => $courier->id, 'delivery_ids' => [$a->id]])->json('data.id');
    $this->postJson("/api/v1/cod-remittances/{$id}/post", ['received_amount' => 15000])->assertOk();

    $this->getJson('/api/v1/reports/sales')->assertOk()
        ->assertJsonPath('data.orders', 2)->assertJsonPath('data.revenue.amount', 16000 + 26000)
        ->assertJsonPath('data.by_status.cancelled', 1)->assertJsonCount(1, 'data.days');
    $this->getJson('/api/v1/reports/sales?from=2000-01-01&to=2000-01-02')->assertOk()->assertJsonPath('data.orders', 0);
    $this->getJson('/api/v1/reports/sales?from=bad')->assertUnprocessable()->assertInvalidFields(['from']);

    $this->getJson('/api/v1/reports/cod-reconciliation')->assertOk()
        ->assertJsonPath('data.couriers.0.collected.amount', 42000)
        ->assertJsonPath('data.couriers.0.remitted_expected.amount', 16000)
        ->assertJsonPath('data.couriers.0.remitted_received.amount', 15000)
        ->assertJsonPath('data.couriers.0.difference.amount', -1000)
        ->assertJsonPath('data.couriers.0.outstanding.amount', 26000)
        ->assertJsonPath('data.outstanding.amount', 26000);

    $this->getJson('/api/v1/reports/stock-valuation')->assertOk()
        ->assertJsonStructure(['data' => ['variants', 'units', 'cost_value' => ['amount', 'currency'], 'retail_value']]);
});

test('delivery seeder is idempotent', function () {
    $this->seed(DeliveryModuleSeeder::class);
    $this->seed(DeliveryModuleSeeder::class);

    expect(Permission::where('name', 'reports.view')->count())->toBe(1);
});
